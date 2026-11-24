package api

import (
	"context"
	"errors"
	"testing"
	"time"
)

func TestBackoffGrowsExponentiallyWithJitterAndIsCapped(t *testing.T) {
	b := Backoff{Initial: time.Second, Max: 8 * time.Second, Multiplier: 2, Rand: func() float64 { return 0 }}
	for attempt, want := range []time.Duration{500 * time.Millisecond, time.Second, 2 * time.Second, 4 * time.Second, 4 * time.Second} {
		if got := b.Delay(attempt); got != want {
			t.Errorf("attempt %d: lower bound %s, want %s", attempt, got, want)
		}
	}
	b.Rand = func() float64 { return 0.999999 }
	for attempt, ceiling := range []time.Duration{time.Second, 2 * time.Second, 4 * time.Second, 8 * time.Second, 8 * time.Second, 8 * time.Second} {
		if got := b.Delay(attempt); got > ceiling || got < ceiling*99/100 {
			t.Errorf("attempt %d: upper bound %s, want just below %s", attempt, got, ceiling)
		}
	}
	if got := b.Delay(1000); got > 8*time.Second {
		t.Errorf("huge attempt numbers must stay capped, got %s", got)
	}
}

func TestDefaultBackoffUsesRealJitter(t *testing.T) {
	b := DefaultBackoff()
	seen := map[time.Duration]bool{}
	for i := 0; i < 20; i++ {
		seen[b.Delay(3)] = true
	}
	if len(seen) < 2 {
		t.Fatal("expected jittered delays")
	}
}

func TestRetryRepeatsOnlyRetryableErrorsUpToTheLimit(t *testing.T) {
	var slept []time.Duration
	b := Backoff{Initial: time.Second, Max: time.Minute, Multiplier: 2, MaxAttempts: 4,
		Rand:  func() float64 { return 0 },
		Sleep: func(_ context.Context, d time.Duration) error { slept = append(slept, d); return nil }}

	calls := 0
	err := b.Do(context.Background(), func(context.Context) error {
		calls++
		return &UntrustedResponseError{Status: 502}
	})
	if calls != 4 || len(slept) != 3 || err == nil {
		t.Fatalf("calls=%d sleeps=%v err=%v", calls, slept, err)
	}

	calls = 0
	err = b.Do(context.Background(), func(context.Context) error {
		calls++
		if calls < 3 {
			return &APIError{Status: 503, Code: CodeUnavailable}
		}
		return nil
	})
	if err != nil || calls != 3 {
		t.Fatalf("expected success on third attempt, calls=%d err=%v", calls, err)
	}

	calls = 0
	err = b.Do(context.Background(), func(context.Context) error {
		calls++
		return ErrUnauthenticated
	})
	if calls != 1 || !errors.Is(err, ErrUnauthenticated) {
		t.Fatalf("terminal errors must not be retried, calls=%d", calls)
	}
}

func TestRetryStopsWhenTheContextEnds(t *testing.T) {
	ctx, cancel := context.WithCancel(context.Background())
	b := Backoff{Initial: time.Hour, Max: time.Hour, Multiplier: 2, MaxAttempts: 10, Rand: func() float64 { return 0 }}
	calls := 0
	done := make(chan error, 1)
	go func() {
		done <- b.Do(ctx, func(context.Context) error { calls++; return &UntrustedResponseError{Status: 502} })
	}()
	time.Sleep(50 * time.Millisecond)
	cancel()
	select {
	case err := <-done:
		if !errors.Is(err, context.Canceled) || calls != 1 {
			t.Fatalf("calls=%d err=%v", calls, err)
		}
	case <-time.After(2 * time.Second):
		t.Fatal("retry did not stop on cancellation")
	}
}

func TestContextErrorsAreNotRetryable(t *testing.T) {
	if Retryable(context.Canceled) || Retryable(context.DeadlineExceeded) || Retryable(nil) {
		t.Fatal("context errors and nil must not be retryable")
	}
}
