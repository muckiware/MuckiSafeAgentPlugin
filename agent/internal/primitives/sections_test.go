package primitives

import (
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/muckiware/MuckiSafeAgentPlugin/agent/internal/policy"
)

// referencePolicy expresses the aliases of the four Task-10A reference
// services.
const referencePolicy = `version: 1
services:
  jellyfin:
    unit: jellyfin.service
    stopTimeoutSeconds: 30
    startTimeoutSeconds: 120
    requireEmptyCgroupAfterStop: true
endpoints:
  jellyfin-public-info:
    url: http://127.0.0.1:8096/System/Info/Public
    expectedStatuses: [200]
    allowedIpRanges: [127.0.0.0/8]
    timeoutSeconds: 120
    intervalSeconds: 1
  immich-version:
    url: http://127.0.0.1:2283/api/server/version
    expectedStatuses: [200]
    allowedIpRanges: [127.0.0.0/8]
    timeoutSeconds: 60
    intervalSeconds: 2
    maximumBodyBytes: 65536
    jsonFields: {major: 3, minor: 3, patch: 5}
  bitwarden-config:
    url: https://bitwarden.example.org/api/config
    expectedStatuses: [200]
    allowedIpRanges: [93.201.10.105/32]
    timeoutSeconds: 30
    intervalSeconds: 2
    jsonFields: {version: "2026.10.1"}
  paperless-web:
    url: http://127.0.0.1:8091/
    expectedStatuses: [200, 302]
    allowedIpRanges: [127.0.0.0/8]
    timeoutSeconds: 120
    intervalSeconds: 2
    locations:
      302: /accounts/login/?next=/
containers:
  bitwarden-mssql:
    name: bitwarden-mssql
  immich-postgres:
    name: immich_postgres
commands:
  bitwarden-full-backup:
    containerAlias: bitwarden-mssql
    argv: [/backup-db.sh]
    timeoutSeconds: 600
  immich-pg-dump:
    containerAlias: immich-postgres
    argv: [pg_dump, --clean, --if-exists, --dbname=immich, --username=postgres]
    timeoutSeconds: 1200
paths:
  bitwarden-data:
    root: /opt/bitwarden
    recursive: true
  jellyfin-data:
    root: /var/lib/jellyfin
    recursive: true
    maxEntries: 500000
  immich-upload:
    root: /srv/immich/upload
    recursive: true
    maxDepth: 64
principals:
  muckisafe-backup:
    uid: 33
    gid: 33
checks:
  bitwarden-current-full-backup:
    pathAlias: bitwarden-data
    readableBy: muckisafe-backup
    match:
      directory: bwdata/mssql/backups
      suffix: .BAK
      createdByCurrentRun: true
      minimumSize: 1
  bitwarden-required-files:
    pathAlias: bitwarden-data
    readableBy: muckisafe-backup
    relativePaths: [bwdata/env/global.override.env, bwdata/identity/identity.pfx]
    match: {directory: bwdata/core/aspnet-dataprotection, suffix: .xml, minimumCount: 1}
  jellyfin-required-data:
    pathAlias: jellyfin-data
    readableBy: muckisafe-backup
    relativePaths: [., data, data/jellyfin.db]
  immich-upload-required-readable:
    pathAlias: immich-upload
    readableBy: muckisafe-backup
    recursive: true
  immich-current-run-output:
    destinationAlias: immich-manual-backups
    readableBy: muckisafe-backup
    minimumSize: 1
destinations:
  immich-manual-backups:
    pathAlias: immich-upload
    directory: backups
    prefix: immich-db-dump-manual-
    suffix: .sql.gz
    owner: 33
    group: 33
    mode: "0640"
    maximumBytes: 21474836480
    maximumKeep: 10
`

func loadPolicy(t *testing.T, body string) (*policy.Policy, error) {
	t.Helper()
	path := filepath.Join(tempDir(t), "policy.yaml")
	if err := os.WriteFile(path, []byte(body), 0o644); err != nil {
		t.Fatal(err)
	}
	return policy.Load(path, PolicyDecoders())
}

func TestReferencePolicyLoads(t *testing.T) {
	p, err := loadPolicy(t, referencePolicy)
	if err != nil {
		t.Fatal(err)
	}
	entry, _ := p.Entry(sectionDestinations, "immich-manual-backups")
	if d := entry.(destinationEntry); d.mode != 0o640 || d.fileName(testJobID) != "immich-db-dump-manual-"+testJobID+".sql.gz" {
		t.Fatalf("destination %+v", d)
	}
	entry, _ = p.Entry(sectionChecks, "bitwarden-required-files")
	if c := entry.(checkEntry); c.Match.MinimumCount != 1 || len(c.RelativePaths) != 2 {
		t.Fatalf("check %+v", c)
	}
}

