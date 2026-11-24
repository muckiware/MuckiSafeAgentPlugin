package executor

import (
	"bytes"
	"context"
	"errors"
	"strconv"
	"strings"
	"syscall"
	"testing"
	"time"
)

func runner(t *testing.T) *ProcessRunner {
	t.Helper()
	return &ProcessRunner{
		Executables: map[string]bool{helperBinary(t): true},
		TermGrace:   300 * time.Millisecond,
		KillWait:    300 * time.Millisecond,
	}
}

func helperCommand(t *testing.T, args ...string) Command {
	return Command{Path: helperBinary(t), Args: append([]string{"muwa-helper"}, args...)}
}

func TestProcessOutputAndExitCodeAreCaptured(t *testing.T) {
	var stdout, stderr bytes.Buffer
	cmd := helperCommand(t, "print", "out", "err", "3")
	cmd.Stdout, cmd.Stderr = &stdout, &stderr

	status, err := runner(t).Run(context.Background(), cmd)
	if err != nil || status.ExitCode != 3 || status.Signaled {
		t.Fatalf("status %+v, err %v", status, err)
	}
	// coverage-instrumented helpers may append a GOCOVERDIR notice to stderr
	if stdout.String() != "out" || !strings.HasPrefix(stderr.String(), "err") {
		t.Fatalf("stdout %q, stderr %q", stdout.String(), stderr.String())
	}
}

func TestArgvIsPassedVerbatimWithoutAShell(t *testing.T) {
	var stdout bytes.Buffer
	cmd := helperCommand(t, "argv", "$(id)", "a b", "; rm -rf /", "*")
	cmd.Stdout = &stdout

	if _, err := runner(t).Run(context.Background(), cmd); err != nil {
		t.Fatal(err)
	}
	if stdout.String() != "$(id)|a b|; rm -rf /|*" {
		t.Fatalf("argv was interpreted: %q", stdout.String())
	}
}

func TestOnlyAllowlistedVerifiedExecutablesRun(t *testing.T) {
	r := runner(t)
	// /bin/ls is a verifiable root-owned binary that is simply not allowlisted
	for _, path := range []string{"/bin/ls", "/bin/sh", "/bin/bash", "/usr/bin/env", "relative/tool", helperBinary(t) + "/../x"} {
		if _, err := r.Run(context.Background(), Command{Path: path}); !errors.Is(err, ErrExecutableNotAllowed) {
			t.Errorf("%s: expected ErrExecutableNotAllowed, got %v", path, err)
		}
	}
	shells := &ProcessRunner{Executables: map[string]bool{"/bin/sh": true}}
	if _, err := shells.Run(context.Background(), Command{Path: "/bin/sh", Args: []string{"-c", "true"}}); !errors.Is(err, ErrExecutableNotAllowed) {
		t.Fatalf("a shell must never be allowlisted, got %v", err)
	}
}

func TestDefaultAllowlistIsFixed(t *testing.T) {
	r := NewProcessRunner()
	if len(r.Executables) != 2 || !r.Executables["/usr/bin/systemctl"] || !r.Executables["/usr/bin/docker"] {
		t.Fatalf("unexpected default allowlist %v", r.Executables)
	}
	if r.TermGrace != 5*time.Second {
		t.Fatalf("SIGTERM grace must be 5s, got %s", r.TermGrace)
	}
}

func TestEnvironmentIsAMinimalAllowlist(t *testing.T) {
	t.Setenv("MUWA_AGENT_SECRET_LEAK", "do-not-pass")
	var stdout bytes.Buffer
	cmd := helperCommand(t, "env")
	cmd.Stdout = &stdout
	cmd.Env = map[string]string{"SYSTEMD_COLORS": "0"}

	if _, err := runner(t).Run(context.Background(), cmd); err != nil {
		t.Fatal(err)
	}
	env := stdout.String()
	if strings.Contains(env, "do-not-pass") || !strings.Contains(env, "PATH=/usr/sbin:/usr/bin:/sbin:/bin") || !strings.Contains(env, "SYSTEMD_COLORS=0") {
		t.Fatalf("environment %q", env)
	}
	for _, line := range strings.Split(env, "\n") {
		key, _, _ := strings.Cut(line, "=")
		if !map[string]bool{"PATH": true, "LANG": true, "LC_ALL": true, "HOME": true, "SYSTEMD_COLORS": true, "__CF_USER_TEXT_ENCODING": true}[key] {
			t.Errorf("unexpected variable %q", key)
		}
	}

	cmd.Env = map[string]string{"LD_PRELOAD": "/tmp/evil.so"}
	if _, err := runner(t).Run(context.Background(), cmd); err == nil {
		t.Fatal("variables outside the allowlist must be rejected")
	}
}

