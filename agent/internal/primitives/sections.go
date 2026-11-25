package primitives

import (
	"errors"
	"fmt"
	"net/netip"
	"net/url"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"
	"unicode/utf8"

	"gopkg.in/yaml.v3"

	"github.com/muckiware/MuckiSafeAgentPlugin/agent/internal/executor"
	"github.com/muckiware/MuckiSafeAgentPlugin/agent/internal/policy"
)

const (
	sectionServices     = "services"
	sectionEndpoints    = "endpoints"
	sectionContainers   = "containers"
	sectionCommands     = "commands"
	sectionPaths        = "paths"
	sectionPrincipals   = "principals"
	sectionChecks       = "checks"
	sectionDestinations = "destinations"

	maxTimeoutSeconds = 1800
	maxID             = 1<<31 - 2
)

var (
	unitPattern      = regexp.MustCompile(`^[A-Za-z0-9][A-Za-z0-9:_.@-]{0,246}\.service$`)
	containerPattern = regexp.MustCompile(`^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$`)
	aliasPattern     = regexp.MustCompile(`^[a-z][a-z0-9]*(-[a-z0-9]+)*$`)
	fileNamePattern  = regexp.MustCompile(`^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$`)
	suffixPattern    = regexp.MustCompile(`^[A-Za-z0-9._-]{1,64}$`)
	hexPattern       = regexp.MustCompile(`^[0-9a-f]+$`)
	uuidPattern      = regexp.MustCompile(`^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$`)
)

// serviceEntry binds a service alias to one exact systemd unit.
type serviceEntry struct {
	Unit                        string `yaml:"unit"`
	StartTimeoutSeconds         int    `yaml:"startTimeoutSeconds"`
	StopTimeoutSeconds          int    `yaml:"stopTimeoutSeconds"`
	RequireEmptyCgroupAfterStop bool   `yaml:"requireEmptyCgroupAfterStop"`
}

func decodeService(_ string, node *yaml.Node) (any, error) {
	var entry serviceEntry
	if err := policy.DecodeStrict(node, &entry); err != nil {
		return nil, err
	}
	if !unitPattern.MatchString(entry.Unit) {
		return nil, errors.New("unit must be an exact systemd .service name")
	}
	if !inRange(entry.StartTimeoutSeconds, 1, maxTimeoutSeconds) || !inRange(entry.StopTimeoutSeconds, 1, maxTimeoutSeconds) {
		return nil, fmt.Errorf("startTimeoutSeconds and stopTimeoutSeconds must be 1 to %d", maxTimeoutSeconds)
	}
	return entry, nil
}

// endpointEntry binds an endpoint alias to one URL and its response limits.
type endpointEntry struct {
	URL              *url.URL
	ExpectedStatuses map[int]bool
	AllowedRanges    []netip.Prefix
	TimeoutSeconds   int
	IntervalSeconds  int
	MaximumBodyBytes int64
	// Locations maps a status to its exact same-origin Location header;
	// every other response must not carry a Location header.
	Locations map[int]string
	// JSONFields are top-level members a 2xx JSON body must equal.
	JSONFields map[string]any
}

type endpointYAML struct {
	URL              string         `yaml:"url"`
	ExpectedStatuses []int          `yaml:"expectedStatuses"`
	AllowedIPRanges  []string       `yaml:"allowedIpRanges"`
	TimeoutSeconds   int            `yaml:"timeoutSeconds"`
	IntervalSeconds  int            `yaml:"intervalSeconds"`
	MaximumBodyBytes int64          `yaml:"maximumBodyBytes"`
	Locations        map[int]string `yaml:"locations"`
	JSONFields       map[string]any `yaml:"jsonFields"`
}

