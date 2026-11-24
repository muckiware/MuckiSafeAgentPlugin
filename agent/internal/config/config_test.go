package config

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

const (
	keyID  = "0123456789abcdef0123456789abcdef"
	secret = "AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8"
)

// tempDir is a symlink-free temp directory; macOS hides t.TempDir() behind
// the /var -> /private/var symlink, which securefs rightly refuses.
func tempDir(t *testing.T) string {
	t.Helper()
	dir, err := filepath.EvalSymlinks(t.TempDir())
	if err != nil {
		t.Fatal(err)
	}
	return dir
}

func writeConfig(t *testing.T, body string) string {
	t.Helper()
	path := filepath.Join(tempDir(t), "config.yaml")
	if err := os.WriteFile(path, []byte(body), 0o600); err != nil {
		t.Fatal(err)
	}
	return path
}

func minimal(url string, extra string) string {
	return "server:\n  url: " + url + "\n  keyId: " + keyID + "\n  secretFile: /etc/mucki-agent/secret\n" + extra
}

func TestMinimalHTTPSConfigGetsDefaults(t *testing.T) {
	cfg, err := Load(writeConfig(t, minimal("https://backup.example.org", "")))
	if err != nil {
		t.Fatal(err)
	}
	if cfg.Server.URL != "https://backup.example.org" || cfg.Server.AllowInsecureHTTP || cfg.Server.KeyID != keyID {
		t.Fatalf("unexpected server config %+v", cfg.Server)
	}
	if cfg.Server.RequestTimeout != 30*time.Second {
		t.Fatalf("request timeout %s", cfg.Server.RequestTimeout)
	}
	want := Agent{
		PollInterval:      10 * time.Second,
		HeartbeatInterval: 10 * time.Second,
		LeaseDuration:     30 * time.Second,
		MaxConcurrency:    1,
		WakeupDirectory:   "/var/lib/mucki-agent/wakeup",
		StateDirectory:    "/var/lib/mucki-agent/state",
		PolicyFile:        "/etc/mucki-agent/policy.yaml",
	}
	if cfg.Agent != want {
		t.Fatalf("agent defaults %+v, want %+v", cfg.Agent, want)
	}
	if cfg.InsecureTransport() {
		t.Fatal("HTTPS must not be reported as insecure transport")
	}
}

func TestExampleConfigIsValid(t *testing.T) {
	raw, err := os.ReadFile(filepath.Join("..", "..", "config.example.yaml"))
	if err != nil {
		t.Fatal(err)
	}
	cfg, err := Parse(raw)
	if err != nil {
		t.Fatal(err)
	}
	if !strings.HasPrefix(cfg.Server.URL, "https://") || cfg.Server.AllowInsecureHTTP {
		t.Fatal("the example must document the HTTPS default")
	}
}

func TestHTTPRequiresTheExplicitFlag(t *testing.T) {
	_, err := Load(writeConfig(t, minimal("http://muckisafe.internal:8080", "")))
	if err == nil || !strings.Contains(err.Error(), "allowInsecureHttp") {
		t.Fatalf("expected HTTP without flag to be rejected, got %v", err)
	}

	body := "server:\n  url: http://muckisafe.internal:8080\n  allowInsecureHttp: true\n  keyId: " + keyID + "\n  secretFile: /etc/mucki-agent/secret\n"
	cfg, err := Load(writeConfig(t, body))
	if err != nil {
		t.Fatal(err)
	}
	if !cfg.InsecureTransport() {
		t.Fatal("HTTP with the flag must be reported as insecure transport")
	}
}

func TestInvalidServerURLsAreRejected(t *testing.T) {
	for _, url := range []string{
		"ftp://backup.example.org",
		"https://user:pass@backup.example.org",
		"https://",
		"https:///path",
		"https://backup.example.org/shop",
		"https://backup.example.org?x=1",
		"https://backup.example.org#frag",
		"backup.example.org",
		"HTTPS://backup.example.org",
	} {
		if _, err := Load(writeConfig(t, minimal(url, ""))); err == nil {
			t.Errorf("expected %q to be rejected", url)
		}
	}
	if _, err := Load(writeConfig(t, minimal("https://backup.example.org/", ""))); err != nil {
		t.Errorf("a bare trailing slash is accepted: %v", err)
	}
}

func TestThereIsNoTLSSkipOptionAndUnknownKeysFail(t *testing.T) {
	for _, extra := range []string{
		"  skipTlsVerify: true\n",
		"  insecureSkipVerify: true\n",
		"agent:\n  pollIntervall: 5s\n",
		"policies:\n  services:\n    jellyfin: jellyfin\n",
	} {
		body := minimal("https://backup.example.org", "")
		if strings.HasPrefix(extra, "  ") {
			body = strings.Replace(body, "server:\n", "server:\n"+extra, 1)
		} else {
			body += extra
		}
		if _, err := Load(writeConfig(t, body)); err == nil {
			t.Errorf("expected unknown key to be rejected:\n%s", body)
		}
	}
}

