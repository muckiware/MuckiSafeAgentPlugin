//go:build linux

package primitives

import (
	"context"
	"errors"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"sync/atomic"
	"testing"

	"golang.org/x/sys/unix"
)

const backupUID = 33

// tree builds the fixture below a fresh root:
//
//	root/          0750
//	  a.txt        0640
//	  fifo
//	  sub/         0750
//	    b.bin      0750
//	    link     -> outside/secret
//	    deeper/    0750
//	      c.txt    0600
//
// outside/secret must never be touched.
func tree(t *testing.T) (root, outside string) {
	t.Helper()
	base := tempDir(t)
	root, outside = filepath.Join(base, "root"), filepath.Join(base, "outside")
	for _, dir := range []string{root, outside, filepath.Join(root, "sub"), filepath.Join(root, "sub", "deeper")} {
		if err := os.Mkdir(dir, 0o750); err != nil {
			t.Fatal(err)
		}
		os.Chmod(dir, 0o750)
	}
	files := map[string]os.FileMode{"root/a.txt": 0o640, "root/sub/b.bin": 0o750, "root/sub/deeper/c.txt": 0o600, "outside/secret": 0o600}
	for name, mode := range files {
		if err := os.WriteFile(filepath.Join(base, name), []byte(name), mode); err != nil {
			t.Fatal(err)
		}
		os.Chmod(filepath.Join(base, name), mode)
	}
	if err := os.Symlink(filepath.Join(outside, "secret"), filepath.Join(root, "sub", "link")); err != nil {
		t.Fatal(err)
	}
	if err := unix.Mkfifo(filepath.Join(root, "fifo"), 0o600); err != nil {
		t.Fatal(err)
	}
	return root, outside
}

func aclPolicy(root string, extra ...string) string {
	return "version: 1\npaths:\n" +
		"  data:\n    root: " + root + "\n    recursive: true\n" +
		"  flat:\n    root: " + root + "\n" +
		strings.Join(extra, "") +
		"principals:\n  backup:\n    uid: 33\n    gid: 33\n"
}

func aclStep(pathAlias string, relative ...string) string {
	step := `{"primitive":"filesystem.acl.read","pathAlias":"` + pathAlias + `","principalAlias":"backup"`
	if len(relative) > 0 {
		step += `,"relativePath":"` + relative[0] + `"`
	}
	return step + "}"
}

// userPerm returns the named-user ACL permission of uid on path.
func userPerm(t *testing.T, path string, uid uint32) (uint16, bool) {
	t.Helper()
	fd, err := unix.Open(path, unix.O_RDONLY|unix.O_NONBLOCK|unix.O_NOFOLLOW|unix.O_CLOEXEC, 0)
	if err != nil {
		t.Fatal(err)
	}
	defer unix.Close(fd)
	var st unix.Stat_t
	unix.Fstat(fd, &st)
	acl, err := readACL(fd, st.Mode)
	if err != nil {
		t.Fatal(err)
	}
	for _, entry := range acl {
		if entry.tag == aclUser && entry.id == uid {
			return entry.perm, true
		}
	}
	return 0, false
}

// readableAs opens relative below root with the principal's identity.
func readableAs(t *testing.T, root, relative string) error {
	t.Helper()
	rootfd, err := openRoot(root)
	if err != nil {
		t.Fatal(err)
	}
	defer unix.Close(rootfd)
	return asPrincipal(principalEntry{UID: backupUID, GID: backupUID}, func() error {
		fd, err := openBeneath(rootfd, relative, readFlags)
		if err == nil {
			unix.Close(fd)
		}
		return err
	})
}

func TestACLGrantsReadAndSearchRecursively(t *testing.T) {
	root, outside := tree(t)
	h := newHarness(t, aclPolicy(root))
	for _, relative := range []string{"a.txt", "sub/deeper/c.txt"} {
		if err := readableAs(t, root, relative); !errors.Is(err, unix.EACCES) {
			t.Fatalf("%s must not be readable before the grant: %v", relative, err)
		}
	}

	expect(t, h.run(context.Background(), aclStep("data")), "succeeded", "ok")
	want := map[string]uint16{".": 5, "a.txt": 4, "sub": 5, "sub/b.bin": 5, "sub/deeper": 5, "sub/deeper/c.txt": 4}
	for relative, perm := range want {
		if got, ok := userPerm(t, filepath.Join(root, relative), backupUID); !ok || got != perm {
			t.Errorf("%s: user 33 has %o (%v), want %o", relative, got, ok, perm)
		}
		if err := readableAs(t, root, relative); err != nil {
			t.Errorf("%s is not readable as the principal: %v", relative, err)
		}
	}
	if _, ok := userPerm(t, filepath.Join(outside, "secret"), backupUID); ok {
		t.Fatal("the symlink target outside the root was changed")
	}
}

func TestACLIsIdempotentAndKeepsExistingEntries(t *testing.T) {
	root, _ := tree(t)
	file := filepath.Join(root, "a.txt")
	fd, _ := unix.Open(file, unix.O_RDONLY, 0)
	other := accessACL{{tag: aclUserObj, perm: 6, id: aclUndefined}, {tag: aclUser, perm: 6, id: 1000},
		{tag: aclGroupObj, perm: 4, id: aclUndefined}, {tag: aclMask, perm: 6, id: aclUndefined}, {tag: aclOther, perm: 0, id: aclUndefined}}
	if err := unix.Fsetxattr(fd, aclXattr, other.encode(), 0); err != nil {
		t.Fatalf("the test file system needs POSIX ACLs: %v", err)
	}
	unix.Close(fd)
	h := newHarness(t, aclPolicy(root))

	expect(t, h.run(context.Background(), aclStep("data")), "succeeded", "ok")
	var first, second unix.Stat_t
	unix.Stat(file, &first)
	expect(t, h.run(context.Background(), aclStep("data")), "succeeded", "ok")
	unix.Stat(file, &second)
	if first.Ctim != second.Ctim {
		t.Fatal("a repeated grant must not rewrite an unchanged ACL")
	}
	if perm, ok := userPerm(t, file, 1000); !ok || perm != 6 {
		t.Fatal("an existing named entry was lost")
	}
}