func decodeEndpoint(_ string, node *yaml.Node) (any, error) {
	var raw endpointYAML
	if err := policy.DecodeStrict(node, &raw); err != nil {
		return nil, err
	}
	target, err := endpointURL(raw.URL)
	if err != nil {
		return nil, err
	}
	entry := endpointEntry{URL: target, ExpectedStatuses: map[int]bool{}, TimeoutSeconds: raw.TimeoutSeconds,
		IntervalSeconds: raw.IntervalSeconds, MaximumBodyBytes: raw.MaximumBodyBytes, Locations: raw.Locations, JSONFields: raw.JSONFields}
	if entry.MaximumBodyBytes == 0 {
		entry.MaximumBodyBytes = 65536
	}
	if err := entry.validateLimits(raw.ExpectedStatuses); err != nil {
		return nil, err
	}
	if len(raw.AllowedIPRanges) == 0 {
		return nil, errors.New("allowedIpRanges must not be empty")
	}
	for _, text := range raw.AllowedIPRanges {
		prefix, err := netip.ParsePrefix(text)
		if err != nil || prefix != prefix.Masked() {
			return nil, fmt.Errorf("allowedIpRanges: %q is not a canonical CIDR range", text)
		}
		entry.AllowedRanges = append(entry.AllowedRanges, prefix)
	}
	return entry, nil
}

func endpointURL(text string) (*url.URL, error) {
	target, err := url.Parse(text)
	switch {
	case err != nil:
		return nil, fmt.Errorf("url: %w", err)
	case target.Scheme != "http" && target.Scheme != "https":
		return nil, errors.New("url must use http or https")
	case target.User != nil || target.Fragment != "" || strings.Contains(text, "#"):
		return nil, errors.New("url must not contain userinfo or a fragment")
	case target.Hostname() == "" || target.Opaque != "":
		return nil, errors.New("url must name a host")
	}
	return target, nil
}

func (e *endpointEntry) validateLimits(statuses []int) error {
	if len(statuses) < 1 || len(statuses) > 16 {
		return errors.New("expectedStatuses must contain 1 to 16 statuses")
	}
	for _, status := range statuses {
		if status < 100 || status > 599 || e.ExpectedStatuses[status] {
			return errors.New("expectedStatuses must contain unique statuses from 100 to 599")
		}
		e.ExpectedStatuses[status] = true
	}
	switch {
	case !inRange(e.TimeoutSeconds, 1, maxTimeoutSeconds):
		return fmt.Errorf("timeoutSeconds must be 1 to %d", maxTimeoutSeconds)
	case !inRange(e.IntervalSeconds, 1, 60) || e.IntervalSeconds > e.TimeoutSeconds:
		return errors.New("intervalSeconds must be 1 to 60 and not exceed timeoutSeconds")
	case e.MaximumBodyBytes < 1 || e.MaximumBodyBytes > 1<<20:
		return errors.New("maximumBodyBytes must be 1 to 1048576")
	}
	for status, location := range e.Locations {
		if !e.ExpectedStatuses[status] || !strings.HasPrefix(location, "/") || strings.HasPrefix(location, "//") ||
			strings.ContainsAny(location, "\\\r\n") || len(location) > 2048 {
			return fmt.Errorf("locations: %d must be an expected status with a same-origin absolute path", status)
		}
	}
	for name, value := range e.JSONFields {
		switch value.(type) {
		case string, int, bool:
		default:
			return fmt.Errorf("jsonFields: %q must be a string, integer or boolean", name)
		}
	}
	return nil
}

// containerEntry binds a container alias to one exact container name.
type containerEntry struct {
	Name string `yaml:"name"`
}

func decodeContainer(_ string, node *yaml.Node) (any, error) {
	var entry containerEntry
	if err := policy.DecodeStrict(node, &entry); err != nil {
		return nil, err
	}
	if !containerPattern.MatchString(entry.Name) || hexPattern.MatchString(entry.Name) {
		return nil, errors.New("name must be an exact container name that docker cannot mistake for an id prefix")
	}
	return entry, nil
}

// commandEntry binds a command alias to a container and its complete argv.
type commandEntry struct {
	ContainerAlias string   `yaml:"containerAlias"`
	Argv           []string `yaml:"argv"`
	TimeoutSeconds int      `yaml:"timeoutSeconds"`
}

