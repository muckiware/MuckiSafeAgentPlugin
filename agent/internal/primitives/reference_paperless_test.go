//go:build linux

package primitives

import (
	"context"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"reflect"
	"strings"
	"testing"
)

type paperlessFixture struct {
	FixtureVersion int    `json:"fixtureVersion"`
	Service        string `json:"service"`
	Contract       string `json:"contract"`
	Actions        struct {
		Pre  string `json:"pre"`
		Post string `json:"post"`
	} `json:"actions"`
	Policy       string            `json:"policy"`
	PolicyRoots  map[string]string `json:"policyRoots"`
	PolicyOrigin string            `json:"policyOrigin"`
	Description  string            `json:"description"`
	LegacyHook   struct {
		Command                     string   `json:"command"`
		Arguments                   []string `json:"arguments"`
		ObservedStderr              string   `json:"observedStderr"`
		ForbiddenInPolicyAndActions []string `json:"forbiddenInPolicyAndActions"`
	} `json:"legacyHook"`
	Seed referenceSeed `json:"seed"`
	Core struct {
		Operations []string       `json:"operations"`
		ReadableBy principalEntry `json:"readableBy"`
		Sources    []string       `json:"sources"`
	} `json:"core"`
	Scenarios []paperlessScenario `json:"scenarios"`
	Cleanup   json.RawMessage     `json:"cleanup"`
}

type paperlessScenario struct {
	Name   string `json:"name"`
	Inject struct {
		CoreFails  bool              `json:"coreFails"`
		Unreadable string            `json:"unreadable"`
		Remove     string            `json:"remove"`
		Symlink    *symlinkInjection `json:"symlink"`
		Runs       int               `json:"runs"`
	} `json:"inject"`
	Web struct {
		Status   int    `json:"status"`
		Location string `json:"location"`
	} `json:"web"`
	Expect struct {
		Pre  expectedResult  `json:"pre"`
		Core string          `json:"core"`
		Post *expectedResult `json:"post"`
	} `json:"expect"`
}

// TestPaperlessReferenceAction runs pre, the simulated typed core backup and
// post. The action only reads: no process is started and the trees, modes
// and ACLs end exactly as they started.
func TestPaperlessReferenceAction(t *testing.T) {
	var fixture paperlessFixture
	decodeFixture(t, "paperless", &fixture)
	pre, post := readRepositoryFile(t, fixture.Actions.Pre), readRepositoryFile(t, fixture.Actions.Post)
	for _, scenario := range fixture.Scenarios {
		t.Run(scenario.Name, func(t *testing.T) {
			runPaperlessScenario(t, fixture, pre, post, scenario)
		})
	}
}

func runPaperlessScenario(t *testing.T, fixture paperlessFixture, pre, post string, scenario paperlessScenario) {
	base := filepath.Join(tempDir(t), "paperless")
	if err := os.Mkdir(base, 0o755); err != nil {
		t.Fatal(err)
	}
	seedTree(t, base, fixture.Seed)
	inject(t, base, scenario.Inject.Remove, scenario.Inject.Symlink)
	if path := scenario.Inject.Unreadable; path != "" {
		if err := os.Chmod(filepath.Join(base, path), 0o700); err != nil {
			t.Fatal(err)
		}
	}
	var targetACL string
	if link := scenario.Inject.Symlink; link != nil {
		targetACL = aclOf(t, link.Target)
	}
	start := snapshotTree(t, base)

	s := script(t, fakeScript{})
	web := paperlessWeb(t, scenario)
	h := paperlessHarness(t, fixture, base, web.URL)

	runs := max(1, scenario.Inject.Runs)
	preSucceeded := true
	for range runs {
		result := h.execute(context.Background(), phaseJob(t, "pre", pre)).ForWire()
		scenario.Expect.Pre.check(t, "pre", result)
		preSucceeded = result.Outcome == "succeeded"
	}
	core := paperlessCore(t, fixture, base, preSucceeded, scenario.Inject.CoreFails)
	if core != scenario.Expect.Core {
		t.Fatalf("core %s, want %s", core, scenario.Expect.Core)
	}
	// Facility dispatches the after-event, and with it post, only on success
	if (core == "readable") != (scenario.Expect.Post != nil) {
		t.Fatalf("post expected=%v after core %s", scenario.Expect.Post != nil, core)
	}
	if scenario.Expect.Post != nil {
		for range runs {
			scenario.Expect.Post.check(t, "post", h.execute(context.Background(), phaseJob(t, "post", post)).ForWire())
		}
	}

	if calls := invocations(t, s); len(calls) != 0 {
		t.Fatalf("the Paperless action must not start processes: %q", calls)
	}
	if link := scenario.Inject.Symlink; link != nil && aclOf(t, link.Target) != targetACL {
		t.Fatalf("the ACL of the symlink target %s changed", link.Target)
	}
	if end := snapshotTree(t, base); !reflect.DeepEqual(end, start) {
		t.Fatalf("the read-only action changed the trees:\nstart %v\nend   %v", start, end)
	}
}

