// Package agent runs claimed jobs: it journals each job before the first host
// effect, enforces the signed deadlines with local monotonic timers, keeps the
// lease alive and reports exactly the journalled result to the control plane.
//
// Exactly-once host effects are not promised. When the journal cannot prove
// that repeating is safe, the job ends `ambiguous` (server status `unknown`)
// instead of running again.
package agent

import (
	"context"
	"errors"
	"fmt"
	"log/slog"
	"time"

	"github.com/muckiware/MuckiSafeAgentPlugin/agent/internal/api"
	"github.com/muckiware/MuckiSafeAgentPlugin/agent/internal/idempotency"
)

// Job is the executor's view of a claimed job.
type Job struct {
	ID                  string
	Phase               string
	IdempotencyClass    string
	ActionHash          string
	DefinitionCanonical string
	Parameters          map[string]any
}

// StepGate must be called before every step and every external process; a
// non-nil error means no new host effect may begin.
type StepGate func() error

// Executor performs the action of a job. It must consult the gate before each
// step and, once ctx is cancelled, terminate its process group (SIGTERM, at
// most 5 seconds grace, SIGKILL) and return promptly.
type Executor interface {
	Execute(ctx context.Context, job Job, gate StepGate) api.CompletionResult
}

// API is the part of *api.Client used by the runner and the loops.
type API interface {
	Claim(ctx context.Context) (api.ClaimResponse, error)
	StartJob(ctx context.Context, jobID string, lease api.JobLeaseRequest) (api.JobStartResponse, api.ResponseMeta, error)
	JobHeartbeat(ctx context.Context, jobID string, lease api.JobLeaseRequest) (api.JobHeartbeatResponse, api.ResponseMeta, error)
	CompleteJob(ctx context.Context, jobID string, request api.JobCompleteRequest) (api.JobCompleteResponse, error)
	HostHeartbeat(ctx context.Context, request api.HostHeartbeatRequest) (api.HostHeartbeatResponse, error)
}

// Journal is the part of *idempotency.Journal used by the runner.
type Journal interface {
	Load(jobID string) (idempotency.Record, error)
	Write(record idempotency.Record) error
	Remove(jobID string) error
	List() ([]string, error)
}

// ErrBusy is returned while a job runs; V1 enforces maxConcurrency=1 locally.
var ErrBusy = errors.New("agent: a job is already running (maxConcurrency=1)")

// RunnerConfig configures a Runner. Zero durations take the V1 defaults.
type RunnerConfig struct {
	API      API
	Journal  Journal
	Executor Executor
	Logger   *slog.Logger
	// HeartbeatInterval is the lease heartbeat period (default 10s).
	HeartbeatInterval time.Duration
	// ShutdownGrace is how long a running job may continue after shutdown
	// began before it is stopped; it must fit the systemd stop budget
	// together with StopGrace and the completion (default 60s).
	ShutdownGrace time.Duration
	// StopGrace bounds the wait for the executor after a stop: SIGTERM,
	// 5 seconds grace, SIGKILL (default 6s, never beyond the stop deadline).
	StopGrace time.Duration
	// CompletionBackoff paces start and completion retries.
	CompletionBackoff api.Backoff
}

// Runner executes one claimed job at a time.
type Runner struct {
	cfg    RunnerConfig
	logger *slog.Logger
	slot   chan struct{}
}

// NewRunner validates the dependencies and applies defaults.
func NewRunner(cfg RunnerConfig) (*Runner, error) {
	if cfg.API == nil || cfg.Journal == nil || cfg.Executor == nil {
		return nil, errors.New("agent: runner needs API, journal and executor")
	}
	if cfg.HeartbeatInterval <= 0 {
		cfg.HeartbeatInterval = 10 * time.Second
	}
	if cfg.ShutdownGrace <= 0 {
		cfg.ShutdownGrace = 60 * time.Second
	}
	if cfg.StopGrace <= 0 {
		cfg.StopGrace = 6 * time.Second
	}
	if cfg.CompletionBackoff.Initial <= 0 {
		cfg.CompletionBackoff = api.DefaultBackoff()
	}
	logger := cfg.Logger
	if logger == nil {
		logger = slog.Default()
	}
	return &Runner{cfg: cfg, logger: logger, slot: make(chan struct{}, 1)}, nil
}

func (r *Runner) acquire() bool {
	select {
	case r.slot <- struct{}{}:
		return true
	default:
		return false
	}
}

