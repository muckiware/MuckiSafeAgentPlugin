package version

import (
	"runtime"
	"strings"
	"testing"
)

func TestDefaultVersionSatisfiesTheHeartbeatContract(t *testing.T) {
	if !Valid(Version) {
		t.Fatalf("default version %q violates the agentVersion pattern", Version)
	}
	for _, v := range []string{"1.2.3", "0.1.0-rc.1", "10.0.0-dev"} {
		if !Valid(v) {
			t.Errorf("%q must be valid", v)
		}
	}
	for _, v := range []string{"", "v1.2.3", "1.2", "1.2.3 ", "1.2.3+build", strings.Repeat("1", 65) + ".0.0"} {
		if Valid(v) {
			t.Errorf("%q must be invalid", v)
		}
	}
}

func TestStringNamesVersionCommitAndPlatform(t *testing.T) {
	s := String()
	for _, part := range []string{"mucki-agent", Version, Commit, runtime.GOOS + "/" + runtime.GOARCH} {
		if !strings.Contains(s, part) {
			t.Errorf("%q lacks %q", s, part)
		}
	}
}
