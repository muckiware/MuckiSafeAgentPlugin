package agent

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"io"
	"log/slog"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/muckiware/MuckiSafeAgentPlugin/agent/internal/api"
	"github.com/muckiware/MuckiSafeAgentPlugin/agent/internal/idempotency"
)

const (
	jobID      = "0190c3a4-5b6e-7f80-9a1b-2c3d4e5f6a7b"
	token      = "Tx3IBqFEOrmbYYXG-3ry1Cq-8GW8waH3hgKndGrL-dw"
	definition = `{"steps":[],"version":1}`
)

var actionHash = hashOf(definition)

func hashOf(canonical string) string {
	sum := sha256.Sum256([]byte(canonical))
	return hex.EncodeToString(sum[:])
}

var serverEpoch = time.Date(2030, 1, 1, 0, 0, 0, 0, time.UTC)

func wire(t time.Time) string { return t.UTC().Format(time.RFC3339) }

// fakeServer is an in-memory control plane with the fencing and completion
// rules of JobTransitionGuard/JobCompletion and injectable failures.
type fakeServer struct {
	mu        sync.Mutex
	started   time.Time
	status    string
	lease     int64
	queue     []api.ClaimedJob
	execAfter time.Duration
	stopAfter time.Duration

	heartbeatErr  func(n int) error
	heartbeatResp func(n int) *api.JobHeartbeatResponse
	completeErrs  []error
	claimErr      error

	heartbeats  int
	starts      int
	completions []api.JobCompleteRequest
	accepted    *api.CompletionResult
	onComplete  func()
}

func newServer(execAfter, stopAfter time.Duration) *fakeServer {
	return &fakeServer{started: time.Now(), status: "claimed", lease: 1, execAfter: execAfter, stopAfter: stopAfter}
}

func (s *fakeServer) now() time.Time {
	return serverEpoch.Add(time.Since(s.started)).Truncate(time.Second)
}

func (s *fakeServer) job(class string) api.ClaimedJob {
	return api.ClaimedJob{
		ID: jobID, ClaimToken: token, LeaseVersion: s.lease,
		LeaseExpiresAt:    wire(serverEpoch.Add(30 * time.Second)),
		PhaseDeadline:     wire(serverEpoch.Add(s.stopAfter + 10*time.Second)),
		ExecutionDeadline: wire(serverEpoch.Add(s.execAfter)),
		StopDeadline:      wire(serverEpoch.Add(s.stopAfter)),
		Phase:             "pre", IdempotencyClass: class,
		Action:     api.ClaimedAction{DefinitionCanonical: definition, ActionHash: actionHash},
		Parameters: map[string]any{},
	}
}

func conflict() error { return &api.APIError{Status: 409, Code: api.CodeClaimConflict} }

func (s *fakeServer) fenced(id string, lease api.JobLeaseRequest) bool {
	return id == jobID && lease.ClaimToken == token && lease.LeaseVersion == s.lease
}

func (s *fakeServer) Claim(context.Context) (api.ClaimResponse, error) {
	s.mu.Lock()
	defer s.mu.Unlock()
	if s.claimErr != nil {
		return api.ClaimResponse{}, s.claimErr
	}
	if len(s.queue) == 0 {
		return api.ClaimResponse{}, nil
	}
	job := s.queue[0]
	s.queue = s.queue[1:]
	s.status = "claimed"
	return api.ClaimResponse{Job: &job}, nil
}

func (s *fakeServer) StartJob(_ context.Context, id string, lease api.JobLeaseRequest) (api.JobStartResponse, api.ResponseMeta, error) {
	s.mu.Lock()
	defer s.mu.Unlock()
	s.starts++
	if !s.fenced(id, lease) || s.status != "claimed" {
		return api.JobStartResponse{}, api.ResponseMeta{}, conflict()
	}
	s.status = "running"
	return api.JobStartResponse{
		LeaseVersion: s.lease, LeaseExpiresAt: wire(s.now().Add(30 * time.Second)),
		PhaseDeadline:     wire(serverEpoch.Add(s.stopAfter + 10*time.Second)),
		ExecutionDeadline: wire(serverEpoch.Add(s.execAfter)),
		StopDeadline:      wire(serverEpoch.Add(s.stopAfter)),
	}, api.ResponseMeta{ServerTime: s.now()}, nil
}

func (s *fakeServer) JobHeartbeat(_ context.Context, id string, lease api.JobLeaseRequest) (api.JobHeartbeatResponse, api.ResponseMeta, error) {
	s.mu.Lock()
	defer s.mu.Unlock()
	s.heartbeats++
	n := s.heartbeats
	if s.heartbeatErr != nil {
		if err := s.heartbeatErr(n); err != nil {
			return api.JobHeartbeatResponse{}, api.ResponseMeta{}, err
		}
	}
	if !s.fenced(id, lease) || (s.status != "running" && s.status != "cancel_requested") {
		return api.JobHeartbeatResponse{}, api.ResponseMeta{}, conflict()
	}
	if s.heartbeatResp != nil {
		if resp := s.heartbeatResp(n); resp != nil {
			if resp.StopRequested {
				s.status = "cancel_requested"
			}
			return *resp, api.ResponseMeta{ServerTime: s.now()}, nil
		}
	}
	return api.JobHeartbeatResponse{LeaseVersion: s.lease, LeaseExpiresAt: wire(s.now().Add(30 * time.Second))},
		api.ResponseMeta{ServerTime: s.now()}, nil
}

