package agent

import (
	"context"
	"errors"
	"os"
	"path/filepath"
	"sync"
	"sync/atomic"
	"testing"
	"time"

	"github.com/muckiware/MuckiSafeAgentPlugin/agent/internal/api"
	"github.com/muckiware/MuckiSafeAgentPlugin/agent/internal/idempotency"
)

func TestFreshJobIsJournalledBeforeTheFirstHostEffectAndCompletedOnce(t *testing.T) {
	server := newServer(time.Minute, time.Minute+5*time.Second)
	journal := openJournal(t)
	executor := &executorFunc{run: func(ctx context.Context, job Job, gate StepGate) api.CompletionResult {
		record, err := journal.Load(job.ID)
		if err != nil || record.State != idempotency.StateStarted || record.LeaseVersion != 1 || record.ActionHash != actionHash {
			t.Errorf("started record missing before host effect: %+v %v", record, err)
		}
		if job.DefinitionCanonical == "" || job.Parameters == nil {
			t.Errorf("job snapshot incomplete: %+v", job)
		}
		return succeed(ctx, job, gate)
	}}
	server.onComplete = func() {
		record, err := journal.Load(jobID)
		if err != nil || record.State != idempotency.StateCompleted || record.Completion.Outcome != "succeeded" {
			t.Errorf("completed record must be persisted before completion is sent: %+v %v", record, err)
		}
	}

	if err := newRunner(t, server, journal, executor).Run(context.Background(), server.job("non_retryable")); err != nil {
		t.Fatal(err)
	}

	if executor.count() != 1 || onlyCompletion(t, server).Outcome != "succeeded" {
		t.Fatalf("executions %d", executor.count())
	}
	if _, err := journal.Load(jobID); !errors.Is(err, idempotency.ErrNotFound) {
		t.Fatalf("accepted job must leave the journal: %v", err)
	}
}

func TestCompletionNetworkFailuresAreRetriedIdenticallyWithoutReExecution(t *testing.T) {
	server := newServer(time.Minute, time.Minute+5*time.Second)
	server.completeErrs = []error{&api.TransportError{Err: errors.New("reset")}, &api.UntrustedResponseError{Status: 502}}
	journal := openJournal(t)
	executor := &executorFunc{run: func(context.Context, Job, StepGate) api.CompletionResult {
		tail, encoding := "exit 3", "utf-8"
		return api.CompletionResult{Outcome: "failed", ResultCode: "step_failed", Summary: "x", StderrTail: &tail, StderrEncoding: &encoding}
	}}

	if err := newRunner(t, server, journal, executor).Run(context.Background(), server.job("non_retryable")); err != nil {
		t.Fatal(err)
	}

	completions, _, _ := server.snapshot()
	if executor.count() != 1 || len(completions) != 3 || onlyCompletion(t, server).Outcome != "failed" {
		t.Fatalf("executions %d, completions %d", executor.count(), len(completions))
	}
}

func TestCompletedRecordOnRestartOnlyRepeatsTheCompletion(t *testing.T) {
	server := newServer(time.Minute, time.Minute+5*time.Second)
	server.status = "running" // the crashed agent had started the job
	journal := openJournal(t)
	record := startedRecord(server, "non_retryable")
	record.State = idempotency.StateCompleted
	record.Completion = &api.CompletionResult{Outcome: "succeeded", ResultCode: "ok", Summary: "done before crash"}
	if err := journal.Write(record); err != nil {
		t.Fatal(err)
	}
	executor := &executorFunc{run: succeed}

	if err := newRunner(t, server, journal, executor).Recover(context.Background()); err != nil {
		t.Fatal(err)
	}

	if executor.count() != 0 || onlyCompletion(t, server).Summary != "done before crash" {
		t.Fatalf("executions %d", executor.count())
	}
	if _, err := journal.Load(jobID); !errors.Is(err, idempotency.ErrNotFound) {
		t.Fatal("record must be removed after acceptance")
	}
}

func TestStartedNonRetryableRecordOnRestartBecomesAmbiguous(t *testing.T) {
	server := newServer(time.Minute, time.Minute+5*time.Second)
	server.status = "running"
	journal := openJournal(t)
	if err := journal.Write(startedRecord(server, "non_retryable")); err != nil {
		t.Fatal(err)
	}
	executor := &executorFunc{run: succeed}

	if err := newRunner(t, server, journal, executor).Recover(context.Background()); err != nil {
		t.Fatal(err)
	}

	result := onlyCompletion(t, server)
	if executor.count() != 0 || result.Outcome != "ambiguous" || result.StdoutTail != nil {
		t.Fatalf("executions %d, result %+v", executor.count(), result)
	}
}

