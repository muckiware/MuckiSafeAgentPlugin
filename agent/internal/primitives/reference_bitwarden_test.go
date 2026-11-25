//go:build linux

package primitives

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"io/fs"
	"os"
	"path/filepath"
	"reflect"
	"strconv"
	"strings"
	"testing"
	"time"

	"golang.org/x/sys/unix"

	"github.com/muckiware/MuckiSafeAgentPlugin/agent/internal/agent"
)

// repositoryRoot is the plugin root seen from this package.
const repositoryRoot = "../../.."

type referenceFixture struct {
	FixtureVersion int           `json:"fixtureVersion"`
	Service        string        `json:"service"`
	Contract       string        `json:"contract"`
	Action         string        `json:"action"`
	Policy         string        `json:"policy"`
	PolicyRoot     string        `json:"policyRoot"`
	Description    string        `json:"description"`
	Seed           referenceSeed `json:"seed"`
	BackupCommand  struct {
		ContainerAlias string   `json:"containerAlias"`
		CommandAlias   string   `json:"commandAlias"`
		Container      string   `json:"container"`
		Argv           []string `json:"argv"`
		Artifact       struct {
			Directory string `json:"directory"`
			Name      string `json:"name"`
			Mode      string `json:"mode"`
			UID       int    `json:"uid"`
			GID       int    `json:"gid"`
			Bytes     int    `json:"bytes"`
		} `json:"artifact"`
	} `json:"backupCommand"`
	Core struct {
		Operation  string         `json:"operation"`
		ReadableBy principalEntry `json:"readableBy"`
		Source     string         `json:"source"`
	} `json:"core"`
	Scenarios []referenceScenario `json:"scenarios"`
	Cleanup   json.RawMessage     `json:"cleanup"`
}

type referenceSeed struct {
	Directories []seedEntry `json:"directories"`
	Files       []seedEntry `json:"files"`
}

type seedEntry struct {
	Path    string `json:"path"`
	Mode    string `json:"mode"`
	UID     int    `json:"uid"`
	GID     int    `json:"gid"`
	Content string `json:"content"`
}

type referenceScenario struct {
	Name   string `json:"name"`
	Inject struct {
		CommandExit   int               `json:"commandExit"`
		Artifact      string            `json:"artifact"`
		ArtifactBytes *int              `json:"artifactBytes"`
		Remove        string            `json:"remove"`
		Symlink       *symlinkInjection `json:"symlink"`
	} `json:"inject"`
	Expect struct {
		Outcome       string `json:"outcome"`
		ResultCode    string `json:"resultCode"`
		SummaryPrefix string `json:"summaryPrefix"`
		Core          string `json:"core"`
	} `json:"expect"`
}

type symlinkInjection struct {
	Path   string `json:"path"`
	Target string `json:"target"`
}

func loadReference(t *testing.T, service string) referenceFixture {
	t.Helper()
	var fixture referenceFixture
	decodeFixture(t, service, &fixture)
	return fixture
}

// decodeFixture strictly decodes tests/E2E/fixtures/<service>/fixture.json.
func decodeFixture(t *testing.T, service string, fixture any) {
	t.Helper()
	raw, err := os.ReadFile(filepath.Join(repositoryRoot, "tests/E2E/fixtures", service, "fixture.json"))
	if err != nil {
		t.Fatal(err)
	}
	decoder := json.NewDecoder(bytes.NewReader(raw))
	decoder.DisallowUnknownFields()
	if err := decoder.Decode(fixture); err != nil {
		t.Fatal(err)
	}
}

func readRepositoryFile(t *testing.T, path string) string {
	t.Helper()
	raw, err := os.ReadFile(filepath.Join(repositoryRoot, path))
	if err != nil {
		t.Fatal(err)
	}
	return string(raw)
}

func octal(t *testing.T, mode string) os.FileMode {
	t.Helper()
	value, err := strconv.ParseUint(mode, 8, 32)
	if err != nil {
		t.Fatal(err)
	}
	return os.FileMode(value)
}

// seedTree creates the fixture tree below root as root-owned objects unless
// the entry names another owner.
func seedTree(t *testing.T, root string, seed referenceSeed) {
	t.Helper()
	for _, dir := range seed.Directories {
		path := filepath.Join(root, dir.Path)
		if err := os.Mkdir(path, 0o700); err != nil {
			t.Fatal(err)
		}
		chownChmod(t, path, dir)
	}
	for _, file := range seed.Files {
		path := filepath.Join(root, file.Path)
		if err := os.WriteFile(path, []byte(file.Content), 0o600); err != nil {
			t.Fatal(err)
		}
		chownChmod(t, path, file)
	}
}

