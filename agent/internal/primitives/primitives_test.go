package primitives

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"io"
	"log/slog"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"github.com/muckiware/MuckiSafeAgentPlugin/agent/internal/action"
	"github.com/muckiware/MuckiSafeAgentPlugin/agent/internal/agent"
	"github.com/muckiware/MuckiSafeAgentPlugin/agent/internal/api"
	"github.com/muckiware/MuckiSafeAgentPlugin/agent/internal/executor"
	"github.com/muckiware/MuckiSafeAgentPlugin/agent/internal/policy"
)

const testJobID = "0190c3a4-5b6e-7f80-9a1b-2c3d4e5f6a7b"

// TestMain turns the test binary into a fake systemctl or docker when it is
// started as `<binary> <args>` by a primitive under test. The executor gives
// processes a fixed environment, so the fake finds its script through the
// parent pid.
func TestMain(m *testing.M) {
	if script, err := os.ReadFile(fakeScriptPath(os.Getppid())); err == nil {
		os.Exit(fake(script, os.Args[1:]))
	}
	os.Exit(m.Run())
}

func fakeScriptPath(pid int) string {
	return fmt.Sprintf("/tmp/muwa-primitives-fake-%d.json", pid)
}

// fakeScript drives the fake executables of one test.
type fakeScript struct {
	Log string `json:"log"`
	// systemctl
	Unit           string `json:"unit"`
	Active         string `json:"active"`
	ControlGroup   string `json:"controlGroup"`
	StopSleeps     bool   `json:"stopSleeps"`
	StopExit       int    `json:"stopExit"`
	StopKeepsGroup bool   `json:"stopKeepsGroup"`
	StopNoop       bool   `json:"stopNoop"`
	StartFails     bool   `json:"startFails"`
	// docker
	Container string `json:"container"`
	Output    string `json:"output"`
	Repeat    int    `json:"repeat"`
	Stderr    string `json:"stderr"`
	Exit      int    `json:"exit"`
	Sleeps    bool   `json:"sleeps"`
	State     string `json:"state"`
	// Create is a file the fake command creates, like /backup-db.sh its .BAK.
	Create *fakeFile `json:"create"`
}

type fakeFile struct {
	Path  string      `json:"path"`
	Bytes int         `json:"bytes"`
	UID   int         `json:"uid"`
	GID   int         `json:"gid"`
	Mode  os.FileMode `json:"mode"`
}

// script installs the fake script for processes started by this test.
func script(t *testing.T, s fakeScript) *fakeScript {
	t.Helper()
	s.Log = filepath.Join(tempDir(t), "invocations.log")
	s.State = filepath.Join(tempDir(t), "state.json")
	writeScript(t, s)
	t.Cleanup(func() { _ = os.Remove(fakeScriptPath(os.Getpid())) })
	return &s
}

func writeScript(t *testing.T, s fakeScript) {
	t.Helper()
	raw, _ := json.Marshal(s)
	if err := os.WriteFile(fakeScriptPath(os.Getpid()), raw, 0o600); err != nil {
		t.Fatal(err)
	}
}

func invocations(t *testing.T, s *fakeScript) []string {
	t.Helper()
	raw, err := os.ReadFile(s.Log)
	if os.IsNotExist(err) {
		return nil
	}
	if err != nil {
		t.Fatal(err)
	}
	return strings.Split(strings.TrimSuffix(string(raw), "\n"), "\n")
}

func fake(raw []byte, args []string) int {
	var s fakeScript
	if err := json.Unmarshal(raw, &s); err != nil {
		return 98
	}
	if log, err := os.OpenFile(s.Log, os.O_APPEND|os.O_CREATE|os.O_WRONLY, 0o600); err == nil {
		fmt.Fprintln(log, strings.Join(args, " "))
		log.Close()
	}
	if len(args) > 0 && args[0] == "exec" {
		return fakeDocker(s, args[1:])
	}
	return fakeSystemctl(s, args)
}

