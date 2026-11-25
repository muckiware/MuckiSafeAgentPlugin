//go:build linux

package primitives

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"sort"
	"strconv"

	"golang.org/x/sys/unix"

	"github.com/muckiware/MuckiSafeAgentPlugin/agent/internal/executor"
)

// retention keeps the newest `keep` outputs of a destination manifest and
// deletes older ones. Only manifest entries are candidates; every candidate
// must still be a regular file with link count 1 and its recorded
// device/inode, otherwise nothing at all is deleted. Each identity is
// re-checked descriptor-relatively right before unlinkat.
type retention struct {
	manifests *manifestStore
}

func (r *retention) Run(_ context.Context, step executor.StepContext) error {
	destination, alias, err := resolve[destinationEntry](step, "manifestAlias", sectionDestinations)
	if err != nil {
		return err
	}
	if pathAlias, _ := step.Step["pathAlias"].(string); pathAlias != destination.PathAlias {
		return failure(codePolicy, "manifest %s belongs to path %s, not %s", alias, destination.PathAlias, pathAlias)
	}
	number, _ := step.Step["keep"].(json.Number)
	keep, err := strconv.Atoi(number.String())
	if err != nil || keep > destination.MaximumKeep {
		return failure(codePolicy, "manifest %s allows keeping at most %d files", alias, destination.MaximumKeep)
	}
	path, err := lookup[pathEntry](step, sectionPaths, destination.PathAlias, "destination "+alias)
	if err != nil {
		return err
	}
	dirfd, err := openAliasDirectory(path, destination.Directory)
	if err != nil {
		return pathFailure(codeRetention, "manifest "+alias, err)
	}
	defer unix.Close(dirfd)
	err = r.manifests.update(alias, func(m *manifest) error {
		return prune(step, dirfd, destination, m, keep)
	})
	var stepError *executor.StepError
	if err != nil && !errors.As(err, &stepError) {
		return failure(codeRetention, "manifest %s: %v", alias, err)
	}
	return err
}

// prune verifies every recorded output first and deletes nothing on any
// mismatch. Vanished outputs are forgotten and do not occupy keep slots.
func prune(step executor.StepContext, dirfd int, destination destinationEntry, m *manifest, keep int) error {
	sort.Slice(m.Entries, func(i, j int) bool { return m.Entries[i].Sequence < m.Entries[j].Sequence })
	var existing []manifestEntry
	for _, entry := range m.Entries {
		exists, err := verifyIdentity(dirfd, destination, entry)
		if err != nil {
			return failure(codeRetention, "nothing was deleted: %s: %v", entry.Name, err)
		}
		if exists {
			existing = append(existing, entry)
		}
	}
	expired := existing[:max(len(existing)-keep, 0)]
	if len(expired) > 0 {
		if err := step.Gate(); err != nil {
			return fmt.Errorf("no deletion may start: %w", err)
		}
	}
	for _, entry := range expired {
		if err := remove(dirfd, destination, entry); err != nil {
			return failure(codeRetention, "%s: %v", entry.Name, err)
		}
	}
	if err := unix.Fsync(dirfd); err != nil {
		return failure(codeRetention, "sync destination directory: %v", err)
	}
	m.Entries = append([]manifestEntry{}, existing[len(expired):]...)
	return nil
}

// verifyIdentity reports whether the entry still exists with its recorded
// identity; a vanished file is fine, a different one is an error.
func verifyIdentity(dirfd int, destination destinationEntry, entry manifestEntry) (bool, error) {
	if !destination.owns(entry) {
		return false, errors.New("the manifest entry is not an output name of this destination")
	}
	var st unix.Stat_t
	err := unix.Fstatat(dirfd, entry.Name, &st, unix.AT_SYMLINK_NOFOLLOW)
	if errors.Is(err, unix.ENOENT) {
		return false, nil
	}
	if err != nil {
		return false, err
	}
	switch {
	case st.Mode&unix.S_IFMT != unix.S_IFREG:
		return false, errors.New("not a regular file")
	case st.Dev != entry.Device || st.Ino != entry.Inode:
		return false, errors.New("device/inode differ from the manifest")
	case st.Nlink != 1:
		return false, errors.New("link count is not 1")
	}
	return true, nil
}

func remove(dirfd int, destination destinationEntry, entry manifestEntry) error {
	exists, err := verifyIdentity(dirfd, destination, entry)
	if err != nil || !exists {
		return err
	}
	return unix.Unlinkat(dirfd, entry.Name, 0)
}
