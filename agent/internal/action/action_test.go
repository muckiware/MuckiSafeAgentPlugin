package action

import (
	"bytes"
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"testing"
)

type fixtureFile struct {
	Cases []struct {
		Name       string          `json:"name"`
		Valid      bool            `json:"valid"`
		Definition json.RawMessage `json:"definition"`
		Canonical  string          `json:"canonical"`
		SHA256     string          `json:"sha256"`
	} `json:"cases"`
	RawCases []struct {
		Name         string `json:"name"`
		Valid        bool   `json:"valid"`
		Input        string `json:"input"`
		PaddingBytes int    `json:"paddingBytes"`
	} `json:"rawCases"`
}

func TestSharedFixtures(t *testing.T) {
	raw, err := os.ReadFile(filepath.Join("..", "..", "..", "tests", "Fixtures", "action-dsl-v1.json"))
	if err != nil {
		t.Fatal(err)
	}
	decoder := json.NewDecoder(bytes.NewReader(raw))
	var fixture fixtureFile
	if err := decoder.Decode(&fixture); err != nil {
		t.Fatal(err)
	}
	for _, c := range fixture.Cases {
		c := c
		t.Run(c.Name, func(t *testing.T) {
			definition, err := Decode(c.Definition)
			if !c.Valid {
				if err == nil {
					t.Fatal("invalid fixture accepted")
				}
				return
			}
			if err != nil {
				t.Fatal(err)
			}
			canonical, err := Canonical(definition)
			if err != nil {
				t.Fatal(err)
			}
			if canonical != c.Canonical {
				t.Fatalf("canonical\n got: %s\nwant: %s", canonical, c.Canonical)
			}
			hash, err := Hash(definition)
			if err != nil {
				t.Fatal(err)
			}
			if hash != c.SHA256 {
				t.Fatalf("hash %s, want %s", hash, c.SHA256)
			}
			if _, err := VerifyCanonical(canonical, hash); err != nil {
				t.Fatalf("canonical transport rejected: %v", err)
			}
		})
	}
	for _, c := range fixture.RawCases {
		c := c
		t.Run("raw/"+c.Name, func(t *testing.T) {
			input := append(bytes.Repeat([]byte{' '}, c.PaddingBytes), []byte(c.Input)...)
			_, err := Decode(input)
			if (err == nil) != c.Valid {
				t.Fatalf("valid=%v, error=%v", c.Valid, err)
			}
		})
	}
}

func TestSharedParameterFixtures(t *testing.T) {
	raw, err := os.ReadFile(filepath.Join("..", "..", "..", "tests", "Fixtures", "action-parameters-v1.json"))
	if err != nil {
		t.Fatal(err)
	}
	var fixture struct {
		Cases []struct {
			Name       string          `json:"name"`
			Valid      bool            `json:"valid"`
			Parameters json.RawMessage `json:"parameters"`
		} `json:"cases"`
	}
	if err := json.Unmarshal(raw, &fixture); err != nil {
		t.Fatal(err)
	}
	for _, c := range fixture.Cases {
		c := c
		t.Run(c.Name, func(t *testing.T) {
			decoder := json.NewDecoder(bytes.NewReader(c.Parameters))
			decoder.UseNumber()
			var parameters map[string]any
			decodeErr := decoder.Decode(&parameters)
			err := decodeErr
			if err == nil && parameters == nil {
				err = fmt.Errorf("parameters must be an object")
			}
			if err == nil {
				err = ValidateParameters(parameters)
			}
			if (err == nil) != c.Valid {
				t.Fatalf("valid=%v, error=%v", c.Valid, err)
			}
		})
	}
}
