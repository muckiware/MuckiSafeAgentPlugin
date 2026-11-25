// Package primitives implements the seven V1 action primitives
// (implementation plan 2.7). A step only carries policy aliases and closed
// values; every host path, unit, container, argv, URL and principal is
// resolved from the job's immutable policy snapshot. Each primitive registers
// the policy sections it reads, so a policy with unknown sections or entries
// never loads.
//
// File system work below an alias root is descriptor-relative through
// openat2(RESOLVE_BENEATH|RESOLVE_NO_SYMLINKS|RESOLVE_NO_MAGICLINKS); these
// primitives are Linux-only and fail with check_failed elsewhere.
package primitives

import (
	"context"
	"crypto/x509"
	"fmt"
	"net"

	"github.com/muckiware/MuckiSafeAgentPlugin/agent/internal/executor"
	"github.com/muckiware/MuckiSafeAgentPlugin/agent/internal/policy"
)

// Result codes of primitive failures. A symlink, traversal or unresolvable
// alias is a policy rejection like an unknown alias. The codes follow the
// failure injections of the E2E reference contracts.
const (
	codePolicy       = "policy_rejected"
	codeStop         = "service_stop_failed"
	codeStart        = "service_start_failed"
	codeEndpoint     = "endpoint_failed"
	codeCommand      = "command_failed"
	codeVerification = "verification_failed"
	codePipeline     = "pipeline_failed"
	codeRetention    = "retention_rejected"
)

// Options configures the primitives of one agent.
type Options struct {
	// StateDirectory holds the root-protected write-pipeline manifests
	// below `manifests/`.
	StateDirectory string
	// Systemctl and Docker default to the fixed V1 executables.
	Systemctl string
	Docker    string
	// CgroupRoot is the cgroup v2 mount, default /sys/fs/cgroup.
	CgroupRoot string
	// RootCAs verifies HTTPS endpoints; nil uses the system pool.
	RootCAs *x509.CertPool
	// LookupIP resolves endpoint hosts; nil uses the system resolver.
	LookupIP func(ctx context.Context, host string) ([]net.IP, error)
}

// New returns all V1 primitives keyed by their action name.
func New(options Options) map[string]executor.Primitive {
	if options.Systemctl == "" {
		options.Systemctl = "/usr/bin/systemctl"
	}
	if options.Docker == "" {
		options.Docker = "/usr/bin/docker"
	}
	if options.CgroupRoot == "" {
		options.CgroupRoot = "/sys/fs/cgroup"
	}
	if options.LookupIP == nil {
		options.LookupIP = func(ctx context.Context, host string) ([]net.IP, error) {
			return net.DefaultResolver.LookupIP(ctx, "ip", host)
		}
	}
	manifests := &manifestStore{stateDirectory: options.StateDirectory}
	return map[string]executor.Primitive{
		"service.control":            &serviceControl{systemctl: options.Systemctl, cgroupRoot: options.CgroupRoot, requireCgroup2: true},
		"service.wait-http":          &waitHTTP{rootCAs: options.RootCAs, lookupIP: options.LookupIP},
		"docker.container.exec":      &containerExec{docker: options.Docker},
		"filesystem.acl.read":        &aclRead{},
		"filesystem.assert-readable": &assertReadable{manifests: manifests},
		"file.write-pipeline":        &writePipeline{docker: options.Docker, manifests: manifests},
		"file.retention":             &retention{manifests: manifests},
	}
}

// PolicyDecoders returns the decoders of every policy section the V1
// primitives read.
func PolicyDecoders() policy.Decoders {
	return policy.Decoders{
		sectionServices:     decodeService,
		sectionEndpoints:    decodeEndpoint,
		sectionContainers:   decodeContainer,
		sectionCommands:     decodeCommand,
		sectionPaths:        decodePath,
		sectionPrincipals:   decodePrincipal,
		sectionChecks:       decodeCheck,
		sectionDestinations: decodeDestination,
	}
}

func failure(code, format string, args ...any) error {
	return &executor.StepError{Code: code, Message: fmt.Sprintf(format, args...)}
}

// resolve looks up the alias held by field of the step in section.
func resolve[T any](step executor.StepContext, field, section string) (T, string, error) {
	var zero T
	alias, _ := step.Step[field].(string)
	value, ok := step.Policy.Entry(section, alias)
	if !ok {
		return zero, alias, failure(codePolicy, "%s %q is not defined in the local policy section %s", field, alias, section)
	}
	typed, ok := value.(T)
	if !ok {
		return zero, alias, failure(codePolicy, "%s %q has an unexpected policy type", field, alias)
	}
	return typed, alias, nil
}

// lookup resolves an alias that a policy entry (not the step) refers to.
func lookup[T any](step executor.StepContext, section, alias, owner string) (T, error) {
	var zero T
	value, ok := step.Policy.Entry(section, alias)
	typed, isType := value.(T)
	if !ok || !isType {
		return zero, failure(codePolicy, "%s refers to %q, which is not defined in the local policy section %s", owner, alias, section)
	}
	return typed, nil
}