func TestStartedSafeRecordOnRestartMayRunAgainWhileTheLeaseHolds(t *testing.T) {
	server := newServer(time.Minute, time.Minute+5*time.Second)
	server.status = "running"
	journal := openJournal(t)
	if err := journal.Write(startedRecord(server, "safe")); err != nil {
		t.Fatal(err)
	}
	executor := &executorFunc{run: succeed}

	if err := newRunner(t, server, journal, executor).Recover(context.Background()); err != nil {
		t.Fatal(err)
	}
	if executor.count() != 1 || onlyCompletion(t, server).Outcome != "succeeded" {
		t.Fatalf("executions %d", executor.count())
	}
}

func TestStartedSafeRecordWithALostLeaseIsDroppedWithoutExecution(t *testing.T) {
	server := newServer(time.Minute, time.Minute+5*time.Second)
	server.status = "unknown"
	journal := openJournal(t)
	if err := journal.Write(startedRecord(server, "safe")); err != nil {
		t.Fatal(err)
	}
	executor := &executorFunc{run: succeed}

	if err := newRunner(t, server, journal, executor).Recover(context.Background()); err != nil {
		t.Fatal(err)
	}
	if executor.count() != 0 {
		t.Fatal("a fenced job must never run again")
	}
	if _, err := journal.Load(jobID); !errors.Is(err, idempotency.ErrNotFound) {
		t.Fatal("the fenced record must be dropped")
	}
}

func TestJournalConflictsOnClaimEndAmbiguousWithoutHostEffect(t *testing.T) {
	cases := map[string]func(t *testing.T, server *fakeServer, journal *idempotency.Journal) string{
		"action hash mismatch": func(t *testing.T, server *fakeServer, journal *idempotency.Journal) string {
			record := startedRecord(server, "safe")
			record.DefinitionCanonical = `{"steps":[{}],"version":1}`
			record.ActionHash = hashOf(record.DefinitionCanonical)
			mustWrite(t, journal, record)
			return "safe"
		},
		"started under an older lease": func(t *testing.T, server *fakeServer, journal *idempotency.Journal) string {
			mustWrite(t, journal, startedRecord(server, "non_retryable"))
			server.lease = 2
			return "non_retryable"
		},
		"corrupt record": func(t *testing.T, server *fakeServer, journal *idempotency.Journal) string {
			record := startedRecord(server, "safe")
			mustWrite(t, journal, record)
			corrupt(t, journal)
			return "safe"
		},
	}
	for name, prepare := range cases {
		t.Run(name, func(t *testing.T) {
			server := newServer(time.Minute, time.Minute+5*time.Second)
			journal := openJournal(t)
			class := prepare(t, server, journal)
			executor := &executorFunc{run: succeed}

			if err := newRunner(t, server, journal, executor).Run(context.Background(), server.job(class)); err != nil {
				t.Fatal(err)
			}
			result := onlyCompletion(t, server)
			if executor.count() != 0 || result.Outcome != "ambiguous" {
				t.Fatalf("executions %d, result %+v", executor.count(), result)
			}
		})
	}
}

func TestSafeJobStartedUnderAnOlderLeaseRunsAgain(t *testing.T) {
	server := newServer(time.Minute, time.Minute+5*time.Second)
	journal := openJournal(t)
	mustWrite(t, journal, startedRecord(server, "safe"))
	server.lease = 2
	executor := &executorFunc{run: succeed}

	if err := newRunner(t, server, journal, executor).Run(context.Background(), server.job("safe")); err != nil {
		t.Fatal(err)
	}
	completions, _, _ := server.snapshot()
	if executor.count() != 1 || completions[0].LeaseVersion != 2 {
		t.Fatalf("executions %d, completions %+v", executor.count(), completions)
	}
}

