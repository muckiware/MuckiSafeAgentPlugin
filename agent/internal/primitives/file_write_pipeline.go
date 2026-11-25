//go:build linux

package primitives

import (
	"compress/gzip"
	"context"
	"crypto/rand"
	"encoding/hex"
	"errors"
	"fmt"
	"time"

	"golang.org/x/sys/unix"

	"github.com/muckiware/MuckiSafeAgentPlugin/agent/internal/executor"
)

// writePipeline streams the stdout of a fixed source command, gzip-compressed
// in Go, into a destination directory. The name comes from the run (job) id
// of the authenticated job only. The output is written to an O_EXCL
// temporary and fsynced. Its identity is recorded in the destination
// manifest before it is published with renameat2(RENAME_NOREPLACE) and the
// directory is fsynced, so an existing file is never replaced and a crash
// never leaves a published file that retention does not know (a recorded
// entry without a file is simply dropped by retention).
type writePipeline struct {
	docker    string
	manifests *manifestStore
	// sync is replaceable in tests to observe durability.
	sync func(fd int) error
}

func (p *writePipeline) Run(ctx context.Context, step executor.StepContext) error {
	command, container, err := resolveCommand(step, "sourceCommandAlias")
	if err != nil {
		return err
	}
	destination, alias, err := resolve[destinationEntry](step, "destinationAlias", sectionDestinations)
	if err != nil {
		return err
	}
	path, err := lookup[pathEntry](step, sectionPaths, destination.PathAlias, "destination "+alias)
	if err != nil {
		return err
	}
	dirfd, err := openAliasDirectory(path, destination.Directory)
	if err != nil {
		return pathFailure(codePipeline, "destination "+alias, err)
	}
	defer unix.Close(dirfd)
	name := destination.fileName(step.Job.ID)
	var existing unix.Stat_t
	if err := unix.Fstatat(dirfd, name, &existing, unix.AT_SYMLINK_NOFOLLOW); !errors.Is(err, unix.ENOENT) {
		return failure(codePipeline, "destination %s: %s already exists", alias, name)
	}
	return p.publish(ctx, step, dirfd, alias, command, container, destination)
}

// publish writes and atomically publishes the output; on any failure the
// temporary is removed and nothing is published.
func (p *writePipeline) publish(ctx context.Context, step executor.StepContext, dirfd int, alias string,
	command commandEntry, container containerEntry, destination destinationEntry) error {
	name := destination.fileName(step.Job.ID)
	suffix := make([]byte, 8)
	if _, err := rand.Read(suffix); err != nil {
		return err
	}
	temporary := "." + name + "." + hex.EncodeToString(suffix) + ".part"
	fd, err := unix.Openat(dirfd, temporary, unix.O_WRONLY|unix.O_CREAT|unix.O_EXCL|unix.O_NOFOLLOW|unix.O_CLOEXEC, 0o600)
	if err != nil {
		return failure(codePipeline, "create temporary output: %v", err)
	}
	defer unix.Close(fd)
	published := false
	defer func() {
		if !published {
			_ = unix.Unlinkat(dirfd, temporary, 0)
		}
	}()
	if err := p.produce(ctx, step, fd, command, container, destination); err != nil {
		return err
	}
	var st unix.Stat_t
	if err := p.finish(fd, destination, &st); err != nil {
		return failure(codePipeline, "finish output: %v", err)
	}
	entry := manifestEntry{Name: name, Device: st.Dev, Inode: st.Ino, RunID: step.Job.ID}
	if err := p.record(alias, entry); err != nil {
		return err
	}
	if err := unix.Renameat2(dirfd, temporary, dirfd, name, unix.RENAME_NOREPLACE); err != nil {
		_ = p.forget(alias, entry)
		return failure(codePipeline, "publish %s without replacing: %v", name, err)
	}
	published = true
	if err := p.fsync(dirfd); err != nil {
		return failure(codePipeline, "sync destination directory: %v", err)
	}
	return nil
}

// produce runs the source command into gzip into fd within the size limit.
func (p *writePipeline) produce(ctx context.Context, step executor.StepContext, fd int,
	command commandEntry, container containerEntry, destination destinationEntry) error {
	file := &limitWriter{fd: fd, limit: destination.MaximumBytes}
	compressor := gzip.NewWriter(file)
	source := &countingWriter{w: compressor}
	cmd := dockerExec(p.docker, container, command)
	cmd.Stdout = source
	status, err := runBounded(ctx, step, cmd, time.Duration(command.TimeoutSeconds)*time.Second, codePipeline)
	if err != nil {
		return err
	}
	closeErr := compressor.Close()
	switch {
	case file.exceeded:
		return failure(codePipeline, "the output exceeds %d bytes", destination.MaximumBytes)
	case source.err != nil || closeErr != nil:
		return failure(codePipeline, "write output: %v", errors.Join(source.err, closeErr))
	case status.ExitCode != 0:
		return failure(codePipeline, "source command exited with %d", status.ExitCode)
	case source.n == 0:
		return failure(codePipeline, "the source command produced no output")
	}
	return nil
}

// finish sets owner and mode, syncs the file and returns its identity.
func (p *writePipeline) finish(fd int, destination destinationEntry, st *unix.Stat_t) error {
	if err := unix.Fchown(fd, destination.Owner, destination.Group); err != nil {
		return err
	}
	if err := unix.Fchmod(fd, destination.mode); err != nil {
		return err
	}
	if err := p.fsync(fd); err != nil {
		return err
	}
	return unix.Fstat(fd, st)
}

func (p *writePipeline) record(alias string, entry manifestEntry) error {
	err := p.manifests.update(alias, func(m *manifest) error {
		entry.Sequence = m.Next
		m.Entries = append(m.Entries, entry)
		m.Next++
		return nil
	})
	if err != nil {
		return failure(codePipeline, "record %s in the manifest: %v", entry.Name, err)
	}
	return nil
}

// forget removes the entry of an output that was not published.
func (p *writePipeline) forget(alias string, entry manifestEntry) error {
	return p.manifests.update(alias, func(m *manifest) error {
		kept := m.Entries[:0]
		for _, recorded := range m.Entries {
			if recorded.RunID != entry.RunID || recorded.Inode != entry.Inode || recorded.Device != entry.Device {
				kept = append(kept, recorded)
			}
		}
		m.Entries = kept
		return nil
	})
}

func (p *writePipeline) fsync(fd int) error {
	if p.sync != nil {
		return p.sync(fd)
	}
	return unix.Fsync(fd)
}

// limitWriter writes to fd and refuses to exceed limit bytes.
type limitWriter struct {
	fd       int
	limit    int64
	written  int64
	exceeded bool
}

func (w *limitWriter) Write(b []byte) (int, error) {
	if w.written+int64(len(b)) > w.limit {
		w.exceeded = true
		return 0, fmt.Errorf("output limit of %d bytes exceeded", w.limit)
	}
	if err := writeAll(w.fd, b); err != nil {
		return 0, err
	}
	w.written += int64(len(b))
	return len(b), nil
}

// countingWriter counts the source bytes and keeps the first write error.
type countingWriter struct {
	w   interface{ Write([]byte) (int, error) }
	n   int64
	err error
}

func (c *countingWriter) Write(b []byte) (int, error) {
	if c.err != nil {
		return 0, c.err
	}
	n, err := c.w.Write(b)
	c.n += int64(n)
	c.err = err
	return n, err
}
