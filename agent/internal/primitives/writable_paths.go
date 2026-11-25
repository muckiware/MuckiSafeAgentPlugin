package primitives

import (
	"fmt"
	"path/filepath"
	"slices"
	"strings"

	"github.com/muckiware/MuckiSafeAgentPlugin/agent/internal/policy"
)

// WritablePaths lists the host paths the primitives modify, outermost first
// and without nested duplicates: filesystem.acl.read writes ACLs below every
// path alias root, file.write-pipeline and file.retention write to the
// destination directories. The systemd unit keeps the whole file system
// read-only except the state directory and exactly these paths.
func WritablePaths(p *policy.Policy) ([]string, error) {
	var paths []string
	for _, alias := range p.Aliases(sectionPaths) {
		entry, _ := p.Entry(sectionPaths, alias)
		paths = append(paths, entry.(pathEntry).Root)
	}
	for _, alias := range p.Aliases(sectionDestinations) {
		entry, _ := p.Entry(sectionDestinations, alias)
		destination := entry.(destinationEntry)
		root, ok := p.Entry(sectionPaths, destination.PathAlias)
		if !ok {
			return nil, fmt.Errorf("destination %q: path alias %q is not defined", alias, destination.PathAlias)
		}
		paths = append(paths, filepath.Join(root.(pathEntry).Root, destination.Directory))
	}
	return outermost(paths), nil
}

// outermost sorts paths and drops every path below another listed path.
func outermost(paths []string) []string {
	slices.Sort(paths)
	var kept []string
	for _, path := range paths {
		if !slices.ContainsFunc(kept, func(outer string) bool { return path == outer || strings.HasPrefix(path, outer+"/") }) {
			kept = append(kept, path)
		}
	}
	return kept
}
