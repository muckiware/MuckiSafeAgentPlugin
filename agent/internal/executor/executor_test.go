package executor

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"fmt"
	"io"
	"log/slog"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"gopkg.in/yaml.v3"

	"github.com/muckiware/MuckiSafeAgentPlugin/agent/internal/action"
	"github.com/muckiware/MuckiSafeAgentPlugin/agent/internal/agent"
	"github.com/muckiware/MuckiSafeAgentPlugin/agent/internal/policy"
)

// job builds a valid V1 job from service.control steps on the given aliases.
func job(t *testing.T, aliases ...string) agent.Job {
	t.Helper()
	steps := make([]string, len(aliases))
	for i, alias := range aliases {
		steps[i] = `{"primitive":"service.control","serviceAlias":"` + alias + `","operation":"stop"}`
	}
	definition, err := action.Decode([]byte(`{"version":1,"steps":[` + strings.Join(steps, ",") + `]}`))
	if err != nil {
		t.Fatal(err)
	}
	canonical, err := action.Canonical(definition)
	if err != nil {
		t.Fatal(err)
	}
	sum := sha256.Sum256([]byte(canonical))
	return agent.Job{
		ID: "0190c3a4-5b6e-7f80-9a1b-2c3d4e5f6a7b", Phase: "pre", IdempotencyClass: "safe",
		DefinitionCanonical: canonical, ActionHash: hex.EncodeToString(sum[:]), Parameters: map[string]any{},
	}
}

// fakePrimitive records the aliases it ran and fails or stalls on demand.
type fakePrimitive struct {
	ran   []string
	units []string
	fail  map[string]error
	hook  func(StepContext)
}

func (f *fakePrimitive) Run(_ context.Context, step StepContext) error {
	alias := step.Step["serviceAlias"].(string)
	f.ran = append(f.ran, alias)
	if entry, ok := step.Policy.Entry("services", alias); ok {
		f.units = append(f.units, entry.(string))
	}
	fmt.Fprintf(step.Stdout, "stopping %s with "+secret+"\n", alias)
	fmt.Fprintf(step.Stderr, "warning on %s\n", alias)
	if f.hook != nil {
		f.hook(step)
	}
	return f.fail[alias]
}

var serviceDecoders = policy.Decoders{"services": func(_ string, node *yaml.Node) (any, error) {
	var unit string
	return unit, node.Decode(&unit)
}}

func store(t *testing.T, body string) (*policy.Store, string) {
	t.Helper()
	dir, err := filepath.EvalSymlinks(t.TempDir())
	if err != nil {
		t.Fatal(err)
	}
	path := filepath.Join(dir, "policy.yaml")
	if err := os.WriteFile(path, []byte(body), 0o644); err != nil {
		t.Fatal(err)
	}
	s, err := policy.NewStore(path, serviceDecoders)
	if err != nil {
		t.Fatal(err)
	}
	return s, path
}

const twoServices = "version: 1\nservices:\n  jellyfin: jellyfin.service\n  immich: immich.service\n"

func newExecutor(t *testing.T, primitive Primitive) *Executor {
	t.Helper()
	policies, _ := store(t, twoServices)
	return &Executor{
		Policies:   policies,
		Primitives: map[string]Primitive{"service.control": primitive},
		Processes:  runner(t),
		Redactor:   NewRedactor(secret),
		Logger:     slog.New(slog.NewTextHandler(io.Discard, nil)),
	}
}

func gateOpen() error { return nil }

func TestSuccessfulActionRunsEveryStepInOrderWithoutTails(t *testing.T) {
	primitive := &fakePrimitive{}
	result := newExecutor(t, primitive).Execute(context.Background(), job(t, "jellyfin", "immich"), gateOpen)

	if result.Outcome != "succeeded" || result.ResultCode != "ok" || result.StdoutTail != nil || result.StderrTail != nil {
		t.Fatalf("result %+v", result)
	}
	if strings.Join(primitive.ran, ",") != "jellyfin,immich" || strings.Join(primitive.units, ",") != "jellyfin.service,immich.service" {
		t.Fatalf("ran %v with units %v", primitive.ran, primitive.units)
	}
	if err := result.Validate(); err != nil {
		t.Fatal(err)
	}
}

func TestFirstStepFailureKeepsItsCodeAndRedactedTails(t *testing.T) {
	primitive := &fakePrimitive{fail: map[string]error{"jellyfin": &StepError{Code: "unit_not_stopped", Message: "unit still active, token " + secret}}}
	result := newExecutor(t, primitive).Execute(context.Background(), job(t, "jellyfin", "immich"), gateOpen)

	if result.Outcome != "failed" || result.ResultCode != "unit_not_stopped" || len(primitive.ran) != 1 {
		t.Fatalf("result %+v, ran %v", result, primitive.ran)
	}
	if result.StdoutTail == nil || result.StderrTail == nil || *result.StdoutEncoding != "utf-8" {
		t.Fatalf("failure must carry tails: %+v", result)
	}
	for _, text := range []string{*result.StdoutTail, result.Summary} {
		if strings.Contains(text, secret) || !strings.Contains(text, Redacted) {
			t.Fatalf("secret not redacted: %q", text)
		}
	}
	if !strings.Contains(result.Summary, "step 1") || !strings.Contains(result.Summary, "service.control") {
		t.Fatalf("summary %q", result.Summary)
	}
}