func decodeCommand(_ string, node *yaml.Node) (any, error) {
	var entry commandEntry
	if err := policy.DecodeStrict(node, &entry); err != nil {
		return nil, err
	}
	if !aliasPattern.MatchString(entry.ContainerAlias) || len(entry.ContainerAlias) > 64 {
		return nil, errors.New("containerAlias must be a policy alias")
	}
	if len(entry.Argv) < 1 || len(entry.Argv) > 64 || entry.Argv[0] == "" || strings.HasPrefix(entry.Argv[0], "-") {
		return nil, errors.New("argv must contain 1 to 64 items and start with a program")
	}
	if executor.ForbiddenProgram(entry.Argv[0]) {
		return nil, fmt.Errorf("argv must not start a shell or interpreter (%s)", entry.Argv[0])
	}
	for _, argument := range entry.Argv {
		if len(argument) > 4096 || !utf8.ValidString(argument) || strings.ContainsRune(argument, 0) {
			return nil, errors.New("argv items must be valid UTF-8 without NUL and at most 4096 bytes")
		}
	}
	if !inRange(entry.TimeoutSeconds, 1, maxTimeoutSeconds) {
		return nil, fmt.Errorf("timeoutSeconds must be 1 to %d", maxTimeoutSeconds)
	}
	return entry, nil
}

// pathEntry binds a path alias to an absolute root and its walk limits.
type pathEntry struct {
	Root       string `yaml:"root"`
	Recursive  bool   `yaml:"recursive"`
	MaxDepth   int    `yaml:"maxDepth"`
	MaxEntries int    `yaml:"maxEntries"`
}

func decodePath(_ string, node *yaml.Node) (any, error) {
	entry := pathEntry{MaxDepth: 32, MaxEntries: 100000}
	if err := policy.DecodeStrict(node, &entry); err != nil {
		return nil, err
	}
	if entry.Root == "/" || !filepath.IsAbs(entry.Root) || filepath.Clean(entry.Root) != entry.Root || !utf8.ValidString(entry.Root) {
		return nil, errors.New("root must be a clean absolute path other than /")
	}
	if !inRange(entry.MaxDepth, 1, 256) || !inRange(entry.MaxEntries, 1, 10000000) {
		return nil, errors.New("maxDepth must be 1 to 256 and maxEntries 1 to 10000000")
	}
	return entry, nil
}

// principalEntry is the unprivileged backup identity.
type principalEntry struct {
	UID int `yaml:"uid"`
	GID int `yaml:"gid"`
}

func decodePrincipal(_ string, node *yaml.Node) (any, error) {
	entry := principalEntry{UID: -1, GID: -1}
	if err := policy.DecodeStrict(node, &entry); err != nil {
		return nil, err
	}
	if !inRange(entry.UID, 1, maxID) || !inRange(entry.GID, 1, maxID) {
		return nil, errors.New("uid and gid must be unprivileged ids")
	}
	return entry, nil
}

// checkEntry is one bounded readability verification.
type checkEntry struct {
	PathAlias        string     `yaml:"pathAlias"`
	DestinationAlias string     `yaml:"destinationAlias"`
	ReadableBy       string     `yaml:"readableBy"`
	Recursive        bool       `yaml:"recursive"`
	RelativePaths    []string   `yaml:"relativePaths"`
	Match            *matchRule `yaml:"match"`
	// MinimumSize applies to the current-run output of a destination.
	MinimumSize int64 `yaml:"minimumSize"`
}

// matchRule requires regular files with a fixed suffix in one directory.
type matchRule struct {
	Directory           string `yaml:"directory"`
	Suffix              string `yaml:"suffix"`
	MinimumCount        int    `yaml:"minimumCount"`
	CreatedByCurrentRun bool   `yaml:"createdByCurrentRun"`
	MinimumSize         int64  `yaml:"minimumSize"`
}

func decodeCheck(_ string, node *yaml.Node) (any, error) {
	var entry checkEntry
	if err := policy.DecodeStrict(node, &entry); err != nil {
		return nil, err
	}
	if !isAlias(entry.ReadableBy) || isAlias(entry.PathAlias) == isAlias(entry.DestinationAlias) {
		return nil, errors.New("readableBy and exactly one of pathAlias and destinationAlias must be policy aliases")
	}
	if entry.MinimumSize < 0 {
		return nil, errors.New("minimumSize must not be negative")
	}
	if entry.DestinationAlias != "" {
		if entry.Recursive || entry.RelativePaths != nil || entry.Match != nil {
			return nil, errors.New("a destination check verifies only the current-run output")
		}
		return entry, nil
	}
	if entry.MinimumSize != 0 || (!entry.Recursive && len(entry.RelativePaths) == 0 && entry.Match == nil) {
		return nil, errors.New("a path check needs recursive, relativePaths or match")
	}
	if len(entry.RelativePaths) > 64 {
		return nil, errors.New("relativePaths must contain at most 64 paths")
	}
	for _, path := range entry.RelativePaths {
		if !validRelative(path) {
			return nil, fmt.Errorf("relativePaths: %q is not a safe relative path", path)
		}
	}
	if entry.Match != nil {
		return entry, entry.Match.validate()
	}
	return entry, nil
}

