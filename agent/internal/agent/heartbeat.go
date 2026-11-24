package agent

import (
	"context"
	"errors"
	"fmt"
	"log/slog"
	"runtime"
	"time"

	"github.com/muckiware/MuckiSafeAgentPlugin/agent/internal/api"
	"github.com/muckiware/MuckiSafeAgentPlugin/agent/internal/version"
)

type leaseBeat struct {
	response api.JobHeartbeatResponse
	meta     api.ResponseMeta
	err      error
}

// leaseHeartbeats renews the lease every HeartbeatInterval until ctx ends.
// Results are delivered to the execution loop; a blocked request never
// blocks deadline enforcement.
func (r *Runner) leaseHeartbeats(ctx context.Context, jobID string, lease api.JobLeaseRequest) <-chan leaseBeat {
	beats := make(chan leaseBeat, 1)
	go func() {
		ticker := time.NewTicker(r.cfg.HeartbeatInterval)
		defer ticker.Stop()
		for {
			select {
			case <-ctx.Done():
				return
			case <-ticker.C:
			}
			response, meta, err := r.cfg.API.JobHeartbeat(ctx, jobID, lease)
			if ctx.Err() != nil {
				return
			}
			select {
			case beats <- leaseBeat{response: response, meta: meta, err: err}:
			case <-ctx.Done():
				return
			}
		}
	}()
	return beats
}

// HostHeartbeat reports the agent's liveness, version and capabilities.
type HostHeartbeat struct {
	API          API
	Interval     time.Duration
	Capabilities []string
	Logger       *slog.Logger
}

// Run sends a heartbeat immediately and then every Interval until ctx ends.
// An unknown or revoked key (unsigned 401) is terminal and returned.
func (h HostHeartbeat) Run(ctx context.Context) error {
	if runtime.GOARCH != "amd64" && runtime.GOARCH != "arm64" {
		return fmt.Errorf("agent: unsupported architecture %s", runtime.GOARCH)
	}
	if h.Interval <= 0 {
		return errors.New("agent: host heartbeat interval must be positive")
	}
	logger := h.Logger
	if logger == nil {
		logger = slog.Default()
	}
	capabilities := h.Capabilities
	if capabilities == nil {
		capabilities = []string{}
	}
	ticker := time.NewTicker(h.Interval)
	defer ticker.Stop()
	for {
		_, err := h.API.HostHeartbeat(ctx, api.HostHeartbeatRequest{
			AgentVersion: version.Version, Platform: "linux", Arch: runtime.GOARCH,
			Capabilities: capabilities, AgentTime: time.Now().UTC().Format(time.RFC3339),
		})
		switch {
		case ctx.Err() != nil:
			return nil
		case errors.Is(err, api.ErrUnauthenticated):
			return err
		case err != nil:
			logger.Warn("host heartbeat failed", "error", err)
		}
		select {
		case <-ctx.Done():
			return nil
		case <-ticker.C:
		}
	}
}
