package cli

import (
	"bytes"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/muckiware/MuckiSafeAgentPlugin/agent/internal/version"
)

const secret = "AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8"

func run(args ...string) (int, string, string) {
	var stdout, stderr bytes.Buffer
	code := Run(args, &stdout, &stderr)
	return code, stdout.String(), stderr.String()
}

func setup(t *testing.T, server string, secretContent string) string {
	t.Helper()
	dir, err := filepath.EvalSymlinks(t.TempDir())
	if err != nil {
		t.Fatal(err)
	}
	secretFile := filepath.Join(dir, "secret")
	if err := os.WriteFile(secretFile, []byte(secretContent), 0o600); err != nil {
		t.Fatal(err)
	}
	policyFile := filepath.Join(dir, "policy.yaml")
	if err := os.WriteFile(policyFile, []byte("version: 1\n"), 0o644); err != nil {
		t.Fatal(err)
	}
	body := "server:\n" + server + "  keyId: 0123456789abcdef0123456789abcdef\n  secretFile: " + secretFile + "\n" +
		"agent:\n  policyFile: " + policyFile + "\n"
	path := filepath.Join(dir, "config.yaml")
	if err := os.WriteFile(path, []byte(body), 0o600); err != nil {
		t.Fatal(err)
	}
	return path
}

func TestVersionFlagPrintsTheBuildVersion(t *testing.T) {
	for _, arg := range []string{"--version", "version"} {
		code, stdout, _ := run(arg)
		if code != 0 || !strings.Contains(stdout, version.Version) || !strings.HasPrefix(stdout, "mucki-agent ") {
			t.Fatalf("%s: code %d, output %q", arg, code, stdout)
		}
	}
}

func TestConfigValidateAcceptsAValidHTTPSConfig(t *testing.T) {
	path := setup(t, "  url: https://backup.example.org\n", secret+"\n")

	for _, args := range [][]string{{"config", "validate", "--config", path}, {"config", "validate", "--config=" + path}} {
		code, stdout, stderr := run(args...)
		if code != 0 || !strings.Contains(stdout, "configuration valid") || stderr != "" {
			t.Fatalf("code %d, stdout %q, stderr %q", code, stdout, stderr)
		}
		if strings.Contains(stdout+stderr, secret) {
			t.Fatal("secret leaked")
		}
	}
}

func TestConfigValidateWarnsAboutPlainHTTP(t *testing.T) {
	path := setup(t, "  url: http://muckisafe.internal:8080\n  allowInsecureHttp: true\n", secret)

	code, _, stderr := run("config", "validate", "--config", path)
	if code != 0 || !strings.Contains(stderr, "not confidential") {
		t.Fatalf("code %d, stderr %q", code, stderr)
	}
}

func TestConfigValidateFailsClosed(t *testing.T) {
	cases := map[string]string{
		"http without flag": setup(t, "  url: http://muckisafe.internal:8080\n", secret),
		"malformed secret":  setup(t, "  url: https://backup.example.org\n", secret[:40]),
		"missing file":      filepath.Join(t.TempDir(), "absent.yaml"),
	}
	for name, path := range cases {
		code, stdout, stderr := run("config", "validate", "--config", path)
		if code != 1 || stderr == "" || strings.Contains(stdout, "configuration valid") {
			t.Errorf("%s: code %d, stdout %q, stderr %q", name, code, stdout, stderr)
		}
		if strings.Contains(stderr, secret[:40]) {
			t.Errorf("%s: secret leaked: %s", name, stderr)
		}
	}
}

func TestUsageErrors(t *testing.T) {
	for _, args := range [][]string{{}, {"frobnicate"}, {"config"}, {"config", "validate", "--unknown"}, {"config", "validate", "extra"}, {"run", "extra"}, {"run", "--unknown"},
		{"policy"}, {"policy", "writable-paths", "extra"}, {"policy", "writable-paths", "--format", "json"}} {
		if code, _, stderr := run(args...); code != 2 || !strings.Contains(stderr, "usage") {
			t.Errorf("%v: code %d, stderr %q", args, code, stderr)
		}
	}
}

