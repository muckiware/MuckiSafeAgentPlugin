package idempotency

import (
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"golang.org/x/sys/unix"

	"github.com/muckiware/MuckiSafeAgentPlugin/agent/internal/api"
)

const (
	jobID      = "0190c3a4-5b6e-7f80-9a1b-2c3d4e5f6a7b"
	token      = "Tx3IBqFEOrmbYYXG-3ry1Cq-8GW8waH3hgKndGrL-dw"
	definition = `{"steps":[],"version":1}`
)

func definitionHash(canonical string) string {
	sum := sha256.Sum256([]byte(canonical))
	return hex.EncodeToString(sum[:])
}

// stateDir returns a private state directory below a symlink-free temp path
// (macOS places t.TempDir() behind the /var -> /private/var symlink).
func stateDir(t *testing.T) string {
	t.Helper()
	base, err := filepath.EvalSymlinks(t.TempDir())
	if err != nil {
		t.Fatal(err)
	}
	dir := filepath.Join(base, "state")
	if err := os.Mkdir(dir, 0o700); err != nil {
		t.Fatal(err)
	}
	return dir
}

func open(t *testing.T, dir string) *Journal {
	t.Helper()
	j, err := Open(dir)
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { _ = j.Close() })
	return j
}

func started() Record {
	return Record{
		JobID:               jobID,
		Phase:               "pre",
		DefinitionCanonical: definition,
		ActionHash:          definitionHash(definition),
		Parameters:          map[string]any{"keep": float64(7)},
		IdempotencyClass:    "non_retryable",
		ClaimToken:          token,
		LeaseVersion:        3,
		PhaseDeadline:       "2030-03-17T17:56:40Z",
		ExecutionDeadline:   "2030-03-17T17:56:25Z",
		StopDeadline:        "2030-03-17T17:56:30Z",
		State:               StateStarted,
	}
}

func completed() Record {
	r := started()
	tail, encoding := "boom", "utf-8"
	r.State = StateCompleted
	r.Completion = &api.CompletionResult{Outcome: "failed", ResultCode: "step_failed", Summary: "step 2 failed", StderrTail: &tail, StderrEncoding: &encoding}
	return r
}

func recordPath(dir string) string { return filepath.Join(dir, "jobs", jobID+".json") }

func TestStartedAndCompletedRecordsRoundTrip(t *testing.T) {
	dir := stateDir(t)
	j := open(t, dir)

	if _, err := j.Load(jobID); !errors.Is(err, ErrNotFound) {
		t.Fatalf("expected ErrNotFound, got %v", err)
	}
	for _, record := range []Record{started(), completed()} {
		if err := j.Write(record); err != nil {
			t.Fatal(err)
		}
		loaded, err := j.Load(jobID)
		if err != nil {
			t.Fatal(err)
		}
		if loaded.State != record.State || loaded.LeaseVersion != 3 || loaded.ClaimToken != token {
			t.Fatalf("unexpected record %+v", loaded)
		}
	}
	loaded, _ := j.Load(jobID)
	if loaded.DefinitionCanonical != definition || loaded.Parameters["keep"] != float64(7) {
		t.Fatalf("job snapshot not persisted: %+v", loaded)
	}
	if loaded.Completion == nil || *loaded.Completion.StderrTail != "boom" {
		t.Fatalf("completion not persisted verbatim: %+v", loaded.Completion)
	}
	info, err := os.Lstat(recordPath(dir))
	if err != nil || info.Mode().Perm() != 0o600 {
		t.Fatalf("record mode %v / %v", info.Mode(), err)
	}
	jobsInfo, _ := os.Lstat(filepath.Join(dir, "jobs"))
	if jobsInfo.Mode().Perm() != 0o700 {
		t.Fatalf("jobs directory mode %v", jobsInfo.Mode())
	}
	entries, _ := os.ReadDir(filepath.Join(dir, "jobs"))
	if len(entries) != 1 {
		t.Fatalf("temporary files left behind: %v", entries)
	}
	if err := j.Remove(jobID); err != nil {
		t.Fatal(err)
	}
	if _, err := j.Load(jobID); !errors.Is(err, ErrNotFound) {
		t.Fatalf("expected removed record, got %v", err)
	}
}

