//go:build !linux

package primitives

import (
	"context"
	"errors"

	"github.com/muckiware/MuckiSafeAgentPlugin/agent/internal/executor"
)

// The file system primitives and the cgroup check need Linux (openat2,
// renameat2, POSIX ACL xattrs, statx, cgroup v2); elsewhere they fail closed.

type manifestStore struct {
	stateDirectory string
}

type aclRead struct{}

type assertReadable struct {
	manifests *manifestStore
}

type writePipeline struct {
	docker    string
	manifests *manifestStore
}

type retention struct {
	manifests *manifestStore
}

func unsupported() error {
	return failure(codeVerification, "this primitive requires Linux")
}

func (*aclRead) Run(context.Context, executor.StepContext) error        { return unsupported() }
func (*assertReadable) Run(context.Context, executor.StepContext) error { return unsupported() }
func (*writePipeline) Run(context.Context, executor.StepContext) error  { return unsupported() }
func (*retention) Run(context.Context, executor.StepContext) error      { return unsupported() }

func (s *serviceControl) cgroupEmpty(group string) error {
	if group == "" {
		return nil
	}
	return errors.New("the cgroup check requires Linux")
}
