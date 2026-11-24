package executor

import (
	"context"
	"errors"
	"io"
	"log/slog"
	"os"
	"path/filepath"
	"strconv"
	"sync"
	"syscall"
	"testing"
	"time"

	"github.com/muckiware/MuckiSafeAgentPlugin/agent/internal/agent"
	"github.com/muckiware/MuckiSafeAgentPlugin/agent/internal/api"
	"github.com/muckiware/MuckiSafeAgentPlugin/agent/internal/idempotency"
)

// partitionedAPI starts jobs but loses every lease heartbeat, like a network
// partition after /start. Completion works again afterwards.
type partitionedAPI struct {
	mu          sync.Mutex
	epoch       time.Time
	completions []api.JobCompleteRequest
	onComplete  func()
}

func (p *partitionedAPI) Claim(context.Context) (api.ClaimResponse, error) {
	return api.ClaimResponse{}, nil
}

func (p *partitionedAPI) StartJob(context.Context, string, api.JobLeaseRequest) (api.JobStartResponse, api.ResponseMeta, error) {
	wire := func(d time.Duration) string { return p.epoch.Add(d).Format(time.RFC3339) }
	return api.JobStartResponse{LeaseVersion: 1, LeaseExpiresAt: wire(30 * time.Second), ExecutionDeadline: wire(time.Second),
		StopDeadline: wire(3 * time.Second), PhaseDeadline: wire(13 * time.Second)}, api.ResponseMeta{ServerTime: p.epoch}, nil
}

func (p *partitionedAPI) JobHeartbeat(context.Context, string, api.JobLeaseRequest) (api.JobHeartbeatResponse, api.ResponseMeta, error) {
	return api.JobHeartbeatResponse{}, api.ResponseMeta{}, &api.TransportError{Err: errors.New("network partition")}
}

func (p *partitionedAPI) CompleteJob(_ context.Context, _ string, request api.JobCompleteRequest) (api.JobCompleteResponse, error) {
	if p.onComplete != nil {
		p.onComplete()
	}
	p.mu.Lock()
	defer p.mu.Unlock()
	p.completions = append(p.completions, request)
	return api.JobCompleteResponse{Accepted: true, TerminalStatus: request.Outcome}, nil
}

func (p *partitionedAPI) HostHeartbeat(context.Context, api.HostHeartbeatRequest) (api.HostHeartbeatResponse, error) {
	return api.HostHeartbeatResponse{}, nil
}

// stubbornPrimitive starts a process that ignores SIGTERM and records its pid.
type stubbornPrimitive struct {
	t      *testing.T
	output *syncBuffer
}

func (s stubbornPrimitive) Run(ctx context.Context, step StepContext) error {
	cmd := helperCommand(s.t, "ignore-term-pid")
	cmd.Stdout = s.output
	_, err := step.Run(ctx, cmd)
	return err
}

func TestNetworkPartitionStillStopsAStubbornProcessAndJournalsWithinTheBuffer(t *testing.T) {
	base, err := filepath.EvalSymlinks(t.TempDir())
	if err != nil {
		t.Fatal(err)
	}
	stateDir := filepath.Join(base, "state")
	if err := os.Mkdir(stateDir, 0o700); err != nil {
		t.Fatal(err)
	}
	journal, err := idempotency.Open(stateDir)
	if err != nil {
		t.Fatal(err)
	}
	defer journal.Close()

	j := job(t, "jellyfin")
	server := &partitionedAPI{epoch: time.Date(2030, 1, 1, 0, 0, 0, 0, time.UTC)}
	server.onComplete = func() {
		record, err := journal.Load(j.ID)
		if err != nil || record.State != idempotency.StateCompleted {
			t.Errorf("result must be journalled before completion: %+v %v", record, err)
		}
	}
	output := &syncBuffer{}
	executor := newExecutor(t, stubbornPrimitive{t: t, output: output})
	logger := slog.New(slog.NewTextHandler(io.Discard, nil))
	runner, err := agent.NewRunner(agent.RunnerConfig{API: server, Journal: journal, Executor: executor, Logger: logger,
		HeartbeatInterval: 50 * time.Millisecond})
	if err != nil {
		t.Fatal(err)
	}

	start := time.Now()
	err = runner.Run(context.Background(), api.ClaimedJob{
		ID: j.ID, ClaimToken: "Tx3IBqFEOrmbYYXG-3ry1Cq-8GW8waH3hgKndGrL-dw", LeaseVersion: 1, Phase: "pre", IdempotencyClass: "safe",
		Action: api.ClaimedAction{DefinitionCanonical: j.DefinitionCanonical, ActionHash: j.ActionHash}, Parameters: map[string]any{},
	})
	elapsed := time.Since(start)
	if err != nil {
		t.Fatal(err)
	}
	if len(server.completions) != 1 {
		t.Fatalf("completions %d", len(server.completions))
	}
	result := server.completions[0].CompletionResult
	if result.Outcome != "timed_out" || result.ResultCode != "execution_deadline" {
		t.Fatalf("result %+v", result)
	}
	// execution deadline 1s + SIGTERM grace 0.3s + exit; far below the 3s stop deadline
	if elapsed > 2500*time.Millisecond {
		t.Fatalf("stop took %s", elapsed)
	}
	pid, err := strconv.Atoi(output.String())
	if err != nil {
		t.Fatalf("helper pid %q: %v", output.String(), err)
	}
	if syscall.Kill(pid, 0) == nil {
		_ = syscall.Kill(pid, syscall.SIGKILL)
		t.Fatal("the stubborn process survived the stop")
	}
}
