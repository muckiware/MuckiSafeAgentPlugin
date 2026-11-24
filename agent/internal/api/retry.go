package api

import (
	"context"
	"math"
	"math/rand/v2"
	"time"
)

// Backoff retries retryable API errors with capped exponential backoff and
// "equal jitter": each delay lies in [d/2, d) with d = min(Max, Initial*Multiplier^attempt),
// so concurrent agents spread out without ever retrying immediately.
type Backoff struct {
	Initial     time.Duration
	Max         time.Duration
	Multiplier  float64
	MaxAttempts int
	// Rand returns a value in [0, 1); nil uses math/rand/v2.
	Rand func() float64
	// Sleep waits for d or returns ctx.Err(); nil uses a timer.
	Sleep func(ctx context.Context, d time.Duration) error
}

// DefaultBackoff is 1s doubling up to 60s, five attempts per call.
func DefaultBackoff() Backoff {
	return Backoff{Initial: time.Second, Max: time.Minute, Multiplier: 2, MaxAttempts: 5}
}

// Delay returns the jittered wait before retry number attempt (0-based).
func (b Backoff) Delay(attempt int) time.Duration {
	ceiling := float64(b.Initial) * math.Pow(b.Multiplier, float64(attempt))
	if math.IsInf(ceiling, 0) || math.IsNaN(ceiling) || ceiling > float64(b.Max) {
		ceiling = float64(b.Max)
	}
	random := rand.Float64
	if b.Rand != nil {
		random = b.Rand
	}
	return time.Duration(ceiling/2 + random()*ceiling/2)
}

// Do runs op until it succeeds, fails with a non-retryable error, the
// attempts are exhausted or ctx ends. It returns the last error.
func (b Backoff) Do(ctx context.Context, op func(context.Context) error) error {
	sleep := b.Sleep
	if sleep == nil {
		sleep = sleepContext
	}
	attempts := max(b.MaxAttempts, 1)
	var err error
	for attempt := 0; attempt < attempts; attempt++ {
		if err = op(ctx); err == nil || !Retryable(err) {
			return err
		}
		if attempt == attempts-1 {
			break
		}
		if sleepErr := sleep(ctx, b.Delay(attempt)); sleepErr != nil {
			return sleepErr
		}
	}
	return err
}

func sleepContext(ctx context.Context, d time.Duration) error {
	timer := time.NewTimer(d)
	defer timer.Stop()
	select {
	case <-ctx.Done():
		return ctx.Err()
	case <-timer.C:
		return nil
	}
}
