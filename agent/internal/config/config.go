// Package config loads and validates the agent configuration and its secret.
//
// The YAML file is decoded strictly: unknown keys, a second document and any
// TLS-skip option are rejected. `https://` is the default transport; `http://`
// is accepted only with `server.allowInsecureHttp: true`. Validation errors
// name the offending key but never echo values that may carry credentials.
package config

import (
	"bytes"
	"errors"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"regexp"
	"syscall"
	"time"

	"gopkg.in/yaml.v3"

	"github.com/muckiware/MuckiSafeAgentPlugin/agent/internal/api"
)

// DefaultPath is the configuration path of a host installation.
const DefaultPath = "/etc/mucki-agent/config.yaml"

const (
	maxConfigBytes = 64 * 1024
	maxSecretBytes = 128
)

var keyIDPattern = regexp.MustCompile(`^[0-9a-f]{32}$`)

// Config is the validated agent configuration.
type Config struct {
	Server Server `yaml:"server"`
	Agent  Agent  `yaml:"agent"`
}

// Server describes the control plane endpoint and the agent credential.
type Server struct {
	URL               string        `yaml:"url"`
	AllowInsecureHTTP bool          `yaml:"allowInsecureHttp"`
	KeyID             string        `yaml:"keyId"`
	SecretFile        string        `yaml:"secretFile"`
	RequestTimeout    time.Duration `yaml:"requestTimeout"`
}

// Agent holds the local scheduling and filesystem settings.
type Agent struct {
	PollInterval      time.Duration `yaml:"pollInterval"`
	HeartbeatInterval time.Duration `yaml:"heartbeatInterval"`
	LeaseDuration     time.Duration `yaml:"leaseDuration"`
	MaxConcurrency    int           `yaml:"maxConcurrency"`
	WakeupDirectory   string        `yaml:"wakeupDirectory"`
	StateDirectory    string        `yaml:"stateDirectory"`
}

func defaults() Config {
	return Config{
		Server: Server{RequestTimeout: 30 * time.Second},
		Agent: Agent{
			PollInterval:      10 * time.Second,
			HeartbeatInterval: 10 * time.Second,
			LeaseDuration:     30 * time.Second,
			MaxConcurrency:    1,
			WakeupDirectory:   "/var/lib/mucki-agent/wakeup",
			StateDirectory:    "/var/lib/mucki-agent/state",
		},
	}
}

// InsecureTransport reports whether the control plane is reached over plain
// HTTP, which keeps integrity through HMAC but has no confidentiality.
func (c Config) InsecureTransport() bool {
	return c.Server.AllowInsecureHTTP && len(c.Server.URL) >= 7 && c.Server.URL[:7] == "http://"
}

// Load reads, strictly decodes and validates the configuration file.
func Load(path string) (Config, error) {
	raw, err := readLimited(path, maxConfigBytes)
	if err != nil {
		return Config{}, fmt.Errorf("config: %w", err)
	}
	cfg := defaults()
	decoder := yaml.NewDecoder(bytes.NewReader(raw))
	decoder.KnownFields(true)
	if err := decoder.Decode(&cfg); err != nil {
		return Config{}, fmt.Errorf("config: %w", err)
	}
	var extra any
	if err := decoder.Decode(&extra); !errors.Is(err, io.EOF) {
		return Config{}, errors.New("config: exactly one YAML document is allowed")
	}
	if err := cfg.validate(); err != nil {
		return Config{}, fmt.Errorf("config: %w", err)
	}
	return cfg, nil
}

func (c Config) validate() error {
	if err := api.ValidateServerURL(c.Server.URL, c.Server.AllowInsecureHTTP); err != nil {
		return err
	}
	if !keyIDPattern.MatchString(c.Server.KeyID) {
		return errors.New("server.keyId must be the 32-character lowercase hex key id")
	}
	if !cleanAbsolute(c.Server.SecretFile) {
		return errors.New("server.secretFile must be a clean absolute path")
	}
	if c.Server.RequestTimeout < time.Second || c.Server.RequestTimeout > 5*time.Minute {
		return errors.New("server.requestTimeout must be between 1s and 5m")
	}
	a := c.Agent
	switch {
	case a.PollInterval < time.Second || a.PollInterval > time.Hour:
		return errors.New("agent.pollInterval must be between 1s and 1h")
	case a.LeaseDuration < 2*time.Second || a.LeaseDuration > 10*time.Minute:
		return errors.New("agent.leaseDuration must be between 2s and 10m")
	case a.HeartbeatInterval < time.Second || 2*a.HeartbeatInterval > a.LeaseDuration:
		return errors.New("agent.heartbeatInterval must be at least 1s and at most half of agent.leaseDuration")
	case a.MaxConcurrency != 1:
		return errors.New("agent.maxConcurrency is fixed to 1 in V1")
	case !cleanAbsolute(a.WakeupDirectory):
		return errors.New("agent.wakeupDirectory must be a clean absolute path")
	case !cleanAbsolute(a.StateDirectory):
		return errors.New("agent.stateDirectory must be a clean absolute path")
	}
	return nil
}

func cleanAbsolute(path string) bool {
	return path != "" && filepath.IsAbs(path) && filepath.Clean(path) == path
}

// LoadSecret reads the base64url agent secret and returns the decoded 32-byte
// HMAC key. The file must be a regular file owned by the current user without
// group or other permissions; a symlink is refused by O_NOFOLLOW. Exactly one
// trailing LF is tolerated. Errors never contain secret material.
func LoadSecret(path string) ([]byte, error) {
	file, err := os.OpenFile(path, os.O_RDONLY|syscall.O_NOFOLLOW|syscall.O_NONBLOCK, 0)
	if err != nil {
		return nil, fmt.Errorf("secret file: %w", err)
	}
	defer file.Close()
	info, err := file.Stat()
	if err != nil {
		return nil, fmt.Errorf("secret file: %w", err)
	}
	if err := checkPrivate(info); err != nil {
		return nil, err
	}
	raw, err := io.ReadAll(io.LimitReader(file, maxSecretBytes+1))
	if err != nil {
		return nil, fmt.Errorf("secret file: %w", err)
	}
	raw = bytes.TrimSuffix(raw, []byte("\n"))
	key, err := api.DecodeSecret(string(raw))
	if err != nil {
		return nil, errors.New("secret file: content is not a valid 32-byte base64url agent secret")
	}
	return key, nil
}

func checkPrivate(info os.FileInfo) error {
	if !info.Mode().IsRegular() {
		return errors.New("secret file: not a regular file")
	}
	if info.Mode().Perm()&0o077 != 0 {
		return errors.New("secret file: must not be accessible by group or others (use mode 0600 or 0400)")
	}
	if stat, ok := info.Sys().(*syscall.Stat_t); ok && int(stat.Uid) != os.Geteuid() {
		return errors.New("secret file: must be owned by the user running the agent")
	}
	return nil
}

func readLimited(path string, limit int64) ([]byte, error) {
	file, err := os.Open(path)
	if err != nil {
		return nil, err
	}
	defer file.Close()
	raw, err := io.ReadAll(io.LimitReader(file, limit+1))
	if err != nil {
		return nil, err
	}
	if int64(len(raw)) > limit {
		return nil, fmt.Errorf("file exceeds %d bytes", limit)
	}
	return raw, nil
}
