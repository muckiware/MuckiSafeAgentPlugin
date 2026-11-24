package securefs

import (
	"testing"

	"golang.org/x/sys/unix"
)

func mkfifo(t *testing.T, path string) {
	t.Helper()
	if err := unix.Mkfifo(path, 0o600); err != nil {
		t.Fatal(err)
	}
}
