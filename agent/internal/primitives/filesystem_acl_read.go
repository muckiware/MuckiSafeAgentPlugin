//go:build linux

package primitives

import (
	"context"
	"strings"

	"golang.org/x/sys/unix"

	"github.com/muckiware/MuckiSafeAgentPlugin/agent/internal/executor"
)

// aclRead grants the backup principal read access to a path alias, to its
// optional relative subpath and, for a recursive alias, to every directory
// and regular file below. Every object is opened descriptor-relatively
// beneath the alias root; symlinks and special files are never touched.
type aclRead struct{}

func (a *aclRead) Run(_ context.Context, step executor.StepContext) error {
	path, alias, err := resolve[pathEntry](step, "pathAlias", sectionPaths)
	if err != nil {
		return err
	}
	principal, _, err := resolve[principalEntry](step, "principalAlias", sectionPrincipals)
	if err != nil {
		return err
	}
	relative, _ := step.Step["relativePath"].(string)
	if relative == "" {
		relative = "."
	}
	what := "path " + alias + "/" + strings.TrimPrefix(relative, ".")
	rootfd, err := openRoot(path.Root)
	if err != nil {
		return pathFailure(codeVerification, what, err)
	}
	defer unix.Close(rootfd)
	fd, err := openBeneath(rootfd, relative, readFlags)
	if err != nil {
		return pathFailure(codeVerification, what, err)
	}
	defer unix.Close(fd)
	if err := grantTree(fd, path, principal); err != nil {
		return pathFailure(codeVerification, what, err)
	}
	return nil
}

func grantTree(fd int, path pathEntry, principal principalEntry) error {
	var st unix.Stat_t
	if err := unix.Fstat(fd, &st); err != nil {
		return err
	}
	if kind := st.Mode & unix.S_IFMT; kind != unix.S_IFDIR && kind != unix.S_IFREG {
		return errNotFileOrDirectory
	}
	if err := grantRead(fd, &st, principal.UID); err != nil {
		return err
	}
	if !path.Recursive || st.Mode&unix.S_IFMT != unix.S_IFDIR {
		return nil
	}
	w := &walker{maxDepth: path.MaxDepth, maxEntries: path.MaxEntries, entries: 1,
		visit: func(fd int, st *unix.Stat_t, relative string) error {
			if err := grantRead(fd, st, principal.UID); err != nil {
				return &walkError{relative: relative, err: err}
			}
			return nil
		}}
	return w.walk(fd, ".", 0)
}
