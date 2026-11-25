package primitives

import (
	"context"
	"strings"
	"testing"
	"time"
)

const containerPolicy = `version: 1
containers:
  bitwarden-mssql:
    name: bitwarden-mssql
  immich-postgres:
    name: immich_postgres
commands:
  bitwarden-full-backup:
    containerAlias: bitwarden-mssql
    argv: [/backup-db.sh]
    timeoutSeconds: 30
  bitwarden-slow-backup:
    containerAlias: bitwarden-mssql
    argv: [/backup-db.sh, --slow]
    timeoutSeconds: 1
  dangling:
    containerAlias: missing
    argv: [/backup-db.sh]
    timeoutSeconds: 30
`

func execStep(container, command string) string {
	return `{"primitive":"docker.container.exec","containerAlias":"` + container + `","commandAlias":"` + command + `","arguments":{}}`
}

func TestContainerExecRunsTheFixedArgvInTheFixedContainer(t *testing.T) {
	h := newHarness(t, containerPolicy)
	s := script(t, fakeScript{Container: "bitwarden-mssql", Output: "BACKUP DATABASE successfully processed"})

	result := h.run(context.Background(), execStep("bitwarden-mssql", "bitwarden-full-backup"))
	expect(t, result, "succeeded", "ok")
	if result.StdoutTail != nil {
		t.Fatal("a successful step must not report output")
	}
	if calls := invocations(t, s); len(calls) != 1 || calls[0] != "exec bitwarden-mssql /backup-db.sh" {
		t.Fatalf("invocations %q", calls)
	}
}

func TestContainerExecFailureKeepsTheTails(t *testing.T) {
	h := newHarness(t, containerPolicy)
	script(t, fakeScript{Container: "bitwarden-mssql", Stderr: "Msg 3201: cannot open backup device", Exit: 3})

	result := h.run(context.Background(), execStep("bitwarden-mssql", "bitwarden-full-backup"))
	expect(t, result, "failed", codeCommand, "exited with 3")
	if result.StderrTail == nil || !strings.Contains(*result.StderrTail, "Msg 3201") {
		t.Fatalf("stderr tail %v", result.StderrTail)
	}
}

func TestContainerExecRejectsMismatchedAndUnknownAliases(t *testing.T) {
	h := newHarness(t, containerPolicy)
	s := script(t, fakeScript{Container: "immich_postgres"})

	expect(t, h.run(context.Background(), execStep("immich-postgres", "bitwarden-full-backup")), "failed", codePolicy, "bound to container bitwarden-mssql")
	expect(t, h.run(context.Background(), execStep("bitwarden-mssql", "pg-dump")), "failed", codePolicy, "pg-dump")
	expect(t, h.run(context.Background(), execStep("missing", "dangling")), "failed", codePolicy, `"missing"`)
	if calls := invocations(t, s); len(calls) != 0 {
		t.Fatalf("rejected steps ran %q", calls)
	}
}

func TestContainerExecTimesOutWithinItsPolicyBudget(t *testing.T) {
	h := newHarness(t, containerPolicy)
	script(t, fakeScript{Container: "bitwarden-mssql", Sleeps: true})

	started := time.Now()
	expect(t, h.run(context.Background(), execStep("bitwarden-mssql", "bitwarden-slow-backup")), "failed", codeCommand, "timed out after 1s")
	if elapsed := time.Since(started); elapsed > 4*time.Second {
		t.Fatalf("took %s", elapsed)
	}
}

func TestContainerExecActionCannotPassArguments(t *testing.T) {
	h := newHarness(t, containerPolicy)
	s := script(t, fakeScript{Container: "bitwarden-mssql"})
	job := actionJob(t, testJobID, execStep("bitwarden-mssql", "bitwarden-full-backup"))
	job.DefinitionCanonical = strings.Replace(job.DefinitionCanonical, `"arguments":{}`, `"arguments":{"file":"/etc/shadow"}`, 1)

	result := h.execute(context.Background(), job)
	expect(t, result, "failed", "action_rejected")
	if calls := invocations(t, s); len(calls) != 0 {
		t.Fatalf("ran %q", calls)
	}
}
