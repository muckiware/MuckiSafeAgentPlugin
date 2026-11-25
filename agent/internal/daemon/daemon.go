// Package daemon composes the long-running agent process (`mucki-agent run`).
//
// Start-up loads config, secret and policy through verified, symlink-free
// descriptors, opens the journal and settles the records of a previous
// process before anything new is claimed; any failure aborts the start
// before the control plane is contacted. Afterwards the host heartbeat, the
// wakeup watcher and the claim loop run until SIGTERM/SIGINT. Shutdown stops
// new claims; a running job continues within the runner's shutdown grace and
// is journalled and completed before the process exits. SIGHUP reloads the
// policy atomically and keeps the previous snapshot if the new one is invalid.
package daemon

import (
	"context"
	"encoding/base64"
	"errors"
	"io"
	"log/slog"
	"os"
	"os/signal"
	"slices"
	"sync"
	"syscall"
	"time"

	"github.com/muckiware/MuckiSafeAgentPlugin/agent/internal/agent"
	"github.com/muckiware/MuckiSafeAgentPlugin/agent/internal/api"
	"github.com/muckiware/MuckiSafeAgentPlugin/agent/internal/config"
	"github.com/muckiware/MuckiSafeAgentPlugin/agent/internal/executor"
	"github.com/muckiware/MuckiSafeAgentPlugin/agent/internal/idempotency"
	"github.com/muckiware/MuckiSafeAgentPlugin/agent/internal/policy"
	"github.com/muckiware/MuckiSafeAgentPlugin/agent/internal/primitives"
	"github.com/muckiware/MuckiSafeAgentPlugin/agent/internal/version"
	"github.com/muckiware/MuckiSafeAgentPlugin/agent/internal/wakeup"
)

// Options configures one agent process. Everything except ConfigPath is a
// test seam; zero values select the production behaviour.
type Options struct {
	ConfigPath    string
	Primitives    map[string]executor.Primitive
	Processes     *executor.ProcessRunner
	ShutdownGrace time.Duration
}

// startupError marks failures before the agent contacted the control plane.
type startupError struct{ err error }

func (e startupError) Error() string { return e.err.Error() }
func (e startupError) Unwrap() error { return e.err }

// Main runs the agent with signal handling and returns the exit code:
// 0 after a graceful shutdown, 1 if the start or the run failed.
func Main(opts Options, logs io.Writer) int {
	logger := newLogger(logs)
	ctx, stop := signal.NotifyContext(context.Background(), syscall.SIGTERM, syscall.SIGINT)
	defer stop()
	reload := make(chan os.Signal, 1)
	signal.Notify(reload, syscall.SIGHUP)
	defer signal.Stop(reload)

	err := Run(ctx, reload, opts, logger)
	var startup startupError
	switch {
	case errors.As(err, &startup):
		logger.Error("agent cannot start", "error", err)
		return 1
	case err != nil:
		logger.Error("agent stopped with an error", "error", err)
		return 1
	}
	logger.Info("agent stopped")
	return 0
}

func newLogger(logs io.Writer) *slog.Logger {
	return slog.New(slog.NewTextHandler(logs, nil))
}

// Run starts the agent and blocks until ctx ends or a terminal error occurs.
// Every reload signal reloads the policy.
func Run(ctx context.Context, reload <-chan os.Signal, opts Options, logger *slog.Logger) error {
	p, err := setup(opts, logger)
	if err != nil {
		return startupError{err}
	}
	defer p.journal.Close()
	if err := p.runner.Recover(ctx); err != nil {
		return startupError{err}
	}
	logger.Info("agent started", "version", version.Version, "config", opts.ConfigPath,
		"insecureTransport", p.cfg.InsecureTransport(), "capabilities", p.capabilities)
	return p.serve(ctx, reload)
}

// process holds the verified dependencies of one agent run.
type process struct {
	cfg          config.Config
	client       *api.Client
	policies     *policy.Store
	journal      *idempotency.Journal
	runner       *agent.Runner
	capabilities []string
	logger       *slog.Logger
}

