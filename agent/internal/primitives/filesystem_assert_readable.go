//go:build linux

package primitives

import (
	"context"
	"errors"
	"fmt"
	"strings"
	"time"

	"golang.org/x/sys/unix"

	"github.com/muckiware/MuckiSafeAgentPlugin/agent/internal/executor"
)

// assertReadable proves, with the file system identity of the backup
// principal, that the objects of a check alias can be opened and listed.
// The check alias alone selects the root, principal and rule; the action
// cannot supply a path, glob or id.
type assertReadable struct {
	manifests *manifestStore
}

func (a *assertReadable) Run(_ context.Context, step executor.StepContext) error {
	check, alias, err := resolve[checkEntry](step, "checkAlias", sectionChecks)
	if err != nil {
		return err
	}
	principal, err := lookup[principalEntry](step, sectionPrincipals, check.ReadableBy, "check "+alias)
	if err != nil {
		return err
	}
	if check.DestinationAlias != "" {
		return a.currentOutput(step, alias, check, principal)
	}
	path, err := lookup[pathEntry](step, sectionPaths, check.PathAlias, "check "+alias)
	if err != nil {
		return err
	}
	rootfd, err := openRoot(path.Root)
	if err != nil {
		return pathFailure(codeVerification, "check "+alias, err)
	}
	defer unix.Close(rootfd)
	err = asPrincipal(principal, func() error { return verifyPath(rootfd, path, check, step.Started) })
	if err != nil {
		return pathFailure(codeVerification, "check "+alias, err)
	}
	return nil
}

// verifyPath runs on the principal's thread and must not start goroutines.
func verifyPath(rootfd int, path pathEntry, check checkEntry, started time.Time) error {
	for _, relative := range check.RelativePaths {
		if err := verifyObject(rootfd, relative, path.MaxEntries); err != nil {
			return err
		}
	}
	if check.Recursive {
		fd, err := openBeneath(rootfd, ".", directoryFlags)
		if err != nil {
			return err
		}
		defer unix.Close(fd)
		w := &walker{maxDepth: path.MaxDepth, maxEntries: path.MaxEntries, entries: 1,
			visit: func(int, *unix.Stat_t, string) error { return nil }}
		if err := w.walk(fd, ".", 0); err != nil {
			return err
		}
	}
	if check.Match != nil {
		return verifyMatch(rootfd, path, *check.Match, started)
	}
	return nil
}

// verifyObject opens a file or lists a directory below the root.
func verifyObject(rootfd int, relative string, maxEntries int) error {
	fd, err := openBeneath(rootfd, relative, readFlags)
	if err != nil {
		return fmt.Errorf("%s: %w", relative, err)
	}
	defer unix.Close(fd)
	var st unix.Stat_t
	if err := unix.Fstat(fd, &st); err != nil {
		return err
	}
	switch st.Mode & unix.S_IFMT {
	case unix.S_IFREG:
		return nil
	case unix.S_IFDIR:
		if _, err := readNames(fd, maxEntries); err != nil {
			return fmt.Errorf("%s: %w", relative, err)
		}
		// AT_EACCESS checks with the thread's fsuid/fsgid
		if err := unix.Faccessat(fd, ".", unix.X_OK, unix.AT_EACCESS); err != nil {
			return fmt.Errorf("%s: search: %w", relative, err)
		}
		return nil
	}
	return fmt.Errorf("%s: %w", relative, errNotFileOrDirectory)
}

// verifyMatch requires at least MinimumCount readable regular files with the
// suffix in the directory that satisfy the size and current-run rules.
func verifyMatch(rootfd int, path pathEntry, rule matchRule, started time.Time) error {
	dirfd, err := openBeneath(rootfd, rule.Directory, directoryFlags)
	if err != nil {
		return fmt.Errorf("%s: %w", rule.Directory, err)
	}
	defer unix.Close(dirfd)
	names, err := readNames(dirfd, path.MaxEntries)
	if err != nil {
		return fmt.Errorf("%s: %w", rule.Directory, err)
	}
	found := 0
	for _, name := range names {
		if !strings.HasSuffix(name, rule.Suffix) {
			continue
		}
		matches, err := matchingFile(dirfd, name, rule, started)
		if err != nil {
			return fmt.Errorf("%s/%s: %w", rule.Directory, name, err)
		}
		if matches {
			found++
		}
	}
	if found < rule.MinimumCount {
		return fmt.Errorf("%w: %s holds %d of at least %d required *%s files", errCheckUnmet, rule.Directory, found, rule.MinimumCount, rule.Suffix)
	}
	return nil
}

