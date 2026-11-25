//go:build linux

package primitives

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"reflect"
	"slices"
	"strings"
	"testing"

	"golang.org/x/sys/unix"
)

type immichFixture struct {
	FixtureVersion int                          `json:"fixtureVersion"`
	Service        string                       `json:"service"`
	Contract       string                       `json:"contract"`
	Action         string                       `json:"action"`
	Policy         string                       `json:"policy"`
	Layouts        map[string]map[string]string `json:"layouts"`
	Description    string                       `json:"description"`
	Seed           referenceSeed                `json:"seed"`
	Dump           struct {
		Container        string   `json:"container"`
		Argv             []string `json:"argv"`
		Output           string   `json:"output"`
		DestinationAlias string   `json:"destinationAlias"`
		Directory        string   `json:"directory"`
		Prefix           string   `json:"prefix"`
		Suffix           string   `json:"suffix"`
		Mode             string   `json:"mode"`
		UID              int      `json:"uid"`
		GID              int      `json:"gid"`
	} `json:"dump"`
	History struct {
		RunIDPrefix string `json:"runIdPrefix"`
		Runs        int    `json:"runs"`
		Mode        string `json:"mode"`
		UID         int    `json:"uid"`
		GID         int    `json:"gid"`
	} `json:"history"`
	Core struct {
		Operation  string         `json:"operation"`
		ReadableBy principalEntry `json:"readableBy"`
		Sources    []string       `json:"sources"`
	} `json:"core"`
	Scenarios []immichScenario `json:"scenarios"`
	Cleanup   json.RawMessage  `json:"cleanup"`
}

type immichScenario struct {
	Name   string `json:"name"`
	Layout string `json:"layout"`
	Inject struct {
		CommandExit    int               `json:"commandExit"`
		Output         string            `json:"output"`
		Remove         string            `json:"remove"`
		Symlink        *symlinkInjection `json:"symlink"`
		ReplaceHistory *int              `json:"replaceHistory"`
	} `json:"inject"`
	Expect struct {
		Outcome        string `json:"outcome"`
		ResultCode     string `json:"resultCode"`
		SummaryPrefix  string `json:"summaryPrefix"`
		Invoked        bool   `json:"invoked"`
		Dump           string `json:"dump"`
		RemovedHistory []int  `json:"removedHistory"`
		Core           string `json:"core"`
	} `json:"expect"`
}

func TestImmichReferenceAction(t *testing.T) {
	var fixture immichFixture
	decodeFixture(t, "immich", &fixture)
	definition := readRepositoryFile(t, fixture.Action)
	for _, scenario := range fixture.Scenarios {
		t.Run(scenario.Name, func(t *testing.T) {
			runImmichScenario(t, fixture, definition, scenario)
		})
	}
}

func runImmichScenario(t *testing.T, fixture immichFixture, definition string, scenario immichScenario) {
	base := filepath.Join(tempDir(t), "immich")
	if err := os.Mkdir(base, 0o755); err != nil {
		t.Fatal(err)
	}
	seedTree(t, base, fixture.Seed)
	layout, ok := fixture.Layouts[scenario.Layout]
	if !ok {
		t.Fatalf("unknown layout %q", scenario.Layout)
	}
	h := immichHarness(t, fixture, base, layout)
	history := primeHistory(t, h, fixture, base)
	if i := scenario.Inject.ReplaceHistory; i != nil {
		replaceFile(t, filepath.Join(base, fixture.Dump.Directory, history[*i]))
	}
	inject(t, base, scenario.Inject.Remove, scenario.Inject.Symlink)
	var targetACL string
	if link := scenario.Inject.Symlink; link != nil {
		targetACL = aclOf(t, link.Target)
	}
	start := snapshotTree(t, base)
	dumpDir := fixture.Dump.Directory
	var before map[string]artifactIdentity
	if scenario.Expect.Dump == "published" {
		before = directoryIdentities(t, base, dumpDir)
	}

	s := script(t, dumpScript(fixture, scenario))
	result := h.execute(context.Background(), definitionJob(t, testJobID, definition)).ForWire()

	expect := scenario.Expect
	if result.Outcome != expect.Outcome || result.ResultCode != expect.ResultCode || !strings.HasPrefix(result.Summary, expect.SummaryPrefix) {
		t.Fatalf("got %s/%s %q, want %s/%s %q", result.Outcome, result.ResultCode, result.Summary, expect.Outcome, expect.ResultCode, expect.SummaryPrefix)
	}
	assertDumpInvocation(t, fixture, s, expect.Invoked)
	assertNoTemporaries(t, base, dumpDir)
	assertDump(t, h, fixture, base, expect.Dump == "published")
	removed := removedNames(history, expect.RemovedHistory)
	assertRetention(t, h, fixture, base, history, removed, start, expect.Dump == "published")
	if link := scenario.Inject.Symlink; link != nil && aclOf(t, link.Target) != targetACL {
		t.Fatalf("the ACL of the symlink target %s changed", link.Target)
	}
	core := "skipped"
	if result.Outcome == "succeeded" {
		for _, source := range fixture.Core.Sources {
			if err := coreBackup(base, layout[source], fixture.Core.ReadableBy); err != nil {
				t.Fatalf("the core backup cannot read %s as the backup principal: %v", source, err)
			}
		}
		core = "readable"
	}
	if core != expect.Core {
		t.Fatalf("core %s, want %s", core, expect.Core)
	}

	// retention's deletions are the run's intended effect, not cleanup's
	remaining := withoutObjects(start, dumpDir, removed)
	cleanupDir, artifacts := "library", []artifactIdentity(nil)
	if expect.Dump == "published" {
		cleanupDir, artifacts = dumpDir, newArtifacts(directoryIdentities(t, base, dumpDir), before)
	}
	if err := cleanupRun(base, cleanupDir, artifacts, remaining); err != nil {
		t.Fatalf("cleanup: %v", err)
	}
	if end := snapshotTree(t, base); !reflect.DeepEqual(end, remaining) {
		t.Fatalf("cleanup did not restore the start state:\nstart %v\nend   %v", remaining, end)
	}
}

