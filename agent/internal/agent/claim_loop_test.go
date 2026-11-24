package agent

import (
	"context"
	"errors"
	"io"
	"log/slog"
	"sync/atomic"
	"testing"
	"time"

	"github.com/muckiware/MuckiSafeAgentPlugin/agent/internal/api"
)

// countingAPI counts claims on top of the fake server.
type countingAPI struct {
	*fakeServer
	claims atomic.Int32
}

func (c *countingAPI) Claim(ctx context.Context) (api.ClaimResponse, error) {
	c.claims.Add(1)
	return c.fakeServer.Claim(ctx)
}

func loop(t *testing.T, server API, runner *Runner, wake chan struct{}) *ClaimLoop {
	t.Helper()
	return &ClaimLoop{
		API: server, Runner: runner, Wake: wake,
		Backoff: api.Backoff{Initial: 5 * time.Millisecond, Max: 10 * time.Millisecond, Multiplier: 2, MaxAttempts: 3},
		Logger:  slog.New(slog.NewTextHandler(io.Discard, nil)),
	}
}

func runLoop(l *ClaimLoop, ctx context.Context) chan error {
	done := make(chan error, 1)
	go func() { done <- l.Run(ctx) }()
	return done
}

func TestWakeClaimsUntilTheQueueIsEmpty(t *testing.T) {
	server := newServer(time.Minute, time.Minute+5*time.Second)
	server.queue = []api.ClaimedJob{server.job("safe")}
	counting := &countingAPI{fakeServer: server}
	executor := &executorFunc{run: succeed}
	wake := make(chan struct{}, 1)
	ctx, cancel := context.WithCancel(context.Background())
	done := runLoop(loop(t, counting, newRunner(t, server, openJournal(t), executor), wake), ctx)

	wake <- struct{}{}
	waitFor(t, func() bool { return counting.claims.Load() >= 2 })
	cancel()
	if err := <-done; err != nil {
		t.Fatal(err)
	}
	if executor.count() != 1 || counting.claims.Load() != 2 {
		t.Fatalf("executions %d, claims %d", executor.count(), counting.claims.Load())
	}
}

func TestNoClaimWithoutWakeAndNoneAfterShutdown(t *testing.T) {
	server := newServer(time.Minute, time.Minute+5*time.Second)
	counting := &countingAPI{fakeServer: server}
	wake := make(chan struct{}, 1)
	ctx, cancel := context.WithCancel(context.Background())
	done := runLoop(loop(t, counting, newRunner(t, server, openJournal(t), &executorFunc{run: succeed}), wake), ctx)

	time.Sleep(50 * time.Millisecond)
	cancel()
	if err := <-done; err != nil {
		t.Fatal(err)
	}
	wake <- struct{}{}
	time.Sleep(50 * time.Millisecond)
	if counting.claims.Load() != 0 {
		t.Fatalf("claims %d", counting.claims.Load())
	}
}

func TestUnknownKeyStopsTheLoop(t *testing.T) {
	server := newServer(time.Minute, time.Minute+5*time.Second)
	server.claimErr = api.ErrUnauthenticated
	wake := make(chan struct{}, 1)
	wake <- struct{}{}

	select {
	case err := <-runLoop(loop(t, server, newRunner(t, server, openJournal(t), &executorFunc{run: succeed}), wake), context.Background()):
		if !errors.Is(err, api.ErrUnauthenticated) {
			t.Fatalf("expected terminal error, got %v", err)
		}
	case <-time.After(2 * time.Second):
		t.Fatal("loop did not stop")
	}
}

func TestTransientClaimFailuresWaitForTheNextWake(t *testing.T) {
	server := newServer(time.Minute, time.Minute+5*time.Second)
	server.claimErr = &api.TransportError{Err: errors.New("down")}
	counting := &countingAPI{fakeServer: server}
	wake := make(chan struct{}, 1)
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	done := runLoop(loop(t, counting, newRunner(t, server, openJournal(t), &executorFunc{run: succeed}), wake), ctx)

	wake <- struct{}{}
	waitFor(t, func() bool { return counting.claims.Load() == 3 })
	time.Sleep(50 * time.Millisecond)
	if counting.claims.Load() != 3 {
		t.Fatalf("expected the bounded retries only, got %d claims", counting.claims.Load())
	}
	select {
	case err := <-done:
		t.Fatalf("loop ended on a transient error: %v", err)
	default:
	}
}

func TestHostHeartbeatReportsImmediatelyAndStopsOnUnknownKey(t *testing.T) {
	var calls atomic.Int32
	api401 := &hostAPI{fakeServer: newServer(time.Minute, time.Minute), call: func(request api.HostHeartbeatRequest) error {
		calls.Add(1)
		if request.Platform != "linux" || request.AgentVersion == "" || request.Capabilities == nil {
			t.Errorf("request %+v", request)
		}
		if calls.Load() == 3 {
			return api.ErrUnauthenticated
		}
		return nil
	}}
	err := HostHeartbeat{API: api401, Interval: 10 * time.Millisecond}.Run(context.Background())
	if !errors.Is(err, api.ErrUnauthenticated) || calls.Load() != 3 {
		t.Fatalf("calls %d, err %v", calls.Load(), err)
	}
}

type hostAPI struct {
	*fakeServer
	call func(api.HostHeartbeatRequest) error
}

func (h *hostAPI) HostHeartbeat(_ context.Context, request api.HostHeartbeatRequest) (api.HostHeartbeatResponse, error) {
	return api.HostHeartbeatResponse{}, h.call(request)
}

func waitFor(t *testing.T, condition func() bool) {
	t.Helper()
	deadline := time.Now().Add(3 * time.Second)
	for !condition() {
		if time.Now().After(deadline) {
			t.Fatal("condition not reached")
		}
		time.Sleep(5 * time.Millisecond)
	}
}