func chownChmod(t *testing.T, path string, entry seedEntry) {
	t.Helper()
	if err := os.Chown(path, entry.UID, entry.GID); err != nil {
		t.Fatal(err)
	}
	if err := os.Chmod(path, octal(t, entry.Mode)); err != nil {
		t.Fatal(err)
	}
}

// waitForNextSecond makes every seeded file older than the run start, which
// the current-run rule compares at second granularity.
func waitForNextSecond() {
	time.Sleep(time.Until(time.Now().Truncate(time.Second).Add(time.Second + 20*time.Millisecond)))
}

func TestBitwardenReferenceAction(t *testing.T) {
	fixture := loadReference(t, "bitwarden")
	definition := readRepositoryFile(t, fixture.Action)
	for _, scenario := range fixture.Scenarios {
		t.Run(scenario.Name, func(t *testing.T) {
			runBitwardenScenario(t, fixture, definition, scenario)
		})
	}
}

func runBitwardenScenario(t *testing.T, fixture referenceFixture, definition string, scenario referenceScenario) {
	root := filepath.Join(tempDir(t), "bitwarden")
	if err := os.Mkdir(root, 0o755); err != nil {
		t.Fatal(err)
	}
	seedTree(t, root, fixture.Seed)
	inject(t, root, scenario.Inject.Remove, scenario.Inject.Symlink)
	start := snapshotTree(t, root)
	artifactDir := fixture.BackupCommand.Artifact.Directory
	before := directoryIdentities(t, root, artifactDir)
	waitForNextSecond()

	h := newHarness(t, strings.Replace(readRepositoryFile(t, fixture.Policy), "root: "+fixture.PolicyRoot+"\n", "root: "+root+"\n", 1))
	s := script(t, backupScript(fixture, root, scenario))
	result := h.execute(context.Background(), definitionJob(t, testJobID, definition)).ForWire()

	expect := scenario.Expect
	if result.Outcome != expect.Outcome || result.ResultCode != expect.ResultCode || !strings.HasPrefix(result.Summary, expect.SummaryPrefix) {
		t.Fatalf("got %s/%s %q, want %s/%s %q", result.Outcome, result.ResultCode, result.Summary, expect.Outcome, expect.ResultCode, expect.SummaryPrefix)
	}
	want := "exec " + fixture.BackupCommand.Container + " " + strings.Join(fixture.BackupCommand.Argv, " ")
	if calls := invocations(t, s); len(calls) != 1 || calls[0] != want {
		t.Fatalf("invocations %q, want %q", calls, want)
	}
	if expect.Core == "readable" {
		if err := coreBackup(root, fixture.Core.Source, fixture.Core.ReadableBy); err != nil {
			t.Fatalf("the core backup cannot read the source as the backup principal: %v", err)
		}
	}

	artifacts := newArtifacts(directoryIdentities(t, root, artifactDir), before)
	if err := cleanupRun(root, artifactDir, artifacts, start); err != nil {
		t.Fatalf("cleanup: %v", err)
	}
	if end := snapshotTree(t, root); !reflect.DeepEqual(end, start) {
		t.Fatalf("cleanup did not restore the start state:\nstart %v\nend   %v", start, end)
	}
}

func inject(t *testing.T, root, remove string, link *symlinkInjection) {
	t.Helper()
	if remove != "" {
		// Lstat keeps a misspelled path from passing silently
		if _, err := os.Lstat(filepath.Join(root, remove)); err != nil {
			t.Fatal(err)
		}
		if err := os.RemoveAll(filepath.Join(root, remove)); err != nil {
			t.Fatal(err)
		}
	}
	if link != nil {
		if err := os.RemoveAll(filepath.Join(root, link.Path)); err != nil {
			t.Fatal(err)
		}
		if err := os.Symlink(link.Target, filepath.Join(root, link.Path)); err != nil {
			t.Fatal(err)
		}
	}
}