func immichHarness(t *testing.T, fixture immichFixture, base string, layout map[string]string) *harness {
	t.Helper()
	policy := readRepositoryFile(t, fixture.Policy)
	for root, dir := range layout {
		old := "root: " + root + "\n"
		if strings.Count(policy, old) != 1 {
			t.Fatalf("the policy must contain %q exactly once", old)
		}
		policy = strings.Replace(policy, old, "root: "+filepath.Join(base, dir)+"\n", 1)
	}
	return newHarness(t, policy)
}

// primeHistory publishes the dumps of earlier runs and records them in the
// manifest, as earlier write-pipeline runs did. It returns their names,
// oldest first.
func primeHistory(t *testing.T, h *harness, fixture immichFixture, base string) []string {
	t.Helper()
	manifests := h.primitives["file.write-pipeline"].(*writePipeline).manifests
	names := make([]string, fixture.History.Runs)
	for i := range names {
		runID := fmt.Sprintf("%s%02d", fixture.History.RunIDPrefix, i)
		names[i] = fixture.Dump.Prefix + runID + fixture.Dump.Suffix
		path := filepath.Join(base, fixture.Dump.Directory, names[i])
		entry := seedEntry{Mode: fixture.History.Mode, UID: fixture.History.UID, GID: fixture.History.GID}
		if err := os.WriteFile(path, []byte("earlier manual dump"), 0o600); err != nil {
			t.Fatal(err)
		}
		chownChmod(t, path, entry)
		var st unix.Stat_t
		if err := unix.Lstat(path, &st); err != nil {
			t.Fatal(err)
		}
		err := manifests.update(fixture.Dump.DestinationAlias, func(m *manifest) error {
			m.Entries = append(m.Entries, manifestEntry{Name: names[i], Device: st.Dev, Inode: st.Ino, RunID: runID, Sequence: m.Next})
			m.Next++
			return nil
		})
		if err != nil {
			t.Fatal(err)
		}
	}
	return names
}

// replaceFile puts another file with the same name, owner and mode in place.
func replaceFile(t *testing.T, path string) {
	t.Helper()
	var st unix.Stat_t
	if err := unix.Lstat(path, &st); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(path+".other", []byte("not the recorded dump"), 0o600); err != nil {
		t.Fatal(err)
	}
	if err := os.Chown(path+".other", int(st.Uid), int(st.Gid)); err != nil {
		t.Fatal(err)
	}
	if err := os.Chmod(path+".other", os.FileMode(st.Mode).Perm()); err != nil {
		t.Fatal(err)
	}
	if err := os.Rename(path+".other", path); err != nil {
		t.Fatal(err)
	}
}

