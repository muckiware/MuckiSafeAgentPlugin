package api

import (
	"bytes"
	"encoding/json"
	"errors"
	"regexp"
	"unicode/utf8"
)

// HostHeartbeatRequest mirrors resources/schema/protocol/v1/heartbeat-request.schema.json.
type HostHeartbeatRequest struct {
	AgentVersion string   `json:"agentVersion"`
	Platform     string   `json:"platform"`
	Arch         string   `json:"arch"`
	Capabilities []string `json:"capabilities"`
	AgentTime    string   `json:"agentTime"`
}

// HostHeartbeatResponse mirrors heartbeat-response.schema.json.
type HostHeartbeatResponse struct {
	ServerTime       string `json:"serverTime"`
	PollAfterSeconds int    `json:"pollAfterSeconds"`
}

func (r *HostHeartbeatResponse) validate() error {
	if !wireTimePattern.MatchString(r.ServerTime) || r.PollAfterSeconds < 1 || r.PollAfterSeconds > 3600 {
		return errors.New("invalid heartbeat response")
	}
	return nil
}

// ClaimRequest mirrors claim-request.schema.json; V1 claims exactly one job.
type ClaimRequest struct {
	MaxJobs int `json:"maxJobs"`
}

// ClaimResponse mirrors claim-response.schema.json. Job is nil for an empty queue.
type ClaimResponse struct {
	Job *ClaimedJob `json:"job"`
}

// UnmarshalJSON requires the `job` member to be present (null or object).
func (r *ClaimResponse) UnmarshalJSON(raw []byte) error {
	var members map[string]json.RawMessage
	if err := json.Unmarshal(raw, &members); err != nil {
		return err
	}
	if _, ok := members["job"]; !ok || len(members) != 1 {
		return errors.New("claim response must contain exactly the job member")
	}
	type plain ClaimResponse
	return decodeMember(members["job"], &(*plain)(r).Job)
}

// ClaimedJob is the signed job snapshot of a successful claim.
type ClaimedJob struct {
	ID                string         `json:"id"`
	ClaimToken        string         `json:"claimToken"`
	LeaseVersion      int64          `json:"leaseVersion"`
	LeaseExpiresAt    string         `json:"leaseExpiresAt"`
	PhaseDeadline     string         `json:"phaseDeadline"`
	ExecutionDeadline string         `json:"executionDeadline"`
	StopDeadline      string         `json:"stopDeadline"`
	Phase             string         `json:"phase"`
	IdempotencyClass  string         `json:"idempotencyClass"`
	Action            ClaimedAction  `json:"action"`
	Parameters        map[string]any `json:"parameters"`
}

// ClaimedAction carries the canonical action definition and its hash.
type ClaimedAction struct {
	DefinitionCanonical string `json:"definitionCanonical"`
	ActionHash          string `json:"actionHash"`
}

var actionHashPattern = regexp.MustCompile(`^[0-9a-f]{64}$`)

func (r *ClaimResponse) validate() error {
	job := r.Job
	if job == nil {
		return nil
	}
	switch {
	case !uuidRegexp.MatchString(job.ID), !claimTokenPattern.MatchString(job.ClaimToken):
		return errors.New("invalid claimed job identity")
	case job.LeaseVersion < 1 || job.LeaseVersion > 2147483647:
		return errors.New("invalid claimed job lease version")
	case !wireTimePattern.MatchString(job.LeaseExpiresAt), !wireTimePattern.MatchString(job.PhaseDeadline),
		!wireTimePattern.MatchString(job.ExecutionDeadline), !wireTimePattern.MatchString(job.StopDeadline):
		return errors.New("invalid claimed job time")
	case job.Phase != "pre" && job.Phase != "post" && job.Phase != "finally":
		return errors.New("unsupported claimed job phase")
	case job.IdempotencyClass != "safe" && job.IdempotencyClass != "non_retryable":
		return errors.New("unsupported claimed job idempotency class")
	case len(job.Action.DefinitionCanonical) < 2 || len(job.Action.DefinitionCanonical) > 65536,
		!actionHashPattern.MatchString(job.Action.ActionHash):
		return errors.New("invalid claimed job action")
	case job.Parameters == nil:
		return errors.New("claimed job parameters must be an object")
	}
	return nil
}

// decodeMember decodes a nested member with the same strictness as decodeStrict.
func decodeMember(raw json.RawMessage, target any) error {
	decoder := json.NewDecoder(bytes.NewReader(raw))
	decoder.DisallowUnknownFields()
	return decoder.Decode(target)
}

func (r *HostHeartbeatResponse) required() []string {
	return []string{"serverTime", "pollAfterSeconds"}
}

// JobLeaseRequest fences start, lease heartbeat and completion.
type JobLeaseRequest struct {
	ClaimToken   string `json:"claimToken"`
	LeaseVersion int64  `json:"leaseVersion"`
}

// JobStartResponse mirrors job-start-response.schema.json.
type JobStartResponse struct {
	LeaseVersion      int64  `json:"leaseVersion"`
	LeaseExpiresAt    string `json:"leaseExpiresAt"`
	PhaseDeadline     string `json:"phaseDeadline"`
	ExecutionDeadline string `json:"executionDeadline"`
	StopDeadline      string `json:"stopDeadline"`
}

