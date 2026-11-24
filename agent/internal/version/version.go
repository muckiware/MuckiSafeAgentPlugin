// Package version holds the build identity of the agent binary. Release
// builds set both variables via
// -ldflags "-X .../internal/version.Version=1.2.3 -X .../internal/version.Commit=<sha>".
package version

import (
	"regexp"
	"runtime"
)

var (
	// Version is the semantic version reported as agentVersion.
	Version = "0.0.0-dev"
	// Commit is the source revision of the build.
	Commit = "unknown"
)

// pattern mirrors agentVersion of heartbeat-request.schema.json (maxLength 64).
var pattern = regexp.MustCompile(`^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.-]+)?$`)

// Valid reports whether v is acceptable as agentVersion on the wire.
func Valid(v string) bool {
	return len(v) <= 64 && pattern.MatchString(v)
}

// String is the human-readable build identity printed by --version.
func String() string {
	return "mucki-agent " + Version + " (" + Commit + ") " + runtime.GOOS + "/" + runtime.GOARCH
}