func dumpScript(fixture immichFixture, scenario immichScenario) fakeScript {
	s := fakeScript{Container: fixture.Dump.Container, Exit: scenario.Inject.CommandExit, Output: fixture.Dump.Output}
	switch scenario.Inject.Output {
	case "partial":
		s.Output = fixture.Dump.Output[:len(fixture.Dump.Output)/2]
	case "none":
		s.Output = ""
	}
	return s
}

func assertDumpInvocation(t *testing.T, fixture immichFixture, s *fakeScript, invoked bool) {
	t.Helper()
	var want []string
	if invoked {
		want = []string{"exec " + fixture.Dump.Container + " " + strings.Join(fixture.Dump.Argv, " ")}
	}
	if calls := invocations(t, s); !slices.Equal(calls, want) {
		t.Fatalf("invocations %q, want %q", calls, want)
	}
}

// assertNoTemporaries requires that no .part file survives the run.
func assertNoTemporaries(t *testing.T, base, dir string) {
	t.Helper()
	info, err := os.Lstat(filepath.Join(base, dir))
	if err != nil {
		t.Fatal(err)
	}
	if !info.IsDir() {
		return
	}
	entries, err := os.ReadDir(filepath.Join(base, dir))
	if err != nil {
		t.Fatal(err)
	}
	for _, entry := range entries {
		if strings.HasSuffix(entry.Name(), ".part") {
			t.Fatalf("temporary output %s was left behind", entry.Name())
		}
	}
}

// assertDump checks the current-run dump: gzip of the source output with
// the destination's owner and mode, recorded in the manifest.
func assertDump(t *testing.T, h *harness, fixture immichFixture, base string, published bool) {
	t.Helper()
	name := fixture.Dump.Prefix + testJobID + fixture.Dump.Suffix
	path := filepath.Join(base, fixture.Dump.Directory, name)
	var st unix.Stat_t
	err := unix.Lstat(path, &st)
	if !published {
		if !errors.Is(err, unix.ENOENT) {
			t.Fatalf("no dump may be published, got %v", err)
		}
		return
	}
	if err != nil {
		t.Fatal(err)
	}
	if st.Uid != uint32(fixture.Dump.UID) || st.Gid != uint32(fixture.Dump.GID) || uint32(octal(t, fixture.Dump.Mode)) != st.Mode&0o7777 {
		t.Fatalf("dump owner %d:%d mode %o", st.Uid, st.Gid, st.Mode&0o7777)
	}
	if content := gunzip(t, path); content != fixture.Dump.Output {
		t.Fatalf("dump content %q", content)
	}
	for _, entry := range readManifest(t, h, fixture.Dump.DestinationAlias).Entries {
		if entry.RunID == testJobID && entry.Name == name && entry.Device == st.Dev && entry.Inode == st.Ino {
			return
		}
	}
	t.Fatal("the dump is not recorded in the manifest")
}

// assertRetention requires that exactly the removed history dumps are gone
// from disk and manifest; everything else in the directory is untouched.
func assertRetention(t *testing.T, h *harness, fixture immichFixture, base string, history, removed []string, start treeSnapshot, published bool) {
	t.Helper()
	var want []string
	for _, name := range history {
		_, existed := start.Objects[filepath.Join(fixture.Dump.Directory, name)]
		_, err := os.Lstat(filepath.Join(base, fixture.Dump.Directory, name))
		if exists := err == nil; exists != (existed && !slices.Contains(removed, name)) {
			t.Fatalf("history dump %s exists=%v, existed=%v, removed=%v", name, exists, existed, slices.Contains(removed, name))
		}
		if !slices.Contains(removed, name) {
			want = append(want, name)
		}
	}
	if published {
		want = append(want, fixture.Dump.Prefix+testJobID+fixture.Dump.Suffix)
	}
	var got []string
	for _, entry := range readManifest(t, h, fixture.Dump.DestinationAlias).Entries {
		got = append(got, entry.Name)
	}
	if !slices.Equal(got, want) {
		t.Fatalf("manifest %q, want %q", got, want)
	}
}

func removedNames(history []string, indexes []int) []string {
	names := make([]string, len(indexes))
	for i, index := range indexes {
		names[i] = history[index]
	}
	return names
}

func withoutObjects(snapshot treeSnapshot, dir string, names []string) treeSnapshot {
	remaining := treeSnapshot{Device: snapshot.Device, Inode: snapshot.Inode, Objects: map[string]objectState{}}
	for relative, state := range snapshot.Objects {
		remaining.Objects[relative] = state
	}
	for _, name := range names {
		delete(remaining.Objects, filepath.Join(dir, name))
	}
	return remaining
}