func TestCancellationTerminatesTheWholeProcessGroup(t *testing.T) {
	var stdout syncBuffer
	cmd := helperCommand(t, "spawn-child", "and-wait")
	cmd.Stdout = &stdout
	ctx, cancel := context.WithCancel(context.Background())
	done := make(chan ProcessStatus, 1)
	go func() {
		status, _ := runner(t).Run(ctx, cmd)
		done <- status
	}()
	waitUntil(t, func() bool { return stdout.String() != "" })
	child, _ := strconv.Atoi(stdout.String())
	cancel()

	status := <-done
	if !status.Signaled || status.Signal != syscall.SIGTERM {
		t.Fatalf("expected SIGTERM termination, got %+v", status)
	}
	waitUntil(t, func() bool { return !alive(child) })
}

func TestIgnoredSIGTERMIsFollowedBySIGKILLAfterTheGrace(t *testing.T) {
	var stdout syncBuffer
	cmd := helperCommand(t, "ignore-term")
	cmd.Stdout = &stdout
	ctx, cancel := context.WithCancel(context.Background())
	r := runner(t)
	done := make(chan ProcessStatus, 1)
	go func() {
		status, _ := r.Run(ctx, cmd)
		done <- status
	}()
	waitUntil(t, func() bool { return stdout.String() == "ready" })
	start := time.Now()
	cancel()

	status := <-done
	elapsed := time.Since(start)
	if !status.Signaled || status.Signal != syscall.SIGKILL {
		t.Fatalf("expected SIGKILL, got %+v", status)
	}
	if elapsed < r.TermGrace || elapsed > r.TermGrace+r.KillWait+time.Second {
		t.Fatalf("SIGKILL after %s, grace %s", elapsed, r.TermGrace)
	}
}

func TestUnkillableProcessIsReportedAsNotTerminated(t *testing.T) {
	var stdout syncBuffer
	cmd := helperCommand(t, "ignore-term")
	cmd.Stdout = &stdout
	r := runner(t)
	var pgid int
	r.signal = func(target int, sig syscall.Signal) error {
		pgid = -target
		if sig == syscall.SIGKILL {
			return nil // D-state: SIGKILL has no effect
		}
		return syscall.Kill(target, sig)
	}
	t.Cleanup(func() { _ = syscall.Kill(-pgid, syscall.SIGKILL) })
	ctx, cancel := context.WithCancel(context.Background())
	done := make(chan error, 1)
	go func() {
		_, err := r.Run(ctx, cmd)
		done <- err
	}()
	waitUntil(t, func() bool { return stdout.String() == "ready" })
	cancel()

	if err := <-done; !errors.Is(err, ErrNotTerminated) {
		t.Fatalf("expected ErrNotTerminated, got %v", err)
	}
}

func TestLeftoverGroupMembersAreKilledAfterANormalExit(t *testing.T) {
	var stdout bytes.Buffer
	cmd := helperCommand(t, "spawn-child")
	cmd.Stdout = &stdout

	status, err := runner(t).Run(context.Background(), cmd)
	if err != nil || status.ExitCode != 0 {
		t.Fatalf("status %+v, err %v", status, err)
	}
	child, _ := strconv.Atoi(stdout.String())
	waitUntil(t, func() bool { return !alive(child) })
}

func TestAlreadyCancelledContextStartsNothing(t *testing.T) {
	ctx, cancel := context.WithCancel(context.Background())
	cancel()
	if _, err := runner(t).Run(ctx, helperCommand(t, "sleep")); !errors.Is(err, context.Canceled) {
		t.Fatalf("expected context.Canceled, got %v", err)
	}
}

func waitUntil(t *testing.T, condition func() bool) {
	t.Helper()
	deadline := time.Now().Add(5 * time.Second)
	for !condition() {
		if time.Now().After(deadline) {
			t.Fatal("condition not reached")
		}
		time.Sleep(10 * time.Millisecond)
	}
}

func TestAProcessStoppedByCancellationIsNeverASuccess(t *testing.T) {
	ctx, cancel := context.WithCancel(context.Background())
	go func() {
		time.Sleep(100 * time.Millisecond)
		cancel()
	}()
	status, err := runner(t).Run(ctx, helperCommand(t, "sleep"))
	if !errors.Is(err, ErrStopped) || !status.Signaled {
		t.Fatalf("expected ErrStopped with the signal status, got %+v / %v", status, err)
	}
}
