package executor

import (
	"fmt"
	"os"
	"os/exec"
	"os/signal"
	"path/filepath"
	"sort"
	"strings"
	"syscall"
	"testing"
	"time"
)

// TestMain turns the test binary into a controllable child process when it
// is started as `<binary> muwa-helper <mode> [args]`.
func TestMain(m *testing.M) {
	if len(os.Args) > 2 && os.Args[1] == "muwa-helper" {
		os.Exit(helper(os.Args[2], os.Args[3:]))
	}
	os.Exit(m.Run())
}

func helper(mode string, args []string) int {
	switch mode {
	case "print":
		fmt.Fprint(os.Stdout, args[0])
		fmt.Fprint(os.Stderr, args[1])
		code := 0
		fmt.Sscan(args[2], &code)
		return code
	case "argv":
		fmt.Print(strings.Join(args, "|"))
		return 0
	case "env":
		env := os.Environ()
		sort.Strings(env)
		fmt.Print(strings.Join(env, "\n"))
		return 0
	case "sleep":
		time.Sleep(time.Hour)
		return 0
	case "ignore-term":
		signal.Ignore(syscall.SIGTERM)
		fmt.Print("ready")
		time.Sleep(time.Hour)
		return 0
	case "ignore-term-pid":
		signal.Ignore(syscall.SIGTERM)
		fmt.Print(os.Getpid())
		time.Sleep(time.Hour)
		return 0
	case "spawn-child":
		// a child in the same process group that outlives a normal exit
		child := exec.Command(os.Args[0], "muwa-helper", "sleep")
		if err := child.Start(); err != nil {
			return 9
		}
		fmt.Print(child.Process.Pid)
		if len(args) > 0 && args[0] == "and-wait" {
			time.Sleep(time.Hour)
		}
		return 0
	}
	return 99
}

// helperBinary is the symlink-free path of the test binary.
func helperBinary(t *testing.T) string {
	t.Helper()
	path, err := filepath.EvalSymlinks(os.Args[0])
	if err != nil {
		t.Fatal(err)
	}
	abs, err := filepath.Abs(path)
	if err != nil {
		t.Fatal(err)
	}
	return abs
}

func alive(pid int) bool {
	return syscall.Kill(pid, 0) == nil
}
