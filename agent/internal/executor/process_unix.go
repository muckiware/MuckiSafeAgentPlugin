//go:build unix

package executor

import (
	"context"
	"errors"
	"fmt"
	"io"
	"os/exec"
	"path/filepath"
	"sort"
	"syscall"
	"time"

	"github.com/muckiware/MuckiSafeAgentPlugin/agent/internal/securefs"
)

var (
	// ErrExecutableNotAllowed rejects every executable outside the fixed
	// allowlist, every shell or interpreter and every unverifiable path.
	ErrExecutableNotAllowed = errors.New("executor: executable not allowed")
	// ErrStopped means the process was terminated because the execution
	// was stopped; it is never a success, whatever the exit status says.
	ErrStopped = errors.New("executor: process stopped")
	// ErrNotTerminated means the process group survived SIGKILL (e.g.
	// uninterruptible sleep); the host effect is unknown and not retryable.
	ErrNotTerminated = errors.New("executor: process group did not terminate")
)

// forbidden names can never run, even if a future allowlist lists them.
var forbidden = map[string]bool{
	"sh": true, "bash": true, "dash": true, "zsh": true, "ash": true, "ksh": true, "csh": true, "tcsh": true, "fish": true,
	"busybox": true, "env": true, "sudo": true, "su": true, "doas": true, "xargs": true,
	"python": true, "python3": true, "perl": true, "ruby": true, "node": true, "php": true,
}

// ForbiddenProgram reports whether program names a shell, interpreter or
// privilege/indirection tool that must never be the entry point of a command.
func ForbiddenProgram(program string) bool {
	return forbidden[filepath.Base(program)]
}

// baseEnvironment is the complete inherited environment of every process.
var baseEnvironment = map[string]string{
	"PATH":   "/usr/sbin:/usr/bin:/sbin:/bin",
	"LANG":   "C",
	"LC_ALL": "C",
	"HOME":   "/root",
}

// allowedEnvironment are the only keys a primitive may add.
var allowedEnvironment = map[string]bool{"SYSTEMD_COLORS": true, "SYSTEMD_PAGER": true, "NO_COLOR": true}

// Command is one external process with a separated argv; nothing is ever
// interpreted by a shell. Nil writers discard output, stdin is /dev/null.
type Command struct {
	Path   string
	Args   []string
	Env    map[string]string
	Stdout io.Writer
	Stderr io.Writer
}

// ProcessStatus describes how the process ended.
type ProcessStatus struct {
	ExitCode int
	Signaled bool
	Signal   syscall.Signal
}

// ProcessRunner starts allowlisted executables in their own process group
// and stops the whole group on cancellation: SIGTERM, TermGrace (at most
// 5 seconds), SIGKILL, then at most KillWait for the exit.
type ProcessRunner struct {
	Executables map[string]bool
	TermGrace   time.Duration
	KillWait    time.Duration
	// signal is replaceable in tests to emulate an unkillable process.
	signal func(pid int, sig syscall.Signal) error
}

// NewProcessRunner allows exactly the V1 executables of the primitive policy.
func NewProcessRunner() *ProcessRunner {
	return &ProcessRunner{
		Executables: map[string]bool{"/usr/bin/systemctl": true, "/usr/bin/docker": true},
		TermGrace:   5 * time.Second,
		KillWait:    500 * time.Millisecond,
	}
}

// Run executes cmd and returns its status. A cancelled ctx stops the group
// and returns ErrStopped; ErrNotTerminated reports a group that survived
// SIGKILL.
func (r *ProcessRunner) Run(ctx context.Context, cmd Command) (ProcessStatus, error) {
	if err := r.check(cmd.Path); err != nil {
		return ProcessStatus{}, err
	}
	env, err := environment(cmd.Env)
	if err != nil {
		return ProcessStatus{}, err
	}
	if err := ctx.Err(); err != nil {
		return ProcessStatus{}, err
	}
	process := &exec.Cmd{
		Path:        cmd.Path,
		Args:        append([]string{cmd.Path}, cmd.Args...),
		Env:         env,
		Stdout:      cmd.Stdout,
		Stderr:      cmd.Stderr,
		SysProcAttr: &syscall.SysProcAttr{Setpgid: true},
		WaitDelay:   r.KillWait,
	}
	if err := process.Start(); err != nil {
		return ProcessStatus{}, fmt.Errorf("executor: start %s: %w", cmd.Path, err)
	}
	group := -process.Process.Pid
	exited := make(chan struct{})
	go func() {
		_ = process.Wait()
		close(exited)
	}()
	defer func() { _ = r.kill(group, syscall.SIGKILL) }() // stragglers of the group

	select {
	case <-exited:
		return status(process), nil
	case <-ctx.Done():
	}
	stopped := fmt.Errorf("%w: %v", ErrStopped, context.Cause(ctx))
	_ = r.kill(group, syscall.SIGTERM)
	if waitFor(exited, r.TermGrace) {
		return status(process), stopped
	}
	_ = r.kill(group, syscall.SIGKILL)
	if waitFor(exited, r.KillWait) {
		return status(process), stopped
	}
	return ProcessStatus{}, ErrNotTerminated
}

func (r *ProcessRunner) check(path string) error {
	if path == "" || !filepath.IsAbs(path) || filepath.Clean(path) != path {
		return fmt.Errorf("%w: %q is not a clean absolute path", ErrExecutableNotAllowed, path)
	}
	if !r.Executables[path] || forbidden[filepath.Base(path)] {
		return fmt.Errorf("%w: %s", ErrExecutableNotAllowed, path)
	}
	if err := securefs.VerifyExecutable(path); err != nil {
		return fmt.Errorf("%w: %v", ErrExecutableNotAllowed, err)
	}
	return nil
}

func (r *ProcessRunner) kill(group int, sig syscall.Signal) error {
	if r.signal != nil {
		return r.signal(group, sig)
	}
	return syscall.Kill(group, sig)
}

func environment(extra map[string]string) ([]string, error) {
	merged := map[string]string{}
	for key, value := range baseEnvironment {
		merged[key] = value
	}
	for key, value := range extra {
		if !allowedEnvironment[key] {
			return nil, fmt.Errorf("executor: environment variable %q is not allowed", key)
		}
		merged[key] = value
	}
	env := make([]string, 0, len(merged))
	for key, value := range merged {
		env = append(env, key+"="+value)
	}
	sort.Strings(env)
	return env, nil
}

func waitFor(done <-chan struct{}, d time.Duration) bool {
	timer := time.NewTimer(d)
	defer timer.Stop()
	select {
	case <-done:
		return true
	case <-timer.C:
		return false
	}
}

func status(process *exec.Cmd) ProcessStatus {
	state := process.ProcessState
	if state == nil {
		return ProcessStatus{ExitCode: -1}
	}
	if ws, ok := state.Sys().(syscall.WaitStatus); ok && ws.Signaled() {
		return ProcessStatus{ExitCode: -1, Signaled: true, Signal: ws.Signal()}
	}
	return ProcessStatus{ExitCode: state.ExitCode()}
}