func TestACLWithoutRecursionOrOnARelativePathTouchesOnlyTheTarget(t *testing.T) {
	root, _ := tree(t)
	h := newHarness(t, aclPolicy(root))

	expect(t, h.run(context.Background(), aclStep("flat")), "succeeded", "ok")
	expect(t, h.run(context.Background(), aclStep("flat", "sub/b.bin")), "succeeded", "ok")
	for relative, granted := range map[string]bool{".": true, "sub/b.bin": true, "a.txt": false, "sub": false} {
		if _, ok := userPerm(t, filepath.Join(root, relative), backupUID); ok != granted {
			t.Errorf("%s granted=%v", relative, ok)
		}
	}
}

func TestACLRefusesSymlinksMagicLinksAndEscapes(t *testing.T) {
	root, outside := tree(t)
	if err := os.Symlink(outside, filepath.Join(root, "escape")); err != nil {
		t.Fatal(err)
	}
	if err := os.Symlink(root, filepath.Join(filepath.Dir(root), "rootlink")); err != nil {
		t.Fatal(err)
	}
	extra := "  linked:\n    root: " + filepath.Join(filepath.Dir(root), "rootlink") + "\n" +
		"  magic:\n    root: /proc/1/root\n" +
		"  proc:\n    root: /proc/1\n"
	h := newHarness(t, aclPolicy(root, extra))

	expect(t, h.run(context.Background(), aclStep("data", "sub/link")), "failed", codePolicy, "symlink")
	expect(t, h.run(context.Background(), aclStep("data", "escape/secret")), "failed", codePolicy, "symlink")
	expect(t, h.run(context.Background(), aclStep("linked")), "failed", codePolicy, "symlink")
	expect(t, h.run(context.Background(), aclStep("magic")), "failed", codePolicy, "symlink")
	expect(t, h.run(context.Background(), aclStep("proc", "cwd")), "failed", codePolicy, "symlink")
	expect(t, h.run(context.Background(), aclStep("data", "missing")), "failed", codeVerification, "no such file")
	if _, ok := userPerm(t, filepath.Join(outside, "secret"), backupUID); ok {
		t.Fatal("a refused target was changed")
	}
}

func TestACLWalkLimitsAndHardLinksFailClosed(t *testing.T) {
	root, _ := tree(t)
	extra := "  tiny:\n    root: " + root + "\n    recursive: true\n    maxEntries: 3\n" +
		"  shallow:\n    root: " + root + "\n    recursive: true\n    maxDepth: 1\n"
	h := newHarness(t, aclPolicy(root, extra))
	expect(t, h.run(context.Background(), aclStep("tiny")), "failed", codeVerification, "walk limit exceeded")
	expect(t, h.run(context.Background(), aclStep("shallow")), "failed", codeVerification, "deeper than 1")

	if err := os.Link(filepath.Join(root, "a.txt"), filepath.Join(root, "sub", "a-link.txt")); err != nil {
		t.Fatal(err)
	}
	expect(t, h.run(context.Background(), aclStep("data")), "failed", codeVerification, "hard-linked")
}

// TestACLNeverEscapesThroughAConcurrentSymlinkSwap swaps a directory of the
// tree with a symlink to the outside while grants run.
func TestACLNeverEscapesThroughAConcurrentSymlinkSwap(t *testing.T) {
	root, outside := tree(t)
	h := newHarness(t, aclPolicy(root))
	stop := swapper(t, filepath.Join(root, "sub"), outside)
	for i := 0; i < 100; i++ {
		result := h.run(context.Background(), aclStep("data"))
		if result.Outcome == "failed" && result.ResultCode != codePolicy && result.ResultCode != codeVerification {
			t.Fatalf("unexpected result %+v", result)
		}
	}
	if stop() == 0 {
		t.Fatal("the swapper never swapped")
	}
	if _, ok := userPerm(t, filepath.Join(outside, "secret"), backupUID); ok {
		t.Fatal("the grant escaped through a swapped symlink")
	}
	if _, ok := userPerm(t, outside, backupUID); ok {
		t.Fatal("the grant escaped to the outside directory")
	}
}

// swapper repeatedly replaces dir by a symlink to target and back until the
// returned stop function is called; stop reports the number of swaps.
func swapper(t *testing.T, dir, target string) func() int {
	t.Helper()
	parked := dir + ".parked"
	var done atomic.Bool
	var swaps atomic.Int32
	var wg sync.WaitGroup
	wg.Add(1)
	go func() {
		defer wg.Done()
		for !done.Load() {
			if os.Rename(dir, parked) == nil && os.Symlink(target, dir) == nil {
				swaps.Add(1)
				os.Remove(dir)
			}
			os.Rename(parked, dir)
		}
	}()
	return func() int {
		done.Store(true)
		wg.Wait()
		return int(swaps.Load())
	}
}
