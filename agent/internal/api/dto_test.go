package api

import (
	"encoding/json"
	"os"
	"path/filepath"
	"testing"
)

// TestResponseDTOsAgreeWithTheSharedFixtures decodes every response document
// of tests/Fixtures/protocol/dtos-v1.json with the client's strict decoder:
// valid documents must be accepted, invalid ones rejected.
func TestResponseDTOsAgreeWithTheSharedFixtures(t *testing.T) {
	raw, err := os.ReadFile(filepath.Join(fixtureDir, "dtos-v1.json"))
	if err != nil {
		t.Fatal(err)
	}
	var fixture struct {
		Cases []struct {
			Schema   string          `json:"schema"`
			Name     string          `json:"name"`
			Valid    bool            `json:"valid"`
			Document json.RawMessage `json:"document"`
		} `json:"cases"`
	}
	if err := json.Unmarshal(raw, &fixture); err != nil {
		t.Fatal(err)
	}
	targets := map[string]func() any{
		"heartbeat-response":     func() any { return &HostHeartbeatResponse{} },
		"claim-response":         func() any { return &ClaimResponse{} },
		"error-response":         func() any { return &errorResponse{} },
		"job-start-response":     func() any { return &JobStartResponse{} },
		"job-heartbeat-response": func() any { return &JobHeartbeatResponse{} },
		"job-complete-response":  func() any { return &JobCompleteResponse{} },
		"job-complete-request":   func() any { return &JobCompleteRequest{} },
	}
	seen := map[string]int{}
	for _, c := range fixture.Cases {
		target, ok := targets[c.Schema]
		if !ok {
			continue
		}
		seen[c.Schema]++
		t.Run(c.Schema+": "+c.Name, func(t *testing.T) {
			err := decodeStrict(c.Document, target())
			if c.Valid && err != nil {
				t.Fatalf("valid document rejected: %v", err)
			}
			if !c.Valid && err == nil {
				t.Fatal("invalid document accepted")
			}
		})
	}
	if len(seen) != len(targets) {
		t.Fatalf("fixture lacks cases for some response DTOs: %v", seen)
	}
}