// TestPaperlessNeverRunsTheLegacyHook is the regression for the broken
// paperless_pre.sh (bare ${2} under set -u): neither the policy nor the
// actions may reference it, and the DSL has no primitive that could.
func TestPaperlessNeverRunsTheLegacyHook(t *testing.T) {
	var fixture paperlessFixture
	decodeFixture(t, "paperless", &fixture)
	texts := map[string]string{}
	for _, path := range []string{fixture.Policy, fixture.Actions.Pre, fixture.Actions.Post} {
		texts[path] = readRepositoryFile(t, path)
	}
	for path, text := range texts {
		for _, forbidden := range fixture.LegacyHook.ForbiddenInPolicyAndActions {
			if strings.Contains(text, forbidden) {
				t.Errorf("%s references the legacy hook (%q)", path, forbidden)
			}
		}
	}
	for _, path := range []string{fixture.Actions.Pre, fixture.Actions.Post} {
		job := definitionJob(t, testJobID, texts[path])
		if strings.Contains(job.DefinitionCanonical, "docker.container.exec") || strings.Contains(job.DefinitionCanonical, "file.write-pipeline") {
			t.Errorf("%s starts a process", path)
		}
	}
}

func paperlessHarness(t *testing.T, fixture paperlessFixture, base, origin string) *harness {
	t.Helper()
	policy := readRepositoryFile(t, fixture.Policy)
	replacements := map[string]string{"url: " + fixture.PolicyOrigin + "/": "url: " + origin + "/"}
	for root, dir := range fixture.PolicyRoots {
		replacements["root: "+root+"\n"] = "root: " + filepath.Join(base, dir) + "\n"
	}
	for old, replacement := range replacements {
		if strings.Count(policy, old) != 1 {
			t.Fatalf("the policy must contain %q exactly once", old)
		}
		policy = strings.Replace(policy, old, replacement, 1)
	}
	return newHarness(t, policy)
}

// paperlessWeb answers / like the Paperless web container of the scenario.
func paperlessWeb(t *testing.T, scenario paperlessScenario) *httptest.Server {
	t.Helper()
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/" {
			w.WriteHeader(http.StatusNotFound)
			return
		}
		if scenario.Web.Location != "" {
			w.Header().Set("Location", scenario.Web.Location)
		}
		w.WriteHeader(scenario.Web.Status)
	}))
	t.Cleanup(server.Close)
	return server
}

// paperlessCore stands in for the typed database backup and file backup;
// it runs only after a successful pre.
func paperlessCore(t *testing.T, fixture paperlessFixture, base string, preSucceeded, fails bool) string {
	t.Helper()
	if !preSucceeded {
		return "skipped"
	}
	if fails {
		return "failed"
	}
	for _, source := range fixture.Core.Sources {
		if err := coreBackup(base, fixture.PolicyRoots[source], fixture.Core.ReadableBy); err != nil {
			t.Fatalf("the core backup cannot read %s as the backup principal: %v", source, err)
		}
	}
	return "readable"
}