func (r *JobStartResponse) required() []string {
	return []string{"leaseVersion", "leaseExpiresAt", "phaseDeadline", "executionDeadline", "stopDeadline"}
}

func (r *JobStartResponse) validate() error {
	if r.LeaseVersion < 1 || r.LeaseVersion > 2147483647 {
		return errors.New("invalid start lease version")
	}
	for _, value := range []string{r.LeaseExpiresAt, r.PhaseDeadline, r.ExecutionDeadline, r.StopDeadline} {
		if !wireTimePattern.MatchString(value) {
			return errors.New("invalid start deadline")
		}
	}
	return nil
}

// JobHeartbeatResponse mirrors job-heartbeat-response.schema.json; StopDeadline
// is present exactly when StopRequested is true.
type JobHeartbeatResponse struct {
	LeaseVersion   int64  `json:"leaseVersion"`
	LeaseExpiresAt string `json:"leaseExpiresAt"`
	StopRequested  bool   `json:"stopRequested"`
	StopDeadline   string `json:"stopDeadline,omitempty"`
}

func (r *JobHeartbeatResponse) required() []string {
	return []string{"leaseVersion", "leaseExpiresAt", "stopRequested"}
}

func (r *JobHeartbeatResponse) validate() error {
	switch {
	case r.LeaseVersion < 1 || r.LeaseVersion > 2147483647, !wireTimePattern.MatchString(r.LeaseExpiresAt):
		return errors.New("invalid lease heartbeat response")
	case r.StopRequested != (r.StopDeadline != ""):
		return errors.New("stopDeadline must be present exactly when stopRequested is true")
	case r.StopDeadline != "" && !wireTimePattern.MatchString(r.StopDeadline):
		return errors.New("invalid stop deadline")
	}
	return nil
}

// CompletionResult is the outcome part of a completion. The agent journal
// persists it verbatim so that every retry is byte-identical.
type CompletionResult struct {
	Outcome        string  `json:"outcome"`
	ResultCode     string  `json:"resultCode"`
	Summary        string  `json:"summary"`
	StdoutTail     *string `json:"stdoutTail,omitempty"`
	StderrTail     *string `json:"stderrTail,omitempty"`
	StdoutEncoding *string `json:"stdoutEncoding,omitempty"`
	StderrEncoding *string `json:"stderrEncoding,omitempty"`
}

var resultCodePattern = regexp.MustCompile(`^[a-z][a-z0-9_]{0,63}$`)

// Validate applies the completion schema and the server's outcome/tail
// matrix: succeeded and ambiguous carry no tails, a tail needs its encoding.
func (r CompletionResult) Validate() error {
	switch r.Outcome {
	case "succeeded", "failed", "timed_out", "ambiguous":
	default:
		return errors.New("completion: unsupported outcome")
	}
	if !resultCodePattern.MatchString(r.ResultCode) {
		return errors.New("completion: invalid result code")
	}
	if !utf8.ValidString(r.Summary) || utf8.RuneCountInString(r.Summary) > 4096 {
		return errors.New("completion: summary must be valid UTF-8 of at most 4096 characters")
	}
	if err := validateTail(r.Outcome, r.StdoutTail, r.StdoutEncoding); err != nil {
		return err
	}
	return validateTail(r.Outcome, r.StderrTail, r.StderrEncoding)
}

func validateTail(outcome string, tail, encoding *string) error {
	if tail == nil && encoding == nil {
		return nil
	}
	if outcome == "succeeded" || outcome == "ambiguous" {
		return errors.New("completion: " + outcome + " must not carry output tails")
	}
	if tail == nil || encoding == nil || (*encoding != "utf-8" && *encoding != "base64") {
		return errors.New("completion: a tail requires its utf-8 or base64 encoding")
	}
	if utf8.RuneCountInString(*tail) > 87384 {
		return errors.New("completion: output tail too long")
	}
	return nil
}

// JobCompleteRequest mirrors job-complete-request.schema.json.
type JobCompleteRequest struct {
	JobLeaseRequest
	CompletionResult
}

func (r *JobCompleteRequest) required() []string {
	return []string{"claimToken", "leaseVersion", "outcome", "resultCode", "summary"}
}

func (r *JobCompleteRequest) validate() error {
	if !claimTokenPattern.MatchString(r.ClaimToken) || r.LeaseVersion < 1 || r.LeaseVersion > 2147483647 {
		return errors.New("invalid completion fence")
	}
	return r.CompletionResult.Validate()
}

// JobCompleteResponse mirrors job-complete-response.schema.json.
type JobCompleteResponse struct {
	Accepted       bool   `json:"accepted"`
	TerminalStatus string `json:"terminalStatus"`
}

func (r *JobCompleteResponse) required() []string { return []string{"accepted", "terminalStatus"} }

func (r *JobCompleteResponse) validate() error {
	switch r.TerminalStatus {
	case "succeeded", "failed", "timed_out", "unknown":
	default:
		return errors.New("unsupported terminal status")
	}
	if !r.Accepted {
		return errors.New("completion response must be accepted")
	}
	return nil
}
