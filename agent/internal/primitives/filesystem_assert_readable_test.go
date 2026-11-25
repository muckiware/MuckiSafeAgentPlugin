//go:build linux

package primitives

import (
	"context"
	"os"
	"path/filepath"
	"testing"
	"time"

	"golang.org/x/sys/unix"

	"github.com/muckiware/MuckiSafeAgentPlugin/agent/internal/agent"
	"github.com/muckiware/MuckiSafeAgentPlugin/agent/internal/executor"
)

func checkPolicy(root string) string {
	return aclPolicy(root, "  tiny:\n    root: "+root+"\n    recursive: true\n    maxEntries: 3\n") + `checks:
  files:
    pathAlias: data
    readableBy: backup
    relativePaths: [., a.txt, sub/deeper]
  tree:
    pathAlias: data
    readableBy: backup
    recursive: true
  tiny-tree:
    pathAlias: tiny
    readableBy: backup
    recursive: true
  through-link:
    pathAlias: data
    readableBy: backup
    relativePaths: [sub/link]
  current-bak:
    pathAlias: data
    readableBy: backup
    match: {directory: sub, suffix: .BAK, createdByCurrentRun: true, minimumSize: 1}
  two-keys:
    pathAlias: data
    readableBy: backup
    match: {directory: ., suffix: .xml, minimumCount: 2}
  orphan:
    pathAlias: data
    readableBy: nobody
    recursive: true
`
}

func assertStep(check string) string {
	return `{"primitive":"filesystem.assert-readable","checkAlias":"` + check + `"}`
}

// runDirect runs a primitive outside the executor with a chosen run start.
func runDirect(t *testing.T, h *harness, name, check string, started time.Time) error {
	t.Helper()
	return h.primitives[name].Run(context.Background(), executor.StepContext{
		Job: agent.Job{ID: testJobID}, Step: map[string]any{"primitive": name, "checkAlias": check},
		Started: started, Policy: h.store().Current(), Gate: func() error { return nil },
	})
}

func code(err error) string {
	if stepError, ok := err.(*executor.StepError); ok {
		return stepError.Code
	}
	return ""
}

func TestAssertReadableChecksAsThePrincipalNotAsRoot(t *testing.T) {
	root, _ := tree(t)
	h := newHarness(t, checkPolicy(root))

	expect(t, h.run(context.Background(), assertStep("files")), "failed", codeVerification, "permission denied")
	expect(t, h.run(context.Background(), aclStep("data"), assertStep("files"), assertStep("tree")), "succeeded", "ok")
}

func TestAssertReadableRecursiveFindsAnUnreadableDescendant(t *testing.T) {
	root, _ := tree(t)
	h := newHarness(t, checkPolicy(root))
	expect(t, h.run(context.Background(), aclStep("data")), "succeeded", "ok")
	if err := unix.Removexattr(filepath.Join(root, "sub", "deeper", "c.txt"), aclXattr); err != nil {
		t.Fatal(err)
	}

	expect(t, h.run(context.Background(), assertStep("files")), "succeeded", "ok")
	expect(t, h.run(context.Background(), assertStep("tree")), "failed", codeVerification, "sub/deeper/c.txt", "permission denied")
	expect(t, h.run(context.Background(), assertStep("tiny-tree")), "failed", codeVerification, "walk limit exceeded")
}

func TestAssertReadableRequiresSearchOnListedDirectories(t *testing.T) {
	root, _ := tree(t)
	h := newHarness(t, checkPolicy(root))
	expect(t, h.run(context.Background(), aclStep("data")), "succeeded", "ok")
	deeper := filepath.Join(root, "sub", "deeper")
	fd, _ := unix.Open(deeper, unix.O_RDONLY|unix.O_DIRECTORY, 0)
	readOnly := accessACL{{tag: aclUserObj, perm: 7, id: aclUndefined}, {tag: aclUser, perm: 4, id: backupUID},
		{tag: aclGroupObj, perm: 5, id: aclUndefined}, {tag: aclMask, perm: 5, id: aclUndefined}, {tag: aclOther, perm: 0, id: aclUndefined}}
	if err := unix.Fsetxattr(fd, aclXattr, readOnly.encode(), 0); err != nil {
		t.Fatal(err)
	}
	unix.Close(fd)

	expect(t, h.run(context.Background(), assertStep("files")), "failed", codeVerification, "sub/deeper: search")
}

