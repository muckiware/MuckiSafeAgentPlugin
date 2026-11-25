package main

import (
	"bytes"
	"context"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"testing"
	"time"

	"github.com/muckiware/MuckiSafeAgentPlugin/agent/internal/api"
)

const (
	keyID  = "0123456789abcdef0123456789abcdef"
	secret = "AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8"
)

// The fake must satisfy the real client, otherwise the E2E tests would test
// the fake instead of the agent.
func TestTheRealClientCompletesAnEnqueuedJob(t *testing.T) {
	key, err := api.DecodeSecret(secret)
	if err != nil {
		t.Fatal(err)
	}
	server := httptest.NewServer(newPlane(keyID, key))
	defer server.Close()
	client, err := api.NewClient(api.ClientOptions{BaseURL: server.URL, AllowInsecureHTTP: true, KeyID: keyID, Key: key})
	if err != nil {
		t.Fatal(err)
	}
	ctx := context.Background()

	response, err := http.Post(server.URL+"/_e2e/enqueue", "application/json", bytes.NewBufferString(
		`{"definition":{"version":1,"steps":[{"primitive":"service.control","serviceAlias":"dummy","operation":"restart"}]}}`))
	if err != nil || response.StatusCode != http.StatusOK {
		t.Fatalf("enqueue: %v %v", err, response)
	}
	if _, err := client.HostHeartbeat(ctx, api.HostHeartbeatRequest{AgentVersion: "1.0.0", Platform: "linux", Arch: "amd64", Capabilities: []string{}, AgentTime: wire(time.Now())}); err != nil {
		t.Fatal(err)
	}
	claim, err := client.Claim(ctx)
	if err != nil || claim.Job == nil {
		t.Fatalf("claim %+v, %v", claim, err)
	}
	lease := api.JobLeaseRequest{ClaimToken: claim.Job.ClaimToken, LeaseVersion: claim.Job.LeaseVersion}
	started, _, err := client.StartJob(ctx, claim.Job.ID, lease)
	if err != nil {
		t.Fatal(err)
	}
	lease.LeaseVersion = started.LeaseVersion
	if _, _, err := client.JobHeartbeat(ctx, claim.Job.ID, lease); err != nil {
		t.Fatal(err)
	}
	if _, err := http.Post(server.URL+"/_e2e/stop", "application/json", bytes.NewBufferString(`{"jobId":"`+claim.Job.ID+`"}`)); err != nil {
		t.Fatal(err)
	}
	if beat, _, err := client.JobHeartbeat(ctx, claim.Job.ID, lease); err != nil || !beat.StopRequested {
		t.Fatalf("stop heartbeat %+v, %v", beat, err)
	}
	result := api.CompletionResult{Outcome: "succeeded", ResultCode: "ok", Summary: "done"}
	if _, err := client.CompleteJob(ctx, claim.Job.ID, api.JobCompleteRequest{JobLeaseRequest: lease, CompletionResult: result}); err != nil {
		t.Fatal(err)
	}
	if empty, err := client.Claim(ctx); err != nil || empty.Job != nil {
		t.Fatalf("queue must be empty: %+v %v", empty, err)
	}

	statusResponse, err := http.Get(server.URL + "/_e2e/status")
	if err != nil {
		t.Fatal(err)
	}
	var status Status
	if err := json.NewDecoder(statusResponse.Body).Decode(&status); err != nil {
		t.Fatal(err)
	}
	if len(status.Heartbeats) != 1 || status.Claims != 2 || len(status.Completions) != 1 || status.Completions[0].Outcome != "succeeded" {
		t.Fatalf("status %+v", status)
	}
}
