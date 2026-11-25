// Package executor runs the declarative steps of a job on the Linux host.
//
// Before any step the executor re-verifies what the control plane sent: the
// SHA-256 over the received canonical bytes, byte-identical
// re-canonicalization, the V1 action schema and the (empty) V1 parameters.
// Every step is dispatched to a registered primitive, which resolves its
// aliases exclusively from the job's immutable policy snapshot. External
// processes run through the ProcessRunner: fixed allowlist, separated argv,
// no shell, minimal environment, own process group. Output is redacted and
// tail-limited per stream for the whole job.
package executor

import (
	"context"
	"errors"
	"fmt"
	"io"
	"log/slog"
	"regexp"
	"time"
	"unicode/utf8"

	"github.com/muckiware/MuckiSafeAgentPlugin/agent/internal/action"
	"github.com/muckiware/MuckiSafeAgentPlugin/agent/internal/agent"
	"github.com/muckiware/MuckiSafeAgentPlugin/agent/internal/api"
	"github.com/muckiware/MuckiSafeAgentPlugin/agent/internal/policy"
)

// Primitive executes one validated step of its kind.
type Primitive interface {
	// Run returns nil on success, a *StepError with a stable result code,
	// or an error wrapping ErrNotTerminated when a process could not be
	// stopped. It must call step.Run for every external process.
	Run(ctx context.Context, step StepContext) error
}

// StepError is a primitive failure with a stable, allowlisted result code.
type StepError struct {
	Code    string
	Message string
}

func (e *StepError) Error() string { return e.Message }

// StepContext is everything a primitive may use for one step.
type StepContext struct {
	Job   agent.Job
	Index int
	Step  map[string]any
	// Started is when the execution of the job began; files whose
	// timestamps are not older were created by the current run.
	Started time.Time
	Policy  *policy.Policy
	Stdout  io.Writer
	Stderr  io.Writer
	Gate    agent.StepGate

	processes *ProcessRunner
}

// Run starts an external process after consulting the gate; output goes to
// the job tails unless the command sets its own writers.
func (s StepContext) Run(ctx context.Context, cmd Command) (ProcessStatus, error) {
	if err := s.Gate(); err != nil {
		return ProcessStatus{}, fmt.Errorf("no new process may start: %w", err)
	}
	if cmd.Stdout == nil {
		cmd.Stdout = s.Stdout
	}
	if cmd.Stderr == nil {
		cmd.Stderr = s.Stderr
	}
	return s.processes.Run(ctx, cmd)
}

// PolicySource provides the current policy snapshot.
type PolicySource interface {
	Current() *policy.Policy
}

// Executor implements agent.Executor.
type Executor struct {
	Policies   PolicySource
	Primitives map[string]Primitive
	Processes  *ProcessRunner
	Redactor   *Redactor
	Logger     *slog.Logger
}

var resultCodePattern = regexp.MustCompile(`^[a-z][a-z0-9_]{0,63}$`)

// Execute verifies the action and runs its steps in order, stopping at the
// first failure; V1 performs no compensation.
func (e *Executor) Execute(ctx context.Context, job agent.Job, gate agent.StepGate) api.CompletionResult {
	started := time.Now()
	steps, err := verifiedSteps(job)
	if err != nil {
		return e.fail("action_rejected", "The action was rejected before execution: "+err.Error(), nil, nil)
	}
	snapshot := e.Policies.Current()
	if snapshot == nil {
		return e.fail("policy_unavailable", "No valid local policy is loaded.", nil, nil)
	}
	stdout, stderr := NewTail(TailLimit, e.Redactor), NewTail(TailLimit, e.Redactor)
	for index, step := range steps {
		name, _ := step["primitive"].(string)
		if err := gate(); err != nil {
			return e.fail(failureCode(index, "stopped_before_start"), fmt.Sprintf("Execution stopped before step %d (%s): %v.", index+1, name, err), stdout, stderr)
		}
		primitive, ok := e.Primitives[name]
		if !ok {
			return e.fail(failureCode(index, "primitive_unavailable"), fmt.Sprintf("Step %d: primitive %s is not available on this agent.", index+1, name), stdout, stderr)
		}
		err := primitive.Run(ctx, StepContext{
			Job: job, Index: index, Step: step, Started: started, Policy: snapshot,
			Stdout: stdout, Stderr: stderr, Gate: gate, processes: e.Processes,
		})
		if errors.Is(err, ErrNotTerminated) {
			e.logger().Error("process of a step could not be terminated", "job", job.ID, "step", index+1)
			return api.CompletionResult{Outcome: "ambiguous", ResultCode: "process_not_terminated",
				Summary: fmt.Sprintf("Step %d (%s): the process did not terminate; the effect is unknown.", index+1, name)}
		}
		if err != nil {
			return e.fail(failureCode(index, stepCode(err)), fmt.Sprintf("step %d (%s) failed: %v", index+1, name, err), stdout, stderr)
		}
	}
	return api.CompletionResult{Outcome: "succeeded", ResultCode: "ok", Summary: fmt.Sprintf("%d steps completed.", len(steps))}
}

func verifiedSteps(job agent.Job) ([]map[string]any, error) {
	definition, err := action.VerifyCanonical(job.DefinitionCanonical, job.ActionHash)
	if err != nil {
		return nil, err
	}
	// Decode already enforces the V1 schema; validate again in case that
	// coupling ever changes.
	if err := action.Validate(definition); err != nil {
		return nil, err
	}
	if err := action.ValidateParameters(job.Parameters); err != nil {
		return nil, err
	}
	raw, _ := definition["steps"].([]any)
	steps := make([]map[string]any, len(raw))
	for i, step := range raw {
		steps[i], _ = step.(map[string]any)
	}
	return steps, nil
}

// failureCode follows plan 2.7: once a step ran, the code is partial_failure.
func failureCode(index int, code string) string {
	if index > 0 {
		return "partial_failure"
	}
	return code
}

func stepCode(err error) string {
	if errors.Is(err, ErrStopped) {
		return "execution_stopped"
	}
	var stepErr *StepError
	if errors.As(err, &stepErr) && resultCodePattern.MatchString(stepErr.Code) {
		return stepErr.Code
	}
	return "step_failed"
}

func (e *Executor) fail(code, summary string, stdout, stderr *Tail) api.CompletionResult {
	result := api.CompletionResult{Outcome: "failed", ResultCode: code, Summary: limitRunes(e.Redactor.String(summary), 4096)}
	if stdout != nil {
		result.StdoutTail, result.StdoutEncoding = stdout.Encoded()
		result.StderrTail, result.StderrEncoding = stderr.Encoded()
	}
	return result
}

func (e *Executor) logger() *slog.Logger {
	if e.Logger != nil {
		return e.Logger
	}
	return slog.Default()
}

func limitRunes(s string, n int) string {
	if utf8.RuneCountInString(s) <= n {
		return s
	}
	return string([]rune(s)[:n])
}