func TestAssertReadableRefusesLinksAndUnknownAliases(t *testing.T) {
	root, _ := tree(t)
	h := newHarness(t, checkPolicy(root))
	expect(t, h.run(context.Background(), aclStep("data")), "succeeded", "ok")

	expect(t, h.run(context.Background(), assertStep("through-link")), "failed", codePolicy, "symlink")
	expect(t, h.run(context.Background(), assertStep("unknown")), "failed", codePolicy, "unknown")
	expect(t, h.run(context.Background(), assertStep("orphan")), "failed", codePolicy, "nobody")
}

func TestAssertReadableMatchRequiresACurrentNonEmptyReadableFile(t *testing.T) {
	root, _ := tree(t)
	h := newHarness(t, checkPolicy(root))
	expect(t, h.run(context.Background(), aclStep("data")), "succeeded", "ok")
	sub := filepath.Join(root, "sub")
	write := func(name, content string, mode os.FileMode) {
		t.Helper()
		if err := os.WriteFile(filepath.Join(sub, name), []byte(content), mode); err != nil {
			t.Fatal(err)
		}
		os.Chmod(filepath.Join(sub, name), mode)
	}
	write("stale.BAK", "old", 0o644)
	write("empty.BAK", "", 0o644)
	os.Symlink(filepath.Join(sub, "stale.BAK"), filepath.Join(sub, "link.BAK"))
	afterStale := time.Now().Add(2 * time.Second)

	err := runDirect(t, h, "filesystem.assert-readable", "current-bak", afterStale)
	if code(err) != codeVerification {
		t.Fatalf("a stale or empty .BAK must not satisfy the check: %v", err)
	}
	if err := runDirect(t, h, "filesystem.assert-readable", "current-bak", time.Now().Add(-2*time.Second)); err != nil {
		t.Fatalf("a current non-empty .BAK must satisfy the check: %v", err)
	}

	write("private.BAK", "new", 0o600)
	if err := runDirect(t, h, "filesystem.assert-readable", "current-bak", time.Now().Add(-2*time.Second)); code(err) != codeVerification {
		t.Fatalf("a matching unreadable file must fail the check: %v", err)
	}
}

func TestAssertReadableMatchCountsFiles(t *testing.T) {
	root, _ := tree(t)
	h := newHarness(t, checkPolicy(root))
	for _, name := range []string{"key-1.xml", "notes.txt"} {
		os.WriteFile(filepath.Join(root, name), []byte("<key/>"), 0o644)
	}
	expect(t, h.run(context.Background(), aclStep("data")), "succeeded", "ok")
	expect(t, h.run(context.Background(), assertStep("two-keys")), "failed", codeVerification, "1 of at least 2")

	os.WriteFile(filepath.Join(root, "key-2.xml"), []byte("<key/>"), 0o644)
	expect(t, h.run(context.Background(), assertStep("two-keys")), "succeeded", "ok")
}

func TestAssertReadableNeverEscapesThroughAConcurrentSymlinkSwap(t *testing.T) {
	root, outside := tree(t)
	h := newHarness(t, checkPolicy(root))
	expect(t, h.run(context.Background(), aclStep("data")), "succeeded", "ok")
	// the outside is readable for everybody: an escape would succeed
	os.Chmod(outside, 0o755)
	os.Chmod(filepath.Join(outside, "secret"), 0o644)
	os.Remove(filepath.Join(root, "sub", "link"))
	os.WriteFile(filepath.Join(outside, "x.BAK"), []byte("outside"), 0o644)

	stop := swapper(t, filepath.Join(root, "sub"), outside)
	defer stop()
	for i := 0; i < 100; i++ {
		// sub holds no .BAK; only an escape to the outside could match
		if err := runDirect(t, h, "filesystem.assert-readable", "current-bak", time.Now().Add(-time.Hour)); err == nil {
			t.Fatal("the check escaped through a swapped symlink")
		}
	}
}
