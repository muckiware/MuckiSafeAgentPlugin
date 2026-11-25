//go:build linux

package primitives

import (
	"context"
	"os"
	"path/filepath"
	"slices"
	"testing"
	"time"
)

const servicePolicy = `version: 1
services:
  jellyfin:
    unit: jellyfin.service
    startTimeoutSeconds: 10
    stopTimeoutSeconds: 10
    requireEmptyCgroupAfterStop: true
  slow:
    unit: jellyfin.service
    startTimeoutSeconds: 1
    stopTimeoutSeconds: 1
  plain:
    unit: plain.service
    startTimeoutSeconds: 5
    stopTimeoutSeconds: 5
  missing:
    unit: missing.service
    startTimeoutSeconds: 5
    stopTimeoutSeconds: 5
`

const jellyfinGroup = "/system.slice/jellyfin.service"

func serviceStep(alias, operation string) string {
	return `{"primitive":"service.control","serviceAlias":"` + alias + `","operation":"` + operation + `"}`
}

func serviceHarness(t *testing.T) *harness {
	h := newHarness(t, servicePolicy)
	h.primitives["service.control"].(*serviceControl).requireCgroup2 = false
	return h
}

// cgroupEvents writes the cgroup.events file of jellyfin's group.
func cgroupEvents(t *testing.T, h *harness, content string) string {
	t.Helper()
	dir := filepath.Join(h.primitives["service.control"].(*serviceControl).cgroupRoot, jellyfinGroup)
	if err := os.MkdirAll(dir, 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(dir, "cgroup.events"), []byte(content), 0o644); err != nil {
		t.Fatal(err)
	}
	return dir
}

func TestServiceStopRunsSystemctlWithFixedArgvAndVerifiesTheEmptyCgroup(t *testing.T) {
	h := serviceHarness(t)
	cgroupEvents(t, h, "populated 0\nfrozen 0\n")
	s := script(t, fakeScript{Unit: "jellyfin.service", Active: "active", ControlGroup: jellyfinGroup})

	expect(t, h.run(context.Background(), serviceStep("jellyfin", "stop")), "succeeded", "ok")
	calls := invocations(t, s)
	if !slices.Contains(calls, "--no-ask-password stop -- jellyfin.service") {
		t.Fatalf("invocations %q", calls)
	}
	for _, call := range calls {
		if call != "--no-ask-password stop -- jellyfin.service" &&
			call != "--no-ask-password show --property=LoadState --property=ActiveState --property=ControlGroup -- jellyfin.service" {
			t.Fatalf("unexpected invocation %q", call)
		}
	}
}

func TestServiceStopFailsWhileTheCgroupStillHasProcesses(t *testing.T) {
	h := serviceHarness(t)
	cgroupEvents(t, h, "populated 1\nfrozen 0\n")
	script(t, fakeScript{Unit: "jellyfin.service", Active: "active", ControlGroup: jellyfinGroup, StopKeepsGroup: true})

	expect(t, h.run(context.Background(), serviceStep("jellyfin", "stop")), "failed", codeStop, "still contains processes")
}

func TestServiceStopChecksTheGroupKnownBeforeTheStop(t *testing.T) {
	h := serviceHarness(t)
	cgroupEvents(t, h, "populated 1\n")
	// systemd already forgot the group, the leftover process did not
	script(t, fakeScript{Unit: "jellyfin.service", Active: "active", ControlGroup: jellyfinGroup})

	expect(t, h.run(context.Background(), serviceStep("jellyfin", "stop")), "failed", codeStop, "still contains processes")
}

func TestServiceStopWithoutCgroupPolicyIgnoresTheGroup(t *testing.T) {
	h := serviceHarness(t)
	cgroupEvents(t, h, "populated 1\n")
	script(t, fakeScript{Unit: "plain.service", Active: "active", ControlGroup: jellyfinGroup, StopKeepsGroup: true})

	expect(t, h.run(context.Background(), serviceStep("plain", "stop")), "succeeded", "ok")
}