func (m *matchRule) validate() error {
	if m.MinimumCount == 0 {
		m.MinimumCount = 1
	}
	switch {
	case !validRelative(m.Directory):
		return errors.New("match.directory must be a safe relative path")
	case !suffixPattern.MatchString(m.Suffix):
		return errors.New("match.suffix must be a fixed file name suffix")
	case !inRange(m.MinimumCount, 1, 10000):
		return errors.New("match.minimumCount must be 1 to 10000")
	case m.MinimumSize < 0:
		return errors.New("match.minimumSize must not be negative")
	}
	return nil
}

// destinationEntry is where file.write-pipeline publishes and what
// file.retention may prune.
type destinationEntry struct {
	PathAlias    string `yaml:"pathAlias"`
	Directory    string `yaml:"directory"`
	Prefix       string `yaml:"prefix"`
	Suffix       string `yaml:"suffix"`
	Owner        int    `yaml:"owner"`
	Group        int    `yaml:"group"`
	Mode         string `yaml:"mode"`
	MaximumBytes int64  `yaml:"maximumBytes"`
	MaximumKeep  int    `yaml:"maximumKeep"`

	mode uint32
}

func decodeDestination(_ string, node *yaml.Node) (any, error) {
	entry := destinationEntry{Owner: -1, Group: -1}
	if err := policy.DecodeStrict(node, &entry); err != nil {
		return nil, err
	}
	mode, err := strconv.ParseUint(entry.Mode, 8, 32)
	switch {
	case !isAlias(entry.PathAlias) || !validRelative(entry.Directory):
		return nil, errors.New("pathAlias must be a policy alias and directory a safe relative path")
	case !fileNamePattern.MatchString(entry.Prefix) || !suffixPattern.MatchString(entry.Suffix) || !strings.HasSuffix(entry.Suffix, ".gz"):
		return nil, errors.New("prefix must be a file name and suffix a fixed name suffix ending in .gz")
	case !inRange(entry.Owner, 0, maxID) || !inRange(entry.Group, 0, maxID):
		return nil, errors.New("owner and group must be numeric ids")
	case err != nil || len(entry.Mode) != 4 || mode&^0o644 != 0:
		return nil, errors.New("mode must be a four-digit octal mode within 0644")
	case entry.MaximumBytes < 1 || entry.MaximumBytes > 1<<40:
		return nil, errors.New("maximumBytes must be 1 to 1099511627776")
	case !inRange(entry.MaximumKeep, 1, 10000):
		return nil, errors.New("maximumKeep must be 1 to 10000")
	}
	entry.mode = uint32(mode)
	return entry, nil
}

// fileName is the published name of the run's output.
func (d destinationEntry) fileName(runID string) string {
	return d.Prefix + runID + d.Suffix
}

func inRange(value, low, high int) bool { return value >= low && value <= high }

func isAlias(value string) bool { return len(value) <= 64 && aliasPattern.MatchString(value) }

// validRelative mirrors the action relativePath rule: no absolute path,
// backslash, control character, empty, "." or ".." segment; "." alone is
// the root itself.
func validRelative(path string) bool {
	if path == "." {
		return true
	}
	if path == "" || len(path) > 4096 || !utf8.ValidString(path) || strings.HasPrefix(path, "/") || strings.Contains(path, "\\") {
		return false
	}
	for _, character := range path {
		if character < 0x20 || character == 0x7f {
			return false
		}
	}
	for _, segment := range strings.Split(path, "/") {
		if segment == "" || segment == "." || segment == ".." {
			return false
		}
	}
	return true
}
