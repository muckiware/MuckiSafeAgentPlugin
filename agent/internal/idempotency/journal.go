// Package idempotency persists the per-job execution journal under
// `<stateDirectory>/jobs/<job-id>.json`.
//
// The journal decides after a restart whether a host effect may be repeated,
// so it fails closed: the state directory and every parent are opened
// descriptor-relative without following symlinks and must not be writable by
// foreign users. Records are written to a random O_EXCL|O_NOFOLLOW temporary
// with mode 0600, fsynced, renamed into place and the directory is fsynced.
// A record is trusted only as a regular file owned by the agent user with mode
// 0600, link count 1 and content matching its name; anything else is
// ErrCorrupt, which the runner maps to `ambiguous`, never to a new execution.
package idempotency

import (
	"bytes"
	"crypto/rand"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"regexp"
	"sort"
	"strings"
	"sync"
	"time"

	"golang.org/x/sys/unix"

	"github.com/muckiware/MuckiSafeAgentPlugin/agent/internal/api"
	"github.com/muckiware/MuckiSafeAgentPlugin/agent/internal/securefs"
)

// State is the persisted execution state of a job.
type State string

const (
	// StateStarted is written before the first host effect.
	StateStarted State = "started"
	// StateCompleted holds the final result before completion is sent.
	StateCompleted State = "completed"
)

var (
	// ErrNotFound means no record exists for the job.
	ErrNotFound = errors.New("journal: no record")
	// ErrCorrupt means a record exists but cannot be trusted.
	ErrCorrupt = errors.New("journal: record is corrupt or was tampered with")
)

const (
	recordVersion  = 1
	maxRecordBytes = 512 * 1024
	jobsDirectory  = "jobs"
)

var (
	uuidPattern  = regexp.MustCompile(`^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$`)
	hashPattern  = regexp.MustCompile(`^[0-9a-f]{64}$`)
	tokenPattern = regexp.MustCompile(`^[A-Za-z0-9_-]{43}$`)
	tempPattern  = regexp.MustCompile(`^\.[0-9a-f-]{36}\.[0-9a-f]{16}\.tmp$`)
)

// Record is one journal entry, keyed by job id, action hash and lease version.
// The claim token is kept so that a restarted agent can still complete, the
// job snapshot so that a started safe job can run again after a restart.
type Record struct {
	Version             int                   `json:"version"`
	JobID               string                `json:"jobId"`
	Phase               string                `json:"phase"`
	DefinitionCanonical string                `json:"definitionCanonical"`
	ActionHash          string                `json:"actionHash"`
	Parameters          map[string]any        `json:"parameters"`
	IdempotencyClass    string                `json:"idempotencyClass"`
	ClaimToken          string                `json:"claimToken"`
	LeaseVersion        int64                 `json:"leaseVersion"`
	PhaseDeadline       string                `json:"phaseDeadline"`
	ExecutionDeadline   string                `json:"executionDeadline"`
	StopDeadline        string                `json:"stopDeadline"`
	State               State                 `json:"state"`
	Completion          *api.CompletionResult `json:"completion,omitempty"`
}

func (r Record) validate() error {
	switch {
	case r.Version != recordVersion:
		return errors.New("unsupported record version")
	case !uuidPattern.MatchString(r.JobID):
		return errors.New("invalid job id")
	case r.Phase != "pre" && r.Phase != "post" && r.Phase != "finally":
		return errors.New("invalid phase")
	case !hashPattern.MatchString(r.ActionHash), len(r.DefinitionCanonical) < 2 || len(r.DefinitionCanonical) > 65536:
		return errors.New("invalid action")
	case !matchesHash(r.DefinitionCanonical, r.ActionHash):
		return errors.New("action definition does not match its hash")
	case r.Parameters == nil:
		return errors.New("parameters must be an object")
	case r.IdempotencyClass != "safe" && r.IdempotencyClass != "non_retryable":
		return errors.New("invalid idempotency class")
	case !tokenPattern.MatchString(r.ClaimToken), r.LeaseVersion < 1 || r.LeaseVersion > 2147483647:
		return errors.New("invalid lease fence")
	}
	for _, deadline := range []string{r.PhaseDeadline, r.ExecutionDeadline, r.StopDeadline} {
		if _, err := time.Parse(time.RFC3339, deadline); err != nil {
			return errors.New("invalid deadline")
		}
	}
	switch {
	case r.State == StateStarted && r.Completion == nil:
		return nil
	case r.State == StateCompleted && r.Completion != nil:
		return r.Completion.Validate()
	default:
		return errors.New("state and completion do not match")
	}
}

