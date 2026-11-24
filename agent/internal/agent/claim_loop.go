package agent

import (
	"context"
	"errors"
	"log/slog"

	"github.com/muckiware/MuckiSafeAgentPlugin/agent/internal/api"
)

// ClaimLoop claims jobs whenever Wake signals (wakeup file or poll tick) and
// runs them one at a time until the queue is empty. Once shutdown begins no
// new job is claimed; a running job finishes within the runner's shutdown
// grace.
type ClaimLoop struct {
	API     API
	Runner  *Runner
	Wake    <-chan struct{}
	Backoff api.Backoff
	Logger  *slog.Logger
}

// Run returns nil on shutdown and api.ErrUnauthenticated when the key is no
// longer accepted; transient failures wait for the next wake.
func (l *ClaimLoop) Run(shutdown context.Context) error {
	logger := l.Logger
	if logger == nil {
		logger = slog.Default()
	}
	for {
		select {
		case <-shutdown.Done():
			return nil
		case <-l.Wake:
		}
		if err := l.drain(shutdown, logger); err != nil {
			return err
		}
	}
}

func (l *ClaimLoop) drain(shutdown context.Context, logger *slog.Logger) error {
	for shutdown.Err() == nil {
		var response api.ClaimResponse
		err := l.Backoff.Do(shutdown, func(ctx context.Context) error {
			var err error
			response, err = l.API.Claim(ctx)
			return err
		})
		switch {
		case shutdown.Err() != nil:
			return nil
		case errors.Is(err, api.ErrUnauthenticated):
			logger.Error("agent key is not accepted by the control plane", "error", err)
			return err
		case err != nil:
			logger.Warn("claim failed, waiting for the next poll", "error", err)
			return nil
		case response.Job == nil:
			return nil
		}
		if err := l.Runner.Run(shutdown, *response.Job); err != nil {
			logger.Error("job run failed", "job", response.Job.ID, "error", err)
		}
	}
	return nil
}
