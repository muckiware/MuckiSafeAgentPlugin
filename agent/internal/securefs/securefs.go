// Package securefs opens root-trusted agent files (config, secret, policy,
// journal state, executables) without following symlinks anywhere in the
// path and verifies every descriptor with fstat before use.
//
// The path is walked component by component from "/" with
// O_NOFOLLOW|O_DIRECTORY (portable equivalent of Linux openat2 with
// RESOLVE_NO_SYMLINKS). Every directory on the way must be owned by root or
// the agent user and must not be writable by group or others, except a
// root-owned sticky directory such as /tmp. Content is always read from the
// verified descriptor, so a later rename or symlink swap of the path cannot
// change what is loaded.
package securefs

import (
	"errors"
	"fmt"
	"io"
	"io/fs"
	"os"
	"path/filepath"
	"strings"

	"golang.org/x/sys/unix"
)

// afterVerify runs between verification and reading; tests use it to swap
// the path and prove that the verified descriptor is what gets read.
var afterVerify func(path string)

// ReadFile reads a regular file owned by the agent user (root in
// production), group root or the agent's group, link count 1, whose
// permission bits are a subset of maxPerm, and returns at most limit bytes.
func ReadFile(path string, maxPerm fs.FileMode, limit int64) ([]byte, error) {
	parent, name, err := openParent(path)
	if err != nil {
		return nil, err
	}
	fd, err := unix.Openat(parent, name, unix.O_RDONLY|unix.O_NOFOLLOW|unix.O_NONBLOCK|unix.O_CLOEXEC, 0)
	unix.Close(parent)
	if err != nil {
		return nil, fmt.Errorf("%s: open without following symlinks: %w", path, err)
	}
	file := os.NewFile(uintptr(fd), path)
	defer file.Close()
	if err := checkTrustedFile(fd, maxPerm); err != nil {
		return nil, fmt.Errorf("%s: %w", path, err)
	}
	if afterVerify != nil {
		afterVerify(path)
	}
	raw, err := io.ReadAll(io.LimitReader(file, limit+1))
	if err != nil {
		return nil, fmt.Errorf("%s: %w", path, err)
	}
	if int64(len(raw)) > limit {
		return nil, fmt.Errorf("%s: file exceeds %d bytes", path, limit)
	}
	return raw, nil
}

// OpenPrivateDirectory returns a descriptor of a directory owned by the agent
// user without group/other permissions, reached through a verified path.
func OpenPrivateDirectory(path string) (int, error) {
	parent, name, err := openParent(path)
	if err != nil {
		return -1, err
	}
	defer unix.Close(parent)
	return OpenPrivateDirectoryAt(parent, name)
}

// OpenPrivateDirectoryAt opens name below dirfd as a private directory.
func OpenPrivateDirectoryAt(dirfd int, name string) (int, error) {
	fd, err := unix.Openat(dirfd, name, unix.O_RDONLY|unix.O_DIRECTORY|unix.O_NOFOLLOW|unix.O_CLOEXEC, 0)
	if err != nil {
		return -1, fmt.Errorf("open %q without following symlinks: %w", name, err)
	}
	if err := checkPrivateDirectory(fd); err != nil {
		unix.Close(fd)
		return -1, fmt.Errorf("%q: %w", name, err)
	}
	return fd, nil
}

// VerifyExecutable accepts an absolute, symlink-free path to a regular,
// executable file owned by root (or the agent user) that neither group nor
// others can modify, below directories with the same guarantees.
func VerifyExecutable(path string) error {
	parent, name, err := openParent(path)
	if err != nil {
		return err
	}
	defer unix.Close(parent)
	fd, err := unix.Openat(parent, name, unix.O_RDONLY|unix.O_NOFOLLOW|unix.O_NONBLOCK|unix.O_CLOEXEC, 0)
	if err != nil {
		return fmt.Errorf("%s: open without following symlinks: %w", path, err)
	}
	defer unix.Close(fd)
	var st unix.Stat_t
	if err := unix.Fstat(fd, &st); err != nil {
		return err
	}
	mode := uint32(st.Mode)
	switch {
	case mode&unix.S_IFMT != unix.S_IFREG:
		return fmt.Errorf("%s: not a regular file", path)
	case st.Uid != 0 && int(st.Uid) != os.Geteuid():
		return fmt.Errorf("%s: owned by a foreign user", path)
	case mode&0o022 != 0:
		return fmt.Errorf("%s: writable by group or others", path)
	case mode&0o111 == 0:
		return fmt.Errorf("%s: not executable", path)
	}
	return nil
}