func (s *fakeServer) CompleteJob(_ context.Context, id string, request api.JobCompleteRequest) (api.JobCompleteResponse, error) {
	s.mu.Lock()
	if s.onComplete != nil {
		s.mu.Unlock()
		s.onComplete()
		s.mu.Lock()
	}
	defer s.mu.Unlock()
	s.completions = append(s.completions, request)
	if len(s.completeErrs) > 0 {
		err := s.completeErrs[0]
		s.completeErrs = s.completeErrs[1:]
		if err != nil {
			return api.JobCompleteResponse{}, err
		}
	}
	if err := request.CompletionResult.Validate(); err != nil {
		return api.JobCompleteResponse{}, &api.APIError{Status: 400, Code: api.CodePayloadInvalid}
	}
	if s.accepted != nil {
		if mustJSON(*s.accepted) != mustJSON(request.CompletionResult) {
			return api.JobCompleteResponse{}, conflict()
		}
		return api.JobCompleteResponse{Accepted: true, TerminalStatus: terminal(request.Outcome)}, nil
	}
	if !s.fenced(id, request.JobLeaseRequest) || (s.status != "running" && s.status != "cancel_requested") {
		return api.JobCompleteResponse{}, conflict()
	}
	result := request.CompletionResult
	s.accepted = &result
	s.status = terminal(request.Outcome)
	return api.JobCompleteResponse{Accepted: true, TerminalStatus: s.status}, nil
}

func (s *fakeServer) HostHeartbeat(context.Context, api.HostHeartbeatRequest) (api.HostHeartbeatResponse, error) {
	return api.HostHeartbeatResponse{ServerTime: wire(s.now()), PollAfterSeconds: 10}, nil
}

func terminal(outcome string) string {
	if outcome == "ambiguous" {
		return "unknown"
	}
	return outcome
}

func mustJSON(v any) string {
	raw, err := json.Marshal(v)
	if err != nil {
		panic(err)
	}
	return string(raw)
}

func (s *fakeServer) snapshot() (completions []api.JobCompleteRequest, heartbeats, starts int) {
	s.mu.Lock()
	defer s.mu.Unlock()
	return append([]api.JobCompleteRequest(nil), s.completions...), s.heartbeats, s.starts
}

// executorFunc adapts a function to the Executor interface and counts calls.
type executorFunc struct {
	mu    sync.Mutex
	calls int
	run   func(ctx context.Context, job Job, gate StepGate) api.CompletionResult
}

func (e *executorFunc) Execute(ctx context.Context, job Job, gate StepGate) api.CompletionResult {
	e.mu.Lock()
	e.calls++
	e.mu.Unlock()
	return e.run(ctx, job, gate)
}

func (e *executorFunc) count() int {
	e.mu.Lock()
	defer e.mu.Unlock()
	return e.calls
}

func succeed(context.Context, Job, StepGate) api.CompletionResult {
	return api.CompletionResult{Outcome: "succeeded", ResultCode: "ok", Summary: "done"}
}

// untilCancelled blocks like a running process until the runner stops it.
func untilCancelled(ctx context.Context, _ Job, _ StepGate) api.CompletionResult {
	<-ctx.Done()
	tail, encoding := "terminated", "utf-8"
	return api.CompletionResult{Outcome: "failed", ResultCode: "signal", Summary: "terminated", StderrTail: &tail, StderrEncoding: &encoding}
}

func openJournal(t *testing.T) *idempotency.Journal {
	t.Helper()
	base, err := filepath.EvalSymlinks(t.TempDir())
	if err != nil {
		t.Fatal(err)
	}
	dir := filepath.Join(base, "state")
	if err := os.Mkdir(dir, 0o700); err != nil {
		t.Fatal(err)
	}
	j, err := idempotency.Open(dir)
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { _ = j.Close() })
	journalDirs[j] = dir
	return j
}

// journalDirs remembers the state directory of each test journal.
var journalDirs = map[*idempotency.Journal]string{}

func newRunner(t *testing.T, server *fakeServer, journal *idempotency.Journal, executor Executor) *Runner {
	t.Helper()
	runner, err := NewRunner(RunnerConfig{
		API: server, Journal: journal, Executor: executor,
		Logger:            slog.New(slog.NewTextHandler(io.Discard, nil)),
		HeartbeatInterval: 50 * time.Millisecond,
		ShutdownGrace:     300 * time.Millisecond,
		CompletionBackoff: api.Backoff{Initial: 10 * time.Millisecond, Max: 20 * time.Millisecond, Multiplier: 2},
	})
	if err != nil {
		t.Fatal(err)
	}
	return runner
}

func onlyCompletion(t *testing.T, server *fakeServer) api.CompletionResult {
	t.Helper()
	completions, _, _ := server.snapshot()
	if len(completions) == 0 {
		t.Fatal("no completion sent")
	}
	for _, c := range completions[1:] {
		if mustJSON(c) != mustJSON(completions[0]) {
			t.Fatalf("completion retries differ: %s vs %s", mustJSON(c), mustJSON(completions[0]))
		}
	}
	return completions[0].CompletionResult
}

func errorsIsBusy(err error) bool { return errors.Is(err, ErrBusy) }

func containsAll(s string, parts ...string) bool {
	for _, p := range parts {
		if !strings.Contains(s, p) {
			return false
		}
	}
	return true
}