func setup(opts Options, logger *slog.Logger) (*process, error) {
	cfg, err := config.Load(opts.ConfigPath)
	if err != nil {
		return nil, err
	}
	key, err := config.LoadSecret(cfg.Server.SecretFile)
	if err != nil {
		return nil, err
	}
	client, err := api.NewClient(api.ClientOptions{BaseURL: cfg.Server.URL, AllowInsecureHTTP: cfg.Server.AllowInsecureHTTP,
		KeyID: cfg.Server.KeyID, Key: key, Timeout: cfg.Server.RequestTimeout, Logger: logger})
	if err != nil {
		return nil, err
	}
	policies, err := policy.NewStore(cfg.Agent.PolicyFile, primitives.PolicyDecoders())
	if err != nil {
		return nil, err
	}
	journal, err := idempotency.Open(cfg.Agent.StateDirectory)
	if err != nil {
		return nil, err
	}
	p := &process{cfg: cfg, client: client, policies: policies, journal: journal, logger: logger}
	if err := p.buildRunner(opts, base64.RawURLEncoding.EncodeToString(key)); err != nil {
		_ = journal.Close()
		return nil, err
	}
	return p, nil
}

// buildRunner wires the executor; the agent secret is redacted from every
// output tail.
func (p *process) buildRunner(opts Options, secret string) error {
	available := opts.Primitives
	if available == nil {
		available = primitives.New(primitives.Options{StateDirectory: p.cfg.Agent.StateDirectory})
	}
	processes := opts.Processes
	if processes == nil {
		processes = executor.NewProcessRunner()
	}
	exec := &executor.Executor{Policies: p.policies, Primitives: available, Processes: processes,
		Redactor: executor.NewRedactor(secret), Logger: p.logger}
	runner, err := agent.NewRunner(agent.RunnerConfig{API: p.client, Journal: p.journal, Executor: exec, Logger: p.logger,
		HeartbeatInterval: p.cfg.Agent.HeartbeatInterval, ShutdownGrace: opts.ShutdownGrace})
	if err != nil {
		return err
	}
	p.runner = runner
	p.capabilities = sortedNames(available)
	return nil
}

func sortedNames(available map[string]executor.Primitive) []string {
	names := make([]string, 0, len(available))
	for name := range available {
		names = append(names, name)
	}
	slices.Sort(names)
	return names
}

// serve runs heartbeat, watcher and policy reload beside the claim loop. The
// first terminal error of a background task ends the whole agent.
func (p *process) serve(ctx context.Context, reload <-chan os.Signal) error {
	ctx, cancel := context.WithCancel(ctx)
	defer cancel()
	failures := make(chan error, 3)
	var wg sync.WaitGroup
	background := func(run func(context.Context) error) {
		wg.Add(1)
		go func() {
			defer wg.Done()
			if err := run(ctx); err != nil {
				failures <- err
				cancel()
			}
		}()
	}
	wake := make(chan struct{}, 1)
	background(func(ctx context.Context) error {
		return wakeup.Watcher{Directory: p.cfg.Agent.WakeupDirectory, PollInterval: p.cfg.Agent.PollInterval, Logger: p.logger}.Run(ctx, wake)
	})
	background(agent.HostHeartbeat{API: p.client, Interval: p.cfg.Agent.HeartbeatInterval, Capabilities: p.capabilities, Logger: p.logger}.Run)
	background(func(ctx context.Context) error { p.reloadOn(ctx, reload); return nil })

	loop := &agent.ClaimLoop{API: p.client, Runner: p.runner, Wake: wake, Backoff: api.DefaultBackoff(), Logger: p.logger}
	err := loop.Run(ctx)
	cancel()
	wg.Wait()
	close(failures)
	return errors.Join(err, <-failures)
}

func (p *process) reloadOn(ctx context.Context, reload <-chan os.Signal) {
	for {
		select {
		case <-ctx.Done():
			return
		case <-reload:
			if err := p.policies.Reload(); err != nil {
				p.logger.Error("policy reload failed, previous policy stays active", "error", err)
				continue
			}
			p.logger.Info("policy reloaded")
		}
	}
}
