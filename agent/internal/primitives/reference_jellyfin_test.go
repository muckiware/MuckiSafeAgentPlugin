//go:build linux

package primitives

import (
	"context"
	"encoding/json"
	"fmt"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"reflect"
	"strings"
	"testing"

	"github.com/muckiware/MuckiSafeAgentPlugin/agent/internal/agent"
	"github.com/muckiware/MuckiSafeAgentPlugin/agent/internal/api"
)

type jellyfinFixture struct {
	FixtureVersion int    `json:"fixtureVersion"`
	Service        string `json:"service"`
	Contract       string `json:"contract"`
	Actions        struct {
		Pre     string `json:"pre"`
		Finally string `json:"finally"`
	} `json:"actions"`
	Policy       string            `json:"policy"`
	PolicyRoots  map[string]string `json:"policyRoots"`
	PolicyOrigin string            `json:"policyOrigin"`
	Description  string            `json:"description"`
	Seed         referenceSeed     `json:"seed"`
	Unit         struct {
		Name         string `json:"name"`
		ControlGroup string `json:"controlGroup"`
	} `json:"unit"`
	Health struct {
		Path string `json:"path"`
		Body string `json:"body"`
	} `json:"health"`
	Core struct {
		Operation  string         `json:"operation"`
		ReadableBy principalEntry `json:"readableBy"`
		Sources    []string       `json:"sources"`
		Excludes   []string       `json:"excludes"`
	} `json:"core"`
	Scenarios []jellyfinScenario `json:"scenarios"`
	Cleanup   json.RawMessage    `json:"cleanup"`
}

type jellyfinScenario struct {
	Name   string `json:"name"`
	Inject struct {
		InitiallyInactive bool              `json:"initiallyInactive"`
		StopExit          int               `json:"stopExit"`
		CgroupPopulated   bool              `json:"cgroupPopulated"`
		StartFails        bool              `json:"startFails"`
		CoreFails         bool              `json:"coreFails"`
		Remove            string            `json:"remove"`
		Symlink           *symlinkInjection `json:"symlink"`
		Runs              int               `json:"runs"`
	} `json:"inject"`
	Expect struct {
		Pre          expectedResult `json:"pre"`
		Core         string         `json:"core"`
		Finally      expectedResult `json:"finally"`
		Unit         string         `json:"unit"`
		HealthStatus int            `json:"healthStatus"`
	} `json:"expect"`
}

type expectedResult struct {
	Outcome       string `json:"outcome"`
	ResultCode    string `json:"resultCode"`
	SummaryPrefix string `json:"summaryPrefix"`
}

func (e expectedResult) check(t *testing.T, phase string, result api.CompletionResult) {
	t.Helper()
	if result.Outcome != e.Outcome || result.ResultCode != e.ResultCode || !strings.HasPrefix(result.Summary, e.SummaryPrefix) {
		t.Fatalf("%s: got %s/%s %q, want %s/%s %q", phase, result.Outcome, result.ResultCode, result.Summary, e.Outcome, e.ResultCode, e.SummaryPrefix)
	}
}

// TestJellyfinReferenceLifecycle runs pre, the simulated core backup and
// finally like the BackupRunCoordinator: finally follows every pre and core
// outcome, so Jellyfin is started again after a stop or core failure.
func TestJellyfinReferenceLifecycle(t *testing.T) {
	var fixture jellyfinFixture
	decodeFixture(t, "jellyfin", &fixture)
	pre, final := readRepositoryFile(t, fixture.Actions.Pre), readRepositoryFile(t, fixture.Actions.Finally)
	for _, scenario := range fixture.Scenarios {
		t.Run(scenario.Name, func(t *testing.T) {
			runJellyfinScenario(t, fixture, pre, final, scenario)
		})
	}
}