func TestConfigValidateRejectsAnInvalidPolicy(t *testing.T) {
	path := setup(t, "  url: https://backup.example.org\n", secret)
	if err := os.WriteFile(filepath.Join(filepath.Dir(path), "policy.yaml"), []byte("version: 1\nshell:\n  any: /bin/sh\n"), 0o644); err != nil {
		t.Fatal(err)
	}
	if code, _, stderr := run("config", "validate", "--config", path); code != 1 || !strings.Contains(stderr, "policy") {
		t.Fatalf("code %d, stderr %q", code, stderr)
	}
}

func TestRunRefusesToStartWithAnUnsafeConfig(t *testing.T) {
	path := setup(t, "  url: https://backup.example.org\n", secret)
	if err := os.Chmod(path, 0o644); err != nil {
		t.Fatal(err)
	}

	code, _, stderr := run("run", "--config", path)

	if code != 78 || !strings.Contains(stderr, "agent cannot start") || !strings.Contains(stderr, "permissions 0644 exceed 0600") {
		t.Fatalf("code %d, stderr %q", code, stderr)
	}
}

const destinationPolicy = `version: 1
paths:
  odd:
    root: /srv/back"up %h\dir
  library:
    root: /opt/immich/library
destinations:
  dumps:
    pathAlias: library
    directory: backups
    prefix: d-
    suffix: .sql.gz
    owner: 33
    group: 33
    mode: "0640"
    maximumBytes: 100
    maximumKeep: 1
  odd:
    pathAlias: odd
    directory: .
    prefix: o-
    suffix: .sql.gz
    owner: 0
    group: 0
    mode: "0600"
    maximumBytes: 100
    maximumKeep: 1
`

func writePolicy(t *testing.T, config, body string) {
	t.Helper()
	if err := os.WriteFile(filepath.Join(filepath.Dir(config), "policy.yaml"), []byte(body), 0o644); err != nil {
		t.Fatal(err)
	}
}

func TestPolicyWritablePathsPrintsThePathRootsAndDestinations(t *testing.T) {
	path := setup(t, "  url: https://backup.example.org\n", secret)
	writePolicy(t, path, destinationPolicy)

	code, stdout, stderr := run("policy", "writable-paths", "--config", path)

	want := "/opt/immich/library\n/srv/back\"up %h\\dir\n"
	if code != 0 || stdout != want || stderr != "" {
		t.Fatalf("code %d, stdout %q, stderr %q", code, stdout, stderr)
	}
}

func TestPolicyWritablePathsFormatsASystemdDropIn(t *testing.T) {
	path := setup(t, "  url: https://backup.example.org\n", secret)
	writePolicy(t, path, destinationPolicy)

	code, stdout, _ := run("policy", "writable-paths", "--config", path, "--format", "systemd")

	// Missing directories are tolerated ("-"), quotes and backslashes are
	// C-escaped and "%" is not a specifier.
	want := "ReadWritePaths=\"-/opt/immich/library\"\nReadWritePaths=\"-/srv/back\\\"up %%h\\\\dir\"\n"
	if code != 0 || stdout != want {
		t.Fatalf("code %d, stdout %q, want %q", code, stdout, want)
	}
}

func TestPolicyWritablePathsRejectsPathsSystemdCannotExpress(t *testing.T) {
	path := setup(t, "  url: https://backup.example.org\n", secret)
	writePolicy(t, path, strings.Replace(destinationPolicy, "/opt/immich/library", "/opt/immich/lib\trary", 1))

	if code, stdout, stderr := run("policy", "writable-paths", "--config", path, "--format", "systemd"); code != 1 || stdout != "" || !strings.Contains(stderr, "control character") {
		t.Fatalf("code %d, stdout %q, stderr %q", code, stdout, stderr)
	}
}

func TestPolicyWritablePathsFailsClosedOnAnInvalidPolicy(t *testing.T) {
	path := setup(t, "  url: https://backup.example.org\n", secret)
	writePolicy(t, path, "version: 1\nshell:\n  any: /bin/sh\n")

	if code, stdout, stderr := run("policy", "writable-paths", "--config", path); code != 1 || stdout != "" || !strings.Contains(stderr, "policy") {
		t.Fatalf("code %d, stdout %q, stderr %q", code, stdout, stderr)
	}
}
