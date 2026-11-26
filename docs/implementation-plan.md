# MuckiSafe Agent Implementation Plan

> **For Hermes:** Use subagent-driven-development skill to implement this plan task-by-task. Apply strict RED-GREEN-REFACTOR and two-stage review (spec compliance, then code quality) to every production-code task.

**Goal:** Das freigegebene MuckiSafe-Agent-Design in drei Repositories implementieren: ein neutrales `BackupFinishedEvent` im `MuckiFacilityPlugin`, die Control Plane plus Linux-Agent im Monorepo `MuckiSafeAgentPlugin` und anschließend die Distribution im `MuckiSafe`-Image.

**Architecture:** `MuckiFacilityPlugin` bleibt allgemein und erhält nur einen neutralen Lifecycle-Contract. `MuckiSafeAgentPlugin` besitzt alle Agent-Daten, Admin-Funktionen, API-Routen, Actions, Jobs und den Go-Quellcode unter `agent/`. Der als GitHub-Release-Binary ausgelieferte Linux-Agent pollt die signierte HTTP(S)-API; Wakeup-Dateien sind nur Hinweise. Das MuckiSafe-Image installiert ausschließlich das PHP-Plugin und enthält weder Go-Toolchain noch Agent-Binary.

**Tech Stack:** Shopware `~6.6.10.14 || ~6.7.6`, PHP `~8.3 || ~8.4 || ~8.5`, PHPUnit 11.5, Doctrine DBAL, MySQL 8.0/8.4, sodium, JavaScript/Vue-Administration, Go 1.24+ nur in Entwicklung/CI, `fsnotify`, systemd, GitHub Actions, Docker Buildx.

---

## 1. Belegte Ausgangslage

### 1.1 Analysierte Revisionen

| Repository | Revision | Zustand |
|---|---|---|
| `muckiware/MuckiFacilityPlugin` | `1a37677ad6682501c004167420fdbcf90c0cdcbf` | 399 Dateien, sauberer `main`; Plugin `v0.10.1` |
| `muckiware/MuckiSafeAgentPlugin` | `4c81815ca0a4fe521afc9dcf92aa670f87aa993b` | nur `LICENSE` und das bisherige Design unter `docs/implementation-plan.md` |
| `muckiware/MuckiSafe` | `ef29f42aebae9774cf955767af0bc4158f1c2160` | 33 Dateien, sauberer `main`; Docker-/Compose-/Release-Rezept vorhanden |

### 1.2 Facility-Fakten

- Alle aktiven Ausführungspfade konvergieren auf `src/Schedule/BackupExecution.php::execute()` und anschließend `src/Services/Backup.php::createBackup()`.
- `BackupExecution` hält den globalen Lock `mucki-facility.backup` bis `createBackup()` zurückkehrt oder wirft.
- `createBackup()` dispatcht heute `BeforeBackupCreatedEvent`, führt den Kern aus, dispatcht nur im Erfolgspfad `AfterBackupCreatedEvent` und speichert die Dauer in `finally`.
- `src/Subscriber/BackupCommandSubscriber.php` hört mit Standardpriorität `0` auf Before und After.
- `Backup` besitzt den EventDispatcher bereits; `services.xml` benötigt für das neue Event keine Änderung.
- Tests laufen über Shopwares Projektroot mit `./vendor/bin/phpunit --configuration="custom/plugins/MuckiFacilityPlugin"`.
- Facility-CI testet Shopware `v6.6.10.25`/`v6.7.14.1`, PHP 8.4/8.5 und MySQL 8.0/8.4.

### 1.3 MuckiSafe-Fakten

- `docker/Dockerfile` installiert Facility-, Facility-Menu- und Log-Plugin gemeinsam per Composer.
- `docker/entrypoint.sh` installiert/aktualisiert/aktiviert composer-installierte Plugins über `shopware/deployment-helper`.
- `app` und `scheduler` verwenden dasselbe Image, besitzen aber noch keinen Agent-Wakeup-Mount.
- `.github/workflows/docker-publish.yml` pinnt auslösende Plugin-Releases und baut amd64/arm64.
- Das Repository enthält keine Go-Dateien und installiert keine Go-Toolchain.
- Bestehende Referenzkits dokumentieren Bitwarden, Jellyfin und Immich. Paperless ist verpflichtender V1-Fall; sein reales Hostskript, der Aufrufvertrag und der bekannte `${2}`-Fehler müssen vor dem DSL-Freeze als Fixture/Regression erfasst werden.

## 2. Verbindliche technische Konkretisierungen

Diese Punkte schließen die bei der Codeanalyse gefundenen Designlücken.

### 2.1 Facility-Event und Fehlerpriorität

Neuer Contract:

```php
final class BackupFinishedEvent extends Event
{
    public const NAME = 'mucki_facility.backup.finished';

    public function __construct(
        private readonly BackupRepositorySettings $backupSettings,
        private readonly array $results,
        private readonly ?\Throwable $primaryError,
        private readonly bool $coreSucceeded,
    ) {}

    public function getBackupSettings(): BackupRepositorySettings { return $this->backupSettings; }
    /** @return array<ResultEntity> */
    public function getResults(): array { return $this->results; }
    public function getPrimaryError(): ?\Throwable { return $this->primaryError; }
    public function isCoreSucceeded(): bool { return $this->coreSucceeded; }
}
```

Semantik:

1. `Finished` wird genau einmal für jeden begonnenen `Backup::createBackup()`-Aufruf dispatcht.
2. Lock-Kollisionen und Fehler vor `createBackup()` erzeugen kein Finished-Event.
3. `coreSucceeded=true` bedeutet: Before, Backup-Kern und After waren erfolgreich. Ein späterer Finished-Fehler ändert dieses Feld nicht.
4. Fehlerpriorität: bestehender Before-/Kern-/After-Fehler vor Finished-Fehler vor Fehler aus `saveBackupDuration()`.
5. Untergeordnete Fehler werden geloggt; nach außen wird das höchstpriorisierte ursprüngliche Throwable erneut geworfen.
6. Finished läuft innerhalb des globalen Facility-Locks.
7. `allResults`, `checkFailure` und `dumpFailure` werden am Anfang jedes Laufs zurückgesetzt, damit lang laufende Worker keine Ergebnisdaten zwischen Läufen vermischen. Die bestehenden nicht-nullbaren Legacy-Properties `backupException` und `backup` sind nicht Teil des neuen Event-Outcomes und werden aus Rückwärtskompatibilitätsgründen in diesem Schritt weder umtypisiert noch über neue Getter-Verträge laufisoliert.
8. Geplante Facility-Version für diesen Contract: `v0.11.0`. Erst deren tatsächliche Veröffentlichung erlaubt den finalen Composer-Constraint im AgentPlugin.

### 2.2 Host-Zuordnung

- Jede Repository-Hook-Zuordnung enthält genau einen `agent_host_id`.
- Jeder Job materialisiert `target_agent_id`.
- Ein Agent darf nur Jobs claimen, deren `target_agent_id` seiner authentisierten Host-ID entspricht.
- Capabilities sind zusätzliche Validierung, keine Autorisierung und kein Ersatz für die Host-Zuordnung.

### 2.3 Zustandsautomaten

BackupRun:

```text
running -> succeeded | failed | unknown
```

Job:

```text
queued -> claimed -> running -> succeeded | failed | timed_out
queued -> timed_out
claimed (noch nie running) -> expired
expired + idempotency=safe + attempts left + phase budget left -> queued
expired + idempotency=non_retryable -> unknown
running -> unknown (ausschließlich authentisierte `ambiguous`-Completion)
running -> cancel_requested -> timed_out | unknown
claimed (noch nie running) -> timed_out
```

- `partial_failure` ist **kein Jobstatus**, sondern ein `result_code` bei terminalem Status `failed`.
- Der Agent sendet bei Completion ausschließlich das Outcome `succeeded`, `failed`, `timed_out` oder `ambiguous`. Der Server mappt `ambiguous` auf Status `unknown`; nur der Server schreibt Statuswerte in die Datenbank.
- `expired` wird ausschließlich vom serverseitigen Reaper gesetzt. `unknown` entsteht entweder aus einem abgelaufenen non-retryable Job oder aus einer vom Agenten gemeldeten ambivalenten Wiederanlaufsituation.
- Ein Job, der den Status `running` jemals erreicht hat, wird wegen Lease-Ablauf **nie** neu eingereiht – auch nicht bei `idempotency=safe`. Damit können alter und neuer Worker keine überlappenden Hostwirkungen erzeugen. Retry nach Lease-Ablauf ist nur für `claimed`, aber noch nie gestartete Jobs erlaubt.
- Lease beginnt beim Claim, nicht erst bei `/start`: Initialdauer 30 Sekunden, Agent-Heartbeat alle 10 Sekunden, erfolgreiche Verlängerung jeweils auf `serverNow + 30s`, jedoch niemals über `executionDeadline`. `/start` muss vor Lease-Ablauf eintreffen.
- Jede Mutation nach Claim prüft `target_agent_id`, SHA-256 des Claim-Tokens und `lease_version`.
- In `claimed` und `running` muss zusätzlich `lease_expires_at > serverNow` gelten. In `cancel_requested` ist Lease-Heartbeat bis einschließlich `stop_deadline` und `timed_out`-/`ambiguous`-Completion bis einschließlich `phase_deadline` auch nach Lease-Ablauf zulässig; Agent-ID, Tokenhash und Lease-Version bleiben zwingend. Alles andere liefert 409.
- Veraltete/falsche Claims liefern HTTP 409.
- MySQL erzwingt höchstens einen aktiven BackupRun global über die nicht im DAL exponierte Spalte `active_guard TINYINT GENERATED ALWAYS AS (CASE WHEN status = 'running' THEN 1 ELSE NULL END) STORED` plus `UNIQUE KEY uniq_active_guard (active_guard)`.
- Ein Integrationstest mit zwei DB-Verbindungen versucht gleichzeitig zwei `running`-Runs einzufügen; exakt einer darf committen.
- V1 erzwingt `maxConcurrency=1` pro Agent doppelt: lokal durch einen Runner-Semaphor und serverseitig durch `active_agent_id BINARY(16) GENERATED ALWAYS AS (CASE WHEN status IN ('claimed','running','cancel_requested') THEN target_agent_id ELSE NULL END) STORED` plus `UNIQUE KEY uniq_active_agent (active_agent_id)`. Zwei parallele Claims desselben Agenten müssen genau einen aktiven Job ergeben; andere Agenten dürfen parallel arbeiten.

### 2.4 Atomarer Claim

- Kein DAL-Read-then-write.
- Doctrine-DBAL-Transaktion mit `SELECT ... FOR UPDATE SKIP LOCKED`, gefiltert auf Ziel-Agent, `queued`, `available_at <= NOW()` und `execution_deadline > NOW()`.
- Innerhalb derselben Transaktion: Versuch erhöhen, Claim-Token-Hash/Lease-Version/Lease-Ende setzen und Status auf `claimed` ändern.
- `phase_deadline`, `execution_deadline = phase_deadline - 15s` und `stop_deadline = min(execution_deadline + 5s, phase_deadline - 10s)` werden bei Joberzeugung unveränderlich materialisiert. Claim ist nur vor `execution_deadline` zulässig; jede Claim-/Start-Lease endet bei `min(serverNow + 30s, execution_deadline)`. Ab `execution_deadline` beginnt keine neue Hostwirkung, spätestens an `stop_deadline` folgt SIGKILL, bis `phase_deadline` bleiben höchstens 10 Sekunden für Journal und Completion.
- Requeue, Timeout, Unknown oder sonstiger Claim-Widerruf erhöhen `lease_version` atomar und verschieben den alten Tokenhash nur in unveränderliche Completion-Auditfelder.
- 100 parallele Claim-Versuche mit zwei echten MySQL-Verbindungen müssen genau einen Gewinner ergeben.

### 2.5 Request- und Response-Signaturen

Request-Basis, UTF-8 und `\n` exakt:

```text
MUWA-HMAC-V1-REQUEST
UPPERCASE_METHOD
ROUTE_PATH_WITHOUT_QUERY
LOWERCASE_HEX_SHA256_OF_RAW_BODY
DECIMAL_UNIX_TIMESTAMP
REQUEST_NONCE
```

Response-Basis:

```text
MUWA-HMAC-V1-RESPONSE
DECIMAL_HTTP_STATUS
ROUTE_PATH_WITHOUT_QUERY
LOWERCASE_HEX_SHA256_OF_RAW_BODY
REQUEST_NONCE
DECIMAL_UNIX_TIMESTAMP
```

