// Package policy loads the local alias policy: the only source of host
// paths, systemd units, container names, command argv and URLs.
//
// The file is read exclusively from a securefs-verified descriptor (root
// owned, no group/other write, symlink-free path) and decoded strictly into
// an immutable snapshot. The envelope is `version: 1` plus alias sections;
// every section needs a registered decoder (primitives register theirs), so
// unknown sections, unknown entry keys and invalid alias names fail closed.
// A reload repeats the whole procedure and replaces the snapshot only on
// success; running jobs keep the snapshot they started with.
package policy

import (
	"bytes"
	"errors"
	"fmt"
	"io"
	"regexp"
	"slices"
	"sync/atomic"

	"gopkg.in/yaml.v3"

	"github.com/muckiware/MuckiSafeAgentPlugin/agent/internal/securefs"
)

const (
	version       = 1
	maxPolicySize = 1 << 20
	// maxPermissions: root may write, everybody may read; nothing more.
	maxPermissions = 0o644
)

// aliasPattern mirrors the action schema (implementation plan 2.7).
var aliasPattern = regexp.MustCompile(`^[a-z][a-z0-9]*(-[a-z0-9]+)*$`)

// SectionDecoder validates one alias entry of a section and returns its
// immutable value (a value type, never a pointer into shared state).
type SectionDecoder func(alias string, node *yaml.Node) (any, error)

// Decoders maps section names to their entry decoders.
type Decoders map[string]SectionDecoder

// Policy is an immutable snapshot. Callers must treat returned entries as
// read-only.
type Policy struct {
	sections map[string]map[string]any
}

// Entry resolves an alias of a section.
func (p *Policy) Entry(section, alias string) (any, bool) {
	entry, ok := p.sections[section][alias]
	return entry, ok
}

// Aliases lists the aliases of a section in sorted order.
func (p *Policy) Aliases(section string) []string {
	aliases := make([]string, 0, len(p.sections[section]))
	for alias := range p.sections[section] {
		aliases = append(aliases, alias)
	}
	slices.Sort(aliases)
	return aliases
}

// Load reads and validates the policy file.
func Load(path string, decoders Decoders) (*Policy, error) {
	raw, err := securefs.ReadFile(path, maxPermissions, maxPolicySize)
	if err != nil {
		return nil, fmt.Errorf("policy: %w", err)
	}
	policy, err := parse(raw, decoders)
	if err != nil {
		return nil, fmt.Errorf("policy %s: %w", path, err)
	}
	return policy, nil
}

func parse(raw []byte, decoders Decoders) (*Policy, error) {
	decoder := yaml.NewDecoder(bytes.NewReader(raw))
	var document yaml.Node
	if err := decoder.Decode(&document); err != nil {
		return nil, err
	}
	var extra yaml.Node
	if err := decoder.Decode(&extra); !errors.Is(err, io.EOF) {
		return nil, errors.New("exactly one YAML document is allowed")
	}
	if document.Kind != yaml.DocumentNode || len(document.Content) != 1 || document.Content[0].Kind != yaml.MappingNode {
		return nil, errors.New("policy must be a mapping")
	}
	root := document.Content[0]
	policy := &Policy{sections: map[string]map[string]any{}}
	seenVersion := false
	for i := 0; i < len(root.Content); i += 2 {
		key, value := root.Content[i], root.Content[i+1]
		if key.Kind != yaml.ScalarNode {
			return nil, errors.New("section names must be scalars")
		}
		if key.Value == "version" {
			if value.Kind != yaml.ScalarNode || value.Tag != "!!int" || value.Value != "1" {
				return nil, fmt.Errorf("version must be the integer %d", version)
			}
			seenVersion = true
			continue
		}
		if err := policy.addSection(key.Value, value, decoders); err != nil {
			return nil, err
		}
	}
	if !seenVersion {
		return nil, errors.New("version is required")
	}
	return policy, nil
}

func (p *Policy) addSection(name string, node *yaml.Node, decoders Decoders) error {
	decode, ok := decoders[name]
	if !ok {
		return fmt.Errorf("unknown section %q", name)
	}
	if _, duplicate := p.sections[name]; duplicate {
		return fmt.Errorf("section %q is defined twice", name)
	}
	if node.Kind != yaml.MappingNode {
		return fmt.Errorf("section %q must map aliases to entries", name)
	}
	entries := map[string]any{}
	for i := 0; i < len(node.Content); i += 2 {
		alias := node.Content[i].Value
		if node.Content[i].Kind != yaml.ScalarNode || len(alias) > 64 || !aliasPattern.MatchString(alias) {
			return fmt.Errorf("section %q: invalid alias %q", name, alias)
		}
		if _, duplicate := entries[alias]; duplicate {
			return fmt.Errorf("section %q: alias %q is defined twice", name, alias)
		}
		entry, err := decode(alias, node.Content[i+1])
		if err != nil {
			return fmt.Errorf("section %q, alias %q: %w", name, alias, err)
		}
		entries[alias] = entry
	}
	p.sections[name] = entries
	return nil
}

// DecodeStrict decodes an entry node into out and rejects unknown keys,
// which yaml.Node.Decode alone would silently ignore.
func DecodeStrict(node *yaml.Node, out any) error {
	raw, err := yaml.Marshal(node)
	if err != nil {
		return err
	}
	decoder := yaml.NewDecoder(bytes.NewReader(raw))
	decoder.KnownFields(true)
	return decoder.Decode(out)
}

// Store holds the current snapshot and reloads it atomically.
type Store struct {
	path     string
	decoders Decoders
	current  atomic.Pointer[Policy]
}

// NewStore loads the initial policy; without a valid policy the agent must
// not start.
func NewStore(path string, decoders Decoders) (*Store, error) {
	store := &Store{path: path, decoders: decoders}
	if err := store.Reload(); err != nil {
		return nil, err
	}
	return store, nil
}

// Reload replaces the snapshot only if the complete verified load succeeds.
func (s *Store) Reload() error {
	policy, err := Load(s.path, s.decoders)
	if err != nil {
		return err
	}
	s.current.Store(policy)
	return nil
}

// Current returns the snapshot a job should use for its whole execution.
func (s *Store) Current() *Policy { return s.current.Load() }