func TestServiceCgroupCheckRefusesSymlinksAndNonCgroup2Mounts(t *testing.T) {
	h := serviceHarness(t)
	root := h.primitives["service.control"].(*serviceControl).cgroupRoot
	elsewhere := tempDir(t)
	if err := os.MkdirAll(filepath.Join(elsewhere, "jellyfin.service"), 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(elsewhere, "jellyfin.service", "cgroup.events"), []byte("populated 0\n"), 0o644); err != nil {
		t.Fatal(err)
	}
	if err := os.Symlink(elsewhere, filepath.Join(root, "system.slice")); err != nil {
		t.Fatal(err)
	}
	script(t, fakeScript{Unit: "jellyfin.service", Active: "active", ControlGroup: jellyfinGroup})
	expect(t, h.run(context.Background(), serviceStep("jellyfin", "stop")), "failed", codeStop, "jellyfin.service/cgroup.events")

	h = newHarness(t, servicePolicy)
	script(t, fakeScript{Unit: "jellyfin.service", Active: "active", ControlGroup: jellyfinGroup})
	expect(t, h.run(context.Background(), serviceStep("jellyfin", "stop")), "failed", codeStop, "cgroup v2")
}

func TestServiceStartAndRestartRequireAnActiveUnit(t *testing.T) {
	h := serviceHarness(t)
	s := script(t, fakeScript{Unit: "plain.service", Active: "inactive"})
	expect(t, h.run(context.Background(), serviceStep("plain", "start")), "succeeded", "ok")
	expect(t, h.run(context.Background(), serviceStep("plain", "start")), "succeeded", "ok")
	expect(t, h.run(context.Background(), serviceStep("plain", "restart")), "succeeded", "ok")
	if !slices.Contains(invocations(t, s), "--no-ask-password restart -- plain.service") {
		t.Fatal("restart was not requested")
	}

	script(t, fakeScript{Unit: "plain.service", Active: "inactive", StartFails: true})
	expect(t, h.run(context.Background(), serviceStep("plain", "start")), "failed", codeStart, "exited with 1")
}

func TestServiceStopIsIdempotent(t *testing.T) {
	h := serviceHarness(t)
	script(t, fakeScript{Unit: "plain.service", Active: "inactive"})
	expect(t, h.run(context.Background(), serviceStep("plain", "stop")), "succeeded", "ok")
	expect(t, h.run(context.Background(), serviceStep("plain", "stop")), "succeeded", "ok")
}

func TestServiceStateIsVerifiedNotTrustedFromTheExitCode(t *testing.T) {
	h := serviceHarness(t)
	script(t, fakeScript{Unit: "plain.service", Active: "active", StopNoop: true})
	expect(t, h.run(context.Background(), serviceStep("plain", "stop")), "failed", codeStop, "is active after stop")

	script(t, fakeScript{Unit: "plain.service", Active: "active", StopExit: 3})
	expect(t, h.run(context.Background(), serviceStep("plain", "stop")), "failed", codeStop, "exited with 3")
}

func TestUnknownServicesAndUnitsAreRejected(t *testing.T) {
	h := serviceHarness(t)
	s := script(t, fakeScript{Unit: "jellyfin.service", Active: "active"})
	expect(t, h.run(context.Background(), serviceStep("immich", "stop")), "failed", codePolicy, "immich")
	expect(t, h.run(context.Background(), serviceStep("missing", "stop")), "failed", codeStop, "not loaded")
	for _, call := range invocations(t, s) {
		if call == "--no-ask-password stop -- missing.service" {
			t.Fatal("an unknown unit must not be stopped")
		}
	}
}

func TestServiceStopTimesOutWithinItsPolicyBudget(t *testing.T) {
	h := serviceHarness(t)
	script(t, fakeScript{Unit: "jellyfin.service", Active: "active", StopSleeps: true})
	started := time.Now()
	expect(t, h.run(context.Background(), serviceStep("slow", "stop")), "failed", codeStop, "timed out after 1s")
	if elapsed := time.Since(started); elapsed > 4*time.Second {
		t.Fatalf("timeout took %s", elapsed)
	}
}

func TestAStoppedExecutionIsNotReportedAsAServiceFailure(t *testing.T) {
	h := serviceHarness(t)
	script(t, fakeScript{Unit: "plain.service", Active: "active", StopSleeps: true})
	ctx, cancel := context.WithTimeout(context.Background(), 300*time.Millisecond)
	defer cancel()
	expect(t, h.run(ctx, serviceStep("plain", "stop")), "failed", "execution_stopped")
}
