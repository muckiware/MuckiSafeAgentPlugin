// Package cli implements the mucki-agent command line. Run returns the exit
// code: 0 success, 1 failure, 2 usage error.
package cli

import (
	"flag"
	"fmt"
	"io"
	"log/slog"
	"strings"
	"unicode"

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
  mucki-agent policy writable-paths [--config PATH] [--format plain|systemd]
`

// Run dispatches args (without the program name).
func Run(args []string, stdout, stderr io.Writer) int {
	switch {
	case len(args) == 1 && (args[0] == "--version" || args[0] == "version"):
		fmt.Fprintln(stdout, version.String())
		return 0
	case len(args) >= 2 && args[0] == "config" && args[1] == "validate":
		return validate(args[2:], stdout, stderr)
	case len(args) >= 2 && args[0] == "policy" && args[1] == "writable-paths":
		return writablePaths(args[2:], stdout, stderr)
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

// writablePaths prints the host paths the policy lets the primitives modify
// (path alias roots and backup destinations), plain or as ReadWritePaths=
// lines of the systemd drop-in install.sh writes.
func writablePaths(args []string, stdout, stderr io.Writer) int {
	flags := flag.NewFlagSet("policy writable-paths", flag.ContinueOnError)
	flags.SetOutput(io.Discard)
	path := flags.String("config", config.DefaultPath, "configuration file")
	format := flags.String("format", "plain", "plain or systemd")
	if err := flags.Parse(args); err != nil || flags.NArg() != 0 || (*format != "plain" && *format != "systemd") {
		fmt.Fprint(stderr, usage)
		return 2
	}
	lines, err := loadWritablePaths(*path, *format == "systemd")
	if err != nil {
		fmt.Fprintln(stderr, "policy writable-paths:", err)
		return 1
	}
	for _, line := range lines {
		fmt.Fprintln(stdout, line)
	}
	return 0
}

func loadWritablePaths(configPath string, systemd bool) ([]string, error) {
	cfg, err := config.Load(configPath)
	if err != nil {
		return nil, err
	}
	loaded, err := policy.Load(cfg.Agent.PolicyFile, primitives.PolicyDecoders())
	if err != nil {
		return nil, err
	}
	paths, err := primitives.WritablePaths(loaded)
	if err != nil || !systemd {
		return paths, err
	}
	for i, path := range paths {
		if paths[i], err = readWritePathsLine(path); err != nil {
			return nil, err
		}
	}
	return paths, nil
}

// readWritePathsLine quotes path for a unit file: "-" tolerates a missing
// directory, backslash and quote are C-escaped and "%" is no specifier.
func readWritePathsLine(path string) (string, error) {
	for _, r := range path {
		if unicode.IsControl(r) {
			return "", fmt.Errorf("%q contains a control character systemd cannot express", path)
		}
	}
	escaped := strings.NewReplacer(`\`, `\\`, `"`, `\"`, "%", "%%").Replace(path)
	return `ReadWritePaths="-` + escaped + `"`, nil
}
