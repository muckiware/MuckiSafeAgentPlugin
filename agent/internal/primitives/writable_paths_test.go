package primitives

import (
	"strings"
	"testing"
)

// filesystem.acl.read writes ACLs below every path alias root, the write
// pipeline and retention write to the destinations; nested paths collapse
// into their outermost root.
func TestWritablePathsListsPathRootsAndDestinationsOnce(t *testing.T) {
	p, err := loadPolicy(t, `version: 1
paths:
  media:
    root: /srv/media
  library:
    root: /opt/immich/library
  thumbs:
    root: /opt/immich/library/thumbs
  sibling:
    root: /opt/immich/library-old
  backups:
    root: /var/backups/immich
destinations:
  second:
    pathAlias: library
    directory: backups
    prefix: b-
    suffix: .sql.gz
    owner: 33
    group: 33
    mode: "0640"
    maximumBytes: 100
    maximumKeep: 1
  first:
    pathAlias: library
    directory: backups
    prefix: a-
    suffix: .sql.gz
    owner: 33
    group: 33
    mode: "0640"
    maximumBytes: 100
    maximumKeep: 1
  root:
    pathAlias: media
    directory: .
    prefix: m-
    suffix: .tar.gz
    owner: 0
    group: 0
    mode: "0600"
    maximumBytes: 100
    maximumKeep: 1
`)
	if err != nil {
		t.Fatal(err)
	}

	paths, err := WritablePaths(p)

	if err != nil || strings.Join(paths, ",") != "/opt/immich/library,/opt/immich/library-old,/srv/media,/var/backups/immich" {
		t.Fatalf("paths %v, err %v", paths, err)
	}
}

func TestWritablePathsOfAPolicyWithoutPathsIsEmpty(t *testing.T) {
	p, err := loadPolicy(t, "version: 1\n")
	if err != nil {
		t.Fatal(err)
	}
	if paths, err := WritablePaths(p); err != nil || len(paths) != 0 {
		t.Fatalf("paths %v, err %v", paths, err)
	}
}

func TestWritablePathsRejectsADestinationWithoutItsPath(t *testing.T) {
	p, err := loadPolicy(t, `version: 1
destinations:
  orphan:
    pathAlias: missing
    directory: backups
    prefix: o-
    suffix: .sql.gz
    owner: 0
    group: 0
    mode: "0600"
    maximumBytes: 100
    maximumKeep: 1
`)
	if err != nil {
		t.Fatal(err)
	}
	if _, err := WritablePaths(p); err == nil || !strings.Contains(err.Error(), "orphan") {
		t.Fatalf("err %v", err)
	}
}
