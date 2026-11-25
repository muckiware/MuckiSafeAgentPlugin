package primitives

import (
	"bufio"
	"bytes"
	"context"
	"errors"
	"strings"
	"time"

	"github.com/muckiware/MuckiSafeAgentPlugin/agent/internal/executor"
)

// serviceControl starts, stops or restarts the exact systemd unit of a
// service alias through `systemctl --no-ask-password` and verifies the unit
// state afterwards. With requireEmptyCgroupAfterStop, a stop succeeds only
// once the unit is inactive and its cgroup holds no process.
type serviceControl struct {
	systemctl  string
	cgroupRoot string
	// requireCgroup2 refuses cgroup checks outside a cgroup v2 mount.
	requireCgroup2 bool
}

// unitState is the part of `systemctl show` the primitive relies on.
type unitState struct {
	LoadState    string
	ActiveState  string
	ControlGroup string
}

func (s *serviceControl) Run(ctx context.Context, step executor.StepContext) error {
	entry, alias, err := resolve[serviceEntry](step, "serviceAlias", sectionServices)
	if err != nil {
		return err
	}
	operation, _ := step.Step["operation"].(string)
	code := codeStart
	if operation == "stop" {
		code = codeStop
	}
	before, err := s.show(ctx, step, entry.Unit, code)
	if err != nil {
		return err
	}
	if before.LoadState != "loaded" {
		return failure(code, "service %s: unit %s is not loaded (%s)", alias, entry.Unit, before.LoadState)
	}
	timeout := time.Duration(entry.StartTimeoutSeconds) * time.Second
	if operation != "start" {
		timeout = time.Duration(entry.StopTimeoutSeconds) * time.Second
	}
	if operation == "restart" {
		timeout += time.Duration(entry.StartTimeoutSeconds) * time.Second
	}
	if err := s.control(ctx, step, timeout, operation, entry.Unit, code); err != nil {
		return err
	}
	return s.verify(ctx, step, alias, entry, operation, before.ControlGroup, code)
}

func (s *serviceControl) verify(ctx context.Context, step executor.StepContext, alias string, entry serviceEntry, operation, groupBefore, code string) error {
	after, err := s.show(ctx, step, entry.Unit, code)
	if err != nil {
		return err
	}
	want := "active"
	if operation == "stop" {
		want = "inactive"
	}
	if after.ActiveState != want {
		return failure(code, "service %s: unit %s is %s after %s, expected %s", alias, entry.Unit, after.ActiveState, operation, want)
	}
	if operation != "stop" || !entry.RequireEmptyCgroupAfterStop {
		return nil
	}
	for _, group := range []string{groupBefore, after.ControlGroup} {
		if err := s.cgroupEmpty(group); err != nil {
			return failure(code, "service %s: %v", alias, err)
		}
	}
	return nil
}

func (s *serviceControl) control(ctx context.Context, step executor.StepContext, timeout time.Duration, operation, unit, code string) error {
	status, err := runBounded(ctx, step, executor.Command{
		Path: s.systemctl,
		Args: []string{"--no-ask-password", operation, "--", unit},
		Env:  map[string]string{"SYSTEMD_COLORS": "0"},
	}, timeout, code)
	if err != nil {
		return err
	}
	if status.ExitCode != 0 {
		return failure(code, "systemctl %s %s exited with %d", operation, unit, status.ExitCode)
	}
	return nil
}

// show reads the unit state; its output never enters the job tails.
func (s *serviceControl) show(ctx context.Context, step executor.StepContext, unit, code string) (unitState, error) {
	output := &limitedBuffer{limit: 8192}
	status, err := runBounded(ctx, step, executor.Command{
		Path:   s.systemctl,
		Args:   []string{"--no-ask-password", "show", "--property=LoadState", "--property=ActiveState", "--property=ControlGroup", "--", unit},
		Env:    map[string]string{"SYSTEMD_COLORS": "0"},
		Stdout: output,
	}, 30*time.Second, code)
	if err != nil {
		return unitState{}, err
	}
	if status.ExitCode != 0 || output.overflow {
		return unitState{}, failure(code, "systemctl show %s failed with %d", unit, status.ExitCode)
	}
	var state unitState
	scanner := bufio.NewScanner(bytes.NewReader(output.Bytes()))
	for scanner.Scan() {
		key, value, _ := strings.Cut(scanner.Text(), "=")
		switch key {
		case "LoadState":
			state.LoadState = value
		case "ActiveState":
			state.ActiveState = value
		case "ControlGroup":
			state.ControlGroup = value
		}
	}
	if state.LoadState == "" || state.ActiveState == "" {
		return unitState{}, failure(code, "systemctl show %s returned no unit state", unit)
	}
	return state, nil
}

// runBounded runs cmd with a local step timeout. Its expiry is a failure
// with code; a stopped execution or an unterminated process is returned
// unchanged so the executor reports it as such.
func runBounded(ctx context.Context, step executor.StepContext, cmd executor.Command, timeout time.Duration, code string) (executor.ProcessStatus, error) {
	local, cancel := context.WithTimeout(ctx, timeout)
	defer cancel()
	status, err := step.Run(local, cmd)
	if errors.Is(err, executor.ErrStopped) && ctx.Err() == nil {
		return status, failure(code, "%s %s timed out after %s", cmd.Path, strings.Join(cmd.Args, " "), timeout)
	}
	return status, err
}

// limitedBuffer keeps at most limit bytes and records an overflow.
type limitedBuffer struct {
	bytes.Buffer
	limit    int
	overflow bool
}

func (b *limitedBuffer) Write(p []byte) (int, error) {
	if room := b.limit - b.Len(); len(p) > room {
		b.overflow = true
		b.Buffer.Write(p[:max(room, 0)])
		return len(p), nil
	}
	return b.Buffer.Write(p)
}