func (r *Runner) release() { <-r.slot }

// plan is the journal-based decision for a claimed job.
type plan int

const (
	planExecute plan = iota
	planResend
	planAmbiguous
)

// decide maps the journal state of a freshly claimed job to an action.
func decide(record idempotency.Record, loadErr error, claimed api.ClaimedJob) (plan, string) {
	switch {
	case errors.Is(loadErr, idempotency.ErrNotFound):
		return planExecute, ""
	case loadErr != nil:
		return planAmbiguous, "journal_corrupt"
	case record.ActionHash != claimed.Action.ActionHash:
		return planAmbiguous, "journal_action_mismatch"
	case record.State == idempotency.StateCompleted:
		return planResend, ""
	case claimed.IdempotencyClass == "safe" && record.IdempotencyClass == "safe":
		return planExecute, ""
	default:
		return planAmbiguous, "journal_started"
	}
}

// Run handles one claimed job until its completion was accepted, rejected or
// left in the journal for the next start. shutdown only limits how long the
// job may keep running; heartbeats and journalling continue after it ends.
func (r *Runner) Run(shutdown context.Context, claimed api.ClaimedJob) error {
	if !r.acquire() {
		return ErrBusy
	}
	defer r.release()

	record, loadErr := r.cfg.Journal.Load(claimed.ID)
	decision, code := decide(record, loadErr, claimed)
	if loadErr != nil && !errors.Is(loadErr, idempotency.ErrNotFound) {
		r.logger.Error("journal record of claimed job is not trustworthy, job ends ambiguous", "job", claimed.ID, "error", loadErr)
	}
	lease := api.JobLeaseRequest{ClaimToken: claimed.ClaimToken, LeaseVersion: claimed.LeaseVersion}
	started, meta, err := r.start(shutdown, claimed.ID, lease)
	if err != nil {
		r.logger.Warn("claimed job not started, no host effect happened", "job", claimed.ID, "error", err)
		return nil
	}
	lease.LeaseVersion = started.LeaseVersion
	deadlines, err := calibrate(meta.ServerTime, started.ExecutionDeadline, started.StopDeadline, started.PhaseDeadline)
	if err != nil {
		return fmt.Errorf("agent: job %s: %w", claimed.ID, err)
	}
	base := idempotency.Record{
		JobID: claimed.ID, Phase: claimed.Phase, DefinitionCanonical: claimed.Action.DefinitionCanonical,
		ActionHash: claimed.Action.ActionHash, Parameters: claimed.Parameters, IdempotencyClass: claimed.IdempotencyClass,
		ClaimToken: lease.ClaimToken, LeaseVersion: lease.LeaseVersion,
		PhaseDeadline: started.PhaseDeadline, ExecutionDeadline: started.ExecutionDeadline, StopDeadline: started.StopDeadline,
		State: idempotency.StateStarted,
	}

	var result api.CompletionResult
	switch decision {
	case planResend:
		result = *record.Completion
	case planAmbiguous:
		result = ambiguous(code, "The local journal does not allow a safe execution; no host effect was attempted.")
	default:
		result = r.journalAndExecute(shutdown, base, deadlines)
	}
	r.finish(shutdown, base, result, deadlines.phase)
	return nil
}

func (r *Runner) journalAndExecute(shutdown context.Context, base idempotency.Record, deadlines deadlines) api.CompletionResult {
	if err := r.cfg.Journal.Write(base); err != nil {
		r.logger.Error("cannot journal job start, refusing to execute", "job", base.JobID, "error", err)
		return api.CompletionResult{Outcome: "failed", ResultCode: "journal_unavailable", Summary: "The agent journal could not be written; nothing was executed."}
	}
	return r.execute(shutdown, jobOf(base), api.JobLeaseRequest{ClaimToken: base.ClaimToken, LeaseVersion: base.LeaseVersion}, deadlines)
}

func (r *Runner) start(shutdown context.Context, jobID string, lease api.JobLeaseRequest) (api.JobStartResponse, api.ResponseMeta, error) {
	var started api.JobStartResponse
	var meta api.ResponseMeta
	backoff := r.cfg.CompletionBackoff
	backoff.MaxAttempts = 3
	err := backoff.Do(shutdown, func(ctx context.Context) error {
		var err error
		started, meta, err = r.cfg.API.StartJob(ctx, jobID, lease)
		return err
	})
	return started, meta, err
}

