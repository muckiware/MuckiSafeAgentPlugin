// Package cli implements the mucki-agent command line. Run returns the exit
// code: 0 success, 1 failure, 2 usage error.
package cli

import (
	"flag"
	"fmt"
	"io"
	"log/slog"

	"github.com/muckiware/MuckiSafeAgentPlugin/agent/internal/api"
	"github.com/muckiware/MuckiSafeAgentPlugin/agent/internal/config"
	"github.com/muckiware/MuckiSafeAgentPlugin/agent/internal/daemon"
	"github.com/muckiware/MuckiSafeAgentPlugin/agent/internal/policy"
	"github.com/muckiware/MuckiSafeAgentPlugin/agent/internal/primitives"
	"github.com/muckiware/MuckiSafeAgentPlugin/agent/internal/version"
)

const usage = `usage:
  mucki-agent --version
  mucki-agent config validate [--config PATH]
  mucki-agent run [--config PATH]
`

// Run dispatches args (without the program name).
func Run(args []string, stdout, stderr io.Writer) int {
	switch {
	case len(args) == 1 && (args[0] == "--version" || args[0] == "version"):
		fmt.Fprintln(stdout, version.String())
		return 0
	case len(args) >= 2 && args[0] == "config" && args[1] == "validate":
		return validate(args[2:], stdout, stderr)
	case len(args) >= 1 && args[0] == "run":
		return runAgent(args[1:], stderr)
	default:
		fmt.Fprint(stderr, usage)
		return 2
	}
}

// runAgent starts the long-running agent; logs go to stderr (journald).
func runAgent(args []string, stderr io.Writer) int {
	path, ok := configFlag("run", args, stderr)
	if !ok {
		return 2
	}
	return daemon.Main(daemon.Options{ConfigPath: path}, stderr)
}

// configFlag parses the only option of the config-reading commands.
func configFlag(name string, args []string, stderr io.Writer) (string, bool) {
	flags := flag.NewFlagSet(name, flag.ContinueOnError)
	flags.SetOutput(io.Discard)
	path := flags.String("config", config.DefaultPath, "configuration file")
	if err := flags.Parse(args); err != nil || flags.NArg() != 0 {
		fmt.Fprint(stderr, usage)
		return "", false
	}
	return *path, true
}

// validate loads the configuration, the secret and the policy and builds the
// API client without contacting the server, so the same start-up checks and
// warnings apply as for the running agent.
func validate(args []string, stdout, stderr io.Writer) int {
	path, ok := configFlag("config validate", args, stderr)
	if !ok {
		return 2
	}
	cfg, err := config.Load(path)
	if err != nil {
		fmt.Fprintln(stderr, "config validate:", err)
		return 1
	}
	key, err := config.LoadSecret(cfg.Server.SecretFile)
	if err != nil {
		fmt.Fprintln(stderr, "config validate:", err)
		return 1
	}
	_, err = api.NewClient(api.ClientOptions{
		BaseURL:           cfg.Server.URL,
		AllowInsecureHTTP: cfg.Server.AllowInsecureHTTP,
		KeyID:             cfg.Server.KeyID,
		Key:               key,
		Timeout:           cfg.Server.RequestTimeout,
		Logger:            slog.New(slog.NewTextHandler(stderr, nil)),
	})
	if err != nil {
		fmt.Fprintln(stderr, "config validate:", err)
		return 1
	}
	if _, err := policy.Load(cfg.Agent.PolicyFile, primitives.PolicyDecoders()); err != nil {
		fmt.Fprintln(stderr, "config validate:", err)
		return 1
	}
	fmt.Fprintln(stdout, "configuration valid:", path)
	return 0
}