// CloseFD closes a descriptor returned by this package.
func CloseFD(fd int) { _ = unix.Close(fd) }

// openParent verifies every directory of a clean absolute path and returns a
// descriptor of the directory containing the last component.
func openParent(path string) (int, string, error) {
	if path == "" || !filepath.IsAbs(path) || filepath.Clean(path) != path || path == "/" {
		return -1, "", fmt.Errorf("%q must be a clean absolute path", path)
	}
	components := strings.Split(strings.TrimPrefix(path, "/"), "/")
	fd, err := unix.Open("/", unix.O_RDONLY|unix.O_DIRECTORY|unix.O_CLOEXEC, 0)
	if err != nil {
		return -1, "", err
	}
	for i, name := range components {
		if err := checkAncestor(fd); err != nil {
			unix.Close(fd)
			return -1, "", fmt.Errorf("%s: directory /%s: %w", path, strings.Join(components[:i], "/"), err)
		}
		if i == len(components)-1 {
			return fd, name, nil
		}
		next, err := unix.Openat(fd, name, unix.O_RDONLY|unix.O_DIRECTORY|unix.O_NOFOLLOW|unix.O_CLOEXEC, 0)
		unix.Close(fd)
		if err != nil {
			return -1, "", fmt.Errorf("%s: open /%s without following symlinks: %w", path, strings.Join(components[:i+1], "/"), err)
		}
		fd = next
	}
	return -1, "", errors.New("unreachable")
}

func checkAncestor(fd int) error {
	var st unix.Stat_t
	if err := unix.Fstat(fd, &st); err != nil {
		return err
	}
	mode := uint32(st.Mode)
	switch {
	case mode&unix.S_IFMT != unix.S_IFDIR:
		return errors.New("not a directory")
	case st.Uid != 0 && int(st.Uid) != os.Geteuid():
		return errors.New("owned by a foreign user")
	case mode&0o022 != 0 && !(mode&unix.S_ISVTX != 0 && st.Uid == 0):
		return errors.New("writable by group or others")
	}
	return nil
}

func checkPrivateDirectory(fd int) error {
	var st unix.Stat_t
	if err := unix.Fstat(fd, &st); err != nil {
		return err
	}
	mode := uint32(st.Mode)
	switch {
	case mode&unix.S_IFMT != unix.S_IFDIR:
		return errors.New("not a directory")
	case int(st.Uid) != os.Geteuid():
		return errors.New("not owned by the agent user")
	case mode&0o077 != 0:
		return errors.New("accessible by group or others (use mode 0700)")
	}
	return nil
}

func checkTrustedFile(fd int, maxPerm fs.FileMode) error {
	var st unix.Stat_t
	if err := unix.Fstat(fd, &st); err != nil {
		return err
	}
	mode := uint32(st.Mode)
	switch {
	case mode&unix.S_IFMT != unix.S_IFREG:
		return errors.New("not a regular file")
	case int(st.Uid) != os.Geteuid():
		return errors.New("must be owned by the user running the agent")
	case st.Gid != 0 && int(st.Gid) != os.Getegid():
		return errors.New("must belong to group root")
	case fs.FileMode(mode&0o777)&^maxPerm != 0:
		return fmt.Errorf("permissions %04o exceed %04o", mode&0o777, maxPerm)
	case uint64(st.Nlink) != 1:
		return errors.New("link count is not 1")
	}
	return nil
}
