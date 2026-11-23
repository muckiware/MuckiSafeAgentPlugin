# Action DSL V1

Task 10B freezes the action envelope and the seven primitive shapes in
`resources/schema/action/v1/action.schema.json`. The executable validators are
`src/Service/Action/` and `agent/internal/action/`; shared acceptance, canonical
JSON and SHA-256 vectors are in `tests/Fixtures/action-dsl-v1.json`.

## Envelope and canonical bytes

An action is exactly `{version,steps}` with `version` equal to integer `1` and
1–64 ordered steps. Object keys are recursively sorted by their UTF-8 bytes;
array order is preserved. Output is compact UTF-8 JSON with Unicode and `/`
unescaped. Floats, invalid UTF-8, integers outside signed 64-bit, unknown fields
and nesting beyond 32 levels are rejected. The lowercase action hash is
`sha256(definitionCanonical)`.

The agent hashes the received canonical string before parsing, validates it, and
requires re-canonicalization to produce the exact received bytes. This also
rejects whitespace variants and duplicate-key encodings on the transport path.

Draft 2020-12 provides the portable structural layer. Its numeric data model
intentionally treats `10` and `10.0` as the same integer and has no adjacent-item
comparison for ascending arrays. The schema therefore marks these mandatory
semantic checks with `x-muwa-integerToken` and `x-muwa-strictAscending`; the
strict PHP and Go decoders/validators are the normative enforcement layer.

## Policy boundary

Action fields contain canonical policy aliases plus closed enums, bounded HTTP
statuses/retention count, one lexical relative subpath slot, and fixed V1
structural values. The action cannot select an absolute host path, URL, systemd
unit, process, raw container name, executable, UID/GID, credential, glob, shell
argument or deletion target.

In particular:

- `filesystem.assert-readable.checkAlias` resolves locally to one bounded root,
  principal and verification rule. Multiple roots require multiple steps.
- `service.control(operation=stop)` resolves its optional
  `requireEmptyCgroupAfterStop` rule from root-owned local policy. The action
  cannot disable or enable that postcondition.
- `service.wait-http.expectedStatuses` is sorted, unique and must remain a
  subset of the endpoint policy. The alias owns URL, pinned address ranges,
  redirect policy, accepted header/body conditions and response limits.
- `file.write-pipeline` and `file.retention` share a policy-bound manifest alias.
  Retention can only remove identity-matching manifest entries; it never accepts
  a caller path, glob or deletion target.
- `docker.container.exec` resolves both container and complete argv locally.
  Its required V1 `arguments` object is empty; typed arguments require a later
  schema revision.

## Parameters

None of the four Task-10A reference services needs caller-controlled values.
V1 therefore accepts only an empty parameter object. Any future runtime value
requires a typed parameter-schema revision instead of weakening V1.

## Covered reference actions

The shared fixtures contain valid pre/post/finally definitions for Bitwarden,
Jellyfin, Immich and Paperless. Negative cases cover unknown versions and
fields, generic shell execution, raw paths, container/executable smuggling,
numeric principals, caller globs, caller HTTP statuses, caller cgroup policy,
unmanifested retention selectors, floats and free transform text.