func backupScript(fixture referenceFixture, root string, scenario referenceScenario) fakeScript {
	s := fakeScript{Container: fixture.BackupCommand.Container, Exit: scenario.Inject.CommandExit}
	if scenario.Inject.Artifact == "none" {
		return s
	}
	artifact := fixture.BackupCommand.Artifact
	mode, _ := strconv.ParseUint(artifact.Mode, 8, 32)
	s.Create = &fakeFile{Path: filepath.Join(root, artifact.Directory, artifact.Name), Bytes: artifact.Bytes,
		UID: artifact.UID, GID: artifact.GID, Mode: os.FileMode(mode)}
	if scenario.Inject.ArtifactBytes != nil {
		s.Create.Bytes = *scenario.Inject.ArtifactBytes
	}
	return s
}

// definitionJob builds a job from a complete action definition file.
func definitionJob(t *testing.T, jobID, definition string) agent.Job {
	t.Helper()
	var parsed struct {
		Steps []json.RawMessage `json:"steps"`
	}
	if err := json.Unmarshal([]byte(definition), &parsed); err != nil {
		t.Fatal(err)
	}
	steps := make([]string, len(parsed.Steps))
	for i, step := range parsed.Steps {
		steps[i] = string(step)
	}
	return actionJob(t, jobID, steps...)
}

// coreBackup reads every file of the source tree with the identity of the
// MuckiSafe backup, like the core backup does.
func coreBackup(root, source string, principal principalEntry) error {
	rootfd, err := openRoot(root)
	if err != nil {
		return err
	}
	defer unix.Close(rootfd)
	return asPrincipal(principal, func() error {
		fd, err := openBeneath(rootfd, source, directoryFlags)
		if err != nil {
			return err
		}
		defer unix.Close(fd)
		w := &walker{maxDepth: 32, maxEntries: 10000, entries: 1, visit: func(fd int, st *unix.Stat_t, relative string) error {
			if st.Mode&unix.S_IFMT != unix.S_IFREG {
				return nil
			}
			buffer := make([]byte, 4096)
			for {
				n, err := unix.Read(fd, buffer)
				if err != nil {
					return &walkError{relative: relative, err: err}
				}
				if n == 0 {
					return nil
				}
			}
		}}
		return w.walk(fd, source, 0)
	})
}

// objectState is what a test run may change and cleanup must restore.
type objectState struct {
	Kind string
	Mode os.FileMode
	Size int64
	ACL  string
}

type treeSnapshot struct {
	Device, Inode uint64
	Objects       map[string]objectState
}

func snapshotTree(t *testing.T, root string) treeSnapshot {
	t.Helper()
	var st unix.Stat_t
	if err := unix.Stat(root, &st); err != nil {
		t.Fatal(err)
	}
	snapshot := treeSnapshot{Device: st.Dev, Inode: st.Ino, Objects: map[string]objectState{}}
	err := filepath.WalkDir(root, func(path string, entry fs.DirEntry, err error) error {
		if err != nil {
			return err
		}
		info, err := entry.Info()
		if err != nil {
			return err
		}
		relative, _ := filepath.Rel(root, path)
		state := objectState{Kind: info.Mode().Type().String(), Mode: info.Mode().Perm()}
		if info.Mode().IsRegular() {
			state.Size = info.Size()
		}
		if entry.Type()&fs.ModeSymlink == 0 {
			state.ACL = aclOf(t, path)
		}
		snapshot.Objects[relative] = state
		return nil
	})
	if err != nil {
		t.Fatal(err)
	}
	return snapshot
}

func aclOf(t *testing.T, path string) string {
	t.Helper()
	raw := make([]byte, maxACLBytes)
	n, err := unix.Lgetxattr(path, aclXattr, raw)
	if errors.Is(err, unix.ENODATA) {
		return ""
	}
	if err != nil {
		t.Fatal(err)
	}
	return string(raw[:n])
}

type artifactIdentity struct {
	Name          string
	Device, Inode uint64
}

func directoryIdentities(t *testing.T, root, dir string) map[string]artifactIdentity {
	t.Helper()
	entries, err := os.ReadDir(filepath.Join(root, dir))
	if err != nil {
		t.Fatal(err)
	}
	identities := map[string]artifactIdentity{}
	for _, entry := range entries {
		var st unix.Stat_t
		if err := unix.Lstat(filepath.Join(root, dir, entry.Name()), &st); err != nil {
			t.Fatal(err)
		}
		identities[entry.Name()] = artifactIdentity{Name: entry.Name(), Device: st.Dev, Inode: st.Ino}
	}
	return identities
}