func fakeDocker(s fakeScript, args []string) int {
	if len(args) == 0 || args[0] != s.Container {
		fmt.Fprintf(os.Stderr, "Error response from daemon: No such container: %s\n", strings.Join(args, " "))
		return 1
	}
	if s.Sleeps {
		time.Sleep(time.Hour)
	}
	if c := s.Create; c != nil {
		if os.WriteFile(c.Path, []byte(strings.Repeat("B", c.Bytes)), c.Mode) != nil || os.Chown(c.Path, c.UID, c.GID) != nil || os.Chmod(c.Path, c.Mode) != nil {
			return 95
		}
	}
	for i := 0; i < max(1, s.Repeat); i++ {
		fmt.Fprint(os.Stdout, s.Output)
	}
	fmt.Fprint(os.Stderr, s.Stderr)
	return s.Exit
}

// fakeSystemctl keeps the unit state in s.State between invocations.
func fakeSystemctl(s fakeScript, args []string) int {
	if raw, err := os.ReadFile(s.State); err == nil {
		_ = json.Unmarshal(raw, &s)
	}
	if len(args) < 4 || args[0] != "--no-ask-password" {
		return 97
	}
	unit := args[len(args)-1]
	known := unit == s.Unit && args[len(args)-2] == "--"
	save := func() {
		raw, _ := json.Marshal(s)
		_ = os.WriteFile(s.State, raw, 0o600)
	}
	switch args[1] {
	case "show":
		if !known {
			fmt.Print("LoadState=not-found\nActiveState=inactive\nControlGroup=\n")
			return 0
		}
		fmt.Printf("LoadState=loaded\nActiveState=%s\nControlGroup=%s\n", s.Active, s.ControlGroup)
		return 0
	case "stop":
		if !known {
			fmt.Fprintf(os.Stderr, "Failed to stop %s: Unit %s not loaded.\n", unit, unit)
			return 5
		}
		if s.StopSleeps {
			time.Sleep(time.Hour)
		}
		if s.StopExit == 0 && !s.StopNoop {
			s.Active = "inactive"
			if !s.StopKeepsGroup {
				s.ControlGroup = ""
			}
			save()
		}
		return s.StopExit
	case "start", "restart":
		if !known || s.StartFails {
			fmt.Fprintf(os.Stderr, "Job for %s failed.\n", unit)
			return 1
		}
		s.Active = "active"
		save()
		return 0
	}
	return 96
}

// tempDir is a symlink-free temporary directory.
func tempDir(t *testing.T) string {
	t.Helper()
	dir, err := filepath.EvalSymlinks(t.TempDir())
	if err != nil {
		t.Fatal(err)
	}
	return dir
}

// helperBinary is the symlink-free path of the test binary.
func helperBinary(t *testing.T) string {
	t.Helper()
	path, err := filepath.EvalSymlinks(os.Args[0])
	if err != nil {
		t.Fatal(err)
	}
	abs, err := filepath.Abs(path)
	if err != nil {
		t.Fatal(err)
	}
	return abs
}

// harness runs real jobs through the executor with the V1 primitives.
type harness struct {
	t          *testing.T
	primitives map[string]executor.Primitive
	policy     string
	runner     *executor.ProcessRunner
	stateDir   string
}

func newHarness(t *testing.T, policyBody string) *harness {
	t.Helper()
	binary := helperBinary(t)
	h := &harness{t: t, policy: policyBody, stateDir: privateDir(t)}
	h.primitives = New(Options{StateDirectory: h.stateDir, Systemctl: binary, Docker: binary, CgroupRoot: tempDir(t)})
	h.runner = executor.NewProcessRunner()
	h.runner.Executables = map[string]bool{binary: true}
	h.runner.TermGrace = 200 * time.Millisecond
	return h
}

// privateDir is a 0700 directory as required for agent state.
func privateDir(t *testing.T) string {
	t.Helper()
	dir := tempDir(t)
	if err := os.Chmod(dir, 0o700); err != nil {
		t.Fatal(err)
	}
	return dir
}