- Das einmalig angezeigte Base64url-Secret wird vor HMAC ohne Padding dekodiert und muss exakt 32 Bytes ergeben; niemals wird der dargestellte Text als HMAC-Key verwendet.
- Der Basisstring besteht aus den oben gezeigten ASCII/UTF-8-Zeilen, LF (`0x0a`) ausschließlich zwischen Feldern und keinem abschließenden LF.
- Jeder sicherheitsrelevante Header muss genau einmal vorkommen. Timestamp ist eine nichtnegative kanonische Dezimalzahl ohne `+` und ohne führende Nullen (außer `0`).
- V1 akzeptiert zwei feste ASCII-Pfade (`/api/_action/muwa/agent/v1/heartbeat`, `/api/_action/muwa/agent/v1/jobs/claim`) und drei explizite Job-Pfadfamilien unter `/api/_action/muwa/agent/v1/jobs/{jobId}/{start|heartbeat|complete}`. `{jobId}` ist dabei exakt ein lowercase RFC-4122-UUID-Segment; signiert wird der unveränderte tatsächliche Pfad mit eingesetzter UUID. Query, Prozentkodierung, Backslash, Dot-Segmente, doppelte Slashes, zusätzliche Segmente und abweichende Schreibweise/Trailing-Slashes werden vor Controller-Ausführung abgelehnt.
- Agent-Routen erlauben in V1 keine Query-Parameter.
- Nonce: 32–64 Zeichen, `[A-Za-z0-9_-]+`.
- Timestamp-Fenster ±60 Sekunden.
- Der Go-Agent erzeugt Nonces ausschließlich mit `crypto/rand`. Der Replay-Eintrag läuft frühestens bei `requestTimestamp + 60s + 5s Cleanup-Toleranz` ab; Cleanup löscht nur `expires_at < serverNow`.
- Request- und Response-HMAC: SHA-256 mit demselben Agent-Secret.
- Response bindet sich an die Request-Nonce; nach erfolgreicher Key-Auflösung verwirft der Go-Agent bei HTTP **und** HTTPS jede fehlende oder ungültige Response-Signatur. Die einzige Ausnahme ist der unten definierte terminale Unknown-Key-Transportfehler ohne Nutzdaten.
- Damit schützt HTTP Integrität und Authentizität beider Richtungen, aber weiterhin nicht Vertraulichkeit. Die dokumentierte Warnung bleibt.
- Agent-Routen erhalten `auth_required=false`, werden jedoch ausnahmslos durch `AgentAuthenticationSubscriber` geschützt. `AgentResponseSigningSubscriber` signiert nach erfolgreicher Key-Auflösung auch alle Fehlerantworten.
- Go-Konfiguration verwendet exakt `server.url` und `server.allowInsecureHttp`. `https://` ist ohne Zusatzflag zulässig; `http://` wird nur mit `allowInsecureHttp: true` akzeptiert und erzeugt bei jedem Start die dokumentierte Warnung. Eine `skipTlsVerify`-Option existiert in V1 nicht.

Versionierter Wire-Contract:

| Route-Name | Methode/Pfad | Controller-Methode |
|---|---|---|
| `api.action.muwa.agent.v1.host_heartbeat` | `POST /api/_action/muwa/agent/v1/heartbeat` | `HostHeartbeatController::heartbeat()` |
| `api.action.muwa.agent.v1.job_claim` | `POST /api/_action/muwa/agent/v1/jobs/claim` | `JobClaimController::claim()` |
| `api.action.muwa.agent.v1.job_start` | `POST /api/_action/muwa/agent/v1/jobs/{jobId}/start` | `JobStartController::start()` |
| `api.action.muwa.agent.v1.job_heartbeat` | `POST /api/_action/muwa/agent/v1/jobs/{jobId}/heartbeat` | `JobHeartbeatController::heartbeat()` |
| `api.action.muwa.agent.v1.job_complete` | `POST /api/_action/muwa/agent/v1/jobs/{jobId}/complete` | `JobCompleteController::complete()` |

Alle fünf Routen besitzen `_routeScope=[\"api\"]`, `auth_required=false`, erlauben ausschließlich `POST` und werden vor Controller-Aufruf durch `AgentAuthenticationSubscriber` auf `KernelEvents::REQUEST` mit Priorität `256` geschützt. `AgentResponseSigningSubscriber` signiert auf `KernelEvents::RESPONSE` mit Priorität `-256`; auf `KernelEvents::EXCEPTION` wird nach erfolgreicher Key-Auflösung zunächst eine stabile JSON-Fehlerantwort erzeugt, die anschließend denselben Response-Signer durchläuft. Container-/Routentests prüfen jede Route, Methode, Defaults, Tags, Prioritäten, Query-Verbot sowie signierte Controller- und Exception-Antworten. Fehlende Auth-/Signing-Subscriber-Tags lassen den Test fehlschlagen.

Request-Header:

```text
Content-Type: application/json
Accept: application/json
X-Mucki-Agent-Key: <public key id>
X-Mucki-Agent-Timestamp: <unix seconds>
X-Mucki-Agent-Nonce: <base64url nonce>
X-Mucki-Agent-Signature: <lowercase hex HMAC>
```

Die Host-ID wird serverseitig ausschließlich aus `X-Mucki-Agent-Key` aufgelöst; der Client darf keine abweichende Agent-ID behaupten. Response-Header:

```text
Content-Type: application/json
X-Mucki-Agent-Timestamp: <unix seconds>
X-Mucki-Agent-Request-Nonce: <request nonce>
X-Mucki-Agent-Signature: <lowercase hex HMAC>
```

Erfolgs- und authentisierte Fehlerantworten verwenden stabile DTOs beziehungsweise `{"code":"MUWA_AGENT_*","message":"...","traceId":"<uuid>"}`. Ist `X-Mucki-Agent-Key` unbekannt oder so fehlerhaft, dass kein Secret ermittelt werden kann, ist exakt eine unsignierte 401-Antwort mit leerem Body erlaubt. Der Go-Agent akzeptiert sie ausschließlich als terminalen, unauthentisierten Transport-/Authentisierungsfehler, liest daraus keine Semantik und verarbeitet niemals Job- oder Actiondaten. Alle Antworten nach erfolgreicher Key-Auflösung – einschließlich 4xx/5xx – müssen signiert sein. Gemeinsame PHP-/Go-Fixtures decken jeden Header, DTO, Status und Fehlercode ab.

V1-DTO-Felder, jeweils `additionalProperties=false`:

- Hostheartbeat Request `{agentVersion, platform:"linux", arch, capabilities[], agentTime}`; Response `{serverTime, pollAfterSeconds}`.
- Claim Request `{maxJobs:1}`; Response `{job:null|{id, claimToken, leaseVersion, leaseExpiresAt, phaseDeadline, executionDeadline, stopDeadline, phase, idempotencyClass, action:{definitionCanonical, actionHash}, parameters}}`.
- Start Request `{claimToken, leaseVersion}`; Response `{leaseVersion, leaseExpiresAt, phaseDeadline, executionDeadline, stopDeadline}`.
- Lease-Heartbeat Request `{claimToken, leaseVersion}`; Response `{leaseVersion, leaseExpiresAt, stopRequested, stopDeadline?}`.
- Completion Request `{claimToken, leaseVersion, outcome, resultCode, summary, stdoutTail?, stderrTail?, stdoutEncoding?, stderrEncoding?}`; Response `{accepted:true, terminalStatus}`.
- UUIDs sind lowercase RFC-4122-Strings; Zeiten sind UTC/RFC 3339 mit Sekundenauflösung; unbekannte Enumwerte werden mit `MUWA_AGENT_PROTOCOL_UNSUPPORTED` abgelehnt.
- Die vollständigen JSON-Schemas liegen versioniert unter `resources/schema/protocol/v1/`; PHP und Go generieren/validieren dagegen nicht unabhängig erfundene DTOs.

Rohbody-Limits vor JSON-Dekodierung: Hostheartbeat 16.384 Bytes, Claim 1.024 Bytes, Start 4.096 Bytes, Lease-Heartbeat 4.096 Bytes, Completion 262.144 Bytes. Der Server hasht einen zu großen Body streaming weiter, ohne ihn vollständig zu puffern, authentisiert die Signatur und liefert danach eine signierte 413 `MUWA_AGENT_BODY_TOO_LARGE`; vor erfolgreicher Key-Auflösung bleibt der definierte unsignierte 401-Fall bestehen. Gemeinsame PHP-/Go-Fixtures und Routentests decken jedes Limit exakt an Grenze und Grenze+1 ab.

Der vorgeschaltete Webserver/PHP-FPM setzt zusätzlich einen absoluten Transport-Hardcap von 270.336 Bytes und einen 15-Sekunden-Request-Read-Timeout. Eine Überschreitung darf als unsignierter Transportabbruch enden und wird vom Agenten – außer dem exakt definierten leeren Unknown-Key-401 – nie vertraut. Innerhalb dieses Caps liest/spoolt der früh laufende Auth-Layer den Rohbody genau einmal begrenzt, hasht und authentisiert ihn vor jeder JSON-Dekodierung. `Content-Encoding`, mehrere `Content-Length`-Header sowie gleichzeitiges `Content-Length` und `Transfer-Encoding` werden abgelehnt. Agent-Routen senden `Cache-Control: no-store`; Reverse-Proxy-/APM-Bodylogging ist für diese Routen zu deaktivieren.

### 2.6 Secret-Speicherung und Rotation

- Provisionierung erzeugt `random_bytes(32)`, Base64url ohne Padding, zeigt es genau einmal und speichert nie Klartext.
- Server speichert Secret authentisiert verschlüsselt mit sodium secretbox.
- Versionierter Keyring: `MUWA_AGENT_ENCRYPTION_KEYRING` enthält `{\"active\":<positive int>,\"keys\":{\"<version>\":\"<64 hex chars>\"}}`, liegt außerhalb der DB, wird in `app` und `scheduler` identisch gesetzt und niemals über DAL/API ausgegeben. Jeder Schlüssel wird auf exakt 32 dekodierte Bytes geprüft.
- DB-Felder: `secret_ciphertext`, `secret_nonce`, `secret_key_version`.
- Unbekannte oder fehlende Key-Versionen sind fail-closed. Master-Key-Rotation fügt zuerst eine neue aktive Version hinzu, re-encryptet Secrets transaktional und wiederaufnehmbar mit Readback-Prüfung und entfernt alte Schlüssel erst, wenn kein Datensatz mehr darauf verweist.
- V1-Rotation ist bewusst sofortig: neues Secret wird einmal angezeigt und ersetzt atomar das alte. Das alte Secret ist unmittelbar ungültig. Verlorene Anzeige wird durch erneute Rotation behoben; überlappende current/next-Credentials sind Nicht-Ziel von V1.
- Widerruf setzt `revoked_at`; danach sind alle Requests 401.

### 2.7 Action-Definition und Hash

- Erlaubte JSON-Werte: Objekt, Array, String, Integer, Boolean, Null; keine Floats.
- V1-Definitionen sind Objekte mit exakt `version=1` und `steps` (1 bis 64). Unbekannte Felder, Primitive und Primitive-Felder werden abgelehnt.
- Policy-Aliase sind höchstens 64 ASCII-Zeichen lang und entsprechen `^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$`; Rohpfade, Unit-/Container-Namen, Executables, URLs, UIDs/GIDs und Credentials sind keine Action-Eingaben. Relative Unterpfade sind höchstens 4096 UTF-8-Bytes lang und werden ohne Normalisierung gegen absolute Pfade, Backslashes, Steuerzeichen, leere Segmente sowie `.`-/`..`-Segmente geprüft.
- PHP und Go sortieren Objektschlüssel rekursiv byteweise nach ihren UTF-8-Bytes, erhalten Array-Reihenfolge und speichern kompaktes UTF-8-JSON ohne Whitespace, Slash- oder Unicode-Escaping. Steuerzeichen, `"` und `\\` verwenden die JSON-Escapes; ungültiges UTF-8, Floats, Integer außerhalb signed 64-bit und mehr als 32 Ebenen werden abgelehnt.
- API liefert `definitionCanonical` als String und `actionHash=sha256(definitionCanonical)`.
- Go prüft den Hash über exakt diese empfangenen String-Bytes, parst danach strikt und verlangt, dass erneute Kanonisierung bytegleich ist.
- Gemeinsame Fixtures frieren Akzeptanz, kanonische Bytes und Hash sprachübergreifend ein.
- Eine Action enthält geordnete Steps und bricht beim ersten Fehler ab.
- V1 führt keine implizite Kompensation aus. Wurden bereits Steps ausgeführt, lautet der Resultcode `partial_failure`.
- Parameter sind typisiert. Keine freie Template-Sprache, keine Shell, kein `eval`; Pfadparameter dürfen weder absolut sein noch `..`/NUL enthalten und dürfen Alias-Wurzeln nicht über Symlinks verlassen.

Verbindliche Primitive-Policy vor Implementierung:

| Primitive | Eingaben aus der Action | Ausschließlich lokale Policy | Ausführungsgrenzen |
|---|---|---|---|
| `service.control` | `serviceAlias`, `operation=start|stop|restart` | Alias → exakter systemd-Unitname, Timeout; für `stop` optional zwingend `requireEmptyCgroupAfterStop` | nur `/usr/bin/systemctl --no-ask-password`; Status danach prüfen; bei gesetzter Policy-Eigenschaft erst nach inaktiver Unit und leerer exakter systemd-Cgroup erfolgreich; keine Unit-/Prozessnamen aus der Action |
| `service.wait-http` | `endpointAlias`, 1–16 eindeutige aufsteigende Statuscodes 100–599 | Alias → vollständige HTTP(S)-URL, erlaubte Status-/Header-/Body-Grenzen, Intervall, Timeout, erlaubte IP-Bereiche | Action-Statusmenge muss Teilmenge der Policy sein; Userinfo/Fragment/Redirects verboten; geprüfte IP wird für den tatsächlichen Dial gepinnt, bei HTTPS bleiben SNI/Zertifikatsprüfung auf dem Policy-Host; bei jeder neuen Verbindung neu auflösen/prüfen; Body begrenzt prüfen/verwerfen |
| `docker.container.exec` | `containerAlias`, `commandAlias`, in V1 exakt leeres `arguments`-Objekt | Containername und Command-Alias → vollständiges festes argv und Argumentbindungen | nur `/usr/bin/docker exec`; kein Shell-Entry-Point, kein frei wählbares Argument, Executable oder Containerziel; neue Argumente benötigen eine spätere typisierte Schema-Version |
| `filesystem.acl.read` | `pathAlias`, `principalAlias`, optional ein validierter relativer Unterpfad | Alias-Wurzel, UID/GID, Rekursions- und Dateilimit | ACL nur auf descriptor-relativ geöffneten Zielen; keine numerische UID/GID, absoluten Pfade oder Traversal-Segmente aus der Action |
| `filesystem.assert-readable` | `checkAlias` | Check-Alias → genau eine Alias-Wurzel, Principal, begrenztes relatives Verzeichnis, fester Suffix/Anzahl, Current-Run-/Regular-File-/Mindestgrößenregeln | descriptor-relativer Walk, keine Symlinks/Magiclinks, harte Objektzahlgrenze; keine Globs, Präfixe/Suffixe, Pfade oder UIDs aus der Action; mehrere Wurzeln benötigen mehrere Steps |
| `file.write-pipeline` | `sourceCommandAlias`, `destinationAlias`, exakt `transforms=[gzip]`, `nameFromRunId=true` | Quell-argv, Zielwurzel, Run-ID-Namensregel, Dateimode/Owner, Maximalgröße | Run-ID kommt ausschließlich aus dem authentisierten Jobkontext; temp mit `O_CREAT|O_EXCL`, Datei-`fsync`, `renameat2(..., RENAME_NOREPLACE)`, Zielverzeichnis-`fsync`; kein Überschreiben; gzip in Go statt Shellpipe |
| `file.retention` | `pathAlias`, `manifestAlias`, `keep` (1 bis 10000) | Manifest-Alias → gebundene Zielwurzel, fester Präfix/Suffix und maximales Keep-Limit | Policy muss Pfad-/Manifestbeziehung und kleineres Keep-Limit bestätigen; nur durch `file.write-pipeline` in einem rootgeschützten fsync-durablen Manifest erfasste Dateien; Manifest bindet Alias, relativen Namen, Device/Inode und Run-ID; unmittelbar vor `unlinkat` descriptor-relatives `fstatat(AT_SYMLINK_NOFOLLOW)`, Device/Inode-Abgleich und Linkzahl 1; nicht manifestierte/manipulierte Treffer nie löschen |

- Linux-Dateioperationen verwenden `openat2(2)` über `golang.org/x/sys/unix` mit `RESOLVE_BENEATH|RESOLVE_NO_SYMLINKS|RESOLVE_NO_MAGICLINKS`; Mutationen erfolgen descriptor-relativ. Ein bloßer `filepath.Clean`-/`EvalSymlinks`-Vorabcheck ist unzulässig.
- Externe Werkzeuge erhalten nach sicherem Öffnen ausschließlich stabile `/proc/self/fd/<n>`-Ziele; rekursive ACL-Arbeit iteriert descriptor-relativ und begrenzt Objektzahl/Tiefe.
- Policy, Config, Secret, Journal-State und ihre gesamte Elternkette werden descriptor-relativ, symlinkfrei geöffnet. Jeder FD wird per `fstat` auf erwarteten Typ, `root:root` und fehlende Gruppen-/Weltschreibbarkeit geprüft; Config/Secret höchstens `0600`. Policy wird ausschließlich aus dem bereits geprüften FD als unveränderlicher Snapshot geladen; jeder Reload wiederholt denselben atomaren Ablauf. Andernfalls startet der Agent nicht.
- Gemeinsame negative Tests erzwingen Ablehnung bei Symlink-Tausch während der Operation, `..`, NUL, absoluten Unterpfaden, unbekannten Units/Containern/Commands, HTTP-Redirects, zu großen Walks und nicht erlaubten Executables.

### 2.8 Output

- Agent redigiert bekannte Secretwerte vor der Begrenzung.
- Byte-Tail, nicht Zeichenlimit: maximal 65.536 Bytes je Stream.
- Nicht valides UTF-8 wird Base64-kodiert und entsprechend markiert.
- `succeeded`: keine stdout-/stderr-Felder übertragen oder speichern.
- `failed` und `timed_out`: Tails erlaubt; `partial_failure` ist dabei ein `result_code` unter Status `failed`.
- Outcome `ambiguous`/Status `unknown` überträgt nur einen begrenzten strukturierten Grund, keine alten Tails. `expired` entsteht ohne Agent-Completion und erhält keine Tails.
- Completion-Requestlimit: exakt 262.144 rohe HTTP-Bytes, geprüft **vor** JSON-Dekodierung. Nach Base64-Dekodierung gelten weiterhin 65.536 Bytes je Stream. DB-Felder sind `MEDIUMTEXT`, der Service erzwingt die kleineren fachlichen Grenzen.
- Fehler-Tails werden 30 Tage nach `finished_at` durch den Cleanup-Task auf `NULL` gesetzt; Resultcode und redigierte Summary bleiben erhalten.
- Bei HTTP sind Actiondefinition, Parameter und Fehler-Tails trotz Signatur mitlesbar. Diese fehlende Vertraulichkeit steht ausdrücklich in Startwarnung, README und Admin-Konfiguration.
- `summary` und strukturierte Ambiguous-Gründe sind UTF-8 und auf 4.096 Bytes begrenzt; `resultCode` stammt aus einer versionierten Allowlist und ist höchstens 64 ASCII-Zeichen lang. Streaming-Redaction erkennt Secrets auch über Chunkgrenzen, redigiert vor der Tail-Bildung und wendet danach das Byte-Limit erneut an.

### 2.9 Wakeup

- Global und flach: Host `/var/lib/mucki-agent/wakeup`, Container `/var/run/mucki-agent/wakeup`.
- Datei `<job-uuid>.ready`, atomarer Rename aus `.tmp` im selben Verzeichnis.
- Wakeup erst nach erfolgreichem Job-Commit.
- Go-Watcher löscht keine Dateien. Jedes passende Event stößt nur einen API-Poll an.
- PHP-Cleanup entfernt Dateien älter als 24 Stunden.
- Regelmäßiges Polling bleibt autoritativ; verlorene/duplizierte Events sind unschädlich.

### 2.10 Agent-Journal

- JSON-Datei pro Job unter `/var/lib/mucki-agent/state/jobs/`, atomar über temp + `fsync` + Rename.
- Schlüssel: Job-ID, Action-Hash, Lease-Version.
- Vor erster Hostwirkung Status `started` persistieren.
- Nach Hostwirkung Ergebnis als `completed` persistieren, bevor Completion gesendet wird.
- Neustart bei `completed`: nur Completion wiederholen.
- Neustart bei `started` und `non_retryable`: keine Hostwirkung wiederholen; Completion-Outcome `ambiguous` senden, das der Server auf Status `unknown` mappt.
- Neustart bei `started` und `safe`: Ausführung darf wiederholt werden.
- Genau-einmal-Ausführung wird ausdrücklich nicht versprochen; Ambiguität wird sichtbar statt geraten.
- State-Verzeichnis und Eltern werden beim Start descriptor-relativ und symlinkfrei geprüft. Journal-Temps entstehen per dirfd/`openat2`, `O_CREAT|O_EXCL|O_NOFOLLOW`, Mode `0600` und Zufallsnamen; Datei und nach dem Rename das Verzeichnis werden `fsync`-t. Beim Lesen werden regulärer Typ, Owner, Mode, Linkzahl 1, Job-ID, Action-Hash und Lease-Version geprüft. Beschädigte/manipulierte Journale führen fail-closed zu `ambiguous`, niemals zu erneuter Hostwirkung.

### 2.11 Phasenbudgets und Lock-Dauer

- `pre` und `post`: Default 600 Sekunden, serverseitiges Maximum je 1.800 Sekunden.
- `finally`: Default 180 Sekunden, serverseitiges Maximum 600 Sekunden; dieses Budget ist unabhängig reserviert und wird nie durch Pre/Post verbraucht.
- Jede Phase materialisiert unveränderlich DB `phase_deadline` ↔ Wire `phaseDeadline`, DB `execution_deadline = phase_deadline - 15s` ↔ Wire `executionDeadline` und DB `stop_deadline = min(execution_deadline + 5s, phase_deadline - 10s)` ↔ Wire `stopDeadline`. Es gibt kein zusätzliches `deadline_at`/`deadlineAt`. Die letzten 15 Sekunden bestehen aus höchstens 5 Sekunden SIGTERM-Grace bis `stopDeadline`, danach SIGKILL und bis zu 10 Sekunden für Journal/Completion.
- Der Agent prüft die signierte `executionDeadline` vor **jedem** Step und jeder externen Prozessausführung. Er startet nach Erreichen dieser Frist keine neue Hostwirkung. Laufende Prozessgruppen erhalten SIGTERM, nach höchstens 5 Sekunden SIGKILL; der Agent journalisiert `timed_out` vor der Completion.
- Unabhängig von Heartbeats erzwingt der Agent die Deadline über einen lokalen monotonic timer, kalibriert mit `serverTime`. `stopRequested=true` verlangt sofortiges SIGTERM; das mitgelieferte `stopDeadline` darf höchstens `min(serverNow+5s, materializedStopDeadline)` sein und verlängert keine materialisierte Grenze.
- Bei `phaseDeadline - 15s` setzt der Server `queued` und `claimed` (noch nie gestartet) direkt auf `timed_out` und widerruft deren Claim. Nur `running` wechselt atomar auf `cancel_requested`.
- In `cancel_requested` bleibt derselbe Token/dieselbe Lease ausschließlich für Lease-Heartbeat bis einschließlich `stop_deadline` und Timeout-/Ambiguous-Completion bis einschließlich `phase_deadline` gültig. Der Heartbeat verlängert die Lease nicht mehr, liefert aber signiert `stopRequested=true` und das effektive `stopDeadline`. `/start`, normale Success-/Failure-Completion und alle anderen Mutationen liefern 409. Erst bei Timeout-/Ambiguous-Completion oder endgültiger Phase-Deadline wird die Lease-Version erhöht und der Claim vollständig widerrufen.
- `JobWaiter` endet nicht bei bloßem Lease-Ablauf. Bei einem noch nie gestarteten safe Job folgt er dessen Requeue/neu erzeugtem Claim bis zu einem terminalen Zustand oder bis zur Phase-Deadline. Bei einem jemals gestarteten Job wartet er auf Timeout-Completion; fehlt diese bei Phase-Deadline, setzt der Server konservativ `unknown` und beendet den Waiter.
- Offline-Agent/API-Fehler dürfen das Budget nicht verlängern. Nach Rückkehr des Waiters verhindert die lokal signierte Deadline jeden neuen Step; uninterruptible Linux-D-State-Prozesse können technisch nicht garantiert beendet werden und führen sichtbar zu `unknown`, niemals zu automatischem Requeue.
- Der Facility-Kern selbst besitzt weiterhin sein eigenes Backup-Zeitverhalten. Die zusätzlich unter dem globalen Lock verbrachte Agent-Wartezeit ist pro Phase hart begrenzt; ein Test misst diese Obergrenzen mit injizierter Uhr statt realem Warten.
- Nach einem Pre-/Core-/Post-Fehler wird `finally` mit seinem separaten Budget versucht. Ein vorhandener Primärfehler bleibt nach Ablauf/Fehler von Finally prioritär.

## 3. Zielstruktur

```text
MuckiSafeAgentPlugin/
├── composer.json
├── phpunit.xml.dist
├── .gitattributes
├── README.md
├── docs/design.md
├── docs/implementation-plan.md
├── resources/schema/action-v1.schema.json
├── resources/schema/parameters-v1.schema.json
├── examples/actions/{bitwarden,jellyfin,immich,paperless}.json
├── examples/policies/linux-host.yaml
├── src/MuckiSafeAgentPlugin.php
├── src/Migration/Migration1795132800CreateAgentSchema.php
├── src/Core/Content/{AgentHost,AgentAction,AgentRepositoryHook,AgentBackupRun,AgentJob,AgentNonce}/
├── src/Core/Job/{JobStatus,JobPhase,IdempotencyClass}.php
├── src/Controller/Api/
├── src/Controller/Admin/
├── src/Service/{Action,Auth,Hook,Job,Plugin,Secret,Wakeup}/
├── src/Subscriber/
├── src/ScheduledTask/
├── src/Resources/config/{services.xml,routes.xml}
├── src/Resources/app/administration/src/
├── tests/{Unit,Integration,Fixtures,E2E}/
├── agent/go.mod
├── agent/cmd/mucki-agent/main.go
├── agent/internal/{action,agent,api,config,executor,idempotency,policy,primitives,version,wakeup}/
├── agent/packaging/systemd/mucki-agent.service
├── agent/packaging/{install.sh,uninstall.sh}
└── .github/workflows/{php-tests,go-tests,e2e-linux,agent-release,plugin-release}.yml
```