func TestFileIsSyncedBeforeTheRenameAndTheDirectoryAfterIt(t *testing.T) {
	dir := stateDir(t)
	j := open(t, dir)
	var events []string
	j.fsync = func(fd int) error {
		_, err := os.Lstat(recordPath(dir))
		visible := err == nil
		if fd == j.dirfd {
			events = append(events, "dir visible="+boolString(visible))
		} else {
			events = append(events, "file visible="+boolString(visible))
		}
		return unix.Fsync(fd)
	}
	if err := j.Write(started()); err != nil {
		t.Fatal(err)
	}
	if strings.Join(events, ",") != "file visible=false,dir visible=true" {
		t.Fatalf("unexpected fsync order %v", events)
	}
}

func TestFailedFileSyncLeavesNeitherRecordNorTemporary(t *testing.T) {
	dir := stateDir(t)
	j := open(t, dir)
	j.fsync = func(int) error { return unix.EIO }

	if err := j.Write(started()); err == nil {
		t.Fatal("expected the write to fail")
	}
	entries, _ := os.ReadDir(filepath.Join(dir, "jobs"))
	if len(entries) != 0 {
		t.Fatalf("expected an empty journal, got %v", entries)
	}
}

func TestStaleTemporariesOfACrashAreIgnoredAndRemoved(t *testing.T) {
	dir := stateDir(t)
	open(t, dir).Close()
	tmp := filepath.Join(dir, "jobs", "."+jobID+".0123456789abcdef.tmp")
	if err := os.WriteFile(tmp, []byte(`{"half":`), 0o600); err != nil {
		t.Fatal(err)
	}

	j := open(t, dir)
	if _, err := j.Load(jobID); !errors.Is(err, ErrNotFound) {
		t.Fatalf("a temporary must never count as a record: %v", err)
	}
	if _, err := os.Lstat(tmp); !os.IsNotExist(err) {
		t.Fatal("stale temporary not removed on open")
	}
}

func TestUnsafeStateDirectoriesFailClosed(t *testing.T) {
	t.Run("group accessible", func(t *testing.T) {
		dir := stateDir(t)
		_ = os.Chmod(dir, 0o750)
		if _, err := Open(dir); err == nil {
			t.Fatal("expected rejection")
		}
	})
	t.Run("symlinked state directory", func(t *testing.T) {
		dir := stateDir(t)
		link := filepath.Join(filepath.Dir(dir), "link")
		_ = os.Symlink(dir, link)
		if _, err := Open(link); err == nil {
			t.Fatal("expected rejection")
		}
	})
	t.Run("symlink in a parent", func(t *testing.T) {
		dir := stateDir(t)
		parentLink := filepath.Join(filepath.Dir(filepath.Dir(dir)), "parent-link")
		_ = os.Symlink(filepath.Dir(dir), parentLink)
		if _, err := Open(filepath.Join(parentLink, "state")); err == nil {
			t.Fatal("expected rejection")
		}
	})
	t.Run("world writable parent without sticky bit", func(t *testing.T) {
		dir := stateDir(t)
		_ = os.Chmod(filepath.Dir(dir), 0o777)
		defer os.Chmod(filepath.Dir(dir), 0o700)
		if _, err := Open(dir); err == nil {
			t.Fatal("expected rejection")
		}
	})
	t.Run("jobs directory is a symlink", func(t *testing.T) {
		dir := stateDir(t)
		target := filepath.Join(filepath.Dir(dir), "elsewhere")
		_ = os.Mkdir(target, 0o700)
		_ = os.Symlink(target, filepath.Join(dir, "jobs"))
		if _, err := Open(dir); err == nil {
			t.Fatal("expected rejection")
		}
	})
	t.Run("relative or unclean path", func(t *testing.T) {
		for _, path := range []string{"state", "/var/lib/../state", ""} {
			if _, err := Open(path); err == nil {
				t.Errorf("%q accepted", path)
			}
		}
	})
}

