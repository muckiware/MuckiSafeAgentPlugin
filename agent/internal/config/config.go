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
	"path/filepath"
	"regexp"
	"time"

	"gopkg.in/yaml.v3"

	"github.com/muckiware/MuckiSafeAgentPlugin/agent/internal/api"
	"github.com/muckiware/MuckiSafeAgentPlugin/agent/internal/securefs"
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
	PolicyFile        string        `yaml:"policyFile"`
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
			PolicyFile:        "/etc/mucki-agent/policy.yaml",
		},
	}
}

// InsecureTransport reports whether the control plane is reached over plain
// HTTP, which keeps integrity through HMAC but has no confidentiality.
func (c Config) InsecureTransport() bool {
	return c.Server.AllowInsecureHTTP && len(c.Server.URL) >= 7 && c.Server.URL[:7] == "http://"
}

// Load reads the configuration through a verified, symlink-free descriptor
// (owner: agent user, mode at most 0600) and validates it.
func Load(path string) (Config, error) {
	raw, err := securefs.ReadFile(path, 0o600, maxConfigBytes)
	if err != nil {
		return Config{}, fmt.Errorf("config: %w", err)
	}
	return Parse(raw)
}

// Parse strictly decodes and validates configuration content.
func Parse(raw []byte) (Config, error) {
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
	case !cleanAbsolute(a.PolicyFile):
		return errors.New("agent.policyFile must be a clean absolute path")
	}
	return nil
}

func cleanAbsolute(path string) bool {
	return path != "" && filepath.IsAbs(path) && filepath.Clean(path) == path
}

// LoadSecret reads the base64url agent secret through a verified,
// symlink-free descriptor (owner: agent user, mode at most 0600, link count
// 1) and returns the decoded 32-byte HMAC key. Exactly one trailing LF is
// tolerated. Errors never contain secret material.
func LoadSecret(path string) ([]byte, error) {
	raw, err := securefs.ReadFile(path, 0o600, maxSecretBytes)
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