`composer.json` und `.gitattributes` schließen `agent/`, `tests/`, `.github/` und Entwicklungsartefakte aus dem Plugin-Distributionsarchiv aus. Der Quell-Tag enthält weiterhin beide Komponenten.

## 4. Implementierungsaufgaben

### Task 0: Design und Implementierungsplan trennen und normativ angleichen

**Repository:** `MuckiSafeAgentPlugin`

**Files:**
- Move: `docs/implementation-plan.md` → `docs/design.md`
- Create: `docs/implementation-plan.md` aus diesem Dokument

**Steps:**
1. `git mv docs/implementation-plan.md docs/design.md`.
2. Diesen Plan als neues `docs/implementation-plan.md` ablegen.
3. `docs/design.md` als nicht-normative Architektur-/Entscheidungsbeschreibung kennzeichnen und am Anfang auf `docs/implementation-plan.md` als alleinigen normativen V1-Ausführungs-, Protokoll- und Testvertrag verweisen.
4. Design vollständig gegen Abschnitt 2 abgleichen: sofortige Secret-Rotation ohne current/next-Umschaltmodell; keine Requeue nach jemals erreichtem `running`; versionierte fünf Routen; Request- **und** Response-HMAC; Ziel-Agent und `maxConcurrency=1`; Lease-/Heartbeat-/Deadline-/Cancellation-Werte; Resultcode-vs.-Status-Modell; Outputlimits/Retention; globaler flacher Wakeup-Pfad. Widersprechende alte Detailverträge werden entfernt statt parallel stehen gelassen.
5. Ein Dokumenttest sucht in `docs/design.md` nach den verworfenen Contracts/Routen und prüft den Präzedenz-Link; ein manueller Spec-Review bestätigt, dass beide Dokumente keine konkurrierenden normativen Aussagen enthalten.
6. Links und Titel prüfen und `git diff --check` ausführen.
7. Commit: `docs: split and align architecture and implementation plans`.

**Eingangsgate:** Task 0, Dokumenttest und unabhängiger Spec-Review müssen grün sein, bevor Task 1 beginnt.

### Task 1: Facility-Laufzustand isolieren

**Repository:** `MuckiFacilityPlugin`

**Files:**
- Modify: `src/Services/Backup.php`
- Modify: `tests/Services/BackupTest.php`

**RED:** Zwei aufeinanderfolgende `createBackup()`-Aufrufe auf derselben Serviceinstanz dürfen im zweiten Lauf keine Werte aus `allResults`, `checkFailure` oder `dumpFailure` des ersten Laufs enthalten.

**GREEN:** Private Methode `resetRunState(): void` am Anfang von `createBackup()` aufrufen und genau `allResults=[]`, `checkFailure=null`, `dumpFailure=null` setzen. Die heute nicht-nullbaren Properties `backupException` und `backup` werden in diesem neutralen Facility-Schritt nicht umtypisiert; ihr bestehendes Getter-Verhalten bleibt unverändert.

**Verify:**
```bash
./vendor/bin/phpunit --configuration="custom/plugins/MuckiFacilityPlugin" \
  custom/plugins/MuckiFacilityPlugin/tests/Services/BackupTest.php
```

**Commit:** `fix(backup): isolate state between sequential runs`

### Task 2: Neutralen Facility-Eventvertrag hinzufügen

**Repository:** `MuckiFacilityPlugin`

**Files:**
- Create: `src/Events/BackupFinishedEvent.php`
- Create: `tests/Events/BackupFinishedEventTest.php`

**RED:** Die exakten Methoden `getBackupSettings(): BackupRepositorySettings`, `getResults(): array` (`array<ResultEntity>`), `getPrimaryError(): ?Throwable` und `isCoreSucceeded(): bool` liefern dieselbe Settings-/Throwable-Instanz beziehungsweise dasselbe in Inhalt/Reihenfolge unveränderte Result-Array; `NAME` ist stabil.

**GREEN:** Nur die Event-Datenklasse implementieren, keine Agent-Abhängigkeit und keine Service-Registrierung.

**Commit:** `feat(events): add neutral backup finished event`

### Task 3: Facility-Erfolgspfad und Reihenfolge

**Repository:** `MuckiFacilityPlugin`

**Files:**
- Modify: `src/Services/Backup.php::createBackup()`
- Modify: `tests/Services/LifecycleEventDispatchTest.php`

**RED:** Reihenfolge `before`, `after`, `finished`; Finished enthält Settings/Results, `primaryError=null`, `coreSucceeded=true`.

**GREEN:** Finished im bestehenden äußeren Ablauf nach After und vor Dauer-Speicherung dispatchen.

**Commit:** `feat(backup): dispatch finished event after successful run`

### Task 4: Facility-Fehlerpfade und vollständige Fehlerpriorität

**Repository:** `MuckiFacilityPlugin`

**Files:**
- Modify: `src/Services/Backup.php`
- Modify: `tests/Services/LifecycleEventDispatchTest.php`
- Modify: `tests/Services/BackupTest.php`
- Modify: `README.md`
- Modify: `composer.json` (`version` auf `v0.11.0`)

**RED-Fälle:**
- Before-, Kern- und After-Fehler: kein After nach früherem Fehler, Finished genau einmal, identisches Primary-Throwable.
- Finished-Fehler nach Erfolg wird geworfen.
- Finished-Fehler ersetzt keinen vorhandenen Primärfehler.
- Dauerfehler ersetzt weder Primär- noch Finished-Fehler.
- Dauer wird auch bei Primär-/Finished-Fehler genau einmal versucht.
- Gleichzeitig Primär-, Finished- und Dauerfehler: exakt das ursprüngliche Primär-Throwable wird geworfen, beide untergeordneten Fehler werden geloggt. Ohne Primärfehler hat Finished Vorrang vor Dauer.

**GREEN:** Fehler sammeln, untergeordnete Fehler loggen und nach Cleanup den höchstpriorisierten Fehler erneut werfen.

**Regression:** Legacy-Subscriber, `BackupExecution`-Lock und bestehende Lifecycle-Tests unverändert grün.

**Verify:**
```bash
./vendor/bin/phpunit --configuration="custom/plugins/MuckiFacilityPlugin" \
  custom/plugins/MuckiFacilityPlugin/tests/Events/BackupFinishedEventTest.php \
  custom/plugins/MuckiFacilityPlugin/tests/Services/LifecycleEventDispatchTest.php \
  custom/plugins/MuckiFacilityPlugin/tests/Services/BackupTest.php \
  custom/plugins/MuckiFacilityPlugin/tests/Subscriber/BackupCommandSubscriberTest.php \
  custom/plugins/MuckiFacilityPlugin/tests/Schedule/BackupExecutionTest.php
./vendor/bin/phpunit --configuration="custom/plugins/MuckiFacilityPlugin"
composer --working-dir=custom/plugins/MuckiFacilityPlugin run-script phpstan
composer --working-dir=custom/plugins/MuckiFacilityPlugin validate --strict
test "$(php -r '$p=json_decode(file_get_contents("custom/plugins/MuckiFacilityPlugin/composer.json"), true); echo $p["version"];')" = v0.11.0
```

**Commit:** `feat(backup): guarantee finished lifecycle on failures`

**Gate:** Facility `v0.11.0` veröffentlichen und tatsächlichen Packagist-Constraint bestätigen, bevor Task 5 gemergt wird.

### Task 5: AgentPlugin-Skeleton und Testharness

**Repository:** `MuckiSafeAgentPlugin`

**Files:**
- Create: `composer.json`, `phpunit.xml.dist`, `.gitignore`, `.gitattributes`, `README.md`
- Create: `src/MuckiSafeAgentPlugin.php`
- Create: `src/Service/Plugin/FacilityDependencyGuard.php`
- Create: `src/Exception/FacilityDependencyException.php`
- Create: `src/Resources/config/services.xml`, `routes.xml`
- Create: `tests/bootstrap.php`
- Create: `tests/Integration/run-shopware.sh`
- Create: `tests/Unit/Service/Plugin/FacilityDependencyGuardTest.php`
- Create: `tests/Integration/Plugin/PluginBootTest.php`

**RED:** Plugin aktiviert nur mit installierter/aktiver Facility-Version ab `v0.11.0`; fehlend, inaktiv oder zu alt erzeugt klaren Aktivierungsfehler. Wird Facility später deaktiviert, wechseln Agent-Admin/API/Subscriber fail-closed in Zustand `facility_unavailable`, erzeugen keine neuen Jobs und zeigen einen Health-Fehler; bestehende Jobs werden nicht gelöscht. Dokumentierter Lifecycle verlangt: AgentPlugin zuerst deaktivieren/deinstallieren, Facility danach. Composer verhindert das Entfernen des Facility-Pakets, solange AgentPlugin installiert ist.

**GREEN:** Composer-Paket `muckiware/mucki-safe-agent-plugin`, PSR-4, Shopware-Metadaten, sodium-Anforderung und minimaler Guard. Der Integrationstest-Harness startet MySQL 8.0/8.4, checkt die jeweilige unterstützte `shopware/production`-Version aus, legt Facility und AgentPlugin unter `custom/plugins/` ab, führt `composer install`, `system:install`, `plugin:refresh`, `plugin:install --activate` aus und startet PHPUnit vom Shopware-Root mit der Plugin-Konfiguration. Unit-Skripte dürfen im Plugin laufen; DAL-/Migration-/Route-/Boot-Tests laufen ausschließlich in diesem installierten Shopware-Kontext. Arbeitsverzeichnis, DB-Variablen und Matrixwerte werden in `php-tests.yml` und `tests/bootstrap.php` identisch festgelegt.

**Composer scripts:** `test:unit`, `test:integration`, `test`, `phpstan`.

**Verify:**
```bash
composer validate --strict
composer install --no-interaction --prefer-dist
composer test:unit
tests/Integration/run-shopware.sh --shopware v6.6.10.25 --mysql 8.0
tests/Integration/run-shopware.sh --shopware v6.7.14.1 --mysql 8.4
```

**Commit:** `feat: scaffold MuckiSafeAgentPlugin`

### Task 6: Grundschema und Uninstall

**Repository:** `MuckiSafeAgentPlugin`

**Files:**
- Create: `src/Migration/Migration1795132800CreateAgentSchema.php`
- Create all DAL classes under `src/Core/Content/`
- Create enums under `src/Core/Job/` and `src/Core/Run/`
- Create: `tests/Integration/Migration/AgentSchemaTest.php`
- Create: `tests/Integration/Plugin/UninstallTest.php`

**Schema:** `muwa_agent_host`, `muwa_agent_action`, `muwa_agent_repository_hook`, `muwa_agent_backup_run`, `muwa_agent_job`, `muwa_agent_nonce`; eigene FKs erlaubt, kein FK auf Facility-Tabellen. Hook enthält `agent_host_id`; Job enthält `target_agent_id`, unveränderliche `phase_deadline`/`execution_deadline`, `stop_deadline`, Completion-Fingerprint sowie Auditfelder für vorherigen Tokenhash/Lease-Version. Generated `active_guard` erzwingt einen global aktiven Run; generated `active_agent_id` erzwingt `maxConcurrency=1` je Agent.

**RED:** Spalten, Indizes, FK-Regeln, UNIQUE `(facility_repository_id, phase)`, exaktes `active_guard`-/`active_agent_id`-DDL, paralleler Running-Insert sowie zwei parallele aktive Jobs desselben Agenten über echte DB-Verbindungen, Parallelität verschiedener Agenten und `keepUserData`-Semantik.

**GREEN:** Eine initiale Migration; DAL exponiert Secret/Claim-Hash/generated guard nicht über normale API.

**Commit:** `feat(storage): add agent control-plane schema`

### Task 7: Einmaliges Secret und Verschlüsselung

**Repository:** `MuckiSafeAgentPlugin`

**Files:**
- Create: `src/Service/Secret/EncryptionKeyProvider.php`
- Create: `src/Service/Secret/SecretCipher.php`
- Create: `src/Service/Secret/AgentProvisioner.php`
- Create: `src/Service/Secret/AgentSecretRotator.php`
- Create: `src/Service/Secret/MasterKeyReencryptor.php`
- Create: `src/Controller/Admin/AgentProvisionController.php`
- Tests under `tests/Unit/Service/Secret/` and `tests/Integration/Service/Secret/`