func TestExecutionDeadlineIsEnforcedLocallyDespiteNetworkLoss(t *testing.T) {
	server := newServer(time.Second, 3*time.Second)
	server.heartbeatErr = func(int) error { return &api.TransportError{Err: errors.New("network down")} }
	journal := openJournal(t)
	executor := &executorFunc{run: untilCancelled}
	server.onComplete = func() {
		record, err := journal.Load(jobID)
		if err != nil || record.State != idempotency.StateCompleted || record.Completion.Outcome != "timed_out" {
			t.Errorf("timeout must be journalled before completion: %+v %v", record, err)
		}
	}

	start := time.Now()
	if err := newRunner(t, server, journal, executor).Run(context.Background(), server.job("non_retryable")); err != nil {
		t.Fatal(err)
	}
	result := onlyCompletion(t, server)
	if result.Outcome != "timed_out" || result.ResultCode != "execution_deadline" || result.StderrTail == nil {
		t.Fatalf("result %+v", result)
	}
	if elapsed := time.Since(start); elapsed > 2500*time.Millisecond {
		t.Fatalf("deadline enforced too late: %s", elapsed)
	}
	if _, heartbeats, _ := server.snapshot(); heartbeats < 3 {
		t.Fatalf("heartbeats must continue despite failures, got %d", heartbeats)
	}
}

func TestStopRequestedCutsTheExecutionShort(t *testing.T) {
	server := newServer(time.Minute, time.Minute+5*time.Second)
	server.heartbeatResp = func(n int) *api.JobHeartbeatResponse {
		if n < 2 {
			return nil
		}
		return &api.JobHeartbeatResponse{LeaseVersion: 1, LeaseExpiresAt: wire(serverEpoch.Add(30 * time.Second)),
			StopRequested: true, StopDeadline: wire(server.now().Add(5 * time.Second))}
	}
	executor := &executorFunc{run: untilCancelled}

	start := time.Now()
	if err := newRunner(t, server, openJournal(t), executor).Run(context.Background(), server.job("non_retryable")); err != nil {
		t.Fatal(err)
	}
	result := onlyCompletion(t, server)
	if result.Outcome != "timed_out" || result.ResultCode != "stop_requested" || time.Since(start) > 2*time.Second {
		t.Fatalf("result %+v after %s", result, time.Since(start))
	}
}

func TestLostLeaseStopsTheExecution(t *testing.T) {
	server := newServer(time.Minute, time.Minute+5*time.Second)
	server.heartbeatErr = func(n int) error {
		if n >= 2 {
			return conflict()
		}
		return nil
	}
	executor := &executorFunc{run: untilCancelled}
	done := make(chan error, 1)
	go func() {
		done <- newRunner(t, server, openJournal(t), executor).Run(context.Background(), server.job("non_retryable"))
	}()
	select {
	case <-done:
	case <-time.After(2 * time.Second):
		t.Fatal("execution was not stopped after the lease was lost")
	}
}

func TestNoStepBeginsAfterTheExecutionDeadline(t *testing.T) {
	server := newServer(time.Second, 3*time.Second)
	var afterDeadline atomic.Int32
	var deadlinePassed atomic.Bool
	go func() {
		time.Sleep(1200 * time.Millisecond)
		deadlinePassed.Store(true)
	}()
	executor := &executorFunc{run: func(ctx context.Context, _ Job, gate StepGate) api.CompletionResult {
		for {
			if err := gate(); err != nil {
				return api.CompletionResult{Outcome: "failed", ResultCode: "gate_closed", Summary: err.Error()}
			}
			if deadlinePassed.Load() {
				afterDeadline.Add(1)
			}
			time.Sleep(20 * time.Millisecond) // a step that ignores cancellation
		}
	}}

	if err := newRunner(t, server, openJournal(t), executor).Run(context.Background(), server.job("non_retryable")); err != nil {
		t.Fatal(err)
	}
	if afterDeadline.Load() != 0 || onlyCompletion(t, server).Outcome != "timed_out" {
		t.Fatalf("%d steps began after the deadline", afterDeadline.Load())
	}
}

func TestUnterminatedProcessEndsAmbiguousAtTheStopDeadline(t *testing.T) {
	server := newServer(time.Second, 2*time.Second)
	release := make(chan struct{})
	defer close(release)
	executor := &executorFunc{run: func(context.Context, Job, StepGate) api.CompletionResult {
		<-release // D-state: ignores every signal
		return api.CompletionResult{Outcome: "succeeded", ResultCode: "ok", Summary: "late"}
	}}

	start := time.Now()
	if err := newRunner(t, server, openJournal(t), executor).Run(context.Background(), server.job("safe")); err != nil {
		t.Fatal(err)
	}
	result := onlyCompletion(t, server)
	if result.Outcome != "ambiguous" || result.ResultCode != "process_not_terminated" {
		t.Fatalf("result %+v", result)
	}
	if elapsed := time.Since(start); elapsed > 3500*time.Millisecond {
		t.Fatalf("runner waited too long: %s", elapsed)
	}
}

