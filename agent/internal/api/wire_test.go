package api

import (
	"os"
	"regexp"
	"sort"
	"strings"
	"testing"
	"unicode/utf8"
)

// TestResultCodeAllowlistMatchesTheServer keeps the agent's allowlist in
// sync with the server enum.
func TestResultCodeAllowlistMatchesTheServer(t *testing.T) {
	raw, err := os.ReadFile("../../../src/Core/Job/ResultCode.php")
	if err != nil {
		t.Fatal(err)
	}
	var server []string
	for _, match := range regexp.MustCompile(`case \w+ = '([a-z_]+)';`).FindAllStringSubmatch(string(raw), -1) {
		server = append(server, match[1])
	}
	var agent []string
	for code := range resultCodes {
		agent = append(agent, code)
	}
	sort.Strings(server)
	sort.Strings(agent)
	if strings.Join(server, ",") != strings.Join(agent, ",") {
		t.Fatalf("server %v, agent %v", server, agent)
	}
}

func TestForWireKeepsAllowlistedCodes(t *testing.T) {
	for _, r := range []CompletionResult{
		{Outcome: "succeeded", ResultCode: "ok", Summary: "3 steps completed."},
		{Outcome: "failed", ResultCode: "partial_failure", Summary: "step 2 failed"},
		{Outcome: "timed_out", ResultCode: "deadline", Summary: "late"},
		{Outcome: "ambiguous", ResultCode: "journal_started", Summary: "unknown"},
	} {
		if got := r.ForWire(); got != r {
			t.Errorf("%+v changed to %+v", r, got)
		}
	}
}

func TestForWireMapsAgentCodesPerOutcomeAndKeepsThemInTheSummary(t *testing.T) {
	cases := map[string][3]string{
		"failed":    {"service_stop_failed", "step_failed", "[service_stop_failed] step 1 failed"},
		"timed_out": {"execution_deadline", "deadline", "[execution_deadline] step 1 failed"},
		"ambiguous": {"process_not_terminated", "journal_started", "[process_not_terminated] step 1 failed"},
		"succeeded": {"done", "ok", "[done] step 1 failed"},
	}
	for outcome, c := range cases {
		got := CompletionResult{Outcome: outcome, ResultCode: c[0], Summary: "step 1 failed"}.ForWire()
		if got.ResultCode != c[1] || got.Summary != c[2] {
			t.Errorf("%s: %+v", outcome, got)
		}
		if again := got.ForWire(); again != got {
			t.Errorf("%s: mapping is not idempotent: %+v", outcome, again)
		}
		if err := got.Validate(); err != nil {
			t.Errorf("%s: %v", outcome, err)
		}
	}
	if got := (CompletionResult{Outcome: "failed", ResultCode: "Not A Code", Summary: "x"}).ForWire(); got.ResultCode != "step_failed" || got.Summary != "x" {
		t.Errorf("an invalid code must be dropped, not quoted: %+v", got)
	}
}

func TestForWireCutsTheSummaryToTheServerLimits(t *testing.T) {
	for name, summary := range map[string]string{
		"characters": strings.Repeat("a", 5000),
		"bytes":      strings.Repeat("€", 1500), // 1024 characters are 3072 bytes
		"wide":       strings.Repeat("𝄞", 1500), // 1024 characters are 4096 bytes
		"invalid":    "ok\xff",
	} {
		got := CompletionResult{Outcome: "failed", ResultCode: "step_failed", Summary: summary}.ForWire().Summary
		if !utf8.ValidString(got) || utf8.RuneCountInString(got) > summaryLimitChars || len(got) > 4096 {
			t.Errorf("%s: %d characters, %d bytes", name, utf8.RuneCountInString(got), len(got))
		}
		if !strings.HasPrefix(summary, strings.TrimSuffix(got, "�")) {
			t.Errorf("%s: the summary was not cut at its end", name)
		}
	}
}