**RED:** Falscher/fehlender/duplizierter Keyring-Eintrag; unbekannte DB-Key-Version fail-closed; encrypt/decrypt; Manipulationserkennung; Klartext nur in unmittelbarer Provision-/Rotate-Response; DAL-Responses enthalten ihn nie; Agent-Secret-Rotation invalidiert alt sofort; Master-Key-Re-Encryption ist wiederaufnehmbar und entfernt die alte Version erst nach vollständigem Readback; Widerruf.

**GREEN:** sodium secretbox mit zufälliger Nonce, versionierter `%env(MUWA_AGENT_ENCRYPTION_KEYRING)%`, Action-Routen mit separaten ACLs sowie wiederaufnehmbarer Re-Encryption-Service mit Readback-Prüfung.

**Commit:** `feat(auth): provision encrypted one-time agent secrets`

### Task 8: Gemeinsame Signatur-Fixtures

**Repository:** `MuckiSafeAgentPlugin`

**Files:**
- Create: `tests/Fixtures/protocol/{hmac-v1,routes-v1,errors-v1}.json`
- Create: JSON-Schemas/DTO-Fixtures für heartbeat, claim, start, lease-heartbeat und complete
- Create: `src/Service/Auth/RequestSignature.php`
- Create: `src/Service/Auth/ResponseSignature.php`
- Create: `agent/go.mod`
- Create: `agent/internal/api/signing.go`, `signing_test.go`
- Create corresponding PHPUnit unit tests

**RED:** PHP und Go konsumieren dieselben Vektoren für dekodierten 32-Byte-Key, Domänenpräfix, LF ohne Abschluss-LF, leeren/gefüllten Body, exakt kanonischen Pfad, Timestamp/Nonce, Request/Response, doppelte Header, alle fünf Routen, DTOs, Fehlercodes, Bodylimits Grenze/Grenze+1 und den bewusst leeren unsignierten Unknown-Key-401-Fall. Negative Vektoren decken Query, Prozentkodierung, Backslash, Dot-Segmente, doppelte Slashes, Trailing Slash, führende Timestamp-Nullen und ungültiges Base64url ab.

**GREEN:** Konstante Zeitvergleiche, exakte Basisstrings, keine Query-Unterstützung.

**Verify:**
```bash
composer test:unit -- --filter Signature
cd agent && go test ./internal/api -run TestSigningVectors -count=1
```

**Commit:** `feat(protocol): define shared request and response signatures`

### Task 9: Agent-Authentisierung und Replay-Schutz

**Repository:** `MuckiSafeAgentPlugin`

**Files:**
- Create: `src/Service/Auth/AgentAuthenticator.php`, `ReplayGuard.php`
- Create: `src/Subscriber/AgentAuthenticationSubscriber.php`
- Create: `src/Subscriber/AgentResponseSigningSubscriber.php`
- Create auth/replay integration tests

**RED:** gültig; falscher Body/Pfad/Nonce/Timestamp; widerrufen/deaktiviert; Query vorhanden; parallele identische Nonce; Timestamp am positiven Fensterrand bleibt bis `requestTimestamp+65s` replaygeschützt; Cleanup löscht nicht zu früh; signierte 2xx/4xx/5xx-Antworten nach Key-Auflösung; bewusst leere unsignierte 401 vor Key-Auflösung wird vom Client nur als terminaler Fehler akzeptiert und niemals als Nutzdatenquelle; Content-Encoding und mehrdeutige Length-/Transfer-Encoding-Header werden abgelehnt.

**GREEN:** Nonce nach erfolgreicher Signatur atomar inserieren; UNIQUE entscheidet Replay. Cleanup löscht abgelaufene Nonces in Batches.

**Commit:** `feat(auth): secure agent routes with replay-safe HMAC`

### Task 10A: Vier reale Dienste vor dem DSL-Freeze inventarisieren

**Repositories:** `MuckiSafe`, reale Test-/Referenzhosts lesend, danach `MuckiSafeAgentPlugin`

**Files:**
- Create: `docs/reference-services.md`
- Create: `tests/E2E/versions.env`
- Create: `tests/E2E/images.lock`
- Create: vier erwartete Wirkungs-/Cleanup-Manifeste unter `tests/E2E/contracts/`

**Steps:**
1. Bitwarden-, Jellyfin- und Immich-Referenzskripte, Compose-/systemd-Konfiguration und echte Zielpfade erfassen.
2. Das reale Paperless-`paperless_pre.sh`, seine Aufrufer, Compose-Konfiguration, Argumente und die bekannte `${2}`-Fehlerstelle erfassen. Keine fehlenden Argumente erfinden.
3. Für jeden Dienst die benötigten Primitive, Parameter, Aliase, Idempotenzklasse, Startzustände, Health-Kriterium, erwartete Dateien/ACLs/Servicezustände, Fehler-Injektionen und Cleanup definieren.
4. Für alle in CI verwendeten Service-/Datenbankimages exakte Tags **und** Digests in `images.lock` pinnen. `versions.env` enthält die dazugehörigen lesbaren Versionen. Gate B akzeptiert keine `latest`-Tags oder Platzhalter.
5. Bitwarden-Erfolg wird über Exitcode, nicht-leere aktuelle `.BAK`, Lesbarkeit aller Pflichtdateien und erfolgreiche Core-Sicherung geprüft – nicht über einen undefinierten Dump-Inhaltsvergleich.
6. Falls die vier Fälle mit den sieben vorgesehenen Primitives nicht ausdrückbar sind, Primitive-Contract und Bedrohungsanalyse jetzt ergänzen, bevor Action-Schemas, Executor- oder Primitive-Go-Code entstehen. Das bereits isolierte Signaturmodul aus Task 8 ist hiervon nicht betroffen.

**Output-Gate:** Vollständige Requirement→Primitive→E2E-Matrix für alle vier Dienste; Security-Review bestätigt, dass keine generische Shell-Primitive benötigt wird.

**Commit:** `docs: freeze reference service execution contracts`

### Task 10B: Action-DSL und kanonische Definition

**Repository:** `MuckiSafeAgentPlugin`

**Files:**
- Create schemas under `resources/schema/`
- Create `src/Service/Action/{ActionValidator,ParameterValidator,CanonicalJsonHasher}.php`
- Create Go counterparts under `agent/internal/action/`
- Create valid/invalid shared fixtures

**RED:** PHP und Go akzeptieren/ablehnen dieselben Fixtures; Hash identisch; Floats, unbekannte Felder, Shell-Primitives, absolute/Traversal-Pfade und untypisierte Parameter rot. Jede in Task 10A belegte Action muss das Schema erfüllen.

**GREEN:** Erst jetzt V1-Schema für den durch Task 10A bestätigten Primitive-Satz einfrieren; kanonischer String wird gespeichert und transportiert.

**Commit:** `feat(actions): define cross-language action schema`

### Task 10C: Authentisierten Hostheartbeat implementieren

**Repository:** `MuckiSafeAgentPlugin`

**Files:**
- Create: `src/Service/Host/HostHeartbeat.php`
- Create: `src/Controller/Api/HostHeartbeatController.php`
- Create: `tests/Integration/Controller/Api/HostHeartbeatControllerTest.php`

**RED:** Die Route `api.action.muwa.agent.v1.host_heartbeat` akzeptiert nur den aus `X-Mucki-Agent-Key` authentisierten Host, `platform="linux"`, erlaubte Architekturen und validierte Capabilities; aktualisiert `version`, `capabilities` und `last_seen_at` atomar; liefert signiert `{serverTime,pollAfterSeconds}`; widerrufene/falsche Hosts, unbekannte Felder sowie 16.384-Byte-Grenze/Grenze+1 schlagen mit dem normativen Fehlerformat fehl. Request-Agent-ID ist nicht zulässig.

**GREEN:** Dünner Controller, zentrale DTO-/Schema-Validierung und HostHeartbeat-Service. Keine Claim- oder Joblogik.

**Commit:** `feat(hosts): record authenticated agent heartbeats`

### Task 11: Minimaler atomarer Claim-Durchstich

**Repository:** `MuckiSafeAgentPlugin`

**Files:**
- Create: `src/Service/Job/JobCreator.php`, `JobClaimer.php`
- Create: `src/Controller/Api/JobClaimController.php`
- Create DTOs under `src/Api/`
- Create: `tests/Integration/Service/Job/ConcurrentClaimTest.php`

**RED:** Zwei echte MySQL-Verbindungen, 100 Konkurrenzversuche, exakt ein Claim; zweiter Parallelclaim desselben Agenten bleibt leer, solange dessen erster Job `claimed|running|cancel_requested` ist; zwei verschiedene Agenten dürfen je einen Job claimen; falscher Ziel-Agent oder inzwischen fehlende materialisierte Capability erhält keinen Job; abgelaufene/deadline Jobs nicht claimbar.

**GREEN:** kurze DBAL-Transaktion und `FOR UPDATE SKIP LOCKED`; zufälliges Claim-Token nur einmal in Response, DB speichert SHA-256.

**Commit:** `feat(jobs): claim targeted jobs atomically`

### Task 12: Lease, Start, Heartbeat und Fencing

**Repository:** `MuckiSafeAgentPlugin`

**Files:**
- Create services/controllers for start and heartbeat
- Create: `tests/Integration/Service/Job/LeaseFencingTest.php`

**RED:** Unveränderliche `phase_deadline`/`execution_deadline`/`stop_deadline` mit den Formeln aus Abschnitt 2.11; 30-Sekunden-Lease ab Claim, gedeckelt durch Execution-Deadline; Start vor Ablauf; Heartbeat alle 10 Sekunden; falscher Agent/Token/Version; alter Worker nach Reclaim; authentisierte `ambiguous`-Completion mappt `running -> unknown` ohne Requeue; `cancel_requested`; Heartbeat bleibt bis Stop-Deadline und Timeout-/Ambiguous-Completion bis Phase-Deadline ohne Lease-Verlängerung gültig, alle anderen Mutationen 409; Grenzzeitpunkte mit injizierter Uhr.

**GREEN:** zentraler Transition-Guard, keine verteilte Statuslogik in Controllern.

**Commit:** `feat(jobs): add leased execution with fencing`

### Task 13: Completion und Output-Tails

**Repository:** `MuckiSafeAgentPlugin`

**Files:**
- Create: `src/Service/Job/JobCompletion.php`, `OutputTailNormalizer.php`
- Create: `src/Controller/Api/JobCompleteController.php`
- Create completion tests and shared fixtures

**RED:** idempotente identische Completion auch beim Crash/Commit-vor-Response; zwei konkurrierende identische Completions liefern denselben Terminalstatus, widersprüchliche Wiederholung 409; vollständige Outcome→Status/Resultcode-Matrix; Erfolg ohne Tails; Fehler max. 65.536 Bytes je Stream; Summary 4.096 Bytes, Resultcode-Allowlist; UTF-8/Base64-Markierung; 262.144-Byte-Bodylimit vor JSON-Dekodierung; Base64-Overhead; zu große dekodierte Streams; `ambiguous` ohne Tails.

**GREEN:** Completion transaktional, Run/Job-Audit ohne Secrets. In derselben Transaktion werden kanonischer Completion-Fingerprint, gebundener Agent, vorheriger Claim-Tokenhash und akzeptierte Lease-Version unveränderlich gespeichert; nur ein identischer authentisierter Retry erhält erneut 200.

**Commit:** `feat(jobs): complete jobs idempotently with bounded diagnostics`

### Task 14: Reaper und Cleanup

**Repository:** `MuckiSafeAgentPlugin`

**Files:**
- Create: `src/Service/Job/JobReaper.php`
- Create: `src/ScheduledTask/AgentCleanupTask.php`, Handler
- Create: `src/Service/Wakeup/WakeupCleaner.php`
- Tests for retry/unknown/deadline/nonces/wakeup age

**RED:** safe-Requeue ausschließlich für claimed/nie gestartete Jobs; niemals Requeue nach `running`; non-retryable unknown; Versuchslimit; atomarer `cancel_requested`-Übergang am Execution-Cutoff; terminal `unknown` ohne Ack bei Phase-Deadline; Cleanup-Batches; keine jungen Wakeups löschen; Fehler-Tails nach 30 Tagen nullen, Summary/Resultcode erhalten.

**Commit:** `feat(jobs): recover expired leases and clean stale state`

### Task 15: Facility-Hook-Konfiguration und Legacy-Guard

**Repository:** `MuckiSafeAgentPlugin`

**Files:**
- Create: `src/Service/Hook/LegacyHookConflictGuard.php`
- Create hook repository/service/controller tests
- Add explicit AgentHost association in Hook entity

**RED:** Repository existiert; genau ein Hook je Phase; Ziel-Agent vorhanden/aktiv/capable; Facility-Legacy-Pre oder -Post blockiert das Speichern; Facility-Werte bleiben unverändert. Der Guard liest an Facility-Revision `1a37677` exakt `BackupRepositoryEntity::getPreBackupCommandType()` und `getPostBackupCommandType()` beziehungsweise die DAL-Felder `preBackupCommandType`/`postBackupCommandType`; nur der Wert `none` gilt als inaktiv.

