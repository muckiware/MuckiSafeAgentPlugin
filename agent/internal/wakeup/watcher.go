// Package wakeup turns wakeup files of the global flat wakeup directory into
// poll signals for the claim loop.
//
// Wakeups are hints only: the control plane writes `<job-uuid>.ready` after
// the job was committed, and the regular API poll stays authoritative. The
// watcher therefore never reads, trusts or deletes a wakeup file (the PHP
// cleanup removes stale ones); a matching name merely triggers one poll.
// Lost or duplicated events are harmless because polling continues while the
// directory is watched and signals coalesce.
package wakeup

import (
	"context"
	"errors"
	"log/slog"
	"path/filepath"
	"regexp"
	"time"

	"github.com/fsnotify/fsnotify"
)

// namePattern is `<job-uuid>.ready` with a lowercase RFC 4122 UUID, mirroring
// the UUID pattern of the wire protocol (internal/api/protocol.go).
var namePattern = regexp.MustCompile(`^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\.ready$`)

// IsWakeupName reports whether a base name is a finished wakeup file.
// Temporary `.tmp` files and foreign names never match.
func IsWakeupName(name string) bool {
	return namePattern.MatchString(name)
}

// Watcher signals the claim loop on matching wakeup events and on every poll
// tick. Logger is optional and defaults to slog.Default().
type Watcher struct {
	Directory    string
	PollInterval time.Duration
	Logger       *slog.Logger
}

// Run blocks until ctx is done. Every signal is a non-blocking send on wake,
// so a wake channel with capacity 1 coalesces bursts into one pending poll.
// The first signal is sent after the watch was set up, so a wakeup written
// before Run started is covered by that initial poll. Without a usable
// directory Run keeps polling and retries the watch on every tick.
func (w Watcher) Run(ctx context.Context, wake chan<- struct{}) error {
	if w.Directory == "" || w.PollInterval <= 0 {
		return errors.New("wakeup: directory and a positive poll interval are required")
	}
	dir := filepath.Clean(w.Directory)
	logger := w.Logger
	if logger == nil {
		logger = slog.Default()
	}

	s := &session{dir: dir, logger: logger}
	defer s.close()
	s.watch()
	signal(wake)

	ticker := time.NewTicker(w.PollInterval)
	defer ticker.Stop()
	for {
		var events <-chan fsnotify.Event
		var errs <-chan error
		if s.fs != nil {
			events, errs = s.fs.Events, s.fs.Errors
		}
		select {
		case <-ctx.Done():
			return nil
		case <-ticker.C:
			if s.fs == nil {
				s.watch()
			}
			signal(wake)
		case event, ok := <-events:
			if !ok || (event.Name == dir && event.Has(fsnotify.Remove|fsnotify.Rename)) {
				s.lost("wakeup directory watch ended, falling back to polling")
				continue
			}
			if s.matches(event) {
				signal(wake)
			}
		case err, ok := <-errs:
			if !ok {
				s.lost("wakeup directory watch ended, falling back to polling")
				continue
			}
			logger.Warn("wakeup watcher error, polling continues", "error", err)
		}
	}
}

// session owns the fsnotify watch of one Run and logs degradation only once
// per transition instead of on every poll tick.
type session struct {
	dir      string
	logger   *slog.Logger
	fs       *fsnotify.Watcher
	degraded bool
}

func (s *session) watch() {
	fs, err := fsnotify.NewWatcher()
	if err == nil {
		err = fs.Add(s.dir)
		if err != nil {
			_ = fs.Close()
		}
	}
	if err != nil {
		if !s.degraded {
			s.logger.Warn("wakeup directory not watchable, polling only", "directory", s.dir, "error", err)
			s.degraded = true
		}
		return
	}
	if s.degraded {
		s.logger.Info("wakeup directory watched again", "directory", s.dir)
		s.degraded = false
	}
	s.fs = fs
}

func (s *session) lost(message string) {
	s.close()
	s.logger.Warn(message, "directory", s.dir)
	s.degraded = true
}

func (s *session) close() {
	if s.fs != nil {
		_ = s.fs.Close()
		s.fs = nil
	}
}

func (s *session) matches(event fsnotify.Event) bool {
	return event.Has(fsnotify.Create|fsnotify.Write) &&
		filepath.Dir(event.Name) == s.dir &&
		IsWakeupName(filepath.Base(event.Name))
}

func signal(wake chan<- struct{}) {
	select {
	case wake <- struct{}{}:
	default:
	}
}
