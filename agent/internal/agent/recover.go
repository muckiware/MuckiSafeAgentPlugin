package agent

import (
	"context"
	"time"

	"github.com/muckiware/MuckiSafeAgentPlugin/agent/internal/api"
	"github.com/muckiware/MuckiSafeAgentPlugin/agent/internal/idempotency"
)

// Recover settles every journal record left by a previous process before
// new jobs are claimed:
//   - completed: only the stored completion is repeated;
//   - started and non_retryable: ambiguous, never executed again;
//   - started and safe: executed again if the lease still holds, otherwise
//     dropped (the control plane decides the job);
//   - untrustworthy: logged and left alone, never executed.
func (r *Runner) Recover(shutdown context.Context) error {
	if !r.acquire() {
		return ErrBusy
	}
	defer r.release()
	ids, err := r.cfg.Journal.List()
	if err != nil {
		return err
	}
	for _, id := range ids {
		if shutdown.Err() != nil {
			return nil
		}
		record, err := r.cfg.Journal.Load(id)
		if err != nil {
			r.logger.Error("journal record cannot be trusted, job is left to the control plane", "job", id, "error", err)
			continue
		}
		giveUpAt, _ := time.Parse(time.RFC3339, record.PhaseDeadline)
		lease := api.JobLeaseRequest{ClaimToken: record.ClaimToken, LeaseVersion: record.LeaseVersion}
		switch {
		case record.State == idempotency.StateCompleted:
			r.complete(shutdown, id, lease, *record.Completion, giveUpAt)
		case record.IdempotencyClass != "safe":
			r.finish(shutdown, record, ambiguous("journal_started", "The agent restarted after the job had started; its effect is unknown."), giveUpAt)
		default:
			r.resumeSafe(shutdown, record, lease, giveUpAt)
		}
	}
	return nil
}

func (r *Runner) resumeSafe(shutdown context.Context, record idempotency.Record, lease api.JobLeaseRequest, giveUpAt time.Time) {
	response, meta, err := r.cfg.API.JobHeartbeat(context.Background(), record.JobID, lease)
	switch {
	case isConflict(err):
		r.logger.Warn("lease of restarted safe job is gone, record dropped", "job", record.JobID)
		r.forget(record.JobID)
		return
	case err != nil:
		r.logger.Warn("cannot verify the lease of a restarted safe job, record kept", "job", record.JobID, "error", err)
		return
	case response.StopRequested:
		r.finish(shutdown, record, api.CompletionResult{Outcome: "timed_out", ResultCode: "agent_restarted", Summary: "The agent restarted and the job was cancelled meanwhile."}, giveUpAt)
		return
	}
	deadlines, err := calibrate(meta.ServerTime, record.ExecutionDeadline, record.StopDeadline, record.PhaseDeadline)
	if err != nil {
		r.logger.Error("restarted safe job has invalid deadlines", "job", record.JobID, "error", err)
		return
	}
	r.logger.Info("re-executing safe job after restart", "job", record.JobID)
	r.finish(shutdown, record, r.execute(shutdown, jobOf(record), lease, deadlines), deadlines.phase)
}
