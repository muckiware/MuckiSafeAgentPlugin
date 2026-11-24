package policy

import (
	"errors"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"testing"

	"gopkg.in/yaml.v3"
)

type service struct {
	Unit string `yaml:"unit"`
}

// decoders registers one test section the way primitives will in Task 21.
var decoders = Decoders{
	"services": func(alias string, node *yaml.Node) (any, error) {
		var s service
		if err := DecodeStrict(node, &s); err != nil {
			return nil, err
		}
		if !strings.HasSuffix(s.Unit, ".service") {
			return nil, errors.New("unit must end in .service")
		}
		return s, nil
	},
}

func writePolicy(t *testing.T, body string, mode os.FileMode) string {
	t.Helper()
	dir, err := filepath.EvalSymlinks(t.TempDir())
	if err != nil {
		t.Fatal(err)
	}
	path := filepath.Join(dir, "policy.yaml")
	if err := os.WriteFile(path, []byte(body), mode); err != nil {
		t.Fatal(err)
	}
	if err := os.Chmod(path, mode); err != nil {
		t.Fatal(err)
	}
	return path
}

const valid = "version: 1\nservices:\n  jellyfin:\n    unit: jellyfin.service\n"

func TestValidPolicyResolvesAliases(t *testing.T) {
	p, err := Load(writePolicy(t, valid, 0o644), decoders)
	if err != nil {
		t.Fatal(err)
	}
	entry, ok := p.Entry("services", "jellyfin")
	if !ok || entry.(service).Unit != "jellyfin.service" {
		t.Fatalf("entry %+v %v", entry, ok)
	}
	if _, ok := p.Entry("services", "immich"); ok {
		t.Fatal("unknown alias must not resolve")
	}
	if _, ok := p.Entry("containers", "jellyfin"); ok {
		t.Fatal("unknown section must not resolve")
	}
}

func TestEmptySectionsAreAllowed(t *testing.T) {
	if _, err := Load(writePolicy(t, "version: 1\n", 0o600), decoders); err != nil {
		t.Fatal(err)
	}
}

func TestInvalidPoliciesFailClosed(t *testing.T) {
	cases := map[string]string{
		"missing version":    "services: {}\n",
		"wrong version":      "version: 2\n",
		"string version":     "version: \"1\"\n",
		"unknown section":    "version: 1\ncontainers:\n  db:\n    name: postgres\n",
		"invalid alias":      "version: 1\nservices:\n  Jellyfin:\n    unit: jellyfin.service\n",
		"traversal alias":    "version: 1\nservices:\n  ../etc:\n    unit: jellyfin.service\n",
		"long alias":         "version: 1\nservices:\n  " + strings.Repeat("a", 65) + ":\n    unit: jellyfin.service\n",
		"section not a map":  "version: 1\nservices: [jellyfin]\n",
		"duplicate alias":    "version: 1\nservices:\n  jellyfin:\n    unit: a.service\n  jellyfin:\n    unit: b.service\n",
		"unknown entry key":  "version: 1\nservices:\n  jellyfin:\n    unit: jellyfin.service\n    shell: /bin/sh\n",
		"decoder rejection":  "version: 1\nservices:\n  jellyfin:\n    unit: jellyfin\n",
		"second document":    valid + "---\nversion: 1\n",
		"not a mapping":      "- version: 1\n",
		"duplicate sections": valid + "services: {}\n",
	}
	for name, body := range cases {
		t.Run(name, func(t *testing.T) {
			if _, err := Load(writePolicy(t, body, 0o644), decoders); err == nil {
				t.Fatal("expected rejection")
			}
		})
	}
}

func TestPolicyFileMustBeRootProtected(t *testing.T) {
	if _, err := Load(writePolicy(t, valid, 0o664), decoders); err == nil {
		t.Error("group-writable policy must be rejected")
	}
	path := writePolicy(t, valid, 0o644)
	link := filepath.Join(filepath.Dir(path), "link.yaml")
	if err := os.Symlink(path, link); err != nil {
		t.Fatal(err)
	}
	if _, err := Load(link, decoders); err == nil {
		t.Error("symlinked policy must be rejected")
	}
}

func TestStoreKeepsTheLastGoodSnapshotOnAFailedReload(t *testing.T) {
	path := writePolicy(t, valid, 0o644)
	store, err := NewStore(path, decoders)
	if err != nil {
		t.Fatal(err)
	}
	snapshot := store.Current()

	if err := os.WriteFile(path, []byte("version: 1\nservices:\n  jellyfin:\n    unit: other.service\n"), 0o644); err != nil {
		t.Fatal(err)
	}
	if err := store.Reload(); err != nil {
		t.Fatal(err)
	}
	if entry, _ := store.Current().Entry("services", "jellyfin"); entry.(service).Unit != "other.service" {
		t.Fatalf("reload not applied: %+v", entry)
	}
	if entry, _ := snapshot.Entry("services", "jellyfin"); entry.(service).Unit != "jellyfin.service" {
		t.Fatal("an earlier snapshot must stay unchanged for a running job")
	}

	if err := os.WriteFile(path, []byte("version: 1\nunknown: {}\n"), 0o644); err != nil {
		t.Fatal(err)
	}
	if err := store.Reload(); err == nil {
		t.Fatal("broken reload must report an error")
	}
	if entry, _ := store.Current().Entry("services", "jellyfin"); entry.(service).Unit != "other.service" {
		t.Fatal("a failed reload must keep the last good snapshot")
	}
}

func TestStoreRefusesToStartWithoutAValidPolicy(t *testing.T) {
	if _, err := NewStore(writePolicy(t, "version: 3\n", 0o644), decoders); err == nil {
		t.Fatal("expected start failure")
	}
}

func TestConcurrentReloadsAndReadsAreRaceFree(t *testing.T) {
	store, err := NewStore(writePolicy(t, valid, 0o644), decoders)
	if err != nil {
		t.Fatal(err)
	}
	var wg sync.WaitGroup
	for i := 0; i < 4; i++ {
		wg.Add(2)
		go func() {
			defer wg.Done()
			for j := 0; j < 20; j++ {
				_ = store.Reload()
			}
		}()
		go func() {
			defer wg.Done()
			for j := 0; j < 200; j++ {
				if _, ok := store.Current().Entry("services", "jellyfin"); !ok {
					t.Error("snapshot lost its alias")
					return
				}
			}
		}()
	}
	wg.Wait()
}
