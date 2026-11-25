//go:build linux

package primitives

import (
	"context"
	"os"
	"path/filepath"
	"slices"
	"strconv"
	"testing"
)

func retentionStep(pathAlias string, keep int) string {
	return `{"primitive":"file.retention","pathAlias":"` + pathAlias + `","manifestAlias":"manual","keep":` + strconv.Itoa(keep) + `}`
}

// published runs three pipelines (jobs 1..3) and adds two foreign files.
func published(t *testing.T) (*harness, string) {
	t.Helper()
	root := uploadRoot(t)
	h := newHarness(t, pipelinePolicy(root))
	script(t, fakeScript{Container: "immich_postgres", Output: dumpOutput})
	for i := 1; i <= 3; i++ {
		expect(t, h.runJob(context.Background(), jobID(i), pipelineStep("dump", "manual")), "succeeded", "ok")
	}
	backups := filepath.Join(root, "backups")
	for _, name := range []string{"immich-db-dump-auto-20261124.sql.gz", outputName(jobID(9))} {
		os.WriteFile(filepath.Join(backups, name), []byte("foreign"), 0o600)
	}
	return h, backups
}

func TestRetentionDeletesOnlyOldManifestOutputs(t *testing.T) {
	h, backups := published(t)

	expect(t, h.run(context.Background(), retentionStep("upload", 1)), "succeeded", "ok")
	want := []string{"immich-db-dump-auto-20261124.sql.gz", outputName(jobID(3)), outputName(jobID(9))}
	if got := entries(t, backups); !slices.Equal(got, want) {
		t.Fatalf("left %v, want %v", got, want)
	}
	if m := readManifest(t, h, "manual"); len(m.Entries) != 1 || m.Entries[0].RunID != jobID(3) {
		t.Fatalf("manifest %+v", m)
	}
	expect(t, h.run(context.Background(), retentionStep("upload", 1)), "succeeded", "ok")
	if got := entries(t, backups); !slices.Equal(got, want) {
		t.Fatalf("a repeated retention changed %v", got)
	}
}

func TestRetentionDeletesNothingOnAnIdentityMismatch(t *testing.T) {
	tamper := map[string]func(path string){
		"replaced file": func(path string) {
			os.WriteFile(path+".new", []byte("replaced"), 0o600)
			os.Rename(path+".new", path)
		},
		"hard link": func(path string) { os.Link(path, path+".link") },
		"symlink": func(path string) {
			os.Remove(path)
			os.Symlink("/etc/passwd", path)
		},
	}
	for name, change := range tamper {
		t.Run(name, func(t *testing.T) {
			h, backups := published(t)
			change(filepath.Join(backups, outputName(jobID(2))))
			before := entries(t, backups)

			expect(t, h.run(context.Background(), retentionStep("upload", 1)), "failed", codeRetention, "nothing was deleted")
			if got := entries(t, backups); !slices.Equal(got, before) {
				t.Fatalf("deleted despite a mismatch: %v", got)
			}
			if m := readManifest(t, h, "manual"); len(m.Entries) != 3 {
				t.Fatalf("manifest changed: %+v", m)
			}
		})
	}
}

func TestRetentionForgetsVanishedOutputsWithoutCountingThem(t *testing.T) {
	h, backups := published(t)
	os.Remove(filepath.Join(backups, outputName(jobID(1))))

	expect(t, h.run(context.Background(), retentionStep("upload", 2)), "succeeded", "ok")
	for _, n := range []int{2, 3} {
		if _, err := os.Lstat(filepath.Join(backups, outputName(jobID(n)))); err != nil {
			t.Fatalf("output %d must be kept: %v", n, err)
		}
	}
	if m := readManifest(t, h, "manual"); len(m.Entries) != 2 {
		t.Fatalf("manifest %+v", m)
	}
}

func TestRetentionNeverDeletesThroughAConcurrentSymlinkSwap(t *testing.T) {
	h, backups := published(t)
	outside := tempDir(t)
	for i := 1; i <= 3; i++ {
		os.WriteFile(filepath.Join(outside, outputName(jobID(i))), []byte("outside"), 0o600)
	}
	stop := swapper(t, backups, outside)
	for i := 0; i < 50; i++ {
		h.run(context.Background(), retentionStep("upload", 1))
	}
	stop()
	if names := entries(t, outside); len(names) != 3 {
		t.Fatalf("deleted outside the alias root: %v", names)
	}
}

func TestRetentionPolicyBindsPathAndKeepLimit(t *testing.T) {
	h, backups := published(t)
	before := entries(t, backups)

	expect(t, h.run(context.Background(), retentionStep("other", 1)), "failed", codePolicy, "belongs to path upload")
	expect(t, h.run(context.Background(), retentionStep("upload", 11)), "failed", codePolicy, "at most 10")
	if got := entries(t, backups); !slices.Equal(got, before) {
		t.Fatalf("deleted %v", got)
	}
}

func TestRetentionRefusesATamperedManifest(t *testing.T) {
	h, backups := published(t)
	before := entries(t, backups)
	os.Chmod(filepath.Join(h.stateDir, manifestDirectory, "manual.json"), 0o644)

	expect(t, h.run(context.Background(), retentionStep("upload", 1)), "failed", codeRetention, "mode is not 0600")
	if got := entries(t, backups); !slices.Equal(got, before) {
		t.Fatalf("deleted %v", got)
	}
}
