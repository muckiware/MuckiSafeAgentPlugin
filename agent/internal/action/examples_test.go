package action

import (
	"encoding/json"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

// TestExampleActionsAreTheSharedReferenceDefinitions requires every
// examples/actions/<service>.json to be the shared fixture "<service>-pre":
// valid, with the same canonical bytes and hash as in PHP.
func TestExampleActionsAreTheSharedReferenceDefinitions(t *testing.T) {
	raw, err := os.ReadFile("../../../tests/Fixtures/action-dsl-v1.json")
	if err != nil {
		t.Fatal(err)
	}
	var fixture struct {
		Cases []struct {
			Name      string `json:"name"`
			Valid     bool   `json:"valid"`
			Canonical string `json:"canonical"`
			SHA256    string `json:"sha256"`
		} `json:"cases"`
	}
	if err := json.Unmarshal(raw, &fixture); err != nil {
		t.Fatal(err)
	}
	examples, err := filepath.Glob("../../../examples/actions/*.json")
	if err != nil || len(examples) == 0 {
		t.Fatalf("no example actions: %v", err)
	}
	for _, path := range examples {
		name := strings.TrimSuffix(filepath.Base(path), ".json") + "-pre"
		t.Run(name, func(t *testing.T) {
			raw, err := os.ReadFile(path)
			if err != nil {
				t.Fatal(err)
			}
			definition, err := Decode(raw)
			if err != nil {
				t.Fatal(err)
			}
			canonical, _ := Canonical(definition)
			hash, _ := Hash(definition)
			for _, c := range fixture.Cases {
				if c.Name == name {
					if !c.Valid || c.Canonical != canonical || c.SHA256 != hash {
						t.Fatalf("differs from the shared fixture: %s", canonical)
					}
					return
				}
			}
			t.Fatalf("the shared fixture has no case %s", name)
		})
	}
}
