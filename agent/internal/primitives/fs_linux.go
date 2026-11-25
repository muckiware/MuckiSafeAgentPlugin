//go:build linux

package primitives

import (
	"bytes"
	"errors"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"runtime"
	"strings"

	"golang.org/x/sys/unix"
)

const (
	resolveBeneath = unix.RESOLVE_BENEATH | unix.RESOLVE_NO_SYMLINKS | unix.RESOLVE_NO_MAGICLINKS
	cgroup2Magic   = 0x63677270
	readFlags      = unix.O_RDONLY | unix.O_NONBLOCK | unix.O_NOCTTY
	directoryFlags = unix.O_RDONLY | unix.O_DIRECTORY
)

// openBeneath opens path below dirfd; no component, including the last, may
// be a symlink or magic link or leave dirfd (ELOOP/EXDEV). O_NOFOLLOW is not
// added: it would turn a trailing symlink into ENOTDIR for directories. A
// concurrent rename makes openat2 fail with EAGAIN, which is retried.
func openBeneath(dirfd int, path string, flags int) (int, error) {
	how := &unix.OpenHow{Flags: uint64(flags | unix.O_CLOEXEC), Resolve: resolveBeneath}
	for attempt := 0; ; attempt++ {
		fd, err := unix.Openat2(dirfd, path, how)
		if (errors.Is(err, unix.EAGAIN) || errors.Is(err, unix.EINTR)) && attempt < 8 {
			continue
		}
		return fd, err
	}
}

// openRoot opens the absolute root of an alias without following symlinks.
func openRoot(root string) (int, error) {
	slash, err := unix.Open("/", directoryFlags|unix.O_CLOEXEC, 0)
	if err != nil {
		return -1, err
	}
	defer unix.Close(slash)
	return openBeneath(slash, strings.TrimPrefix(root, "/"), directoryFlags)
}

// openAliasDirectory opens relative (or "." for the root) below the root of
// a path alias as a directory.
func openAliasDirectory(entry pathEntry, relative string) (int, error) {
	rootfd, err := openRoot(entry.Root)
	if err != nil {
		return -1, err
	}
	if relative == "." {
		return rootfd, nil
	}
	defer unix.Close(rootfd)
	return openBeneath(rootfd, relative, directoryFlags)
}

// pathFailure turns a resolution error into a policy rejection when a
// symlink, magic link or escape was refused, otherwise into code.
func pathFailure(code, what string, err error) error {
	if errors.Is(err, unix.ELOOP) || errors.Is(err, unix.EXDEV) {
		return failure(codePolicy, "%s: refused to follow a symlink or leave the alias root: %v", what, err)
	}
	return failure(code, "%s: %v", what, err)
}

// walker visits a bounded tree descriptor-relatively. Symlinks and special
// files are never opened or followed; every opened descriptor is checked to
// be the object that was inspected before (no swap in between).
type walker struct {
	maxDepth   int
	maxEntries int
	entries    int
	// visit receives an open descriptor of every directory and regular file.
	visit func(fd int, st *unix.Stat_t, relative string) error
}

// walk visits the directory dirfd (already counted by the caller as one
// entry when it is the root) and, recursively, its descendants.
func (w *walker) walk(dirfd int, relative string, depth int) error {
	names, err := readNames(dirfd, w.maxEntries-w.entries)
	if err != nil {
		return fmt.Errorf("%s: %w", relative, err)
	}
	for _, name := range names {
		child := filepath.Join(relative, name)
		if err := w.child(dirfd, name, child, depth+1); err != nil {
			return err
		}
	}
	return nil
}

func (w *walker) child(dirfd int, name, relative string, depth int) error {
	var st unix.Stat_t
	if err := unix.Fstatat(dirfd, name, &st, unix.AT_SYMLINK_NOFOLLOW); err != nil {
		return fmt.Errorf("%s: %w", relative, err)
	}
	kind := st.Mode & unix.S_IFMT
	if kind != unix.S_IFDIR && kind != unix.S_IFREG {
		return nil
	}
	if w.entries++; w.entries > w.maxEntries {
		return fmt.Errorf("%w: more than %d entries", errWalkLimit, w.maxEntries)
	}
	if kind == unix.S_IFDIR && depth > w.maxDepth {
		return fmt.Errorf("%w: %s is deeper than %d levels", errWalkLimit, relative, w.maxDepth)
	}
	flags := readFlags
	if kind == unix.S_IFDIR {
		flags = directoryFlags
	}
	fd, err := openBeneath(dirfd, name, flags)
	if err != nil {
		return fmt.Errorf("%s: %w", relative, err)
	}
	defer unix.Close(fd)
	opened, err := sameObject(fd, &st)
	if err != nil {
		return fmt.Errorf("%s: %w", relative, err)
	}
	if err := w.visit(fd, &opened, relative); err != nil {
		return err
	}
	if kind == unix.S_IFDIR {
		return w.walk(fd, relative, depth)
	}
	return nil
}