func TestOnlyOneJobRunsAtATime(t *testing.T) {
	server := newServer(time.Minute, time.Minute+5*time.Second)
	running := make(chan struct{})
	release := make(chan struct{})
	executor := &executorFunc{run: func(ctx context.Context, job Job, gate StepGate) api.CompletionResult {
		close(running)
		<-release
		return succeed(ctx, job, gate)
	}}
	runner := newRunner(t, server, openJournal(t), executor)

	var wg sync.WaitGroup
	wg.Add(1)
	go func() {
		defer wg.Done()
		_ = runner.Run(context.Background(), server.job("safe"))
	}()
	<-running
	if err := runner.Run(context.Background(), server.job("safe")); !errorsIsBusy(err) {
		t.Fatalf("expected ErrBusy, got %v", err)
	}
	if err := runner.Recover(context.Background()); !errorsIsBusy(err) {
		t.Fatalf("recovery must not overlap a running job, got %v", err)
	}
	close(release)
	wg.Wait()
}

func TestGracefulShutdownKeepsHeartbeatingAndStopsWithinTheBudget(t *testing.T) {
	server := newServer(time.Minute, time.Minute+5*time.Second)
	journal := openJournal(t)
	executor := &executorFunc{run: untilCancelled}
	shutdown, stop := context.WithCancel(context.Background())
	var heartbeatsAtShutdown int
	go func() {
		time.Sleep(200 * time.Millisecond)
		_, heartbeatsAtShutdown, _ = server.snapshot()
		stop()
	}()

	start := time.Now()
	if err := newRunner(t, server, journal, executor).Run(shutdown, server.job("non_retryable")); err != nil {
		t.Fatal(err)
	}
	elapsed := time.Since(start)
	result := onlyCompletion(t, server)
	_, heartbeats, _ := server.snapshot()
	if result.Outcome != "timed_out" || result.ResultCode != "agent_shutdown" {
		t.Fatalf("result %+v", result)
	}
	if heartbeats <= heartbeatsAtShutdown {
		t.Fatalf("heartbeats stopped at shutdown (%d -> %d)", heartbeatsAtShutdown, heartbeats)
	}
	if elapsed < 450*time.Millisecond || elapsed > 1500*time.Millisecond {
		t.Fatalf("job must run for the shutdown grace and stop after it, took %s", elapsed)
	}
}

func TestShutdownWithUnreachableServerKeepsTheCompletedJournal(t *testing.T) {
	server := newServer(time.Minute, time.Minute+5*time.Second)
	for i := 0; i < 1000; i++ {
		server.completeErrs = append(server.completeErrs, &api.TransportError{Err: errors.New("down")})
	}
	journal := openJournal(t)
	shutdown, stop := context.WithCancel(context.Background())
	executor := &executorFunc{run: func(ctx context.Context, job Job, gate StepGate) api.CompletionResult {
		stop()
		return succeed(ctx, job, gate)
	}}

	if err := newRunner(t, server, journal, executor).Run(shutdown, server.job("non_retryable")); err != nil {
		t.Fatal(err)
	}
	record, err := journal.Load(jobID)
	if err != nil || record.State != idempotency.StateCompleted {
		t.Fatalf("completed record must survive for the next start: %+v %v", record, err)
	}
}

func startedRecord(server *fakeServer, class string) idempotency.Record {
	job := server.job(class)
	return idempotency.Record{
		JobID: jobID, Phase: "pre", DefinitionCanonical: definition, ActionHash: actionHash, Parameters: map[string]any{},
		IdempotencyClass: class, ClaimToken: token, LeaseVersion: server.lease,
		PhaseDeadline: job.PhaseDeadline, ExecutionDeadline: job.ExecutionDeadline, StopDeadline: job.StopDeadline,
		State: idempotency.StateStarted,
	}
}

func mustWrite(t *testing.T, journal *idempotency.Journal, record idempotency.Record) {
	t.Helper()
	if err := journal.Write(record); err != nil {
		t.Fatal(err)
	}
}

// corrupt makes the stored record untrustworthy by widening its mode.
func corrupt(t *testing.T, journal *idempotency.Journal) {
	t.Helper()
	if err := os.Chmod(filepath.Join(journalDirs[journal], "jobs", jobID+".json"), 0o644); err != nil {
		t.Fatal(err)
	}
	if _, err := journal.Load(jobID); !errors.Is(err, idempotency.ErrCorrupt) {
		t.Fatalf("expected a corrupt record, got %v", err)
	}
}
