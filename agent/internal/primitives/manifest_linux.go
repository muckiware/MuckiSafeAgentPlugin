//go:build linux

package primitives

import (
	"bytes"
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"os"
	"path/filepath"

	"golang.org/x/sys/unix"

	"github.com/muckiware/MuckiSafeAgentPlugin/agent/internal/securefs"
)

const (
	manifestDirectory = "manifests"
	manifestVersion   = 1
	maxManifestBytes  = 4 << 20
)

// manifestStore keeps one manifest per destination alias below
// `<stateDirectory>/manifests/`. It records every file file.write-pipeline
// published and is the only source file.retention deletes from. Updates hold
// an exclusive flock on the directory, so concurrent writers (also in other
// processes) never lose entries.
type manifestStore struct {
	stateDirectory string
	// sync is replaceable in tests to observe durability.
	sync func(fd int) error
}

// manifest lists the published files of one destination.
type manifest struct {
	Version     int             `json:"version"`
	Destination string          `json:"destination"`
	Next        int64           `json:"next"`
	Entries     []manifestEntry `json:"entries"`
}

// manifestEntry binds a published name to its file identity and run.
type manifestEntry struct {
	Name     string `json:"name"`
	Device   uint64 `json:"device"`
	Inode    uint64 `json:"inode"`
	RunID    string `json:"runId"`
	Sequence int64  `json:"sequence"`
}

// read returns the manifest of a destination under a shared lock.
func (s *manifestStore) read(destination string) (manifest, error) {
	dirfd, err := s.lock(unix.LOCK_SH)
	if err != nil {
		return manifest{}, err
	}
	defer unix.Close(dirfd)
	return s.load(dirfd, destination)
}

// update applies change to the manifest under an exclusive lock and stores
// the result durably; nothing is stored when change fails.
func (s *manifestStore) update(destination string, change func(*manifest) error) error {
	dirfd, err := s.lock(unix.LOCK_EX)
	if err != nil {
		return err
	}
	defer unix.Close(dirfd)
	current, err := s.load(dirfd, destination)
	if err != nil {
		return err
	}
	if err := change(&current); err != nil {
		return err
	}
	return s.store(dirfd, current)
}

// lock opens the private manifest directory, creating it if needed.
func (s *manifestStore) lock(how int) (int, error) {
	if s.stateDirectory == "" {
		return -1, errors.New("manifest: no state directory is configured")
	}
	statefd, err := securefs.OpenPrivateDirectory(s.stateDirectory)
	if err != nil {
		return -1, fmt.Errorf("manifest: state directory: %w", err)
	}
	defer unix.Close(statefd)
	err = unix.Mkdirat(statefd, manifestDirectory, 0o700)
	if err != nil && !errors.Is(err, unix.EEXIST) {
		return -1, fmt.Errorf("manifest: create directory: %w", err)
	}
	if err == nil {
		if err := s.fsync(statefd); err != nil {
			return -1, fmt.Errorf("manifest: sync state directory: %w", err)
		}
	}
	dirfd, err := securefs.OpenPrivateDirectoryAt(statefd, manifestDirectory)
	if err != nil {
		return -1, fmt.Errorf("manifest: directory: %w", err)
	}
	if err := unix.Flock(dirfd, how); err != nil {
		unix.Close(dirfd)
		return -1, fmt.Errorf("manifest: lock: %w", err)
	}
	return dirfd, nil
}

func (s *manifestStore) load(dirfd int, destination string) (manifest, error) {
	empty := manifest{Version: manifestVersion, Destination: destination, Next: 1, Entries: []manifestEntry{}}
	fd, err := unix.Openat(dirfd, destination+".json", unix.O_RDONLY|unix.O_NOFOLLOW|unix.O_NONBLOCK|unix.O_CLOEXEC, 0)
	if errors.Is(err, unix.ENOENT) {
		return empty, nil
	}
	if err != nil {
		return manifest{}, fmt.Errorf("manifest %s: %w", destination, err)
	}
	file := os.NewFile(uintptr(fd), destination+".json")
	defer file.Close()
	if err := checkPrivateFile(fd); err != nil {
		return manifest{}, fmt.Errorf("manifest %s: %w", destination, err)
	}
	raw, err := io.ReadAll(io.LimitReader(file, maxManifestBytes+1))
	if err != nil || len(raw) > maxManifestBytes {
		return manifest{}, fmt.Errorf("manifest %s: unreadable or oversized", destination)
	}
	decoder := json.NewDecoder(bytes.NewReader(raw))
	decoder.DisallowUnknownFields()
	var loaded manifest
	if err := decoder.Decode(&loaded); err != nil {
		return manifest{}, fmt.Errorf("manifest %s: %w", destination, err)
	}
	if loaded.Version != manifestVersion || loaded.Destination != destination || loaded.Next < 1 {
		return manifest{}, fmt.Errorf("manifest %s: does not belong to this destination", destination)
	}
	return loaded, nil
}

func (s *manifestStore) store(dirfd int, m manifest) error {
	raw, err := json.Marshal(m)
	if err != nil {
		return err
	}
	suffix := make([]byte, 8)
	if _, err := rand.Read(suffix); err != nil {
		return err
	}
	temporary := "." + m.Destination + "." + hex.EncodeToString(suffix) + ".tmp"
	if err := s.writeTemporary(dirfd, temporary, raw); err != nil {
		_ = unix.Unlinkat(dirfd, temporary, 0)
		return fmt.Errorf("manifest %s: write: %w", m.Destination, err)
	}
	if err := unix.Renameat(dirfd, temporary, dirfd, m.Destination+".json"); err != nil {
		_ = unix.Unlinkat(dirfd, temporary, 0)
		return fmt.Errorf("manifest %s: rename: %w", m.Destination, err)
	}
	return s.fsync(dirfd)
}

func (s *manifestStore) writeTemporary(dirfd int, name string, raw []byte) error {
	fd, err := unix.Openat(dirfd, name, unix.O_WRONLY|unix.O_CREAT|unix.O_EXCL|unix.O_NOFOLLOW|unix.O_CLOEXEC, 0o600)
	if err != nil {
		return err
	}
	defer unix.Close(fd)
	if err := unix.Fchmod(fd, 0o600); err != nil {
		return err
	}
	if err := writeAll(fd, raw); err != nil {
		return err
	}
	return s.fsync(fd)
}

func (s *manifestStore) fsync(fd int) error {
	if s.sync != nil {
		return s.sync(fd)
	}
	return unix.Fsync(fd)
}

// checkPrivateFile accepts a regular agent-owned file with mode 0600 and a
// single link.
func checkPrivateFile(fd int) error {
	var st unix.Stat_t
	if err := unix.Fstat(fd, &st); err != nil {
		return err
	}
	switch {
	case st.Mode&unix.S_IFMT != unix.S_IFREG:
		return errors.New("not a regular file")
	case int(st.Uid) != os.Geteuid():
		return errors.New("foreign owner")
	case st.Mode&0o777 != 0o600:
		return errors.New("mode is not 0600")
	case st.Nlink != 1:
		return errors.New("link count is not 1")
	}
	return nil
}

func writeAll(fd int, raw []byte) error {
	for written := 0; written < len(raw); {
		n, err := unix.Write(fd, raw[written:])
		if err != nil {
			return err
		}
		written += n
	}
	return nil
}

// owns reports whether name is exactly the output name of a run.
func (d destinationEntry) owns(entry manifestEntry) bool {
	return uuidPattern.MatchString(entry.RunID) && entry.Name == d.fileName(entry.RunID) && filepath.Base(entry.Name) == entry.Name
}
