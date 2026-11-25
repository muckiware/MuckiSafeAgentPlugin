//go:build linux

package primitives

import (
	"compress/gzip"
	"context"
	"crypto/rand"
	"encoding/base64"
	"encoding/json"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"testing"

	"golang.org/x/sys/unix"
)

const dumpOutput = "-- PostgreSQL database dump\nCREATE TABLE assets ();\n"

func pipelinePolicy(root string) string {
	return `version: 1
paths:
  upload:
    root: ` + root + `
  other:
    root: ` + root + `
containers:
  pg:
    name: immich_postgres
commands:
  dump:
    containerAlias: pg
    argv: [pg_dump, --clean, --if-exists]
    timeoutSeconds: 30
  slow-dump:
    containerAlias: pg
    argv: [pg_dump, --slow]
    timeoutSeconds: 1
principals:
  backup:
    uid: 33
    gid: 33
destinations:
  manual:
    pathAlias: upload
    directory: backups
    prefix: immich-db-dump-manual-
    suffix: .sql.gz
    owner: 33
    group: 33
    mode: "0640"
    maximumBytes: 1048576
    maximumKeep: 10
  small:
    pathAlias: upload
    directory: backups
    prefix: small-
    suffix: .sql.gz
    owner: 0
    group: 0
    mode: "0600"
    maximumBytes: 64
    maximumKeep: 10
checks:
  current-output:
    destinationAlias: manual
    readableBy: backup
    minimumSize: 1
`
}

func pipelineStep(command, destination string) string {
	return `{"primitive":"file.write-pipeline","sourceCommandAlias":"` + command + `","destinationAlias":"` + destination +
		`","transforms":["gzip"],"nameFromRunId":true}`
}

// uploadRoot creates root/backups and returns root.
func uploadRoot(t *testing.T) string {
	t.Helper()
	root := filepath.Join(tempDir(t), "upload")
	if err := os.MkdirAll(filepath.Join(root, "backups"), 0o755); err != nil {
		t.Fatal(err)
	}
	return root
}

func outputName(jobID string) string { return "immich-db-dump-manual-" + jobID + ".sql.gz" }

func gunzip(t *testing.T, path string) string {
	t.Helper()
	file, err := os.Open(path)
	if err != nil {
		t.Fatal(err)
	}
	defer file.Close()
	reader, err := gzip.NewReader(file)
	if err != nil {
		t.Fatal(err)
	}
	raw, err := io.ReadAll(reader)
	if err != nil {
		t.Fatal(err)
	}
	return string(raw)
}

func readManifest(t *testing.T, h *harness, destination string) manifest {
	t.Helper()
	m, err := h.primitives["file.write-pipeline"].(*writePipeline).manifests.read(destination)
	if err != nil {
		t.Fatal(err)
	}
	return m
}

// entries lists the names in dir.
func entries(t *testing.T, dir string) []string {
	t.Helper()
	list, err := os.ReadDir(dir)
	if err != nil {
		t.Fatal(err)
	}
	names := make([]string, len(list))
	for i, entry := range list {
		names[i] = entry.Name()
	}
	return names
}

func TestPipelinePublishesAGzipOutputNamedAfterTheRun(t *testing.T) {
	root := uploadRoot(t)
	h := newHarness(t, pipelinePolicy(root))
	s := script(t, fakeScript{Container: "immich_postgres", Output: dumpOutput, Repeat: 100})

	result := h.run(context.Background(), pipelineStep("dump", "manual"), assertStep("current-output"))
	expect(t, result, "succeeded", "ok")
	path := filepath.Join(root, "backups", outputName(testJobID))
	if got := gunzip(t, path); got != strings.Repeat(dumpOutput, 100) {
		t.Fatalf("content %q", got[:min(len(got), 80)])
	}
	var st unix.Stat_t
	unix.Stat(path, &st)
	if st.Uid != 33 || st.Gid != 33 || st.Mode&0o7777 != 0o640 || st.Nlink != 1 {
		t.Fatalf("owner %d:%d mode %o", st.Uid, st.Gid, st.Mode&0o7777)
	}
	if names := entries(t, filepath.Join(root, "backups")); len(names) != 1 {
		t.Fatalf("leftovers %v", names)
	}
	m := readManifest(t, h, "manual")
	if len(m.Entries) != 1 || m.Entries[0].Inode != st.Ino || m.Entries[0].Device != st.Dev || m.Entries[0].RunID != testJobID {
		t.Fatalf("manifest %+v", m)
	}
	if calls := invocations(t, s); len(calls) != 1 || calls[0] != "exec immich_postgres pg_dump --clean --if-exists" {
		t.Fatalf("invocations %q", calls)
	}
}

