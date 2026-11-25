//go:build linux

package primitives

import (
	"encoding/binary"
	"errors"
	"fmt"
	"sort"

	"golang.org/x/sys/unix"
)

// POSIX access ACL in the kernel's system.posix_acl_access xattr format:
// a little-endian version 2 header followed by {tag u16, perm u16, id u32}.
const (
	aclXattr     = "system.posix_acl_access"
	aclVersion   = 2
	aclUserObj   = 0x01
	aclUser      = 0x02
	aclGroupObj  = 0x04
	aclGroup     = 0x08
	aclMask      = 0x10
	aclOther     = 0x20
	aclUndefined = 0xffffffff
	aclPermRead  = 4
	aclPermExec  = 1
	maxACLBytes  = 4 + 8*1024
)

type aclEntry struct {
	tag  uint16
	perm uint16
	id   uint32
}

type accessACL []aclEntry

// readACL returns the access ACL of fd, or the minimal ACL of its mode.
func readACL(fd int, mode uint32) (accessACL, error) {
	raw := make([]byte, maxACLBytes)
	n, err := unix.Fgetxattr(fd, aclXattr, raw)
	if errors.Is(err, unix.ENODATA) {
		return accessACL{
			{tag: aclUserObj, perm: uint16(mode>>6) & 7, id: aclUndefined},
			{tag: aclGroupObj, perm: uint16(mode>>3) & 7, id: aclUndefined},
			{tag: aclOther, perm: uint16(mode) & 7, id: aclUndefined},
		}, nil
	}
	if err != nil {
		return nil, err
	}
	raw = raw[:n]
	if len(raw) < 4 || (len(raw)-4)%8 != 0 || binary.LittleEndian.Uint32(raw) != aclVersion {
		return nil, errors.New("unsupported access ACL format")
	}
	acl := make(accessACL, 0, (len(raw)-4)/8)
	for offset := 4; offset < len(raw); offset += 8 {
		acl = append(acl, aclEntry{
			tag:  binary.LittleEndian.Uint16(raw[offset:]),
			perm: binary.LittleEndian.Uint16(raw[offset+2:]),
			id:   binary.LittleEndian.Uint32(raw[offset+4:]),
		})
	}
	return acl, nil
}

func (a accessACL) encode() []byte {
	sort.Slice(a, func(i, j int) bool {
		if a[i].tag != a[j].tag {
			return a[i].tag < a[j].tag
		}
		return a[i].id < a[j].id
	})
	raw := binary.LittleEndian.AppendUint32(nil, aclVersion)
	for _, entry := range a {
		raw = binary.LittleEndian.AppendUint16(raw, entry.tag)
		raw = binary.LittleEndian.AppendUint16(raw, entry.perm)
		raw = binary.LittleEndian.AppendUint32(raw, entry.id)
	}
	return raw
}

// grantUser adds perm to the named user entry of uid and makes sure the mask
// lets it through. It never removes a permission and reports whether the
// ACL changed.
func (a accessACL) grantUser(uid uint32, perm uint16) (accessACL, bool) {
	changed := false
	user, mask := -1, -1
	for i, entry := range a {
		switch {
		case entry.tag == aclUser && entry.id == uid:
			user = i
		case entry.tag == aclMask:
			mask = i
		}
	}
	if user < 0 {
		a = append(a, aclEntry{tag: aclUser, id: uid})
		user = len(a) - 1
	}
	if a[user].perm&perm != perm {
		a[user].perm |= perm
		changed = true
	}
	if mask < 0 {
		// a named entry requires a mask: the union of the group class
		var union uint16
		for _, entry := range a {
			if entry.tag == aclUser || entry.tag == aclGroupObj || entry.tag == aclGroup {
				union |= entry.perm
			}
		}
		return append(a, aclEntry{tag: aclMask, perm: union, id: aclUndefined}), true
	}
	if a[mask].perm&perm != perm {
		a[mask].perm |= perm
		changed = true
	}
	return a, changed
}

// grantRead gives uid read access (and search for directories and
// executable files, like setfacl's rX) to the object of fd.
func grantRead(fd int, st *unix.Stat_t, uid int) error {
	kind := st.Mode & unix.S_IFMT
	if kind == unix.S_IFREG && st.Nlink != 1 {
		return errors.New("refusing to change the ACL of a hard-linked file")
	}
	perm := uint16(aclPermRead)
	if kind == unix.S_IFDIR || st.Mode&0o111 != 0 {
		perm |= aclPermExec
	}
	acl, err := readACL(fd, st.Mode)
	if err != nil {
		return fmt.Errorf("read ACL: %w", err)
	}
	acl, changed := acl.grantUser(uint32(uid), perm)
	if !changed {
		return nil
	}
	if err := unix.Fsetxattr(fd, aclXattr, acl.encode(), 0); err != nil {
		return fmt.Errorf("write ACL: %w", err)
	}
	return nil
}