**GREEN:** Facility-Repository über dessen DAL-Repository lesen, kein Cross-FK. Die Laufzeitprüfung und Subscriber-Priorität folgen in Task 16.

**Commit:** `feat(hooks): configure targeted actions without legacy overlap`

### Task 16: BackupRunCoordinator und drei Subscriber

**Repository:** `MuckiSafeAgentPlugin`

**Files:**
- Create: `src/Service/Hook/BackupRunCoordinator.php`, `HostAgentHookExecutor.php`
- Create: three Facility subscribers
- Create: `tests/Integration/Service/Hook/BackupLifecycleTest.php`

**RED-Matrix:** Erfolg; Pre fail true/false; Corefehler; Postfehler; Finallyfehler; genau ein Finally; Primärfehler bleibt; kein Hook; verwaister Run; Offline-Agent; verlorener Heartbeat; Prozessneustart; harte Pre-/Post-/Finally-Budgetgrenzen mit injizierter Uhr; 15-Sekunden-Abbruchpuffer; Waiter folgt safe Requeue eines nie gestarteten Jobs; kein Requeue nach Start; nach Waiter-Rückkehr beginnt kein neuer Step; Finally erhält trotz ausgeschöpftem Pre/Post sein eigenes Budget.

**GREEN:**
- `FacilityBackupBeforeSubscriber::getSubscribedEvents()` mappt `BeforeBackupCreatedEvent::NAME => ['onBefore', 1000]`; `onBefore()` prüft den Legacy-Konflikt vor Run-Erzeugung und jeder Hostseitenwirkung, dann Run erstellen und Pre ausführen/warten. Der bestehende `BackupCommandSubscriber` bleibt auf Priorität `0`.
- After: Post auf demselben Run ausführen/warten.
- Finished: Finally höchstens einmal, Run terminal abschließen.
- `JobWaiter` setzt die festen Defaults/Maxima aus Abschnitt 2.11 serverseitig durch; Clientwerte können sie nicht erhöhen.

**Commit:** `feat(hooks): coordinate pre post and finally host actions`

### Task 17: WakeupWriter und globaler flacher Pfad

**Repository:** `MuckiSafeAgentPlugin`

**Files:**
- Create: `src/Service/Wakeup/WakeupWriter.php`
- Create: `agent/internal/wakeup/watcher.go`
- Unit tests in PHP/Go

**RED:** temp+rename; UUID-Dateiname; Wakeup erst nach Commit; Duplikat/verlorenes Event; fremde/stale Datei; Poll-Fallback; Agent löscht nichts; simulierter Prozessabbruch nach DB-Commit aber vor Dateierzeugung verliert keinen Job.

**GREEN:** `JobCreator` beendet/committet seine DBAL-Transaktion und gibt erst danach den materialisierten Job zurück; der aufrufende Service ruft dann `WakeupWriter` auf. Es gibt keinen erfundenen DBAL-After-Commit-Hook. Writer-Fehler oder Crash-Gap werden geloggt/über Polling geheilt, der bereits committete Job wird nicht zurückgerollt.

**Commit:** `feat(wakeup): signal committed jobs through flat directory`

### Task 18: Go-Agent-Skeleton und Transport

**Repository:** `MuckiSafeAgentPlugin`

**Files:**
- Create Go config, API client, version and CLI files
- Create `agent/config.example.yaml`
- Unit tests beside packages

**RED:** HTTPS default; Zertifikatprüfung; `server.url` mit HTTP ohne `server.allowInsecureHttp: true` abgelehnt; HTTP mit exakt diesem Flag und deutlicher Startwarnung; keine `skipTlsVerify`-Option; Request-/Response-Signatur; Unknown-Key-401 als einziger akzeptierter unsignierter terminaler Fehler; jede andere unsignierte/ungültige Response wird verworfen; Timeouts; Retry/Backoff/Jitter; `config validate`; `--version`.

**GREEN:** Standardbibliothek plus minimale Abhängigkeiten (`fsnotify`, YAML). Keine Credentials in Logs.

**Verify:**
```bash
cd agent
go test ./... -race -coverprofile=coverage.out
go vet ./...
```

**Commit:** `feat(agent): add signed HTTP polling client`

### Task 19: Journal und Runner

**Repository:** `MuckiSafeAgentPlugin`

**Files:**
- Create: `agent/internal/idempotency/journal.go`
- Create: `agent/internal/agent/{runner,claim_loop,heartbeat}.go`
- Tests for crash states and completion retry

**RED:** started/completed atomar; State-/Elternpfad mit falschem Owner/Mode, Symlink, Hardlink oder beschädigtem Journal wird fail-closed; Datei- und Verzeichnis-fsync; Neustart safe/non-retryable; Hash-/Lease-Mismatch; Completion-Netzfehler führt nicht zu doppelter Hostwirkung; lokale Execution-Deadline überlebt Netzwerkverlust; `stopRequested` verkürzt sie; nach Deadline beginnt kein Step; Timeout wird vor Completion journalisiert; `maxConcurrency=1` lokaler Semaphor; Graceful Shutdown stoppt neue Claims, sendet für laufenden Job weiter Heartbeats, beendet ihn innerhalb seiner Deadline oder des systemd-Stop-Zeitbudgets und persistiert Journal vor Prozessende.

**Commit:** `feat(agent): persist execution state across restarts`

### Task 20: Linux-Executor

**Repository:** `MuckiSafeAgentPlugin`

**Files:**
- Create: `agent/internal/executor/{executor,process_linux,output_tail}.go`
- Create policy loader
- Unit tests

**RED:** Policy/Config/Secret werden aus bereits descriptor-relativ verifizierten FDs geladen; unsichere Owner/Modi, Symlink-/Rename-Tausch und Reload-Rennen schlagen fail-closed fehl; getrenntes argv; ausschließlich lokal fest erlaubte Executables; Environment-Allowlist; Prozessgruppe; monotonic Deadline → SIGTERM → höchstens 5 Sekunden Grace → SIGKILL; Exit/Journal innerhalb des reservierten Puffers; simulierte Netzwerkpartition; chunkübergreifende Redaction und Byte-Tails; keine Shell. Ein D-State-/Killfehler wird `ambiguous`, nicht retrybar.

**Commit:** `feat(agent): execute bounded Linux action steps`

### Task 21: Primitive einzeln implementieren

Je Primitive ein eigener RED-GREEN-Commit:

1. `service.control`
2. `service.wait-http`
3. `docker.container.exec`
4. `filesystem.acl.read`
5. `filesystem.assert-readable`
6. `file.write-pipeline`
7. `file.retention`

**Files:** `agent/internal/primitives/<name>.go` und `<name>_test.go`.

Jeder Test deckt den in Abschnitt 2.7 und Task 10A eingefrorenen Schema-/Policy-Contract, Alias-Auflösung, Erfolgsfall, Fehler, Timeout, Idempotenz und Pfad-/Symlink-Grenzen ab. Pflicht-Negativfälle sind konkurrierender Symlink-Tausch, Magiclink, Traversal, unbekannte Unit/URL/Container/Command, Redirect, Größen-/Objektlimit und beschreibbare Policy-Datei. `file.write-pipeline` testet `renameat2(RENAME_NOREPLACE)`, Zielkollision, zwei konkurrierende Writer sowie Datei- und Verzeichnis-`fsync`. `docker.container.exec` ruft `/usr/bin/docker exec` mit argv auf; gzip-Ausgabe wird in Go erzeugt, nicht über Shell-Pipelines.

**Commits:** `feat(primitives): add <primitive>`

### Task 22: Referenzaction Bitwarden

**Files:** `examples/actions/bitwarden.json`, Policy-Fixture, E2E-Fixture.

**Pre-Steps:** DB-Backup im Container, ACL-Lesegrant, Lesbarkeitsassertion für `.BAK`, `global.override.env`, `identity.pfx` und Data-Protection-Key. Keine Post-/Finally-Action nötig.

**RED:** vollständiger Pre/Core-Ablauf; aktueller `.BAK` existiert und ist nicht leer; alle Pflichtdateien sind für den Backup-Principal lesbar; Fehler bei fehlendem/leeren/veraltetem Dump; Cleanup gemäß Task-10A-Manifest.

**Commit:** `feat(examples): add Bitwarden backup preparation`

### Task 23: Referenzaction Jellyfin

**Pre:** `service.control stop`, ACL, DB-Lesbarkeitsprüfung.

**Finally:** `service.control start`, `service.wait-http` auf `/System/Info/Public`.

**RED:** Corefehler startet Jellyfin trotzdem; Stopfehler führt Best-Effort-Finally aus; idempotentes stop/start.

**Commit:** `feat(examples): add Jellyfin backup lifecycle`

### Task 24: Referenzaction Immich

**Pre:** `docker.container.exec` für `pg_dump --clean --if-exists`, Go-gzip in temporäre Datei, atomarer Rename in `backups/`, Retention nur eigener manueller Dumps, ACL auf konfigurierten Pfad-Aliassen, Lesbarkeitsassertion.

**RED:** `.part` wird bei Fehler entfernt; Dump nicht leer; Pfade bleiben unter Alias; getrennte Immich-Pfade unterstützt.

**Commit:** `feat(examples): add Immich online backup preparation`

### Task 25: Referenzaction Paperless

**Prerequisite:** Der in Task 10A freigegebene Paperless-Vertrag inklusive realer Argumente, Compose-Ziele und Regression für die bekannte `${2}`-Fehlerstelle. Dieser Task darf das eingefrorene Primitive-Schema nicht beiläufig erweitern.

**Target:** Die in Task 10A belegten Hostwirkungen mit den bereits implementierten Container-/ACL-/Retention-Primitives ausdrücken; keine generische Shell-Primitive.

**RED:** Export erfolgreich/fehlgeschlagen, Lesbarkeit, Retention, Wiederholung und Cleanup.

**Commit:** `feat(examples): add Paperless backup preparation`

### Task 26: Administration und ACL

**Repository:** `MuckiSafeAgentPlugin`

**Files:** Administration-Modul unter `src/Resources/app/administration/src/module/muwa-agent/`, Snippets, API-Service.

Vertikale Scheiben:
1. Agent-Liste + Provisionierung/einmalige Anzeige/Widerruf.
2. Action-Liste/-Editor mit Schemafehlern.
3. Hook-Zuordnung mit Facility-Repository und Ziel-Agent.
4. Runs/Jobs read-only mit Fehler-Tails nur für berechtigte Rolle.

**ACL:** Viewer, Editor, Provisioner, Executor; jede Admin-Action erhält serverseitigen Negativtest. Administration-Build ist Pflicht; kein reiner UI-ACL-Schutz.

**Commit je Scheibe:** `feat(admin): ...`

### Task 27: CI und getrennte Releases

**Repository:** `MuckiSafeAgentPlugin`

**Files:** fünf Workflows, `.gitattributes`, Composer archive excludes, Go packaging/systemd, `agent/internal/daemon` mit CLI-Befehl `mucki-agent run`.

Nachtrag bei der Umsetzung: Tasks 18–21 lieferten alle Bausteine, aber keinen Dienst-Einstiegspunkt. `mucki-agent run [--config PATH]` setzt sie zusammen: Config, Secret, Policy und Journal werden vor dem ersten Request verifiziert geladen (sonst Exit 1 ohne Kontakt zur Control Plane), danach Journal-Recovery, Host-Heartbeat, Wakeup-Watcher und Claim-Loop. SIGTERM/SIGINT beendet ohne neue Claims; ein laufender Job wird innerhalb der Shutdown-Grace gestoppt, journalisiert und abgeschlossen (Exit 0). SIGHUP lädt die Policy atomar neu und behält bei Fehlern den bisherigen Snapshot. Ein nicht mehr akzeptierter Schlüssel beendet den Agenten mit Exit 78 (`EX_CONFIG`), ebenso jeder Startabbruch; die Unit verhindert dafür per `RestartPreventExitStatus=78` eine Neustartschleife.