func TestPipelineNeverReplacesAnExistingOutput(t *testing.T) {
	root := uploadRoot(t)
	h := newHarness(t, pipelinePolicy(root))
	s := script(t, fakeScript{Container: "immich_postgres", Output: dumpOutput})
	path := filepath.Join(root, "backups", outputName(testJobID))
	os.WriteFile(path, []byte("existing"), 0o600)

	expect(t, h.run(context.Background(), pipelineStep("dump", "manual")), "failed", codePipeline, "already exists")
	if raw, _ := os.ReadFile(path); string(raw) != "existing" {
		t.Fatal("the existing output was replaced")
	}
	if len(invocations(t, s)) != 0 {
		t.Fatal("the source command ran although the output exists")
	}
}

func TestConcurrentPipelinesOfOneRunPublishExactlyOnce(t *testing.T) {
	root := uploadRoot(t)
	h := newHarness(t, pipelinePolicy(root))
	script(t, fakeScript{Container: "immich_postgres", Output: dumpOutput, Repeat: 20000})

	var wg sync.WaitGroup
	results := make([]string, 4)
	for i := range results {
		wg.Add(1)
		go func() {
			defer wg.Done()
			results[i] = h.run(context.Background(), pipelineStep("dump", "manual")).Outcome
		}()
	}
	wg.Wait()
	if strings.Count(strings.Join(results, ","), "succeeded") != 1 {
		t.Fatalf("outcomes %v", results)
	}
	if names := entries(t, filepath.Join(root, "backups")); len(names) != 1 || names[0] != outputName(testJobID) {
		t.Fatalf("files %v", names)
	}
	if got := gunzip(t, filepath.Join(root, "backups", outputName(testJobID))); got != strings.Repeat(dumpOutput, 20000) {
		t.Fatal("the published output is not complete")
	}
	if m := readManifest(t, h, "manual"); len(m.Entries) != 1 {
		t.Fatalf("manifest %+v", m)
	}
}

func TestPipelineFailuresLeaveNoOutput(t *testing.T) {
	random := make([]byte, 4096)
	rand.Read(random)
	cases := map[string]struct {
		script  fakeScript
		step    string
		summary string
	}{
		"exit code":    {fakeScript{Container: "immich_postgres", Output: dumpOutput, Exit: 3}, pipelineStep("dump", "manual"), "exited with 3"},
		"empty output": {fakeScript{Container: "immich_postgres"}, pipelineStep("dump", "manual"), "no output"},
		"size limit":   {fakeScript{Container: "immich_postgres", Output: base64.StdEncoding.EncodeToString(random)}, pipelineStep("dump", "small"), "exceeds 64 bytes"},
		"timeout":      {fakeScript{Container: "immich_postgres", Sleeps: true}, pipelineStep("slow-dump", "manual"), "timed out after 1s"},
	}
	for name, c := range cases {
		t.Run(name, func(t *testing.T) {
			root := uploadRoot(t)
			h := newHarness(t, pipelinePolicy(root))
			script(t, c.script)
			expect(t, h.run(context.Background(), c.step), "failed", codePipeline, c.summary)
			if names := entries(t, filepath.Join(root, "backups")); len(names) != 0 {
				t.Fatalf("leftovers %v", names)
			}
			if m := readManifest(t, h, "manual"); len(m.Entries) != 0 {
				t.Fatalf("manifest %+v", m)
			}
		})
	}
}

func TestConcurrentPipelinesOfDifferentRunsAreAllRecorded(t *testing.T) {
	root := uploadRoot(t)
	h := newHarness(t, pipelinePolicy(root))
	script(t, fakeScript{Container: "immich_postgres", Output: dumpOutput})

	var wg sync.WaitGroup
	for i := 1; i <= 6; i++ {
		wg.Add(1)
		go func() {
			defer wg.Done()
			expect(t, h.runJob(context.Background(), jobID(i), pipelineStep("dump", "manual")), "succeeded", "ok")
		}()
	}
	wg.Wait()
	if m := readManifest(t, h, "manual"); len(m.Entries) != 6 || m.Next != 7 {
		t.Fatalf("manifest lost entries: %+v", m)
	}
}

