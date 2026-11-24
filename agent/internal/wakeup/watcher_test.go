package wakeup

import (
	"context"
	"os"
	"path/filepath"
	"testing"
	"time"
)

const jobFile = "0190b0a8-7e2c-7d4e-9a51-3c6f2b1d8e47.ready"

// eventOnly is a poll interval long enough that every wake within a test
// stems from a filesystem event, not from the poll fallback.
const eventOnly = time.Hour

func start(t *testing.T, dir string, interval time.Duration) chan struct{} {
	t.Helper()
	ctx, cancel := context.WithCancel(context.Background())
	wake := make(chan struct{}, 1)
	done := make(chan error, 1)
	go func() { done <- (Watcher{Directory: dir, PollInterval: interval}).Run(ctx, wake) }()
	t.Cleanup(func() {
		cancel()
		select {
		case err := <-done:
			if err != nil {
				t.Errorf("Run returned %v after cancellation", err)
			}
		case <-time.After(2 * time.Second):
			t.Error("Run did not stop after cancellation")
		}
	})
	return wake
}

func expectWake(t *testing.T, wake <-chan struct{}) {
	t.Helper()
	select {
	case <-wake:
	case <-time.After(2 * time.Second):
		t.Fatal("expected a poll signal")
	}
}

func expectNoWake(t *testing.T, wake <-chan struct{}) {
	t.Helper()
	select {
	case <-wake:
		t.Fatal("unexpected poll signal")
	case <-time.After(300 * time.Millisecond):
	}
}

func writeFile(t *testing.T, path string) {
	t.Helper()
	if err := os.WriteFile(path, []byte(`{"jobId":"x","protocolVersion":1}`), 0o644); err != nil {
		t.Fatal(err)
	}
}

func TestInitialSignalIsSentOnceTheWatchIsSetUp(t *testing.T) {
	wake := start(t, t.TempDir(), eventOnly)

	expectWake(t, wake)
	expectNoWake(t, wake)
}

func TestReadyFileRenamedFromItsTemporaryTriggersAPoll(t *testing.T) {
	dir := t.TempDir()
	wake := start(t, dir, eventOnly)
	expectWake(t, wake)

	writeFile(t, filepath.Join(dir, jobFile+".tmp"))
	expectNoWake(t, wake)

	if err := os.Rename(filepath.Join(dir, jobFile+".tmp"), filepath.Join(dir, jobFile)); err != nil {
		t.Fatal(err)
	}
	expectWake(t, wake)
}

func TestForeignAndTemporaryFilesAreIgnored(t *testing.T) {
	dir := t.TempDir()
	wake := start(t, dir, eventOnly)
	expectWake(t, wake)

	writeFile(t, filepath.Join(dir, "notes.txt"))
	writeFile(t, filepath.Join(dir, "0190B0A8-7E2C-7D4E-9A51-3C6F2B1D8E47.ready"))
	writeFile(t, filepath.Join(dir, jobFile+".tmp"))
	if err := os.Mkdir(filepath.Join(dir, "nested"), 0o700); err != nil {
		t.Fatal(err)
	}
	expectNoWake(t, wake)
}

func TestDuplicateEventsCoalesceAndNothingIsDeleted(t *testing.T) {
	dir := t.TempDir()
	wake := start(t, dir, eventOnly)
	expectWake(t, wake)

	for i := 0; i < 50; i++ {
		writeFile(t, filepath.Join(dir, jobFile))
	}
	writeFile(t, filepath.Join(dir, "1190b0a8-7e2c-7d4e-9a51-3c6f2b1d8e47.ready"))
	time.Sleep(300 * time.Millisecond)

	if pending := len(wake); pending != 1 {
		t.Fatalf("expected exactly one coalesced signal, got %d", pending)
	}
	entries, err := os.ReadDir(dir)
	if err != nil {
		t.Fatal(err)
	}
	if len(entries) != 2 {
		t.Fatalf("the watcher must not delete wakeups, %d entries left", len(entries))
	}
}

func TestPollingContinuesWhileWatchingSoLostEventsAreHarmless(t *testing.T) {
	wake := start(t, t.TempDir(), 20*time.Millisecond)

	for i := 0; i < 4; i++ {
		expectWake(t, wake)
	}
}

func TestMissingDirectoryFallsBackToPolling(t *testing.T) {
	wake := start(t, filepath.Join(t.TempDir(), "absent"), 20*time.Millisecond)

	for i := 0; i < 4; i++ {
		expectWake(t, wake)
	}
}

func TestWatchIsEstablishedOnceTheDirectoryAppears(t *testing.T) {
	dir := filepath.Join(t.TempDir(), "late")
	wake := start(t, dir, 200*time.Millisecond)
	expectWake(t, wake)
	if err := os.Mkdir(dir, 0o700); err != nil {
		t.Fatal(err)
	}
	// two poll ticks: the first re-establishes the watch
	expectWake(t, wake)
	expectWake(t, wake)

	writeFile(t, filepath.Join(dir, jobFile))
	select {
	case <-wake:
	case <-time.After(100 * time.Millisecond):
		t.Fatal("expected an event-driven signal well before the next poll tick")
	}
}

func TestInvalidConfigurationIsRejected(t *testing.T) {
	wake := make(chan struct{}, 1)
	for _, w := range []Watcher{{Directory: "", PollInterval: time.Second}, {Directory: t.TempDir(), PollInterval: 0}} {
		if err := w.Run(context.Background(), wake); err == nil {
			t.Fatalf("expected %+v to be rejected", w)
		}
	}
}

func TestIsWakeupName(t *testing.T) {
	cases := map[string]bool{
		jobFile:          true,
		jobFile + ".tmp": false,
		"0190B0A8-7E2C-7D4E-9A51-3C6F2B1D8E47.ready": false,
		"0190b0a87e2c7d4e9a513c6f2b1d8e47.ready":     false,
		"../" + jobFile:                              false,
		"0190b0a8-7e2c-0d4e-9a51-3c6f2b1d8e47.ready": false,
		"notes.txt": false,
	}
	for name, want := range cases {
		if got := IsWakeupName(name); got != want {
			t.Errorf("IsWakeupName(%q) = %v, want %v", name, got, want)
		}
	}
}