Nachtrag Packaging (vom E2E-Härtungstest unter echtem systemd belegt):
- `ProtectSystem=strict`; beschreibbar sind nur das State-Verzeichnis und die Pfade der lokalen Policy. `filesystem.acl.read` setzt ACLs unterhalb jeder `paths`-Wurzel, Write-Pipeline und Retention schreiben in die `destinations`. `mucki-agent policy writable-paths --format systemd` leitet daraus das Drop-in `mucki-agent.service.d/10-destinations.conf` ab, das `install.sh` bei jedem Lauf neu schreibt.
- Kein `RestrictSUIDSGID`: systemd sperrt damit `openat2()` vollständig (ENOSYS), auf dem jeder descriptor-relative Zugriff des Agenten beruht. Veröffentlichte Backups bleiben über die Policy auf höchstens `0644` begrenzt.
- Artefakttests: `tests/Artifacts/plugin-archive-test.sh` prüft `git archive` (export-ignore) und `composer archive` (`archive.exclude`); `agent/packaging/build-release.sh` baut das Agent-Release-Verzeichnis, `agent/packaging/tests/binary-artifacts-test.sh` prüft es (`file`, `readelf`, `go version -m`, `ldd`, `--version`, `config validate`, `SHA256SUMS`) nativ auf `ubuntu-24.04` und `ubuntu-24.04-arm`.
- `go-tests.yml` führt `go test` als root aus: die Primitive-Tests brauchen `chown`, `setgroups` und `setfsuid` wie der Agent selbst. `composer validate` läuft mit `--no-check-version`, weil `create-release.yml` das `version`-Feld liest.
- Releases: Das Plugin bleibt bei `vX.Y.Z` aus `composer.json` (`create-release.yml` → `plugin-release.yml`: ZIP aus `git archive` mit Archivtest, Packagist, Dispatch `muckisafe-agent-plugin-release` mit `{package, ref}`). Der Agent hat eigene Tags `agent-vX.Y.Z` (`agent-release.yml`: reproduzierbarer Tarball `mucki-agent-X.Y.Z-linux.tar.gz` + `.sha256`, Artefaktprüfung auf frischen amd64-/arm64-Runnern, E2E mit genau diesen Binaries, erst dann das GitHub-Release); Packagist ignoriert diese Tags.
- `e2e-linux.yml` läuft nativ auf `ubuntu-24.04` und `ubuntu-24.04-arm`; der self-hosted Runner `[self-hosted, linux, arm64, muckisafe-e2e]` wird über die Repository-Variable `MUCKISAFE_E2E_ARM64_RUNNER=true` zugeschaltet, sobald er registriert ist (Task 32 ergänzt dort die Dienstszenarien).
- `agent/packaging/tests/hardening-e2e.sh` ist der E2E-Härtungstest (Fake-Control-Plane `agent/internal/e2e/fakeplane`); lokal läuft er per `agent/packaging/tests/run-in-container.sh` in einem privilegierten systemd-Container.

`agent/packaging/install.sh` erzeugt idempotent Config-/State-Verzeichnisse als `root:root` und `/var/lib/mucki-agent/wakeup` als `root:33`, Mode `0770`; es prüft, dass GID 33 zum Container-`www-data`-Vertrag passt, bevor es systemd startet.

Die systemd-Unit setzt mindestens `UMask=0077`, `PrivateTmp=true`, restriktive `ReadWritePaths` nur für State/Wakeup und – soweit mit systemctl/docker/Hostdateien kompatibel – `NoNewPrivileges` sowie begrenzte `RestrictAddressFamilies`/`SystemCallFilter`. Ein E2E-Härtungstest beweist benötigte Funktionen und den Startabbruch bei unsicherer Config-/Secret-/Policy-/State-Datei oder Elternkette.

**RED-Artefakttests:**
- Plugin-ZIP enthält kein `agent/`, keine Go-Dateien und keine Test-/CI-Dateien.
- Agent-Binaries werden explizit mit `CGO_ENABLED=0 GOOS=linux GOARCH=amd64|arm64 -trimpath` gebaut; Prüfsummen vorhanden.
- `file`, `readelf -l/-d` und – auf nativer Architektur – `ldd` weisen weder `INTERP`/`NEEDED` noch dynamische Laufzeitabhängigkeiten nach.
- `--version` und `config validate` laufen im sauberen Runner.
- Plugin- und Agent-Artefakte sind getrennt.

**Workflows:**
- `php-tests.yml`: Shopware 6.6/6.7, PHP 8.3/8.4/8.5 soweit kompatibel; MySQL 8.0/8.4.
- `go-tests.yml`: `go test -race`, `go vet`, Coverage-Artefakt; eigener Graceful-Shutdown-/Signaltest mit laufendem Fake-Prozess und Journalprüfung.
- `e2e-linux.yml`: amd64 nativ auf GitHub-Runner; vollständige arm64-Dienst-E2E auf Runner-Labels `[self-hosted, linux, arm64, muckisafe-e2e]`. QEMU ist nur für ergänzende Smoke-Tests zulässig und ersetzt die native arm64-Abnahme nicht.
- `plugin-release.yml`: ZIP/Packagist und Dispatch `muckisafe-agent-plugin-release` mit `{package, ref}`.
- `agent-release.yml`: Linux-Binaries, Checksummen, Config, systemd, Installer.

**Commit:** `ci: test and release plugin and Linux agent separately`

### Task 28: MuckiSafe-Distributionstest zuerst

**Repository:** `MuckiSafe`

**Files:** Create `tests/agent-plugin-integration-test.sh`.

**RED:** Ausführbarer Test (`100755`) verlangt `AGENT_PLUGIN_VERSION`, Composer-Paket, fail-closed Dispatch/Pinning, genau zwei gleiche Wakeup-Mounts, `MUWA_AGENT_ENCRYPTION_KEYRING`, Env-/README-Doku und Abwesenheit von Go/Agent-Binary im Image. Für `muckisafe-agent-plugin-release` müssen `client_payload.package === "muckiware/mucki-safe-agent-plugin"` und ein gültiger Semver-`ref` vorliegen; fehlendes/falsches Paket oder ungültiger Ref brechen den Workflow ab. Ein positiver Fixture-Test belegt das exakte `AGENT_PLUGIN_VERSION`-Build-Arg.

**Commit:** `test: define agent plugin distribution contract`

### Task 29: AgentPlugin ins MuckiSafe-Image

**Repository:** `MuckiSafe`

**Files:** `docker/Dockerfile`, Publish-Workflow.

**GREEN:** Build-Arg und atomarer Composer-Require; defensives Entfernen lokaler Plugin-Checkouts; Dispatch-Pin; `.muckisafe-versions` nimmt Paket automatisch auf.

**Verify:** Image bauen, `composer show --locked`, Versionsdatei, Plugin-Aktivierung. Dann nachweisen:

```bash
! command -v go
test ! -e /usr/local/bin/mucki-agent
! find /var/www/html/vendor/muckiware/mucki-safe-agent-plugin \
  -type f \( -name '*.go' -o -name go.mod -o -name go.sum -o -name mucki-agent \) \
  -print -quit | grep -q .
```

**Commit:** `build: install MuckiSafeAgentPlugin from Composer`

### Task 30: MuckiSafe-Wakeup und Secrets

**Repository:** `MuckiSafe`

**Files:** `docker-compose.yml`, `.env.example`, README.

**GREEN:**
- `${MUCKI_AGENT_WAKEUP_PATH:-/var/lib/mucki-agent/wakeup}:/var/run/mucki-agent/wakeup` in `app` und `scheduler`.
- `MUWA_AGENT_ENCRYPTION_KEYRING` in beiden Services.
- `install.sh` erzeugt auf dem Host `/var/lib/mucki-agent/wakeup` als `root:33`, Mode `0770`, vor Containerstart; README enthält denselben idempotenten `install -d`-Schritt. Compose selbst wird nicht fälschlich als Besitzer-/Modus-Provisioner behandelt.
- Schreibprobe als `www-data` aus beiden Services.

**Commit:** `feat: mount global host-agent wakeup directory`

### Task 31: Hostinstallation und Distribution-Dokumentation

**Repository:** `MuckiSafe`

**Files:** `README.md`, `docs/host-agent/README.md`.

Dokumentieren: GitHub-Release-Download, SHA-256-Prüfung, systemd, root-owned Config/Secret/State, HTTPS-Default, explizites HTTP, globaler Pfad, kein Go auf Host, Dispatch `muckisafe-agent-plugin-release`.

**Commit:** `docs: document separate MuckiSafe host agent`

### Task 32: Vollständige E2E-Abnahme und Rollout

**Repository:** `MuckiSafeAgentPlugin`; alle folgenden Befehle laufen von dessen Repository-Root. Facility und MuckiSafe werden durch das E2E-Compose als externe Prüflinge auf den freigegebenen Versionen eingebunden.

**Files:**
- Create: `tests/E2E/compose/compose.yaml`
- Create: `tests/E2E/scripts/run-linux-e2e.sh`
- Create: `tests/E2E/scripts/{setup,trigger-backup,inject-failure,assert-effects,cleanup}.sh`
- Create: `tests/E2E/scenarios/{bitwarden,jellyfin,immich,paperless}.sh`
- Create: `tests/E2E/fixtures/{bitwarden,jellyfin,immich,paperless}/`
- Modify: `.github/workflows/e2e-linux.yml`

**Reihenfolge:** Facility `v0.11.0` → AgentPlugin Composer-Release + Agent-Binary → MuckiSafe-Image.

**Pflichtfälle:**
- Bitwarden, Jellyfin, Immich, Paperless jeweils mit den in `tests/E2E/versions.env` und `images.lock` exakt gepinnten Versionen/Digests; kein `latest`, kein unaufgelöster Platzhalter.
- Jeder Dienst beginnt aus dem in Task 10A dokumentierten Startzustand und belegt die erwarteten Hostwirkungen: Bitwarden-Dump/Pflichtdateien lesbar; Jellyfin während Core gestoppt und danach wieder healthy; Immich atomarer nicht-leerer komprimierter Dump plus Retention/ACL; Paperless-Export plus Retention/ACL. Jedes Fixture prüft anschließend seinen dokumentierten Cleanup- und Endzustand.
- Pre-, Core-, Post- und Finally-Fehler.
- Agent offline, API offline, verlorener/duplizierter Wakeup, Agent-Neustart, Lease-Ablauf, alter Worker, Completion-Retry.
- HTTP(S)-Signaturen in beide Richtungen; HTTP-Test bestätigt zusätzlich die sichtbare Warnung über mitlesbare Actions, Parameter und Fehler-Tails.
- Linux amd64 nativ und vollständige arm64-Abnahme auf dem in Task 27 festgelegten nativen self-hosted Runner.
- `docker compose pull && docker compose up -d` erhält Host-Binary, Config, Secret, Journal und Wakeup-Pfad.
- Facility-only Shop ohne AgentPlugin bleibt vollständig grün.

Jedes Szenario startet seinen gepinnten Dienstzustand aus `images.lock`, provisioniert Hostpolicy/Agent, triggert den realen Backup-Pfad, injiziert nacheinander die festgelegten Pre/Core/Post/Finally-/Netzwerk-/Leasefehler, prüft die dienstspezifischen Wirkungen und führt den dokumentierten Cleanup bis zum definierten Endzustand aus. Der Orchestrator schreibt JUnit-XML, Container-/Agentlogs, Run-/Jobstatus und Dateimanifeste als CI-Artefakte.

**Verify:**
```bash
tests/E2E/scripts/run-linux-e2e.sh --arch amd64 --scenario bitwarden
tests/E2E/scripts/run-linux-e2e.sh --arch amd64 --scenario jellyfin
tests/E2E/scripts/run-linux-e2e.sh --arch amd64 --scenario immich
tests/E2E/scripts/run-linux-e2e.sh --arch amd64 --scenario paperless
# dieselben vier Befehle mit --arch arm64 auf [self-hosted, linux, arm64, muckisafe-e2e]
```

Gate E akzeptiert ausschließlich erfolgreiche reale Ausgaben aller vier Szenarien auf beiden nativen Architekturen; vorhandene Fixtures oder eine manuell abgehakte Checkliste genügen nicht.

Erst danach alte service-spezifische systemd.path-Kits deaktivieren; zunächst als Rollback erhalten.