// newArtifacts are the objects a run created, recorded before cleanup.
func newArtifacts(after, before map[string]artifactIdentity) []artifactIdentity {
	var created []artifactIdentity
	for name, identity := range after {
		if old, existed := before[name]; !existed || old != identity {
			created = append(created, identity)
		}
	}
	return created
}

var errMutateNothing = errors.New("identity mismatch, nothing was changed")

// cleanupRun implements the test-only cleanup of the reference contract:
// it deletes only recorded artifacts whose device/inode still match, that
// are regular files with link count 1, and restores the pre-test ACLs and
// modes only while the root is still the snapshot root. Any mismatch
// mutates nothing.
func cleanupRun(root, dir string, artifacts []artifactIdentity, start treeSnapshot) error {
	rootfd, err := openRoot(root)
	if err != nil {
		return err
	}
	defer unix.Close(rootfd)
	var st unix.Stat_t
	if err := unix.Fstat(rootfd, &st); err != nil || st.Dev != start.Device || st.Ino != start.Inode {
		return errMutateNothing
	}
	dirfd, err := openBeneath(rootfd, dir, directoryFlags)
	if err != nil {
		return err
	}
	defer unix.Close(dirfd)
	for _, artifact := range artifacts {
		if err := matchesArtifact(dirfd, artifact); err != nil {
			return err
		}
	}
	for _, artifact := range artifacts {
		if err := matchesArtifact(dirfd, artifact); err != nil {
			return err
		}
		if err := unix.Unlinkat(dirfd, artifact.Name, 0); err != nil {
			return err
		}
	}
	return restoreACLs(rootfd, start)
}

func matchesArtifact(dirfd int, artifact artifactIdentity) error {
	var st unix.Stat_t
	if err := unix.Fstatat(dirfd, artifact.Name, &st, unix.AT_SYMLINK_NOFOLLOW); err != nil {
		return err
	}
	if st.Mode&unix.S_IFMT != unix.S_IFREG || st.Dev != artifact.Device || st.Ino != artifact.Inode || st.Nlink != 1 {
		return errMutateNothing
	}
	return nil
}

func restoreACLs(rootfd int, start treeSnapshot) error {
	for relative, state := range start.Objects {
		if state.Kind == fs.ModeSymlink.String() {
			continue
		}
		fd, err := openBeneath(rootfd, relative, readFlags)
		if err != nil {
			return err
		}
		if state.ACL == "" {
			err = unix.Fremovexattr(fd, aclXattr)
			if errors.Is(err, unix.ENODATA) {
				err = nil
			}
			if err == nil {
				err = unix.Fchmod(fd, uint32(state.Mode))
			}
		} else {
			err = unix.Fsetxattr(fd, aclXattr, []byte(state.ACL), 0)
		}
		unix.Close(fd)
		if err != nil {
			return err
		}
	}
	return nil
}

func TestBitwardenCleanupDeletesNothingOnAnIdentityMismatch(t *testing.T) {
	fixture := loadReference(t, "bitwarden")
	root := filepath.Join(tempDir(t), "bitwarden")
	os.Mkdir(root, 0o755)
	seedTree(t, root, fixture.Seed)
	start := snapshotTree(t, root)
	artifactDir := fixture.BackupCommand.Artifact.Directory
	before := directoryIdentities(t, root, artifactDir)
	waitForNextSecond()
	h := newHarness(t, strings.Replace(readRepositoryFile(t, fixture.Policy), "root: "+fixture.PolicyRoot+"\n", "root: "+root+"\n", 1))
	script(t, backupScript(fixture, root, fixture.Scenarios[0]))
	expect(t, h.execute(context.Background(), definitionJob(t, testJobID, readRepositoryFile(t, fixture.Action))), "succeeded", "ok")
	artifacts := newArtifacts(directoryIdentities(t, root, artifactDir), before)

	// the artifact is replaced by another file under the same name
	path := filepath.Join(root, artifactDir, fixture.BackupCommand.Artifact.Name)
	os.WriteFile(path+".other", []byte("not the run's artifact"), 0o600)
	os.Rename(path+".other", path)
	changed := snapshotTree(t, root)

	if err := cleanupRun(root, artifactDir, artifacts, start); !errors.Is(err, errMutateNothing) {
		t.Fatalf("cleanup must refuse a mismatched artifact: %v", err)
	}
	if after := snapshotTree(t, root); !reflect.DeepEqual(after, changed) {
		t.Fatal("a refused cleanup changed the tree")
	}
}
