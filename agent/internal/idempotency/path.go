package idempotency

import (
	"errors"
	"fmt"
	"strings"

	"golang.org/x/sys/unix"
)

// openVerifiedPath walks an absolute path component by component from "/"
// with O_NOFOLLOW, so no symlink anywhere in the path is followed and no
// component can be swapped between check and use. Every ancestor must be a
// directory owned by root or the agent user and must not be writable by
// group or others, except a root-owned sticky directory such as /tmp. The
// final directory must be owned by the agent user with no group/other bits.
func openVerifiedPath(path string, uid int) (int, error) {
	fd, err := unix.Open("/", unix.O_RDONLY|unix.O_DIRECTORY|unix.O_CLOEXEC, 0)
	if err != nil {
		return -1, err
	}
	components := strings.Split(strings.TrimPrefix(path, "/"), "/")
	for i, name := range components {
		if err := checkAncestor(fd, uid); err != nil {
			unix.Close(fd)
			return -1, fmt.Errorf("parent of %q: %w", name, err)
		}
		next, err := unix.Openat(fd, name, unix.O_RDONLY|unix.O_DIRECTORY|unix.O_NOFOLLOW|unix.O_CLOEXEC, 0)
		unix.Close(fd)
		if err != nil {
			return -1, fmt.Errorf("open %q without following symlinks: %w", strings.Join(components[:i+1], "/"), err)
		}
		fd = next
	}
	if err := checkPrivate(fd, uid); err != nil {
		unix.Close(fd)
		return -1, fmt.Errorf("state directory: %w", err)
	}
	return fd, nil
}

// openPrivateDir opens name below dirfd without following a symlink and
// requires a private directory of the agent user.
func openPrivateDir(dirfd int, name string, uid int) (int, error) {
	fd, err := unix.Openat(dirfd, name, unix.O_RDONLY|unix.O_DIRECTORY|unix.O_NOFOLLOW|unix.O_CLOEXEC, 0)
	if err != nil {
		return -1, err
	}
	if err := checkPrivate(fd, uid); err != nil {
		unix.Close(fd)
		return -1, err
	}
	return fd, nil
}

func checkAncestor(fd, uid int) error {
	var st unix.Stat_t
	if err := unix.Fstat(fd, &st); err != nil {
		return err
	}
	mode := uint32(st.Mode)
	switch {
	case mode&unix.S_IFMT != unix.S_IFDIR:
		return errors.New("not a directory")
	case st.Uid != 0 && int(st.Uid) != uid:
		return errors.New("owned by a foreign user")
	case mode&0o022 != 0 && !(mode&unix.S_ISVTX != 0 && st.Uid == 0):
		return errors.New("writable by group or others")
	}
	return nil
}

func checkPrivate(fd, uid int) error {
	var st unix.Stat_t
	if err := unix.Fstat(fd, &st); err != nil {
		return err
	}
	mode := uint32(st.Mode)
	switch {
	case mode&unix.S_IFMT != unix.S_IFDIR:
		return errors.New("not a directory")
	case int(st.Uid) != uid:
		return errors.New("not owned by the agent user")
	case mode&0o077 != 0:
		return errors.New("accessible by group or others (use mode 0700)")
	}
	return nil
}