Nachtrag bei der Umsetzung (2026-11-25):
- **Bitwarden nur amd64.** Das gepinnte `mcr.microsoft.com/mssql/server:2022-CU18-ubuntu-22.04` ist ausschließlich für linux/amd64 veröffentlicht. Entscheidung: Bitwarden wird nativ nur auf amd64 abgenommen; der arm64-Lauf meldet einen begründeten Skip (JUnit `skipped`). Gate E verlangt damit amd64 für alle vier und arm64 für Jellyfin, Immich und Paperless. QEMU bleibt ausgeschlossen.
- **Erst bauen, dann pinnen.** Zum Umsetzungszeitpunkt fehlten ein Agent-Release `agent-vX.Y.Z` und ein MuckiSafe-Image mit AgentPlugin. `run-linux-e2e.sh` kennt daher `--muckisafe-mode source|release` und `--agent-mode source|release`: Source baut das gepinnte `MUCKISAFE_BASE_IMAGE` (v0.2.5, Facility 0.11.1) plus Plugin aus `git archive` per Composer-Path-Repository bzw. den Agenten aus `agent/`; Release nutzt `MUWA_E2E_MUCKISAFE_IMAGE` (tag@sha256) und den Release-Tarball. Gate E akzeptiert nur Release/Release-Läufe.
- **Datei-Repositories genügen für Gate E** (Entscheidung 2026-11-26). Der Harness legt ausschließlich Facility-Repositories vom Typ `noneDatabase` an. Der typisierte Datenbank-Export (z.B. Paperless per `mysqldump` aus dem Scheduler) ist nicht Teil der Agent-Abnahme; Core-Fehler werden generisch über ein nicht beschreibbares restic-Repository erzeugt (`core_break`), auch für den Paperless-Fall „export fails in the core backup“.
- **System under test** ist MuckiSafes eigenes `docker-compose.yml` am Commit `MUCKISAFE_COMPOSE_REF` plus `tests/E2E/compose/compose.yaml` (Image, Repository-Verzeichnis, Scheduler minütlich, Caddy-TLS-Proxy mit lokaler CA im Host-Trust-Store – der Agent spricht standardmäßig HTTPS ohne TLS-Skip).
- **HTTP-Warnung:** Die vorhandene WARN-Zeile des API-Clients nennt jetzt ausdrücklich mitlesbare Actions, Parameter, Ergebnisse und Fehlerausgaben; der Transport-Schritt der E2E prüft genau diesen Text im Journal.
- **Struktur:** generische Resilienzfälle (Agent offline/zurück und über die Deadline, API offline, verlorener/duplizierter Wakeup, Agent-Neustart, Lease-Ablauf mit altem Worker, Completion-Retry, Core-Fehler) liegen in `scripts/inject-failure.sh` und laufen pro Szenario mit dessen Erfolgsfall; deterministisches Timing liefert `scenario_stall`. Schnittstelle und Ablauf: `tests/E2E/README.md`; `scripts/selftest.sh` prüft Pins, Schnittstelle, Fallabdeckung, den Bitwarden-Skip und den Dry-Run statisch (auch auf macOS).
- Pull Requests führen nur Selftest und Hardening-E2E aus; die Dienst-E2E laufen auf dev/main, manuell (optional mit `agent-version`/`muckisafe-image`) und aus dem Agent-Release; jeder Lauf schreibt `run-info.json` (Modi, Image-Digest, Agent-Version, Tarball-SHA, `gateE`).
- **Lokaler Stack-Smoke (macOS, Docker Desktop)** gegen MuckiSafes echtes Compose am gepinnten Commit belegte: Plugin-Aktivierung im Source-Image, Admin-API, Provisionierung, Caddy-HTTPS, Wakeup-Mounts, echtes Facility-Backup mit Snapshot sowie Hook → Job → Deadline → `timed_out`/`pre_hook_failed`/Core übersprungen. Dabei gefunden und behoben: (1) **Produktfehler `WakeupWriter`**: Der Error-Handler wandelte auch die PHP-8.4-Deprecation beim ersten Logger-Autoload in eine `ErrorException`, sodass ein nicht beschreibbares Wakeup-Verzeichnis das ganze Backup abbrach; jetzt nur `E_WARNING|E_NOTICE` und Logging nach `restore_error_handler()` (Regressionstest). (2) Frische Facility ist deaktiviert (`MuckiFacilityPlugin.config.active`), `backup:create` endet dann still mit Exit 0. (3) Reine Datei-Repositories brauchen Typ `noneDatabase`.

### Task 33: Gestufter Produktiv-Rollout und kontrollierter Rollback

**Reihenfolge und Abnahmekriterien:**
1. Agent-Binary/Policy installieren, Agent provisionieren, aber noch keine Facility-Hooks zuordnen.
2. Mindestens 24 Stunden Heartbeats beobachten: keine Auth-/Replayfehler, keine unerwarteten Restarts, stabile Uhrabweichung innerhalb ±60 Sekunden und keine offenen Jobs.
3. Genau ein nicht kritisches Testrepository auf Agent-Hooks migrieren; Legacy-Hooks dafür erst nach erfolgreicher Konfliktprüfung deaktivieren.
4. Mindestens einen vollständigen Pre/Core/Post/Finally-Lauf und einen absichtlich injizierten Fehlerlauf prüfen; Jobs/Runs müssen terminal sein und Hostendzustand dem Manifest entsprechen.
5. Erst danach weitere Repositories einzeln migrieren; nach jeder Migration mindestens einen vollständigen Lauf abwarten.

**Rollback:**
- Neue Hook-Erzeugung deaktivieren und keine weiteren Jobs claimen lassen.
- `queued`/nie gestartete `claimed` Jobs atomar auf `timed_out` setzen; `running` Jobs auf `cancel_requested` setzen und bis Timeout-/Ambiguous-Completion oder Phase-Deadline warten.
- Rollback darf erst fortfahren, wenn die Abfrage `claimed|running|cancel_requested` exakt null Jobs liefert; `unknown` wird manuell bewertet und nicht automatisch wiederholt.
- Agent-Hook-Zuordnungen entfernen, anschließend die zuvor unverändert erhaltenen Legacy-Hooks/systemd.path-Kits reaktivieren und einen Backup-Lauf verifizieren.
- Agent-Binary, Config und Journal zunächst behalten; Deinstallation ist ein separater letzter Schritt nach erfolgreichem Legacy-Lauf.

**Tests/Runbook:** Admin/API-Integrationstest für „keine offenen Jobs“, dokumentiertes SQL/API-Readback und ein durchgespielter Rollback im Linux-E2E-Fixture.

**Commit:** `docs: add staged rollout and controlled rollback runbook`

Nachtrag bei der Umsetzung (2026-11-26): Die Rollback-Schritte brauchten Servercode, den es noch nicht gab.
- **Globaler, umkehrbarer Rollback-Schalter** in der eigenen Einzeilentabelle `muwa_agent_rollback` (Migration `1795478400`, Uninstall entfernt sie). Solange er aktiv ist, erzeugt `BackupRunCoordinator` weder Run noch Job (Facility-Backups laufen wie ohne AgentPlugin, reaktivierte Legacy-Hooks sind kein Konflikt), und `JobClaimer` schließt im selben SQL jeden Claim aus. Ein bereits offener Run überspringt seine restlichen Phasen (deren Jobs würden nie geclaimt) und endet mit Primärfehler `rollback_active` – post/finally entfallen dann, der Hostzustand ist manuell zu prüfen.
- **Atomarität (Review):** `JobCreator` liest den Schalter in seiner Transaktion mit `FOR SHARE`, `start()` schreibt ihn exklusiv – Schalter und Job-Erzeugung sind serialisiert, bei aktivem Rollback entsteht kein Job (`RollbackActiveException`, der Coordinator überspringt die Phase mit `rollback_active`). `start()` markiert zusätzlich alle offenen Runs mit `rollback_active`, damit ein Run mit übersprungenen Phasen nie `succeeded` schließt. Eine fehlende Zustandszeile ist ein Fehler, nie „inaktiv“ (fail-closed). Migration unmittelbar mit jedem Deploy ausführen.
- **Bekannte Protokollfolge:** Meldet der Agent nach einem Drain noch `succeeded`/`failed` (Aktion war vor dem Stopp fertig), lehnt der Server das in `cancel_requested` ab; der Job endet an der Phase-Deadline `unknown` und wird im Runbook manuell bewertet.
- **Abweichung von „Legacy-Hooks unverändert erhalten“:** Facility löscht beim Setzen auf `none` Kommando und Argumente. Das Runbook sichert sie daher vor Stufe 3 per SQL-Snapshot und stellt sie beim Rollback exakt daraus wieder her.
- **`JobDrainer`** nutzt dieselben atomaren Übergänge wie der Reaper, nur sofort: `queued`/nie gestartete `claimed` → `timed_out` mit `result_code=rollback` und widerrufenem Claim; `running` → `cancel_requested` (Agent erhält `stopRequested` über den Lease-Heartbeat). `unknown`, terminale und `cancel_requested` Jobs bleiben unberührt. Wiederholtes Starten drainiert nachträglich erzeugte Jobs und behält die erste Startzeit.
- **Bedienung:** `GET /api/_action/muwa/agent/rollback` (Rolle viewer) als Gate „keine offenen Jobs“ (`readyForHookRemoval`), `POST …/rollback/start|stop` mit neuer Berechtigung `muwa_agent.rollback`; `bin/console muwa:agent:rollback start|stop|status [--wait=SECONDS] [--json]` (Exit 0 bereit, 3 offene Jobs, 2 Usage); Admin-Tab „Rollback“.
- Tests: Drain/Schalter/Claim-Sperre (`AgentRollbackTest`), Coordinator bei aktivem und mitten im Backup gestartetem Rollback (`BackupLifecycleTest`), Admin-API inkl. ACL und Gate (`RollbackControllerHttpTest`), Console (`RollbackCommandTest`), Uninstall; durchgespielter Rollback als E2E-Schritt `rollback` (Rollback mitten in einem laufenden Job → Stopp im Agent-Journal → Gate `status --wait` → kein Run/Job bei aktivem Schalter, aber Facility-Snapshot → Hooks entfernen → `stop` → Backup ohne Agent; danach Hooks wiederherstellen). Abweichung: Die E2E kennt keine Legacy-Hooks/systemd.path-Kits; der „Legacy-Lauf“ ist dort ein reines Facility-Backup ohne Agent-Hooks. Das Reaktivieren echter Legacy-Kits und die Deinstallation werden im Runbook (`docs/rollout-runbook.md`) beschrieben und in Stufe 3/Rollback auf dem Produktivhost geprüft.

## 5. Standard-Gates pro Task

1. Failing Test schreiben und ausführen; Fehler muss fachlich erwartet sein.
2. Minimale Implementierung.
3. Zieltest grün.
4. Vollständige betroffene Suite grün.
5. `git diff --check`, statische Analyse/Lint.
6. Unabhängiger Spec-Review gegen diesen Task.
7. Unabhängiger Security-/Quality-Review.
8. Erst danach Commit; keine Sammelcommits über mehrere Tasks.

PHP:

```bash
composer validate --strict
composer test:unit
composer test:integration
composer test
composer run-script phpstan
```

Go:

```bash
cd agent
go test ./... -race -coverprofile=coverage.out
go vet ./...
```

MuckiSafe:

```bash
bash -n tests/*.sh docker/*.sh
./tests/agent-plugin-integration-test.sh
./tests/ssh-keys-test.sh
./tests/ssh-integration-test.sh
docker compose --env-file .env.example config
```

## 6. Nicht-Ziele von V1

- macOS/launchd; folgt in Ausbaustufe 2.
- Windows.
- generische Shell-/PowerShell-Ausführung.
- implizite Kompensation beliebiger bereits ausgeführter Steps.
- überlappende Secret-Rotation mit current/next Credentials.
- genau-einmal garantierte externe Hostwirkung.
- frei wählbare Agenten nur anhand von Capabilities.
- Go-Toolchain oder Agent-Binary im MuckiSafe-Image.

## 7. Plan-Gates

- **Eingangsgate:** Task 0 abgeschlossen, Dokumenttest und unabhängiger Spec-Review grün.
- **Gate A:** Tasks 1–4 grün, Facility `v0.11.0` veröffentlicht.
- **Gate B:** Tasks 5–14 grün, Control-Plane-Protokoll stabil.
- **Gate C:** Tasks 15–21 grün, Facility-Integration und Linux-Agent vollständig.
- **Gate D:** Tasks 22–27 grün, vier Referenzfälle, Administration und Releases vorhanden.
- **Gate E:** Tasks 28–32 grün, MuckiSafe-Distribution real verifiziert.
- **Gate F:** Task 33 vollständig protokolliert, 24-Stunden-Heartbeat-Fenster und Testrepository erfolgreich, Rollbackprobe ohne offene Jobs bestanden.

## 8. Definition of Done

- Design und Implementierungsplan sind getrennte Dokumente.
- Facility enthält nur den neutralen Lifecycle-Contract und bleibt ohne AgentPlugin vollständig kompatibel.
- AgentPlugin besitzt ausschließlich eigene Tabellen/ACL/API/Admin und keine Cross-Plugin-FKs.
- Request und Response sind auch bei explizitem HTTP manipulationsgeschützt; HTTP bleibt als nicht vertraulich dokumentiert.
- Ziel-Agent, Claim, Lease, Fencing, Replay und Completion sind durch echte Konkurrenztests belegt.
- `maxConcurrency=1` ist serverseitig und im Go-Agenten belegt; Lease 30 Sekunden/Heartbeat 10 Sekunden sowie Cancellation-/Graceful-Shutdown-Verhalten sind in PHP-, Go- und E2E-Tests konsistent.
- Secrets werden einmal gezeigt und authentisiert verschlüsselt gespeichert.
- Erfolgreiche Jobs speichern keine Output-Tails; Fehler höchstens 64 KiB je Stream.
- Globaler flacher Wakeup-Pfad funktioniert aus `app` und `scheduler`; Polling bleibt korrekt ohne Wakeup.
- Go- und PHP-Unit-/Integrationstests laufen in CI grün.
- Bitwarden, Jellyfin, Immich und Paperless bestehen reale Linux-E2E-Tests auf amd64 und arm64.
- Plugin-ZIP und MuckiSafe-Image enthalten weder Go-Quellen, Go-Toolchain noch Agent-Binary.
- Produktivhost benötigt nur das geprüfte GitHub-Release-Binary, Config, einmaliges Secret, Policy und State-Verzeichnis.
- Rollout und Rollback sind ohne automatische Veränderung bestehender Facility-Legacy-Hooks möglich; 24-Stunden-Beobachtung, Testrepository und Null-Offene-Jobs-Gate sind nachweisbar abgeschlossen.