// matchesHash compares hex(sha256(canonical)) like the control plane's JobCreator.
func matchesHash(canonical, hash string) bool {
	sum := sha256.Sum256([]byte(canonical))
	return hex.EncodeToString(sum[:]) == hash
}

// Journal is an open, verified journal directory.
type Journal struct {
	mu    sync.Mutex
	dirfd int
	uid   int
	// fsync is replaceable in tests to observe durability ordering.
	fsync func(fd int) error
}

// Open verifies stateDir and its parents, creates the private jobs directory
// if needed and removes temporaries left behind by a crash.
func Open(stateDir string) (*Journal, error) {
	if stateDir == "" || !filepath.IsAbs(stateDir) || filepath.Clean(stateDir) != stateDir {
		return nil, errors.New("journal: state directory must be a clean absolute path")
	}
	uid := os.Geteuid()
	statefd, err := securefs.OpenPrivateDirectory(stateDir)
	if err != nil {
		return nil, fmt.Errorf("journal: state directory: %w", err)
	}
	defer unix.Close(statefd)
	if err := unix.Mkdirat(statefd, jobsDirectory, 0o700); err != nil && !errors.Is(err, unix.EEXIST) {
		return nil, fmt.Errorf("journal: create jobs directory: %w", err)
	}
	jobsfd, err := securefs.OpenPrivateDirectoryAt(statefd, jobsDirectory)
	if err != nil {
		return nil, fmt.Errorf("journal: jobs directory: %w", err)
	}
	j := &Journal{dirfd: jobsfd, uid: uid, fsync: unix.Fsync}
	if err := j.removeTemporaries(); err != nil {
		_ = j.Close()
		return nil, err
	}
	return j, nil
}

// Close releases the directory descriptor.
func (j *Journal) Close() error {
	j.mu.Lock()
	defer j.mu.Unlock()
	if j.dirfd < 0 {
		return nil
	}
	err := unix.Close(j.dirfd)
	j.dirfd = -1
	return err
}

// Write atomically replaces the record of r.JobID.
func (j *Journal) Write(r Record) error {
	r.Version = recordVersion
	if err := r.validate(); err != nil {
		return fmt.Errorf("journal: refusing to write record: %w", err)
	}
	raw, err := json.Marshal(r)
	if err != nil {
		return fmt.Errorf("journal: encode: %w", err)
	}
	j.mu.Lock()
	defer j.mu.Unlock()
	suffix := make([]byte, 8)
	if _, err := rand.Read(suffix); err != nil {
		return fmt.Errorf("journal: temporary name: %w", err)
	}
	temporary := "." + r.JobID + "." + hex.EncodeToString(suffix) + ".tmp"
	if err := j.writeTemporary(temporary, raw); err != nil {
		_ = unix.Unlinkat(j.dirfd, temporary, 0)
		return fmt.Errorf("journal: write: %w", err)
	}
	if err := unix.Renameat(j.dirfd, temporary, j.dirfd, r.JobID+".json"); err != nil {
		_ = unix.Unlinkat(j.dirfd, temporary, 0)
		return fmt.Errorf("journal: rename: %w", err)
	}
	if err := j.fsync(j.dirfd); err != nil {
		return fmt.Errorf("journal: sync directory: %w", err)
	}
	return nil
}

func (j *Journal) writeTemporary(name string, raw []byte) error {
	fd, err := unix.Openat(j.dirfd, name, unix.O_WRONLY|unix.O_CREAT|unix.O_EXCL|unix.O_NOFOLLOW|unix.O_CLOEXEC, 0o600)
	if err != nil {
		return err
	}
	defer unix.Close(fd)
	if err := unix.Fchmod(fd, 0o600); err != nil {
		return err
	}
	for written := 0; written < len(raw); {
		n, err := unix.Write(fd, raw[written:])
		if err != nil {
			return err
		}
		written += n
	}
	return j.fsync(fd)
}