func TestTamperedRecordsFailClosed(t *testing.T) {
	cases := map[string]func(t *testing.T, dir string){
		"symlink": func(t *testing.T, dir string) {
			target := filepath.Join(filepath.Dir(dir), "target.json")
			raw, _ := os.ReadFile(recordPath(dir))
			_ = os.WriteFile(target, raw, 0o600)
			_ = os.Remove(recordPath(dir))
			_ = os.Symlink(target, recordPath(dir))
		},
		"hardlink": func(t *testing.T, dir string) {
			_ = os.Link(recordPath(dir), filepath.Join(filepath.Dir(dir), "second-name"))
		},
		"group readable": func(t *testing.T, dir string) { _ = os.Chmod(recordPath(dir), 0o640) },
		"truncated json": func(t *testing.T, dir string) { _ = os.WriteFile(recordPath(dir), []byte(`{"version":1,`), 0o600) },
		"foreign job id": func(t *testing.T, dir string) {
			rewrite(t, dir, func(s string) string { return strings.Replace(s, jobID, "1190c3a4-5b6e-7f80-9a1b-2c3d4e5f6a7b", 1) })
		},
		"unknown member": func(t *testing.T, dir string) {
			rewrite(t, dir, func(s string) string { return strings.Replace(s, `{`, `{"extra":1,`, 1) })
		},
		"completed without completion": func(t *testing.T, dir string) {
			rewrite(t, dir, func(s string) string { return strings.Replace(s, `"started"`, `"completed"`, 1) })
		},
		"invalid action hash": func(t *testing.T, dir string) {
			rewrite(t, dir, func(s string) string { return strings.Replace(s, definitionHash(definition), "zz", 1) })
		},
		"definition does not match its hash": func(t *testing.T, dir string) {
			rewrite(t, dir, func(s string) string { return strings.Replace(s, `[]`, `[{}]`, 1) })
		},
		"parameters not an object": func(t *testing.T, dir string) {
			rewrite(t, dir, func(s string) string { return strings.Replace(s, `{"keep":7}`, `[7]`, 1) })
		},
		"directory instead of file": func(t *testing.T, dir string) {
			_ = os.Remove(recordPath(dir))
			_ = os.Mkdir(recordPath(dir), 0o700)
		},
	}
	for name, tamper := range cases {
		t.Run(name, func(t *testing.T) {
			dir := stateDir(t)
			j := open(t, dir)
			if err := j.Write(started()); err != nil {
				t.Fatal(err)
			}
			tamper(t, dir)
			if _, err := j.Load(jobID); !errors.Is(err, ErrCorrupt) {
				t.Fatalf("expected ErrCorrupt, got %v", err)
			}
		})
	}
}

func rewrite(t *testing.T, dir string, change func(string) string) {
	t.Helper()
	raw, err := os.ReadFile(recordPath(dir))
	if err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(recordPath(dir), []byte(change(string(raw))), 0o600); err != nil {
		t.Fatal(err)
	}
}

func TestInvalidRecordsAreNeverWritten(t *testing.T) {
	j := open(t, stateDir(t))
	broken := []func(*Record){
		func(r *Record) { r.JobID = "../escape" },
		func(r *Record) { r.State = "running" },
		func(r *Record) { r.ClaimToken = "short" },
		func(r *Record) { r.State = StateCompleted },
		func(r *Record) { r.IdempotencyClass = "maybe" },
		func(r *Record) { r.ExecutionDeadline = "tomorrow" },
		func(r *Record) { r.Phase = "during" },
		func(r *Record) { r.DefinitionCanonical = `{"steps":[{}],"version":1}` },
		func(r *Record) { r.Parameters = nil },
	}
	for i, mutate := range broken {
		r := started()
		mutate(&r)
		if err := j.Write(r); err == nil {
			t.Errorf("case %d written", i)
		}
	}
}

func TestListReturnsEveryRecordNameButIgnoresForeignFiles(t *testing.T) {
	dir := stateDir(t)
	j := open(t, dir)
	if err := j.Write(started()); err != nil {
		t.Fatal(err)
	}
	_ = os.WriteFile(filepath.Join(dir, "jobs", "1190c3a4-5b6e-7f80-9a1b-2c3d4e5f6a7b.json"), []byte("garbage"), 0o600)
	_ = os.WriteFile(filepath.Join(dir, "jobs", "notes.txt"), nil, 0o600)

	for i := 0; i < 2; i++ {
		ids, err := j.List()
		if err != nil {
			t.Fatal(err)
		}
		if strings.Join(ids, ",") != jobID+",1190c3a4-5b6e-7f80-9a1b-2c3d4e5f6a7b" {
			t.Fatalf("listing %d: %v", i, ids)
		}
	}
}

func boolString(b bool) string {
	if b {
		return "true"
	}
	return "false"
}