var errWalkLimit = errors.New("walk limit exceeded")

// sameObject verifies that fd is still the inspected object and returns its
// current state (link count, mode) for further decisions.
func sameObject(fd int, inspected *unix.Stat_t) (unix.Stat_t, error) {
	var st unix.Stat_t
	if err := unix.Fstat(fd, &st); err != nil {
		return st, err
	}
	if st.Dev != inspected.Dev || st.Ino != inspected.Ino || st.Mode&unix.S_IFMT != inspected.Mode&unix.S_IFMT {
		return st, errors.New("the object was replaced while it was opened")
	}
	return st, nil
}

// readNames lists a directory through a duplicate descriptor, so the offset
// of dirfd stays untouched, and fails beyond limit names.
func readNames(dirfd int, limit int) ([]string, error) {
	duplicate, err := unix.Dup(dirfd)
	if err != nil {
		return nil, err
	}
	directory := os.NewFile(uintptr(duplicate), "directory")
	defer directory.Close()
	var names []string
	for {
		batch, err := directory.Readdirnames(256)
		names = append(names, batch...)
		if len(names) > limit {
			return nil, fmt.Errorf("%w: more than %d entries", errWalkLimit, limit)
		}
		if errors.Is(err, io.EOF) {
			return names, nil
		}
		if err != nil {
			return nil, err
		}
	}
}

// asPrincipal runs fn on a dedicated OS thread whose file system identity is
// exactly the principal: no supplementary groups, fsgid and fsuid switched,
// which also drops the file capabilities of root. The goroutine never
// unlocks the thread, so the runtime discards the thread afterwards. fn
// must not start goroutines.
func asPrincipal(principal principalEntry, fn func() error) error {
	result := make(chan error, 1)
	go func() {
		runtime.LockOSThread()
		if err := switchIdentity(principal); err != nil {
			result <- err
			return
		}
		result <- fn()
	}()
	return <-result
}

func switchIdentity(principal principalEntry) error {
	if err := unix.Setgroups([]int{}); err != nil {
		return fmt.Errorf("drop supplementary groups (the agent must run as root): %w", err)
	}
	_ = unix.Setfsgid(principal.GID)
	_ = unix.Setfsuid(principal.UID)
	// setfsuid/setfsgid report the previous value only; -1 queries the
	// current one without changing it.
	uid, _ := unix.SetfsuidRetUid(-1)
	gid, _ := unix.SetfsgidRetGid(-1)
	if uid != principal.UID || gid != principal.GID {
		return fmt.Errorf("switch file system identity to %d:%d failed", principal.UID, principal.GID)
	}
	return nil
}

// cgroupEmpty proves that the cgroup v2 group has no process in its
// subtree; a removed group is empty.
func (s *serviceControl) cgroupEmpty(group string) error {
	if group == "" {
		return nil
	}
	relative := strings.TrimPrefix(group, "/")
	if !strings.HasPrefix(group, "/") || !validRelative(relative) {
		return fmt.Errorf("unexpected control group %q", group)
	}
	rootfd, err := openRoot(s.cgroupRoot)
	if err != nil {
		return fmt.Errorf("open cgroup root: %w", err)
	}
	defer unix.Close(rootfd)
	var fs unix.Statfs_t
	if err := unix.Fstatfs(rootfd, &fs); err != nil || (s.requireCgroup2 && fs.Type != cgroup2Magic) {
		return errors.New("the cgroup check requires a cgroup v2 hierarchy")
	}
	fd, err := openBeneath(rootfd, relative+"/cgroup.events", readFlags)
	if errors.Is(err, unix.ENOENT) {
		return nil
	}
	if err != nil {
		return fmt.Errorf("read %s/cgroup.events: %w", group, err)
	}
	events := os.NewFile(uintptr(fd), "cgroup.events")
	defer events.Close()
	raw := make([]byte, 4096)
	n, err := events.Read(raw)
	if err != nil {
		return fmt.Errorf("read %s/cgroup.events: %w", group, err)
	}
	for _, line := range bytes.Split(raw[:n], []byte("\n")) {
		if string(line) == "populated 0" {
			return nil
		}
	}
	return fmt.Errorf("control group %s still contains processes", group)
}

var errNotFileOrDirectory = errors.New("not a directory or regular file")

// walkError names the object below the root a visit failed on.
type walkError struct {
	relative string
	err      error
}

func (e *walkError) Error() string { return e.relative + ": " + e.err.Error() }
func (e *walkError) Unwrap() error { return e.err }