func TestLaterStepFailureIsAPartialFailure(t *testing.T) {
	primitive := &fakePrimitive{fail: map[string]error{"immich": errors.New("boom")}}
	result := newExecutor(t, primitive).Execute(context.Background(), job(t, "jellyfin", "immich"), gateOpen)

	if result.Outcome != "failed" || result.ResultCode != "partial_failure" {
		t.Fatalf("result %+v", result)
	}
}

func TestClosedGateStopsBeforeTheNextStep(t *testing.T) {
	steps := 0
	gate := func() error {
		if steps >= 1 {
			return errors.New("execution deadline reached")
		}
		steps++
		return nil
	}
	primitive := &fakePrimitive{}
	result := newExecutor(t, primitive).Execute(context.Background(), job(t, "jellyfin", "immich"), gate)

	if len(primitive.ran) != 1 || result.Outcome != "failed" || result.ResultCode != "partial_failure" {
		t.Fatalf("ran %v, result %+v", primitive.ran, result)
	}
}

func TestUnterminatedProcessEndsAmbiguousWithoutTails(t *testing.T) {
	primitive := &fakePrimitive{fail: map[string]error{"jellyfin": fmt.Errorf("stop: %w", ErrNotTerminated)}}
	result := newExecutor(t, primitive).Execute(context.Background(), job(t, "jellyfin"), gateOpen)

	if result.Outcome != "ambiguous" || result.ResultCode != "process_not_terminated" || result.StdoutTail != nil {
		t.Fatalf("result %+v", result)
	}
}

func TestRejectedActionsRunNothing(t *testing.T) {
	valid := job(t, "jellyfin")
	cases := map[string]func(j *agent.Job){
		"hash mismatch":  func(j *agent.Job) { j.ActionHash = strings.Repeat("0", 64) },
		"non canonical":  func(j *agent.Job) { j.DefinitionCanonical = " " + j.DefinitionCanonical },
		"parameters set": func(j *agent.Job) { j.Parameters = map[string]any{"path": "/etc"} },
		"schema violation": func(j *agent.Job) {
			canonical := `{"steps":[{"command":"rm -rf /","primitive":"shell.exec"}],"version":1}`
			sum := sha256.Sum256([]byte(canonical))
			j.DefinitionCanonical, j.ActionHash = canonical, hex.EncodeToString(sum[:])
		},
	}
	for name, mutate := range cases {
		t.Run(name, func(t *testing.T) {
			j := valid
			mutate(&j)
			primitive := &fakePrimitive{}
			result := newExecutor(t, primitive).Execute(context.Background(), j, gateOpen)
			if result.Outcome != "failed" || result.ResultCode != "action_rejected" || len(primitive.ran) != 0 {
				t.Fatalf("result %+v, ran %v", result, primitive.ran)
			}
		})
	}
}

func TestUnregisteredPrimitiveFailsClosed(t *testing.T) {
	executor := newExecutor(t, &fakePrimitive{})
	executor.Primitives = map[string]Primitive{}

	result := executor.Execute(context.Background(), job(t, "jellyfin"), gateOpen)
	if result.Outcome != "failed" || result.ResultCode != "primitive_unavailable" {
		t.Fatalf("result %+v", result)
	}
}

func TestAJobKeepsItsPolicySnapshotAcrossAReload(t *testing.T) {
	policies, path := store(t, twoServices)
	primitive := &fakePrimitive{hook: func(step StepContext) {
		if step.Index == 0 {
			_ = os.WriteFile(path, []byte("version: 1\nservices:\n  jellyfin: evil.service\n  immich: evil.service\n"), 0o644)
			if err := policies.Reload(); err != nil {
				t.Error(err)
			}
		}
	}}
	executor := newExecutor(t, primitive)
	executor.Policies = policies

	executor.Execute(context.Background(), job(t, "jellyfin", "immich"), gateOpen)
	if strings.Join(primitive.units, ",") != "jellyfin.service,immich.service" {
		t.Fatalf("units %v", primitive.units)
	}
}

func TestStepProcessesAreGatedAndWriteIntoTheJobTails(t *testing.T) {
	primitive := &fakePrimitive{hook: func(step StepContext) {
		if _, err := step.Run(context.Background(), helperCommand(t, "print", "proc-out "+secret, "proc-err", "0")); err != nil {
			t.Error(err)
		}
	}, fail: map[string]error{"jellyfin": errors.New("fail to expose tails")}}
	result := newExecutor(t, primitive).Execute(context.Background(), job(t, "jellyfin"), gateOpen)
	if result.StdoutTail == nil || !strings.Contains(*result.StdoutTail, "proc-out "+Redacted) {
		t.Fatalf("process output missing or unredacted: %+v", result.StdoutTail)
	}

	closed := StepContext{Gate: func() error { return errors.New("deadline") }, processes: runner(t)}
	if _, err := closed.Run(context.Background(), helperCommand(t, "sleep")); err == nil || errors.Is(err, ErrExecutableNotAllowed) {
		t.Fatalf("a closed gate must prevent the process, got %v", err)
	}
}