func (h *harness) store() *policy.Store {
	h.t.Helper()
	path := filepath.Join(tempDir(h.t), "policy.yaml")
	if err := os.WriteFile(path, []byte(h.policy), 0o644); err != nil {
		h.t.Fatal(err)
	}
	store, err := policy.NewStore(path, PolicyDecoders())
	if err != nil {
		h.t.Fatalf("policy: %v", err)
	}
	return store
}

// run executes one job made of the given step JSON objects.
func (h *harness) run(ctx context.Context, steps ...string) api.CompletionResult {
	h.t.Helper()
	return h.runJob(ctx, testJobID, steps...)
}

func (h *harness) runJob(ctx context.Context, jobID string, steps ...string) api.CompletionResult {
	h.t.Helper()
	return h.execute(ctx, actionJob(h.t, jobID, steps...))
}

func (h *harness) execute(ctx context.Context, job agent.Job) api.CompletionResult {
	h.t.Helper()
	e := &executor.Executor{
		Policies: h.store(), Primitives: h.primitives, Processes: h.runner,
		Redactor: executor.NewRedactor(), Logger: slog.New(slog.NewTextHandler(io.Discard, nil)),
	}
	return e.Execute(ctx, job, func() error { return nil })
}

func actionJob(t *testing.T, jobID string, steps ...string) agent.Job {
	t.Helper()
	definition, err := action.Decode([]byte(`{"version":1,"steps":[` + strings.Join(steps, ",") + `]}`))
	if err != nil {
		t.Fatalf("invalid test action: %v", err)
	}
	canonical, err := action.Canonical(definition)
	if err != nil {
		t.Fatal(err)
	}
	sum := sha256.Sum256([]byte(canonical))
	return agent.Job{ID: jobID, Phase: "pre", IdempotencyClass: "safe",
		DefinitionCanonical: canonical, ActionHash: hex.EncodeToString(sum[:]), Parameters: map[string]any{}}
}

func expect(t *testing.T, result api.CompletionResult, outcome, code string, summary ...string) {
	t.Helper()
	if result.Outcome != outcome || result.ResultCode != code {
		t.Fatalf("expected %s/%s, got %s/%s: %s", outcome, code, result.Outcome, result.ResultCode, result.Summary)
	}
	for _, part := range summary {
		if !strings.Contains(result.Summary, part) {
			t.Fatalf("summary %q does not mention %q", result.Summary, part)
		}
	}
}

func TestEveryV1PrimitiveIsRegistered(t *testing.T) {
	names := []string{"service.control", "service.wait-http", "docker.container.exec", "filesystem.acl.read",
		"filesystem.assert-readable", "file.write-pipeline", "file.retention"}
	primitives := New(Options{})
	if len(primitives) != len(names) {
		t.Fatalf("registered %d primitives", len(primitives))
	}
	for _, name := range names {
		if primitives[name] == nil {
			t.Errorf("%s is not registered", name)
		}
	}
}

func TestPolicyFileMustBeRootProtected(t *testing.T) {
	for _, mode := range []os.FileMode{0o666, 0o664, 0o646} {
		path := filepath.Join(tempDir(t), "policy.yaml")
		if err := os.WriteFile(path, []byte("version: 1\n"), mode); err != nil {
			t.Fatal(err)
		}
		if err := os.Chmod(path, mode); err != nil {
			t.Fatal(err)
		}
		if _, err := policy.Load(path, PolicyDecoders()); err == nil {
			t.Fatalf("a policy with mode %o must not load", mode)
		}
	}
	if os.Geteuid() == 0 {
		path := filepath.Join(tempDir(t), "policy.yaml")
		os.WriteFile(path, []byte("version: 1\n"), 0o644)
		os.Chown(path, 1000, 0)
		if _, err := policy.Load(path, PolicyDecoders()); err == nil {
			t.Fatal("a policy owned by another user must not load")
		}
	}
}