func runJellyfinScenario(t *testing.T, fixture jellyfinFixture, pre, final string, scenario jellyfinScenario) {
	base := filepath.Join(tempDir(t), "jellyfin")
	if err := os.Mkdir(base, 0o755); err != nil {
		t.Fatal(err)
	}
	seedTree(t, base, fixture.Seed)
	inject(t, base, scenario.Inject.Remove, scenario.Inject.Symlink)
	start := snapshotTree(t, base)

	s := script(t, jellyfinUnit(fixture, scenario))
	health := jellyfinEndpoint(t, fixture, s)
	h := jellyfinHarness(t, fixture, base, health.URL, scenario.Inject.CgroupPopulated)

	runs := max(1, scenario.Inject.Runs)
	preSucceeded := true
	for range runs {
		result := h.execute(context.Background(), phaseJob(t, "pre", pre)).ForWire()
		scenario.Expect.Pre.check(t, "pre", result)
		preSucceeded = result.Outcome == "succeeded"
	}
	if core := jellyfinCore(t, fixture, base, s, preSucceeded, scenario.Inject.CoreFails); core != scenario.Expect.Core {
		t.Fatalf("core %s, want %s", core, scenario.Expect.Core)
	}
	for range runs {
		scenario.Expect.Finally.check(t, "finally", h.execute(context.Background(), phaseJob(t, "finally", final)).ForWire())
	}

	if unit := unitActiveState(t, s); unit != scenario.Expect.Unit {
		t.Fatalf("unit is %s after finally, want %s", unit, scenario.Expect.Unit)
	}
	if status := healthStatus(t, health.URL+fixture.Health.Path); status != scenario.Expect.HealthStatus {
		t.Fatalf("public info answers %d after finally, want %d", status, scenario.Expect.HealthStatus)
	}
	for _, call := range invocations(t, s) {
		if !strings.HasSuffix(call, " -- "+fixture.Unit.Name) {
			t.Fatalf("systemctl was called for another unit: %q", call)
		}
	}
	if err := cleanupRun(base, fixture.Core.Sources[0], nil, start); err != nil {
		t.Fatalf("cleanup: %v", err)
	}
	if end := snapshotTree(t, base); !reflect.DeepEqual(end, start) {
		t.Fatalf("cleanup did not restore the start state:\nstart %v\nend   %v", start, end)
	}
}

func jellyfinUnit(fixture jellyfinFixture, scenario jellyfinScenario) fakeScript {
	unit := fakeScript{Unit: fixture.Unit.Name, Active: "active", ControlGroup: fixture.Unit.ControlGroup,
		StopExit: scenario.Inject.StopExit, StopKeepsGroup: scenario.Inject.CgroupPopulated, StartFails: scenario.Inject.StartFails}
	if scenario.Inject.InitiallyInactive {
		unit.Active, unit.ControlGroup = "inactive", ""
	}
	return unit
}

// jellyfinHarness binds the policy roots to the seeded trees, the endpoint
// to the fake server and the unit's cgroup to a fake cgroup.events file.
func jellyfinHarness(t *testing.T, fixture jellyfinFixture, base, origin string, populated bool) *harness {
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
	h := newHarness(t, policy)
	control := h.primitives["service.control"].(*serviceControl)
	control.requireCgroup2 = false
	group := filepath.Join(control.cgroupRoot, fixture.Unit.ControlGroup)
	if err := os.MkdirAll(group, 0o755); err != nil {
		t.Fatal(err)
	}
	events := "populated 0\nfrozen 0\n"
	if populated {
		events = "populated 1\nfrozen 0\n"
	}
	if err := os.WriteFile(filepath.Join(group, "cgroup.events"), []byte(events), 0o644); err != nil {
		t.Fatal(err)
	}
	return h
}

// jellyfinEndpoint serves the public info only while the fake unit is active.
func jellyfinEndpoint(t *testing.T, fixture jellyfinFixture, s *fakeScript) *httptest.Server {
	t.Helper()
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if active, err := readUnitState(s); r.URL.Path != fixture.Health.Path || err != nil || active != "active" {
			w.WriteHeader(http.StatusServiceUnavailable)
			return
		}
		w.Header().Set("Content-Type", "application/json")
		fmt.Fprint(w, fixture.Health.Body)
	}))
	t.Cleanup(server.Close)
	return server
}

// jellyfinCore stands in for the core backup between pre and finally. It
// runs only after a successful pre, always with Jellyfin stopped.
func jellyfinCore(t *testing.T, fixture jellyfinFixture, base string, s *fakeScript, preSucceeded, fails bool) string {
	t.Helper()
	if !preSucceeded {
		return "skipped"
	}
	if unit := unitActiveState(t, s); unit != "inactive" {
		t.Fatalf("the core backup would run while the unit is %s", unit)
	}
	if fails {
		return "failed"
	}
	for _, source := range fixture.Core.Sources {
		if err := coreBackup(base, source, fixture.Core.ReadableBy); err != nil {
			t.Fatalf("the core backup cannot read %s as the backup principal: %v", source, err)
		}
	}
	return "readable"
}

func unitActiveState(t *testing.T, s *fakeScript) string {
	t.Helper()
	active, err := readUnitState(s)
	if err != nil {
		t.Fatal(err)
	}
	return active
}

// readUnitState is the fake unit's state as the last systemctl left it.
func readUnitState(s *fakeScript) (string, error) {
	state := *s
	raw, err := os.ReadFile(s.State)
	if os.IsNotExist(err) {
		return state.Active, nil
	}
	if err == nil {
		err = json.Unmarshal(raw, &state)
	}
	return state.Active, err
}

func healthStatus(t *testing.T, url string) int {
	t.Helper()
	response, err := http.Get(url)
	if err != nil {
		t.Fatal(err)
	}
	response.Body.Close()
	return response.StatusCode
}

func phaseJob(t *testing.T, phase, definition string) agent.Job {
	t.Helper()
	job := definitionJob(t, testJobID, definition)
	job.Phase = phase
	return job
}