// finish journals the final result before sending it, so a lost completion is
// repeated verbatim after a restart and never causes a second host effect.
func (r *Runner) finish(shutdown context.Context, base idempotency.Record, result api.CompletionResult, giveUpAt time.Time) {
	result = result.ForWire()
	if err := result.Validate(); err != nil {
		r.logger.Error("executor returned an invalid result", "job", base.JobID, "error", err)
		result = api.CompletionResult{Outcome: "failed", ResultCode: "executor_result_invalid", Summary: "The executor returned a result outside the protocol."}.ForWire()
	}
	completed := base
	completed.State = idempotency.StateCompleted
	completed.Completion = &result
	if err := r.cfg.Journal.Write(completed); err != nil {
		r.logger.Error("cannot journal job result, completion is not repeatable after a restart", "job", base.JobID, "error", err)
	}
	r.complete(shutdown, base.JobID, api.JobLeaseRequest{ClaimToken: base.ClaimToken, LeaseVersion: base.LeaseVersion}, result, giveUpAt)
}

// complete repeats the identical completion until it is accepted or
// rejected, the phase deadline passed or shutdown began. The journal record
// is removed only once the server has decided.
func (r *Runner) complete(shutdown context.Context, jobID string, lease api.JobLeaseRequest, result api.CompletionResult, giveUpAt time.Time) {
	// ForWire again for results journaled by an agent without the mapping
	request := api.JobCompleteRequest{JobLeaseRequest: lease, CompletionResult: result.ForWire()}
	for attempt := 0; ; attempt++ {
		response, err := r.cfg.API.CompleteJob(context.Background(), jobID, request)
		switch {
		case err == nil:
			r.logger.Info("job completed", "job", jobID, "outcome", result.Outcome, "terminalStatus", response.TerminalStatus)
			r.forget(jobID)
			return
		case isConflict(err):
			r.logger.Warn("completion rejected, the control plane has decided the job", "job", jobID, "error", err)
			r.forget(jobID)
			return
		case !api.Retryable(err):
			r.logger.Error("completion failed permanently, record kept for the next start", "job", jobID, "error", err)
			return
		}
		r.logger.Warn("completion failed, retrying", "job", jobID, "error", err)
		if shutdown.Err() != nil {
			r.logger.Warn("shutting down, completion kept in the journal for the next start", "job", jobID)
			return
		}
		if time.Now().After(giveUpAt) {
			r.logger.Error("phase deadline passed without accepted completion", "job", jobID)
			r.forget(jobID)
			return
		}
		timer := time.NewTimer(r.cfg.CompletionBackoff.Delay(attempt))
		select {
		case <-timer.C:
		case <-shutdown.Done():
			timer.Stop()
		}
	}
}

func (r *Runner) forget(jobID string) {
	if err := r.cfg.Journal.Remove(jobID); err != nil {
		r.logger.Error("cannot remove journal record", "job", jobID, "error", err)
	}
}

func isConflict(err error) bool {
	var apiErr *api.APIError
	return errors.As(err, &apiErr) && apiErr.Code == api.CodeClaimConflict
}

func ambiguous(code, summary string) api.CompletionResult {
	return api.CompletionResult{Outcome: "ambiguous", ResultCode: code, Summary: summary}
}

func jobOf(record idempotency.Record) Job {
	return Job{
		ID: record.JobID, Phase: record.Phase, IdempotencyClass: record.IdempotencyClass, ActionHash: record.ActionHash,
		DefinitionCanonical: record.DefinitionCanonical, Parameters: record.Parameters,
	}
}

// deadlines are local monotonic instants derived from the signed wire values.
type deadlines struct {
	execution, stop, phase time.Time
}

// calibrate maps server deadlines onto the local monotonic clock using the
// signed server time of the same response, so local clock skew and later
// wall-clock jumps cannot extend them.
func calibrate(serverTime time.Time, execution, stop, phase string) (deadlines, error) {
	now := time.Now()
	var local [3]time.Time
	for i, value := range []string{execution, stop, phase} {
		parsed, err := time.Parse(time.RFC3339, value)
		if err != nil {
			return deadlines{}, fmt.Errorf("invalid deadline %q", value)
		}
		local[i] = now.Add(parsed.Sub(serverTime))
	}
	return deadlines{execution: local[0], stop: local[1], phase: local[2]}, nil
}