// Load returns the trusted record of jobID, ErrNotFound or ErrCorrupt.
func (j *Journal) Load(jobID string) (Record, error) {
	if !uuidPattern.MatchString(jobID) {
		return Record{}, errors.New("journal: invalid job id")
	}
	j.mu.Lock()
	defer j.mu.Unlock()
	fd, err := unix.Openat(j.dirfd, jobID+".json", unix.O_RDONLY|unix.O_NOFOLLOW|unix.O_NONBLOCK|unix.O_CLOEXEC, 0)
	if errors.Is(err, unix.ENOENT) {
		return Record{}, ErrNotFound
	}
	if err != nil {
		return Record{}, fmt.Errorf("%w: open: %v", ErrCorrupt, err)
	}
	file := os.NewFile(uintptr(fd), jobID+".json")
	defer file.Close()
	if err := j.checkRecordFile(fd); err != nil {
		return Record{}, fmt.Errorf("%w: %v", ErrCorrupt, err)
	}
	raw, err := io.ReadAll(io.LimitReader(file, maxRecordBytes+1))
	if err != nil || len(raw) > maxRecordBytes {
		return Record{}, fmt.Errorf("%w: unreadable or oversized", ErrCorrupt)
	}
	var record Record
	decoder := json.NewDecoder(bytes.NewReader(raw))
	decoder.DisallowUnknownFields()
	if err := decoder.Decode(&record); err != nil {
		return Record{}, fmt.Errorf("%w: %v", ErrCorrupt, err)
	}
	if _, err := decoder.Token(); !errors.Is(err, io.EOF) {
		return Record{}, fmt.Errorf("%w: trailing data", ErrCorrupt)
	}
	if err := record.validate(); err != nil {
		return Record{}, fmt.Errorf("%w: %v", ErrCorrupt, err)
	}
	if record.JobID != jobID {
		return Record{}, fmt.Errorf("%w: record belongs to another job", ErrCorrupt)
	}
	return record, nil
}

func (j *Journal) checkRecordFile(fd int) error {
	var st unix.Stat_t
	if err := unix.Fstat(fd, &st); err != nil {
		return err
	}
	switch {
	case uint32(st.Mode)&unix.S_IFMT != unix.S_IFREG:
		return errors.New("not a regular file")
	case int(st.Uid) != j.uid:
		return errors.New("foreign owner")
	case uint32(st.Mode)&0o777 != 0o600:
		return errors.New("mode is not 0600")
	case uint64(st.Nlink) != 1:
		return errors.New("link count is not 1")
	}
	return nil
}

// Remove deletes the record of jobID durably; a missing record is fine.
func (j *Journal) Remove(jobID string) error {
	if !uuidPattern.MatchString(jobID) {
		return errors.New("journal: invalid job id")
	}
	j.mu.Lock()
	defer j.mu.Unlock()
	if err := unix.Unlinkat(j.dirfd, jobID+".json", 0); err != nil && !errors.Is(err, unix.ENOENT) {
		return fmt.Errorf("journal: remove: %w", err)
	}
	return j.fsync(j.dirfd)
}

// List returns the sorted job ids of all record names, trusted or not.
func (j *Journal) List() ([]string, error) {
	names, err := j.names()
	if err != nil {
		return nil, err
	}
	var ids []string
	for _, name := range names {
		if id, ok := strings.CutSuffix(name, ".json"); ok && uuidPattern.MatchString(id) {
			ids = append(ids, id)
		}
	}
	sort.Strings(ids)
	return ids, nil
}

func (j *Journal) removeTemporaries() error {
	names, err := j.names()
	if err != nil {
		return err
	}
	for _, name := range names {
		if tempPattern.MatchString(name) {
			if err := unix.Unlinkat(j.dirfd, name, 0); err != nil && !errors.Is(err, unix.ENOENT) {
				return fmt.Errorf("journal: remove stale temporary: %w", err)
			}
		}
	}
	return nil
}

// names reads the directory through a fresh descriptor, so the shared offset
// of the long-lived directory descriptor is never consumed.
func (j *Journal) names() ([]string, error) {
	j.mu.Lock()
	fd, err := unix.Openat(j.dirfd, ".", unix.O_RDONLY|unix.O_DIRECTORY|unix.O_CLOEXEC, 0)
	j.mu.Unlock()
	if err != nil {
		return nil, fmt.Errorf("journal: list: %w", err)
	}
	dir := os.NewFile(uintptr(fd), jobsDirectory)
	defer dir.Close()
	names, err := dir.Readdirnames(-1)
	if err != nil {
		return nil, fmt.Errorf("journal: list: %w", err)
	}
	return names, nil
}