// TestUnsafePolicyEntriesAreRejected replaces one line of the reference
// policy per case.
func TestUnsafePolicyEntriesAreRejected(t *testing.T) {
	cases := map[string][2]string{
		"unit without .service":      {"unit: jellyfin.service", "unit: jellyfin"},
		"unit option injection":      {"unit: jellyfin.service", "unit: --global.service"},
		"unit path":                  {"unit: jellyfin.service", "unit: ../jellyfin.service"},
		"zero stop timeout":          {"stopTimeoutSeconds: 30", "stopTimeoutSeconds: 0"},
		"excessive timeout":          {"startTimeoutSeconds: 120", "startTimeoutSeconds: 1801"},
		"unknown service key":        {"requireEmptyCgroupAfterStop: true", "requireEmptyCgroupAfterStop: true\n    execStart: /bin/sh"},
		"url with userinfo":          {"url: http://127.0.0.1:8091/", "url: http://admin:secret@127.0.0.1:8091/"},
		"url with fragment":          {"url: http://127.0.0.1:8091/", "url: http://127.0.0.1:8091/#x"},
		"url scheme":                 {"url: http://127.0.0.1:8091/", "url: file:///etc/passwd"},
		"no ip ranges":               {"allowedIpRanges: [93.201.10.105/32]", "allowedIpRanges: []"},
		"non canonical range":        {"allowedIpRanges: [93.201.10.105/32]", "allowedIpRanges: [93.201.10.105/24]"},
		"status out of range":        {"expectedStatuses: [200, 302]", "expectedStatuses: [200, 700]"},
		"duplicate status":           {"expectedStatuses: [200, 302]", "expectedStatuses: [302, 302]"},
		"interval above timeout":     {"    timeoutSeconds: 30\n    intervalSeconds: 2", "    timeoutSeconds: 1\n    intervalSeconds: 2"},
		"cross-origin location":      {"302: /accounts/login/?next=/", "302: https://evil.example/"},
		"protocol-relative location": {"302: /accounts/login/?next=/", "302: //evil.example/"},
		"location of other status":   {"302: /accounts/login/?next=/", "301: /accounts/login/"},
		"float json field":           {"jsonFields: {major: 3, minor: 3, patch: 5}", "jsonFields: {major: 3.3}"},
		"container option":           {"name: immich_postgres", "name: --privileged"},
		"container id prefix":        {"name: immich_postgres", "name: db"},
		"container with space":       {"name: immich_postgres", "name: immich postgres"},
		"shell entry point":          {"argv: [/backup-db.sh]", "argv: [/bin/sh, -c, /backup-db.sh]"},
		"bash entry point":           {"argv: [/backup-db.sh]", "argv: [bash, /backup-db.sh]"},
		"option as program":          {"argv: [/backup-db.sh]", "argv: [--privileged, /backup-db.sh]"},
		"empty argv":                 {"argv: [/backup-db.sh]", "argv: []"},
		"command container alias":    {"containerAlias: bitwarden-mssql", "containerAlias: Bitwarden"},
		"relative root":              {"root: /opt/bitwarden", "root: opt/bitwarden"},
		"unclean root":               {"root: /opt/bitwarden", "root: /opt/../etc"},
		"filesystem root":            {"root: /opt/bitwarden", "root: /"},
		"zero max entries":           {"maxEntries: 500000", "maxEntries: 0"},
		"root principal":             {"uid: 33", "uid: 0"},
		"negative principal":         {"gid: 33", "gid: -1"},
		"traversal relative path":    {"relativePaths: [., data, data/jellyfin.db]", "relativePaths: [../etc/shadow]"},
		"absolute relative path":     {"relativePaths: [., data, data/jellyfin.db]", "relativePaths: [/etc/shadow]"},
		"glob suffix":                {"suffix: .BAK", "suffix: '*'"},
		"traversal match directory":  {"directory: bwdata/mssql/backups", "directory: ../backups"},
		"empty path check":           {"recursive: true\n  immich-current-run-output:", "recursive: false\n  immich-current-run-output:"},
		"path and destination":       {"destinationAlias: immich-manual-backups", "destinationAlias: immich-manual-backups\n    pathAlias: immich-upload"},
		"destination with rule":      {"    minimumSize: 1\ndestinations:", "    minimumSize: 1\n    recursive: true\ndestinations:"},
		"executable output":          {`mode: "0640"`, `mode: "0755"`},
		"world-writable output":      {`mode: "0640"`, `mode: "0666"`},
		"uncompressed suffix":        {"suffix: .sql.gz", "suffix: .sql"},
		"prefix with slash":          {"prefix: immich-db-dump-manual-", "prefix: ../immich-"},
		"keep above limit":           {"maximumKeep: 10", "maximumKeep: 10001"},
		"traversal destination":      {"directory: backups", "directory: ../../etc"},
	}
	for name, change := range cases {
		t.Run(name, func(t *testing.T) {
			if !strings.Contains(referencePolicy, change[0]) {
				t.Fatalf("reference policy lacks %q", change[0])
			}
			if _, err := loadPolicy(t, strings.Replace(referencePolicy, change[0], change[1], 1)); err == nil {
				t.Fatal("expected rejection")
			}
		})
	}
}
