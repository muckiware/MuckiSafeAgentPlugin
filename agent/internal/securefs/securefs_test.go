package securefs

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
)

// base returns a symlink-free private temp directory (macOS hides t.TempDir()
// behind the /var -> /private/var symlink).
func base(t *testing.T) string {
	t.Helper()
	dir, err := filepath.EvalSymlinks(t.TempDir())
	if err != nil {
		t.Fatal(err)
	}
	return dir
}

func write(t *testing.T, path, content string, mode os.FileMode) string {
	t.Helper()
	if err := os.WriteFile(path, []byte(content), mode); err != nil {
		t.Fatal(err)
	}
	if err := os.Chmod(path, mode); err != nil {
		t.Fatal(err)
	}
	return path
}

func TestReadFileReturnsTheVerifiedContent(t *testing.T) {
	path := write(t, filepath.Join(base(t), "config.yaml"), "a: 1\n", 0o600)

	raw, err := ReadFile(path, 0o600, 1024)
	if err != nil || string(raw) != "a: 1\n" {
		t.Fatalf("%q %v", raw, err)
	}
}

func TestReadFileFailsClosed(t *testing.T) {
	cases := map[string]func(t *testing.T) string{
		"too permissive": func(t *testing.T) string {
			return write(t, filepath.Join(base(t), "f"), "x", 0o640)
		},
		"symlink": func(t *testing.T) string {
			dir := base(t)
			target := write(t, filepath.Join(dir, "target"), "x", 0o600)
			link := filepath.Join(dir, "link")
			_ = os.Symlink(target, link)
			return link
		},
		"symlinked parent": func(t *testing.T) string {
			dir := base(t)
			_ = os.Mkdir(filepath.Join(dir, "real"), 0o700)
			write(t, filepath.Join(dir, "real", "f"), "x", 0o600)
			_ = os.Symlink(filepath.Join(dir, "real"), filepath.Join(dir, "alias"))
			return filepath.Join(dir, "alias", "f")
		},
		"world writable parent": func(t *testing.T) string {
			dir := base(t)
			sub := filepath.Join(dir, "open")
			_ = os.Mkdir(sub, 0o700)
			path := write(t, filepath.Join(sub, "f"), "x", 0o600)
			_ = os.Chmod(sub, 0o777)
			t.Cleanup(func() { _ = os.Chmod(sub, 0o700) })
			return path
		},
		"hardlink": func(t *testing.T) string {
			dir := base(t)
			path := write(t, filepath.Join(dir, "f"), "x", 0o600)
			_ = os.Link(path, filepath.Join(dir, "second"))
			return path
		},
		"directory": func(t *testing.T) string { return base(t) },
		"oversized": func(t *testing.T) string {
			return write(t, filepath.Join(base(t), "f"), strings.Repeat("x", 2048), 0o600)
		},
		"relative":  func(t *testing.T) string { return "relative/f" },
		"unclean":   func(t *testing.T) string { return base(t) + "/../f" },
		"fifo":      func(t *testing.T) string { p := filepath.Join(base(t), "fifo"); mkfifo(t, p); return p },
		"missing":   func(t *testing.T) string { return filepath.Join(base(t), "absent") },
		"empty arg": func(t *testing.T) string { return "" },
	}
	for name, prepare := range cases {
		t.Run(name, func(t *testing.T) {
			if _, err := ReadFile(prepare(t), 0o600, 1024); err == nil {
				t.Fatal("expected rejection")
			}
		})
	}
}

func TestContentIsReadFromTheVerifiedDescriptorDespiteARenameSwap(t *testing.T) {
	dir := base(t)
	path := write(t, filepath.Join(dir, "policy.yaml"), "verified", 0o600)
	write(t, filepath.Join(dir, "evil"), "swapped", 0o600)
	afterVerify = func(string) {
		if err := os.Rename(filepath.Join(dir, "evil"), path); err != nil {
			t.Fatal(err)
		}
	}
	t.Cleanup(func() { afterVerify = nil })

	raw, err := ReadFile(path, 0o600, 1024)
	if err != nil || string(raw) != "verified" {
		t.Fatalf("read %q %v", raw, err)
	}
}

func TestOpenPrivateDirectory(t *testing.T) {
	dir := filepath.Join(base(t), "state")
	if err := os.Mkdir(dir, 0o700); err != nil {
		t.Fatal(err)
	}
	fd, err := OpenPrivateDirectory(dir)
	if err != nil {
		t.Fatal(err)
	}
	CloseFD(fd)

	_ = os.Chmod(dir, 0o750)
	if _, err := OpenPrivateDirectory(dir); err == nil {
		t.Fatal("group accessible directory must be rejected")
	}
}

func TestVerifyExecutable(t *testing.T) {
	dir := base(t)
	tool := write(t, filepath.Join(dir, "tool"), "#!/bin/false\n", 0o755)
	if err := VerifyExecutable(tool); err != nil {
		t.Fatal(err)
	}
	for name, path := range map[string]string{
		"not executable":  write(t, filepath.Join(dir, "plain"), "x", 0o644),
		"group writable":  write(t, filepath.Join(dir, "gw"), "x", 0o775),
		"world writable":  write(t, filepath.Join(dir, "ww"), "x", 0o757),
		"missing":         filepath.Join(dir, "absent"),
		"symlinked":       symlink(t, tool, filepath.Join(dir, "tool-link")),
		"relative path":   "bin/tool",
		"directory chain": dir,
	} {
		if err := VerifyExecutable(path); err == nil {
			t.Errorf("%s: expected rejection", name)
		}
	}
}

func symlink(t *testing.T, target, link string) string {
	t.Helper()
	if err := os.Symlink(target, link); err != nil {
		t.Fatal(err)
	}
	return link
}