func TestAgentValuesAreBounded(t *testing.T) {
	for _, extra := range []string{
		"agent:\n  maxConcurrency: 2\n",
		"agent:\n  pollInterval: 0s\n",
		"agent:\n  pollInterval: 2h\n",
		"agent:\n  heartbeatInterval: 20s\n",
		"agent:\n  wakeupDirectory: relative/wakeup\n",
		"agent:\n  stateDirectory: /var/lib/../state\n",
		"agent:\n  policyFile: policy.yaml\n",
		"server:\n  requestTimeout: 0s\n",
	} {
		body := minimal("https://backup.example.org", "")
		if strings.HasPrefix(extra, "server:") {
			body = strings.Replace(body, "server:\n", extra, 1)
		} else {
			body += extra
		}
		if _, err := Load(writeConfig(t, body)); err == nil {
			t.Errorf("expected rejection of:\n%s", body)
		}
	}
}

func TestKeyIDAndSecretFileAreValidated(t *testing.T) {
	for _, body := range []string{
		"server:\n  url: https://a.example\n  keyId: agent-linux-01\n  secretFile: /etc/mucki-agent/secret\n",
		"server:\n  url: https://a.example\n  keyId: " + keyID + "\n  secretFile: secret\n",
		"server:\n  url: https://a.example\n  keyId: " + keyID + "\n",
	} {
		if _, err := Load(writeConfig(t, body)); err == nil {
			t.Errorf("expected rejection of:\n%s", body)
		}
	}
}

func TestMultipleDocumentsAndOversizedFilesAreRejected(t *testing.T) {
	body := minimal("https://backup.example.org", "") + "---\n" + minimal("https://evil.example.org", "")
	if _, err := Load(writeConfig(t, body)); err == nil {
		t.Error("expected a second YAML document to be rejected")
	}
	if _, err := Load(writeConfig(t, minimal("https://backup.example.org", "")+"#"+strings.Repeat("x", 70000)+"\n")); err == nil {
		t.Error("expected an oversized config to be rejected")
	}
}

func writeSecret(t *testing.T, content string, mode os.FileMode) string {
	t.Helper()
	path := filepath.Join(tempDir(t), "secret")
	if err := os.WriteFile(path, []byte(content), mode); err != nil {
		t.Fatal(err)
	}
	if err := os.Chmod(path, mode); err != nil {
		t.Fatal(err)
	}
	return path
}

func TestSecretIsDecodedToTheHMACKey(t *testing.T) {
	for _, content := range []string{secret, secret + "\n"} {
		key, err := LoadSecret(writeSecret(t, content, 0o600))
		if err != nil {
			t.Fatal(err)
		}
		if len(key) != 32 || key[0] != 0 || key[31] != 31 {
			t.Fatalf("unexpected key %x", key)
		}
	}
}

func TestSecretFileMustBeAPrivateRegularFile(t *testing.T) {
	if _, err := LoadSecret(writeSecret(t, secret, 0o640)); err == nil {
		t.Error("group-readable secret must be rejected")
	}
	target := writeSecret(t, secret, 0o600)
	link := filepath.Join(tempDir(t), "secret-link")
	if err := os.Symlink(target, link); err != nil {
		t.Fatal(err)
	}
	if _, err := LoadSecret(link); err == nil {
		t.Error("symlinked secret must be rejected")
	}
	if _, err := LoadSecret(tempDir(t)); err == nil {
		t.Error("directory must be rejected")
	}
}

func TestMalformedSecretsNeverLeakIntoErrors(t *testing.T) {
	for _, content := range []string{secret + "\n\n", " " + secret, secret + "=", "c2VjcmV0", secret[:42] + "!"} {
		_, err := LoadSecret(writeSecret(t, content, 0o600))
		if err == nil {
			t.Errorf("expected %q to be rejected", content)
			continue
		}
		if strings.Contains(err.Error(), strings.TrimSpace(content)) || strings.Contains(err.Error(), secret[:16]) {
			t.Errorf("error leaks secret material: %v", err)
		}
	}
}

func TestConfigFileMustBePrivateAndSymlinkFree(t *testing.T) {
	path := writeConfig(t, minimal("https://backup.example.org", ""))
	if err := os.Chmod(path, 0o644); err != nil {
		t.Fatal(err)
	}
	if _, err := Load(path); err == nil {
		t.Error("world-readable config must be rejected")
	}
	_ = os.Chmod(path, 0o600)
	link := filepath.Join(filepath.Dir(path), "link.yaml")
	if err := os.Symlink(path, link); err != nil {
		t.Fatal(err)
	}
	if _, err := Load(link); err == nil {
		t.Error("symlinked config must be rejected")
	}
}
