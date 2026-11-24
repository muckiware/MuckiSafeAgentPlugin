package agent

import (
	"context"
	"errors"
	"time"

	"github.com/muckiware/MuckiSafeAgentPlugin/agent/internal/api"
)

// Reasons for stopping an execution; each becomes the timed_out result code.
var (
	errExecutionDeadline = errors.New("execution deadline reached")
	errStopRequested     = errors.New("stop requested by the control plane")
	errLeaseLost         = errors.New("lease lost")
	errShutdown          = errors.New("agent shutdown")
)

var stopCodes = map[error]string{
	errExecutionDeadline: "execution_deadline",
	errStopRequested:     "stop_requested",
	errLeaseLost:         "lease_lost",
	errShutdown:          "agent_shutdown",
}

// execute runs the executor under the local deadline. Lease heartbeats run
// in their own goroutine, so neither a slow nor a failing control plane can
// delay or extend the deadline. After a stop the executor gets StopGrace,
// never more than the stop deadline; a process that still does not return
// (e.g. uninterruptible sleep) ends the job ambiguous.
func (r *Runner) execute(shutdown context.Context, job Job, lease api.JobLeaseRequest, deadlines deadlines) api.CompletionResult {
	execCtx, cancel := context.WithCancelCause(context.Background())
	defer cancel(nil)
	gate := func() error {
		if !time.Now().Before(deadlines.execution) {
			return errExecutionDeadline
		}
		return context.Cause(execCtx)
	}
	done := make(chan api.CompletionResult, 1)
	go func() { done <- r.cfg.Executor.Execute(execCtx, job, gate) }()

	beatCtx, stopBeats := context.WithCancel(context.Background())
	defer stopBeats()
	beats := r.leaseHeartbeats(beatCtx, job.ID, lease)

	deadline := time.NewTimer(time.Until(deadlines.execution))
	defer deadline.Stop()
	stopDeadline := deadlines.stop
	var hard *time.Timer
	var hardC, graceC <-chan time.Time
	shutdownC := shutdown.Done()
	interrupt := func(reason error) {
		if context.Cause(execCtx) != nil {
			return
		}
		cancel(reason)
		r.logger.Warn("stopping job execution", "job", job.ID, "reason", reason)
		hard = time.NewTimer(r.waitAfterStop(stopDeadline))
		hardC = hard.C
	}
	defer func() {
		if hard != nil {
			hard.Stop()
		}
	}()

	for {
		select {
		case result := <-done:
			cause := context.Cause(execCtx)
			if cause == nil && !time.Now().Before(deadlines.execution) {
				// the gate closed before the deadline timer fired
				cause = errExecutionDeadline
			}
			return interpret(result, cause)
		case <-deadline.C:
			interrupt(errExecutionDeadline)
		case beat := <-beats:
			switch {
			case beat.err != nil && isConflict(beat.err):
				interrupt(errLeaseLost)
			case beat.err != nil:
				r.logger.Warn("lease heartbeat failed, local deadline stays authoritative", "job", job.ID, "error", beat.err)
			case beat.response.StopRequested:
				if local, err := calibrateOne(beat.meta.ServerTime, beat.response.StopDeadline); err == nil && local.Before(stopDeadline) {
					stopDeadline = local
					if hard != nil {
						hard.Reset(r.waitAfterStop(stopDeadline))
					}
				}
				interrupt(errStopRequested)
			}
		case <-shutdownC:
			shutdownC = nil
			graceC = time.After(r.cfg.ShutdownGrace)
			r.logger.Warn("shutdown requested, running job continues within the shutdown grace", "job", job.ID, "grace", r.cfg.ShutdownGrace)
		case <-graceC:
			graceC = nil
			interrupt(errShutdown)
		case <-hardC:
			r.logger.Error("executor did not terminate before the stop deadline", "job", job.ID)
			return ambiguous("process_not_terminated", "The action process did not terminate after the stop; its effect is unknown.")
		}
	}
}

func (r *Runner) waitAfterStop(stopDeadline time.Time) time.Duration {
	return max(min(r.cfg.StopGrace, time.Until(stopDeadline)), 0)
}

// interpret keeps a result the executor finished on its own (including a
// success that raced the stop) and otherwise reports timed_out with the stop
// reason, preserving the executor's output tails.
func interpret(result api.CompletionResult, cause error) api.CompletionResult {
	if cause == nil || result.Outcome == "succeeded" {
		return result
	}
	code, ok := stopCodes[cause]
	if !ok {
		code = "stopped"
	}
	return api.CompletionResult{
		Outcome: "timed_out", ResultCode: code, Summary: "Execution stopped: " + cause.Error() + ".",
		StdoutTail: result.StdoutTail, StdoutEncoding: result.StdoutEncoding,
		StderrTail: result.StderrTail, StderrEncoding: result.StderrEncoding,
	}
}

func calibrateOne(serverTime time.Time, value string) (time.Time, error) {
	parsed, err := time.Parse(time.RFC3339, value)
	if err != nil {
		return time.Time{}, err
	}
	return time.Now().Add(parsed.Sub(serverTime)), nil
}