func TestPipelineNeverWritesThroughAConcurrentSymlinkSwapOrMagicLink(t *testing.T) {
	root := uploadRoot(t)
	outside := tempDir(t)
	h := newHarness(t, pipelinePolicy(root))
	h.policy = strings.Replace(h.policy, "paths:\n", "paths:\n  proc:\n    root: /proc/1\n", 1)
	h.policy = strings.Replace(h.policy, "destinations:\n", "destinations:\n  magic:\n    pathAlias: proc\n    directory: root\n"+
		"    prefix: x-\n    suffix: .gz\n    owner: 0\n    group: 0\n    mode: \"0600\"\n    maximumBytes: 1024\n    maximumKeep: 1\n", 1)
	script(t, fakeScript{Container: "immich_postgres", Output: dumpOutput})
	expect(t, h.run(context.Background(), pipelineStep("dump", "magic")), "failed", codePolicy, "symlink")

	stop := swapper(t, filepath.Join(root, "backups"), outside)
	for i := 1; i <= 30; i++ {
		h.runJob(context.Background(), jobID(i), pipelineStep("dump", "manual"))
	}
	stop()
	if names := entries(t, outside); len(names) != 0 {
		t.Fatalf("wrote outside the alias root: %v", names)
	}
}

func TestPipelineRefusesASymlinkedDestination(t *testing.T) {
	root := uploadRoot(t)
	outside := tempDir(t)
	os.Remove(filepath.Join(root, "backups"))
	os.Symlink(outside, filepath.Join(root, "backups"))
	h := newHarness(t, pipelinePolicy(root))
	s := script(t, fakeScript{Container: "immich_postgres", Output: dumpOutput})

	expect(t, h.run(context.Background(), pipelineStep("dump", "manual")), "failed", codePolicy, "symlink")
	if names := entries(t, outside); len(names) != 0 || len(invocations(t, s)) != 0 {
		t.Fatalf("wrote %v", names)
	}
}

func TestPipelineSyncsTheFileBeforeAndTheDirectoryAfterPublishing(t *testing.T) {
	root := uploadRoot(t)
	h := newHarness(t, pipelinePolicy(root))
	script(t, fakeScript{Container: "immich_postgres", Output: dumpOutput})
	published := filepath.Join(root, "backups", outputName(testJobID))
	var events []string
	h.primitives["file.write-pipeline"].(*writePipeline).sync = func(fd int) error {
		var st unix.Stat_t
		unix.Fstat(fd, &st)
		_, err := os.Lstat(published)
		recorded := len(readManifest(t, h, "manual").Entries) == 1
		events = append(events, fmt.Sprintf("%s published=%v recorded=%v", map[bool]string{true: "dir", false: "file"}[st.Mode&unix.S_IFMT == unix.S_IFDIR], err == nil, recorded))
		return unix.Fsync(fd)
	}

	expect(t, h.run(context.Background(), pipelineStep("dump", "manual")), "succeeded", "ok")
	// the manifest knows the output before it is published
	if strings.Join(events, ",") != "file published=false recorded=false,dir published=true recorded=true" {
		t.Fatalf("sync order %v", events)
	}
}

func TestPipelineManifestIsRootProtected(t *testing.T) {
	root := uploadRoot(t)
	h := newHarness(t, pipelinePolicy(root))
	script(t, fakeScript{Container: "immich_postgres", Output: dumpOutput})
	expect(t, h.run(context.Background(), pipelineStep("dump", "manual")), "succeeded", "ok")
	path := filepath.Join(h.stateDir, manifestDirectory, "manual.json")
	var m manifest
	raw, _ := os.ReadFile(path)
	if err := json.Unmarshal(raw, &m); err != nil || len(m.Entries) != 1 {
		t.Fatalf("manifest %s", raw)
	}

	os.Chmod(path, 0o644)
	expect(t, h.runJob(context.Background(), jobID(2), pipelineStep("dump", "manual")), "failed", codePipeline, "mode is not 0600")
}

func jobID(n int) string { return fmt.Sprintf("0190c3a4-5b6e-7f80-9a1b-%012d", n) }