var errCheckUnmet = errors.New("check not satisfied")

// matchingFile reports whether name satisfies the rule; a satisfying file
// must also be readable.
func matchingFile(dirfd int, name string, rule matchRule, started time.Time) (bool, error) {
	var stx unix.Statx_t
	if err := unix.Statx(dirfd, name, unix.AT_SYMLINK_NOFOLLOW, unix.STATX_BASIC_STATS|unix.STATX_BTIME, &stx); err != nil {
		return false, err
	}
	if stx.Mode&unix.S_IFMT != unix.S_IFREG || int64(stx.Size) < rule.MinimumSize {
		return false, nil
	}
	if rule.CreatedByCurrentRun && !createdSince(&stx, started) {
		return false, nil
	}
	fd, err := openBeneath(dirfd, name, readFlags)
	if err != nil {
		return false, err
	}
	defer unix.Close(fd)
	var st unix.Stat_t
	if err := unix.Fstat(fd, &st); err != nil || st.Ino != stx.Ino {
		return false, errors.New("the file was replaced while it was checked")
	}
	return true, nil
}

// createdSince compares the birth time (or, where the file system has none,
// the modification time) with the run start. Kernel file times use a coarse
// clock, so the start is truncated to the second.
func createdSince(stx *unix.Statx_t, started time.Time) bool {
	since := started.Truncate(time.Second)
	modified := time.Unix(stx.Mtime.Sec, int64(stx.Mtime.Nsec))
	if modified.Before(since) {
		return false
	}
	if stx.Mask&unix.STATX_BTIME != 0 {
		return !time.Unix(stx.Btime.Sec, int64(stx.Btime.Nsec)).Before(since)
	}
	return true
}

// currentOutput verifies the file this run published to a destination by
// its manifest identity.
func (a *assertReadable) currentOutput(step executor.StepContext, alias string, check checkEntry, principal principalEntry) error {
	destination, err := lookup[destinationEntry](step, sectionDestinations, check.DestinationAlias, "check "+alias)
	if err != nil {
		return err
	}
	path, err := lookup[pathEntry](step, sectionPaths, destination.PathAlias, "destination "+check.DestinationAlias)
	if err != nil {
		return err
	}
	recorded, err := a.manifests.read(check.DestinationAlias)
	if err != nil {
		return failure(codeVerification, "check %s: %v", alias, err)
	}
	var output *manifestEntry
	for i, entry := range recorded.Entries {
		if entry.RunID == step.Job.ID && destination.owns(entry) {
			output = &recorded.Entries[i]
		}
	}
	if output == nil {
		return failure(codeVerification, "check %s: this run recorded no output in %s", alias, check.DestinationAlias)
	}
	rootfd, err := openRoot(path.Root)
	if err != nil {
		return pathFailure(codeVerification, "check "+alias, err)
	}
	defer unix.Close(rootfd)
	err = asPrincipal(principal, func() error { return verifyOutput(rootfd, destination, *output, check.MinimumSize) })
	if err != nil {
		return pathFailure(codeVerification, "check "+alias, err)
	}
	return nil
}

func verifyOutput(rootfd int, destination destinationEntry, output manifestEntry, minimumSize int64) error {
	dirfd, err := openBeneath(rootfd, destination.Directory, directoryFlags)
	if err != nil {
		return fmt.Errorf("%s: %w", destination.Directory, err)
	}
	defer unix.Close(dirfd)
	fd, err := openBeneath(dirfd, output.Name, readFlags)
	if err != nil {
		return fmt.Errorf("%s: %w", output.Name, err)
	}
	defer unix.Close(fd)
	var st unix.Stat_t
	if err := unix.Fstat(fd, &st); err != nil {
		return err
	}
	switch {
	case st.Mode&unix.S_IFMT != unix.S_IFREG || st.Dev != output.Device || st.Ino != output.Inode:
		return fmt.Errorf("%s does not match its manifest identity", output.Name)
	case st.Size < minimumSize:
		return fmt.Errorf("%w: %s has %d bytes, at least %d required", errCheckUnmet, output.Name, st.Size, minimumSize)
	}
	return nil
}
