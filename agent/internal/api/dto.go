package api

import (
	"bytes"
	"encoding/json"
	"errors"
	"regexp"
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
