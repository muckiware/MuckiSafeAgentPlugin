# MuckiSafeAgentPlugin and Host Agent Design

> **Dokumentstatus:** Dieses Dokument beschreibt Architektur, Produktgrenzen und Entscheidungen. Der alleinige normative V1-Ausführungs-, Protokoll-, Zustands- und Testvertrag steht in [`implementation-plan.md`](implementation-plan.md). Bei abweichenden technischen Details gilt der Implementierungsplan.

**Goal:** Im Repository `muckiware/MuckiSafeAgentPlugin` ein eigenständiges Shopware-Plugin und einen als Release-Binary ausgelieferten Linux-Agenten bauen, die privilegierte Pre-, Post- und Finally-Aktionen für MuckiSafe-Backups zuverlässig auf dem Host ausführen, ohne normale Installationen des `MuckiFacilityPlugin` mit Agent-Funktionen zu belasten. macOS folgt in Ausbaustufe 2.

**Architecture:** `MuckiFacilityPlugin` bleibt das allgemeine Backup-Plugin. `MuckiSafeAgentPlugin` ist eine optionale Erweiterung und besitzt sämtliche Agent-Tabellen, Admin-Oberflächen, Action-Definitionen, Hook-Zuordnungen, Job-Queue und Agent-API. PHP-Plugin und Go-Agent-Quellcode liegen im selben Repository, bleiben aber logisch getrennte Control- und Execution-Plane. GitHub Actions kompiliert statische Linux-Binaries; Zielhosts und das MuckiSafe-Image benötigen weder Go noch ein Go-Runtime-Paket. HTTPS ist der sichere Default, HTTP kann bewusst aktiviert werden. Für ein garantiertes `finally` erhält Facility den freigegebenen neutralen `BackupFinishedEvent`-Lifecycle-Hook.

**Tech Stack:** Shopware 6.6/6.7, PHP 8.3–8.5, PHPUnit, MySQL 8.4, Go 1.24+ ausschließlich für Entwicklung und GitHub CI/Release-Builds, Go-Unit-Tests, HTTP(S)/JSON, HMAC-SHA256, `fsnotify` und systemd. launchd/macOS folgt in Ausbaustufe 2.

---

## 1. Produkt- und Repository-Grenzen

### 1.1 MuckiFacilityPlugin – bleibt allgemein

Repository: `muckiware/MuckiFacilityPlugin`

Verantwortung:

- Restic-Repositories, Dumps, Datei-Backups, Restore, Schedules und Checks.
- Bestehende `BeforeBackupCreatedEvent`- und `AfterBackupCreatedEvent`-Extension-Points.
- Bestehende Batch-/PHP-Hooks bleiben für normale Shop-Installationen erhalten.
- Keine Agent-Entities, keine Agent-API, keine Agent-Administration, keine Semaphoren und keine Go-Agent-Abhängigkeit.

Einzige geplante Änderung:

- ein neutrales `BackupFinishedEvent`, das in jedem Ausgang – Erfolg und Fehler – ausgelöst wird;
- eine generische Fehlerpriorität, damit ein Fehler eines Finished-Subscribers einen vorhandenen Backup-Primärfehler nicht überschreibt.

Diese Erweiterung ist keine MuckiAgent-Funktion, sondern ein allgemeiner Lifecycle-Extension-Point. Ohne eine solche Änderung ist ein garantiertes `finally` aus einem separaten Plugin nicht zuverlässig möglich. Eine Service-Dekoration von `BackupExecution` wäre technisch möglich, aber stark an private Implementierungsdetails gekoppelt und würde das Finally erst nach Freigabe des globalen Locks ausführen; sie wird deshalb verworfen.

### 1.2 MuckiSafeAgentPlugin – optionale Control Plane

Repository: `muckiware/MuckiSafeAgentPlugin`

Composer-Paket: `muckiware/mucki-safe-agent-plugin`

Plugin-Klasse: `MuckiSafeAgentPlugin\MuckiSafeAgentPlugin`

Verantwortung:

- Host-Agenten provisionieren, sperren und überwachen;
- deklarative Actions und Parameterschemata verwalten;
- Facility-Repositories mit Pre/Post/Finally-Actions verknüpfen;
- BackupRuns und HostJobs persistieren;
- Jobs atomar claimen, leasen und abschließen;
- Semaphoren als unverbindliche Wakeups schreiben;
- eigene Administration, ACL, API, Audit und Cleanup;
- Facility-Lifecycle-Events abonnieren.

Abhängigkeit:

```json
{
  "require": {
    "shopware/core": "~6.6.10.14 || ~6.7.6",
    "muckiware/facility-plugin": "^0.11"
  }
}
```

Die genaue Mindestversion wird durch die Facility-Version mit `BackupFinishedEvent` bestimmt. Aktivierung des Agent-Plugins muss abbrechen, wenn Facility nicht installiert und aktiv ist.

### 1.3 MuckiAgent – Execution Plane im selben Repository

Quellpfad: `muckiware/MuckiSafeAgentPlugin/agent/`

Binary: `mucki-agent`

Verantwortung:

- ausgehendes HTTP(S)-Polling mit HTTPS als Default;
- Semaphore-Wakeup;
- Claim, Lease, Heartbeat und Completion;
- lokale Alias-Policy;
- deklarative Primitives;
- Timeouts, Prozessgruppen, Output-Limits und lokales Idempotenzjournal;
- systemd-Supervision in V1; launchd folgt in Ausbaustufe 2.

Der Go-Agent kennt weder Shopware-DAL noch die MuckiSafe-Datenbank. Er wird von GitHub Actions kompiliert und als Release-Binary für `linux/amd64` und `linux/arm64` veröffentlicht. Auf Produktivhosts wird nur das Binary installiert; Go ist dort keine Abhängigkeit.

### 1.4 MuckiSafe-Distribution

Repository: `muckiware/MuckiSafe`

Verantwortung:

- `MuckiSafeAgentPlugin` zusätzlich zum Facility-Plugin in das MuckiSafe-Image einbauen;
- Wakeup-Bind-Mount und Environment dokumentieren;
- Version des Agent-Plugins im Build und in `.muckisafe-versions` ausweisen;
- Go-Agent nicht kompilieren oder ins Containerimage legen – das vorgebaute GitHub-Release-Binary wird separat auf dem Host installiert.

Normale Shopware-Installationen können weiterhin ausschließlich `MuckiFacilityPlugin` installieren.

## 2. Festgelegte Entscheidungen

1. **Transport:** Dedizierte MuckiSafeAgentPlugin-HTTP(S)-API mit atomarem Claim, Lease, Heartbeat und Completion. HTTPS ist Default; HTTP ist über eine explizite Produktionskonfiguration zulässig. Semaphore nur als Wakeup.
2. **Aktionsmodell:** Action-ID, deklarative Definition und typisierte JSON-Parameter. Kein frei ausführbarer Shell-/PowerShell-Longtext.
3. **Rechte:** Der Linux-Agent läuft in V1 vollständig als `root` unter systemd. macOS/launchd folgt in Ausbaustufe 2.
4. **Phasen:** V1 unterstützt `pre`, `post` und garantiertes `finally`.
5. **Plugin-Trennung:** Agent-Funktionalität lebt ausschließlich im `MuckiSafeAgentPlugin`; Facility bleibt ohne Agent-Abhängigkeit.
6. **Legacy-Neubewertung:** Die frühere Entscheidung „Batch-/PHP-Hooks sofort entfernen“ gilt nur für MuckiSafe-Installationen, die auf Agent-Hooks migriert wurden. Die Hooks werden nicht aus `MuckiFacilityPlugin` entfernt, weil normale Shops sie weiterhin verwenden können. `MuckiSafeAgentPlugin` verbietet eine gemischte Konfiguration pro Repository.
7. **Repository:** PHP-Plugin und Go-Agent-Quellcode liegen gemeinsam in `muckiware/MuckiSafeAgentPlugin`.
8. **Facility-Lifecycle:** `BackupFinishedEvent` wird im Facility-Plugin ergänzt.
9. **Provisionierung:** Die Administration erzeugt ein einmalig angezeigtes Agent-Secret; Rotation erzeugt ein neues einmalig angezeigtes Secret.
10. **Wakeup:** Ein globaler, flacher Hostpfad ohne Instanz-Unterordner.
11. **Actions:** Pro Phase genau eine Action; eine Action darf mehrere geordnete Steps enthalten.
12. **Plattformen:** V1 Linux; macOS ist Ausbaustufe 2.
13. **V1-Abnahme:** Bitwarden, Jellyfin, Immich und Paperless sind verpflichtende reale Referenzfälle.
14. **API:** Regulärer Betrieb bevorzugt über HTTPS; explizit konfiguriertes HTTP bleibt unterstützt.
15. **Resultatdaten:** stdout/stderr werden nur bei Fehlern gespeichert, jeweils als maximal 64-KiB-Tail.
16. **Go-Abhängigkeit:** Go ist nur für lokale Entwicklung und GitHub CI/Release-Builds erforderlich, niemals für MuckiSafe-Image oder Produktivhost.

## 3. Recherche: Build-vs-Buy

| Kandidat | Eignung | Entscheidung |
|---|---|---|
| Rundeck Enterprise Runner | Technisch nahezu passend, aber kommerziell und mit eigener Plattform. | Nicht übernehmen. |
| OliveTin | Schlanker Go-Executor mit definierten Aktionen, aber ohne Pull-Queue, Claim/Lease und MuckiSafe-Integration. | Sicherheitsmodell als Referenz nutzen, nicht als Runtime-Abhängigkeit. |
| Salt, Nomad, Ansible, CI-Runner | Funktionsreich, aber deutlich zu schwer oder als Root-Shell-Executor nicht sicherer. | Verwerfen. |
| `adnanh/webhook` | Klein, aber Push statt Pull und keine durable Jobsemantik. | Verwerfen. |
| systemd.path / launchd | Geeignet für Supervision/Wakeup, nicht als Control Plane. | Nur zur Agent-Supervision verwenden. |
| NATS JetStream | Gute Queue, aber zusätzlicher Broker und eigener Executor weiterhin nötig. | V1 YAGNI. |

**Ergebnis:** Kein schlanker fertiger OSS-Ansatz erfüllt die Anforderungen. Eigenbau mit zwei logisch getrennten Laufzeitkomponenten in einem gemeinsamen AgentPlugin-Repository ist angemessen.

## 4. Plugin-übergreifender Lifecycle

### 4.1 Bestehender Facility-Ablauf

`MuckiFacilityPlugin\Services\Backup::createBackup()` dispatcht derzeit:

1. `BeforeBackupCreatedEvent`
2. Backup-Kern
3. `AfterBackupCreatedEvent`
4. Dauer-Speicherung in `finally`

`AfterBackupCreatedEvent` wird bei Pre-, Dump-, Restic-, Check-, Snapshot- oder Stats-Fehlern nicht erreicht. Ein Agent-Plugin kann daher mit den vorhandenen Events kein garantiertes Finally implementieren.

### 4.2 Minimale Facility-Erweiterung

Neue Datei:

- `MuckiFacilityPlugin/src/Events/BackupFinishedEvent.php`

Eventdaten:

- `BackupRepositorySettings`
- Backup-Ergebnisse
- `?Throwable` als Primärfehler; der normative Contract verwendet dieselbe Throwable-Instanz
- boolescher Kern-Erfolgsstatus

Änderung:

- `MuckiFacilityPlugin/src/Services/Backup.php::createBackup()`

Zielstruktur:

```php
$primaryError = null;
try {
    // bestehendes Before, Backup, After unverändert
} catch (\Throwable $exception) {
    $primaryError = $exception;
} finally {
    try {
        $eventDispatcher->dispatch(new BackupFinishedEvent(...));
    } catch (\Throwable $finishedError) {
        if ($primaryError === null) {
            $primaryError = $finishedError;
        } else {
            // loggen, Primärfehler nicht ersetzen
        }
    }
    try {
        $repositoryStats->saveBackupDuration(...);
    } catch (\Throwable $durationError) {
        if ($primaryError === null) {
            $primaryError = $durationError;
        } else {
            // loggen; Primär-/Finished-Fehler nicht ersetzen
        }
    }
}
if ($primaryError !== null) {
    throw $primaryError;
}
```

Wichtig:

- Existing Before/After-Namen und Semantik bleiben kompatibel.
- Facility kennt keine Agent-Klassen.
- Ohne Subscriber verursacht das neue Event nur einen EventDispatcher-Aufruf.
- Ein Finished-Subscriber darf einen erfolgreichen Backup-Lauf fehlschlagen lassen, aber nie einen vorhandenen Primärfehler überschreiben.

Tests im Facility-Plugin:

- `tests/Services/LifecycleEventDispatchTest.php`
- `tests/Services/BackupTest.php`

Neue Fälle:

- Finished nach Erfolg;
- Finished nach Before-Fehler;
- Finished nach Backup-Kernfehler;
- Finished-Fehler bei erfolgreichem Kern wird geworfen;
- Finished-Fehler überschreibt Kernfehler nicht;
- Laufzeit wird weiterhin immer gespeichert.

### 4.3 MuckiSafeAgentPlugin-Subscriber

Neue Subscriber:

- `Subscriber/FacilityBackupBeforeSubscriber`
- `Subscriber/FacilityBackupAfterSubscriber`
- `Subscriber/FacilityBackupFinishedSubscriber`

Ablauf:

- Before: Agent-Konfiguration prüfen, BackupRun anlegen, Pre-Job erstellen und abwarten.
- After: aktiven BackupRun finden, Post-Job erstellen und abwarten.
- Finished: aktiven BackupRun finden, Finally-Job immer erstellen und abwarten; Runstatus abschließen.

Korrelation:

- Facility hat weiterhin einen globalen Backup-Lock; dadurch gibt es pro MuckiSafe-Instanz nur einen aktiven Backup-Lauf.
- `MuckiSafeAgentPlugin` persistiert beim Before-Event den aktiven Run anhand Facility-Repository-ID und Status.
- After/Finished lösen den aktiven Run über Repository-ID auf.
- Ein datenbankseitiger Active-Guard verhindert mehrere aktive Runs global.
- Nach Prozessabbruch markiert ein Reaper verwaiste Runs und Jobs sichtbar als `unknown`/`expired`.

## 5. Konfliktregel mit Facility-Legacy-Hooks

Facility behält seine Batch-/PHP-Hooks. Für ein Repository gilt in V1 jedoch genau eines:

- Facility Legacy-Hooks oder
- MuckiSafeAgentPlugin-Hooks.

Regeln:

1. Beim Speichern einer Agent-Hook-Zuordnung liest MuckiSafeAgentPlugin die Facility-Repository-Einstellungen.
2. Ist Pre oder Post dort nicht `none`, verweigert MuckiSafeAgentPlugin die Aktivierung und zeigt die betroffenen Felder an.
3. Beim Before-Event wird dieselbe Prüfung erneut durchgeführt, bevor irgendeine Agent- oder Host-Seitenwirkung beginnt.
4. Der Agent-Before-Subscriber erhält höhere Priorität als `BackupCommandSubscriber`, sodass ein Konflikt vor Legacy-Ausführung abbricht.
5. Das Agent-Plugin verändert oder löscht Facility-Legacy-Werte niemals automatisch.
6. Nach bewusster Migration setzt der Administrator die Facility-Hooks auf `none` und aktiviert anschließend Agent-Hooks.

So bleiben normale Facility-Shops unverändert, während MuckiSafe keine Doppel-Ausführung riskiert.

## 6. Zielbild und Kontrollfluss

```text
MuckiFacilityPlugin              MuckiSafeAgentPlugin                  MuckiAgent (root)
───────────────────              ────────────────                  ─────────────────
BeforeBackupCreatedEvent ──────► BackupRun + Pre-Job
                                 Wakeup-Datei atomar ────────────► fsnotify
                                                                   POST /api/_action/muwa/agent/v1/jobs/claim
                                 ◄──────────────────────────────── Job + Claim-Token
                                                                   Action ausführen
                                 ◄──────────────────────────────── Heartbeat/Complete
                                 Jobterminalstatus abwarten
Backup-Kern
AfterBackupCreatedEvent ───────► Post-Job analog
BackupFinishedEvent ───────────► Finally-Job immer
                                 BackupRun abschließen
Facility speichert Dauer
```

Der Agent pollt unabhängig von Semaphoren regelmäßig, empfohlen alle 10 Sekunden.

## 7. Sicherheitsmodell

### 7.1 Vertrauensgrenzen

- Nur MuckiSafeAgentPlugin speichert Agent-Actions und -Jobs.
- Facility-Repository-, DB- und S3-Secrets werden nicht kopiert.
- Agent erhält keinen direkten MySQL-Zugang.
- Semaphore enthält ausschließlich Job-UUID/Protokollversion.
- Das einmalig angezeigte Credential liegt root-owned (`0600`) auf dem Host. Serverseitig wird der für HMAC benötigte Schlüssel verschlüsselt gespeichert; der Verschlüsselungsschlüssel liegt außerhalb der Datenbank.
- Keine generische Shell-Primitive in V1.
- HTTPS ist der Standard und wird für alle nicht vollständig vertrauenswürdigen Netze dringend empfohlen.
- HTTP ist kein reiner Testmodus: Es kann explizit für isolierte LAN-, Docker-, VPN- oder vergleichbar kontrollierte Installationen aktiviert werden.
- Bei HTTP bleiben HMAC, Timestamp, Nonce und Replay-Schutz zwingend. Sie schützen Integrität und Authentizität, aber nicht die Vertraulichkeit beobachtbarer Requests und Responses.
- Der Agent verweigert eine `http://`-URL nur dann, wenn `allowInsecureHttp` nicht explizit gesetzt ist; bei aktivem HTTP protokolliert er bei jedem Start eine deutliche Warnung ohne Credentials.

### 7.2 Agent-Authentisierung

- versionierte Agent-API unter `/api/_action/muwa/agent/v1/`;
- Request- und Response-HMAC-SHA256 mit 256-Bit-Secret unabhängig vom Transport;
- kanonische Basisstrings, Header, DTOs, Bodylimits und der eng begrenzte unsignierte Unknown-Key-Fall gemäß Implementierungsplan;
- HTTPS als Default und empfohlener Produktionsmodus;
- HTTP nur mit `server.allowInsecureHttp: true`; die Option ist regulär dokumentiert und nicht auf Tests oder Debug-Builds beschränkt;
- Zeitfenster ±60 Sekunden;
- Nonce-Deduplizierung;
- Provisionierung erzeugt genau ein zufälliges 256-Bit-Secret, das nur in der unmittelbaren Bestätigungsansicht angezeigt und danach nicht mehr ausgelesen werden kann;
- Rotation ersetzt das alte Secret atomar und widerruft es sofort; ein current/next-Umschaltmodell ist kein Bestandteil von V1;
- Widerruf bleibt jederzeit ohne Kenntnis des Secrets möglich;
- keine normalen Admin-OAuth-Tokens für Agent-Routen.

### 7.3 Root-Agent-Härtung

- kein `sh -c`, `bash -c`, `eval`;
- `exec.CommandContext` mit getrenntem argv und absoluten Executable-Pfaden;
- minimale Environment-Allowlist;
- lokale Alias-Policy für Container, Services, Pfade und URLs;
- Action-/Parameterprüfung im Plugin und erneut im Agenten;
- Prozessgruppe, SIGTERM, Grace Period, SIGKILL;
- stdout/stderr begrenzen und redigieren;
- `maxConcurrency=1` als feste V1-Grenze, serverseitig und lokal erzwungen;
- lokales Idempotenzjournal;
- Binary, Config und Journal root-owned.

## 8. Deklaratives Aktionsmodell

### 8.1 Action-Definition

`muwa_agent_action.definition` enthält eine versionierte, validierte JSON-DSL mit geordneten typisierten Steps. Actiondaten referenzieren ausschließlich lokale Aliase; frei wählbare Executables, Container, systemd-Units, numerische Principals, absolute Pfade und Shell-Argumenttexte sind nicht zulässig. Die lokale root-owned Policy löst Aliase auf exakte Ziele und Grenzen auf. Verbindliche Schemas, Primitive-Parameter und descriptor-relative Linux-Dateisicherheit stehen im Implementierungsplan.

MuckiSafeAgentPlugin sendet nur Aliase und validierte Parameter.

### 8.2 V1-Primitives

1. `service.control`
2. `service.wait-http`
3. `docker.container.exec`
4. `filesystem.acl.read`
5. `filesystem.assert-readable`
6. `file.write-pipeline`
7. `file.retention`

Keine generische `command.exec`-Primitive. Später allenfalls lokal definierte Command-Aliase.

### 8.3 Validierung

- versioniertes Schema;
- unbekannte Primitive/Parameter ablehnen;
- kanonischer SHA-256-Hash der Definition;
- Job materialisiert Definition, Parameter und Hash;
- spätere Action-Änderungen verändern laufende Jobs nicht;
- gemeinsame PHP-/Go-Fixtures.

## 9. Datenmodell ausschließlich im MuckiSafeAgentPlugin

Tabellenpräfix: `muwa_agent_*`.

### 9.1 `muwa_agent_host`

- `id`, `name`, `enabled`
- `key_id` UNIQUE
- `secret_ciphertext`, `secret_nonce`, `secret_key_version`
- `capabilities JSON`
- `version`, `last_seen_at`, `revoked_at`
- Zeitstempel

### 9.2 `muwa_agent_action`

- `id`, `technical_name` UNIQUE, `label`, `enabled`
- `definition LONGTEXT`, `definition_hash`
- `parameter_schema LONGTEXT`
- `required_capabilities JSON`
- Default-/Max-Timeout
- `retry_policy`, `idempotency_class`
- Zeitstempel

### 9.3 `muwa_agent_repository_hook`

- `id`
- `facility_repository_id BINARY(16)` – logische Referenz, **kein Cross-Plugin-FK**
- `phase` (`pre`, `post`, `finally`)
- `agent_host_id` FK, RESTRICT; genau ein Ziel-Agent pro Hook
- `agent_action_id` FK, RESTRICT
- `parameters LONGTEXT`
- `timeout_seconds`, `fail_on_error`, `enabled`
- UNIQUE `(facility_repository_id, phase)`
- Zeitstempel

Kein DB-FK auf `muwa_backup_repository`: Er würde Uninstall-/Update-Reihenfolgen zwischen Plugins koppeln. Repository-Existenz wird bei Save und Lauf über Facility-Service/DAL geprüft; ein Cleanup entfernt verwaiste Zuordnungen.

### 9.4 `muwa_agent_backup_run`

- `id`
- `facility_repository_id` ohne Cross-Plugin-FK
- `source`, `idempotency_key` UNIQUE, `status`
- `started_at`, `finished_at`
- `primary_error_code`, `primary_error_summary`
- Zeitstempel

### 9.5 `muwa_agent_job`

- `id`
- `backup_run_id` FK, CASCADE
- `agent_action_id` FK, RESTRICT
- `facility_repository_id`
- `phase`, `action_definition`, `action_hash`, `payload`
- `idempotency_key` UNIQUE
- `status`, `target_agent_id`, `claim_token_hash`, `lease_version`
- `attempt`, `max_attempts`
- `available_at`, `phase_deadline`, `execution_deadline`, `stop_deadline`
- `claimed_at`, `lease_expires_at`, `heartbeat_at`, `finished_at`
- kanonischer Completion-Fingerprint sowie unveränderliche Auditfelder für gebundenen Agenten, vorherigen Claim-Tokenhash und akzeptierte Lease-Version
- `result_code`, `result_summary`, nullable `stdout_tail`, `stderr_tail`
- Zeitstempel

`stdout_tail` und `stderr_tail` bleiben bei Erfolg `NULL`. Nur echte Fehler-/Timeout-Ergebnisse dürfen je Stream maximal die letzten 64 KiB enthalten; `expired` und `unknown` erhalten keine alten Tails. Der Agent redigiert bekannte sensitive Werte vor der Übertragung, der Server begrenzt erneut und löscht Tails nach der normativen Retention.

Indizes:

- `(status, available_at, execution_deadline)`
- `(target_agent_id, status, lease_expires_at)`
- `(backup_run_id, phase)`
- generated Active-Agent-Guard für genau einen aktiven Job je Agent

### 9.6 `muwa_agent_nonce`

- `agent_id`, `nonce`, `expires_at`
- UNIQUE `(agent_id, nonce)`
- Cleanup per Scheduled Task

## 10. Agent-API im MuckiSafeAgentPlugin

Dedizierte, versionierte Action-Routen:

- `POST /api/_action/muwa/agent/v1/heartbeat`
- `POST /api/_action/muwa/agent/v1/jobs/claim`
- `POST /api/_action/muwa/agent/v1/jobs/{jobId}/start`
- `POST /api/_action/muwa/agent/v1/jobs/{jobId}/heartbeat`
- `POST /api/_action/muwa/agent/v1/jobs/{jobId}/complete`

Claim-Regeln:

- Agent aktiv und nicht widerrufen;
- Job `queued`, verfügbar und nicht abgelaufen;
- Capabilities passend;
- Ziel-Agent entspricht der authentisierten Key-ID und besitzt keinen aktiven Job (`maxConcurrency=1`);
- Versuchslimit nicht überschritten;
- Claim, Tokenhash, Lease-Version und Status in einer Transaktion/CAS.

Lease:

- Default 30 Sekunden;
- Heartbeat alle 10 Sekunden;
- Heartbeat nur bei Agent + Claim-Token + Lease-Version;
- abgelaufene Lease fenced den alten Worker;
- `non_retryable` wird `unknown`, nicht automatisch wiederholt;
- `safe` darf nur neu gequeued werden, solange der Job nie `running` war;
- laufende Jobs beachten signierte Execution-Deadlines, Cancellation und den reservierten Abbruchpuffer aus dem Implementierungsplan.

Completion:

- idempotent;
- falscher/abgelaufener Claim: `409`;
- bei Erfolg keine stdout-/stderr-Daten übertragen oder speichern;
- bei Fehlern stdout und stderr jeweils als maximal 64-KiB-Tail übertragen und speichern;
- keine Prozessumgebung oder Secrets.

## 11. Administration im MuckiSafeAgentPlugin

Eigenes Modul `Mucki Agent`, keine Änderungen am Facility-Adminmodul erforderlich.

Bereiche:

1. **Agenten:** Status, Version, letzte Sichtung, Capabilities, Provisionierung, Rotation, Widerruf.
2. **Actions:** technische ID, Definition, Parameterschema, Timeout, Retry, Idempotenzklasse.
3. **Repository-Hooks:** Facility-Repository auswählen, Pre/Post/Finally konfigurieren, Parameter validieren.
4. **Jobs/Runs:** Status, Dauer, Agent, Phase, Ergebnis, begrenzte Logs.

Facility-Repositories werden über die vorhandene Entity/API gelesen. Das Agent-Plugin definiert eigene ACLs:

- `muwa_agent.viewer`
- `muwa_agent.editor`
- `muwa_agent.provisioner`
- `muwa_agent.executor`

Konflikte mit Legacy-Hooks werden beim Speichern und beim Before-Event geprüft.

## 12. Repositorystruktur von MuckiSafeAgentPlugin

Die folgende Struktur ist illustrativ; exakte Dateien und Pfade stehen im Implementierungsplan.

```text
MuckiSafeAgentPlugin/
├── composer.json
├── src/MuckiSafeAgentPlugin.php
├── src/Migration/
├── src/Core/Content/AgentHost/
├── src/Core/Content/AgentAction/
├── src/Core/Content/AgentRepositoryHook/
├── src/Core/Content/AgentBackupRun/
├── src/Core/Content/AgentJob/
├── src/Controller/AgentApiController.php
├── src/Controller/AgentAdminController.php
├── src/Services/Auth/AgentAuthenticator.php
├── src/Services/Auth/ReplayGuard.php
├── src/Services/Job/JobClaimer.php
├── src/Services/Job/JobCompletion.php
├── src/Services/Job/JobReaper.php
├── src/Services/Job/JobWaiter.php
├── src/Services/Action/ActionValidator.php
├── src/Services/Action/ParameterValidator.php
├── src/Services/Hook/LegacyHookConflictGuard.php
├── src/Services/Hook/HostAgentHookExecutor.php
├── src/Services/Wakeup/WakeupWriter.php
├── src/Subscriber/FacilityBackupBeforeSubscriber.php
├── src/Subscriber/FacilityBackupAfterSubscriber.php
├── src/Subscriber/FacilityBackupFinishedSubscriber.php
├── src/Resources/config/services.xml
├── src/Resources/app/administration/src/module/muwa-agent/
├── tests/Unit/
├── tests/Integration/
├── agent/go.mod
├── agent/cmd/mucki-agent/main.go
├── agent/internal/api/client.go
├── agent/internal/api/signing.go
├── agent/internal/agent/claim_loop.go
├── agent/internal/agent/heartbeat.go
├── agent/internal/config/config.go
├── agent/internal/executor/executor.go
├── agent/internal/executor/process_unix.go
├── agent/internal/idempotency/journal.go
├── agent/internal/policy/aliases.go
├── agent/internal/primitives/service_control.go
├── agent/internal/primitives/service_linux.go
├── agent/internal/primitives/docker_exec.go
├── agent/internal/primitives/filesystem_acl_linux.go
├── agent/internal/primitives/file_pipeline.go
├── agent/internal/wakeup/watcher.go
├── agent/packaging/systemd/mucki-agent.service
├── agent/config.example.yaml
├── agent/install.sh
├── .github/workflows/php-tests.yml
├── .github/workflows/go-tests.yml
└── .github/workflows/agent-release.yml
```

## 13. Go-Agent-Build und Auslieferung

- Go-Toolchain nur in lokalen Entwicklungsumgebungen und GitHub Actions.
- `go test ./... -race` läuft in `go-tests.yml` bei jedem relevanten Push und Pull Request.
- `agent-release.yml` cross-kompiliert bei Tags statische `linux/amd64`- und `linux/arm64`-Binaries mit reproduzierbaren Flags.
- Release enthält Binary, SHA-256-Prüfsummen, Beispielkonfiguration, systemd-Unit und Installationsskript.
- Installationsskript lädt das passende Release-Binary, prüft die Prüfsumme und installiert es root-owned.
- Kein `go`, `go.mod`, Compiler oder Go-Cache wird in das MuckiSafe-Dockerimage oder auf den Zielhost installiert.
- Ausbaustufe 2 ergänzt `darwin/amd64`, `darwin/arm64`, launchd und macOS-spezifische Primitive/Tests.

Agentconfig:

```yaml
server:
  url: https://backup.example.org
  allowInsecureHttp: false
  keyId: agent-linux-01
  secretFile: /etc/mucki-agent/secret
agent:
  pollInterval: 10s
  heartbeatInterval: 10s
  leaseDuration: 30s
  maxConcurrency: 1
  wakeupDirectory: /var/lib/mucki-agent/wakeup
  stateDirectory: /var/lib/mucki-agent/state
policies:
  containers:
    immich-db: immich_postgres
  services:
    jellyfin: jellyfin
  paths:
    immich-data: /srv/immich/upload
```

Installation ohne TLS in einem kontrollierten Netz:

```yaml
server:
  url: http://muckisafe.internal:8080
  allowInsecureHttp: true
  keyId: agent-linux-01
  secretFile: /etc/mucki-agent/secret
```

Validierungsregeln:

- `https://` funktioniert ohne Zusatzoption;
- `http://` funktioniert nur mit `allowInsecureHttp: true`;
- unbekannte Schemes, URL-Credentials und URLs ohne Host werden abgelehnt;
- TLS-Zertifikatsprüfung bleibt bei HTTPS aktiv; eine allgemeine `skipTlsVerify`-Option ist nicht Bestandteil von V1;
- HMAC-Authentisierung ist bei HTTP und HTTPS identisch und niemals abschaltbar.

## 14. Semaphore/Wakeup

- MuckiSafeAgentPlugin schreibt `<job-uuid>.ready.tmp` und benennt atomar nach `.ready` um.
- Inhalt nur Job-ID und Protokollversion.
- Agent überwacht per `fsnotify` und claimt anschließend über den konfigurierten HTTP(S)-Transport.
- API-Polling bleibt aktiv.
- Stale Wakeups werden bereinigt.
- Ein einziger globaler, flacher Hostpfad ersetzt alle service- und instanzspezifischen systemd.path-Units.

Linux:

- Host: `/var/lib/mucki-agent/wakeup`
- Container: `/var/run/mucki-agent/wakeup`
- Dateinamen: `<job-uuid>.ready`; Job-UUIDs verhindern Namenskollisionen ohne Unterordner.
- Mehrere Container dürfen denselben Hostpfad einbinden. Zusätzliche Wakeups sind unschädlich, weil jeder Agent nur über seine konfigurierte API claimt.

macOS/Docker Desktop – Ausbaustufe 2:

- frei konfigurierbarer nativer Hostpfad;
- derselbe Pfad wird in App und Scheduler gemountet;
- launchd überwacht nur den Agentprozess, nicht jede Action.

## 15. MuckiSafe-Distribution

Änderungen ausschließlich in `muckiware/MuckiSafe`:

- `docker/Dockerfile`: `muckiware/mucki-safe-agent-plugin` zusätzlich installieren;
- Version-Build-Arg und `.muckisafe-versions` ergänzen;
- `.github/workflows/docker-publish.yml`: Release-Dispatch des Agent-Plugins berücksichtigen;
- `docker-compose.yml`: Wakeup-Bind-Mount in `app` und `scheduler`;
- `.env.example`: `MUCKI_AGENT_WAKEUP_PATH`;
- `README.md` und `docs/host-agent/`: einmalige Agentinstallation und Provisionierung.
- Im Docker-Build keine Go-Toolchain installieren und keinen Agenten kompilieren.

Normale Facility-Installationen außerhalb MuckiSafe sind davon nicht betroffen.

## 16. Implementierungsphasen

### Phase 0: Lifecycle- und Protocol-Spike

1. Facility-`BackupFinishedEvent` mit Fehlerpriorität als isolierten TDD-Spike bauen.
2. Beweisen, dass Facility ohne AgentPlugin unverändert sichert.
3. Bitwarden, Jellyfin, Immich und Paperless als deklarative Actions modellieren.
4. HMAC-Testvektoren PHP/Go erstellen.
5. MySQL-Claim mit zwei konkurrierenden Clients testen.
6. `fsnotify` über den globalen flachen Linux-Docker-Bind-Mount testen.

Exit:

- Facility-Testmatrix grün ohne AgentPlugin;
- Finished bei jedem Ausgang;
- vier Referenzfälle ohne generische Shell darstellbar;
- 100 konkurrierende Claims vergeben einen Job genau einmal.

### Phase 1: Neues MuckiSafeAgentPlugin scaffolden

1. Repository `muckiware/MuckiSafeAgentPlugin` mit PHP-Plugin und Verzeichnis `agent/` anlegen;
2. Aktivierungscheck auf Facility;
3. eigene Migrationen und DAL-Definitionen TDD;
4. Uninstall mit/ohne `keepUserData` testen;
5. PHPUnit-Unit- und Integration-Teststruktur plus `php-tests.yml` anlegen;
6. keine Facility-Datei außer dem neutralen Lifecycle-Event ändern.

### Phase 2: Go-Agent-Skeleton

1. Go-Modul unter `agent/` und Unit-Teststruktur anlegen;
2. Config und Dateirechteprüfung;
3. signierter API-Client mit HTTPS-Default und explizit aktivierbarem HTTP;
4. Claim-Schleife mit Backoff/Jitter;
5. fsnotify + Poll-Fallback;
6. lokales Idempotenzjournal;
7. Executor mit Timeout/Prozessgruppen/Output-Limit;
8. systemd-Packaging;
9. `go-tests.yml` und `agent-release.yml` für Linux amd64/arm64;
10. jede Go-Komponente testgetrieben mit Unit-Tests implementieren.

### Phase 3: Agent-Control-Plane

1. einmalig angezeigtes Secret, Rotation und Widerruf;
2. HMAC-Authentisierung und Replay-Guard;
3. Claim/Start/Heartbeat/Completion;
4. Lease-Reaper;
5. Agent- und Job-Administration.

### Phase 4: Action-DSL

1. versioniertes Schema;
2. PHP-/Go-Validator mit gemeinsamen Fixtures;
3. sieben V1-Primitives;
4. lokale Alias-Policy;
5. Action-Editor und Parameterprüfung.

### Phase 5: Facility-Integration im AgentPlugin

1. Before/After/Finished-Subscriber;
2. BackupRun-Korrelation;
3. Pre/Post/Finally-Fehlersemantik;
4. Legacy-Konfliktguard;
5. WakeupWriter;
6. eigenes Repository-Hook-Adminmodul.
7. Bitwarden-, Jellyfin-, Immich- und Paperless-Actions mit jeweils einer mehrstufigen Action pro Phase.

### Phase 6: MuckiSafe-Distribution

1. AgentPlugin ins Image;
2. Wakeup-Mount;
3. vorkompiliertes Linux-Agent-Binary aus GitHub Release separat auf dem Linux-Host installieren, niemals ins MuckiSafe-Image kopieren;
4. vier Referenzactions konfigurieren;
5. Erfolg und alle Fehlerpfade E2E;
6. `docker compose pull && up -d` ohne Verlust der Hostkonfiguration beweisen.

## 17. Tests

### Facility-Plugin

- PHPUnit-Unit-Tests für Fehlerpriorität und Eventdaten;
- PHPUnit-Integrationstests für den neuen Finished-Lifecycle;
- AgentPlugin nicht installiert: alle bestehenden Tests unverändert grün;
- Legacy-Batch-/PHP-Hooks funktionieren weiter;
- Finished-Subscriber-Fehler priorisiert korrekt.

Verifikation: vorhandener Facility-PHPUnit-Befehl plus gezielter Lauf der neuen Lifecycle-Testklasse; beide müssen ohne AgentPlugin grün sein.

### MuckiSafeAgentPlugin

- PHPUnit-Unit-Tests unter `tests/Unit/` für Authentisierung, Signaturprüfung, Replay-Guard, Action-/Parameter-Validator, Hook-Fehlersemantik, Output-Tail-Begrenzung und Wakeup-Dateinamen;
- PHPUnit-Integrationstests unter `tests/Integration/` für DAL, Migrationen, Indizes, verschlüsselte Secret-Speicherung und ApiAware-/WriteProtected-Regeln;
- Facility-Aktivierungsabhängigkeit;
- logische Repository-Referenz und Orphan-Cleanup;
- Legacy-Konflikt blockiert vor Seitenwirkung;
- paralleler Claim genau einmal;
- Capability-Mismatch;
- Lease/Fencing/Heartbeat;
- idempotente Completion;
- Nonce-Replay und Widerruf;
- Pre/Core/Post/Finally-Matrix;
- Finally überschreibt Primärfehler nicht;
- Timeout gibt Facility-Backup-Lock frei;
- bei Erfolg bleiben Output-Tails `NULL`, bei Fehlern werden je Stream nur die letzten 64 KiB gespeichert;
- AgentPlugin-Uninstall verändert keine Facility-Daten;
- `.github/workflows/php-tests.yml` führt Unit- und Integrationstests in der unterstützten PHP-/Shopware-Matrix aus.

Lokale Standardbefehle, die als Composer-Skripte angelegt werden:

```bash
composer test:unit
composer test:integration
composer test
```

### Go-Agent

- Go-Unit-Tests direkt neben den Packages (`*_test.go`) für Config, HMAC-Testvektoren, API-Client, Claim-Schleife, Executor, Policy, Primitive, Journal und Wakeup-Watcher;
- HTTPS-Default und Zertifikatsprüfung;
- Startfehler bei HTTP ohne `allowInsecureHttp`;
- erfolgreicher HTTP-Betrieb mit `allowInsecureHttp: true` und sichtbarer Startwarnung;
- identische HMAC-/Replay-Prüfung über HTTP und HTTPS;
- Backoff/Jitter;
- Prozessgruppen-Kill;
- Output-Limit und Environment-Allowlist;
- lokale Alias-Policy;
- Idempotenzjournal;
- Completion-Retry ohne Wiederholung;
- Semaphore-Verlust/-Duplikat;
- Polling ohne Semaphore;
- Graceful Shutdown;
- Race Detector und Coverage-Artefakt in GitHub Actions.

Verifikation aus dem Repository-Root:

```bash
cd agent
go test ./... -race -coverprofile=coverage.out
go vet ./...
```

Beide Befehle müssen vor Merge grün sein. Die Release-Workflow-Binaries werden zusätzlich in einem sauberen Linux-Runner mit `--version` und `config validate` ausgeführt.

### E2E

- Linux amd64/arm64;
- systemd Restart;
- Bitwarden, Jellyfin, Immich, Paperless;
- API-Ausfall, Agent-Neustart, Lease-Verlust, verlorene Semaphore;
- Facility-only Shop ohne AgentPlugin;
- MuckiSafe mit Facility + AgentPlugin.

### Ausbaustufe 2: macOS

- macOS amd64/arm64 Unit-, Integrations- und E2E-Tests;
- launchd Restart;
- macOS-spezifische Service- und ACL-Primitives;
- dieselben vier Referenzdienste soweit auf macOS technisch vorhanden, ansonsten äquivalente Fixtures.

## 18. Rollout

1. neutrales Finished-Event in Facility veröffentlichen;
2. MuckiSafeAgentPlugin gegen diese Facility-Version veröffentlichen;
3. vorkompiliertes MuckiAgent-Release-Binary installieren und das einmalig angezeigte Secret konfigurieren;
4. 24 Stunden Leerlauf/Heartbeat beobachten;
5. ein Testrepository migrieren;
6. Facility-Legacy-Hooks dort manuell auf `none` setzen;
7. Agent-Hooks aktivieren;
8. Bitwarden, Jellyfin, Immich und Paperless nacheinander migrieren;
9. alte service-spezifische Kits erst nach erfolgreichen E2E-Läufen vom Host entfernen.

Rollback:

- neue Agent-Hooks/Claims stoppen;
- queued und nie gestartete claimed Jobs terminalisieren, running Jobs kontrolliert canceln;
- erst bei exakt null `claimed|running|cancel_requested`-Jobs auf Legacy-Hooks zurückschalten;
- `unknown` manuell bewerten und nie automatisch wiederholen;
- Facility-Legacy-Hooks bleiben unverändert gespeichert und werden erst nach kontrolliertem Agent-Abbruch bewusst reaktiviert.

## 19. Risiken

| Risiko | Gegenmaßnahme |
|---|---|
| AgentPlugin koppelt Facility zu stark | Nur öffentliche Facility-Events/Services verwenden; eigene Tabellen und UI; kein Cross-Plugin-FK. |
| Garantiertes Finally braucht Facility-Änderung | Ein neutrales Finished-Event als allgemeine Extension API; keine Agent-Klasse in Facility. |
| Legacy und Agent laufen doppelt | Konfliktprüfung beim Save und im Before-Subscriber mit hoher Priorität. |
| Root-RCE über Action-Definition | Keine Shell, versionierte Primitive, lokale Alias-Policy, doppelte Validierung. |
| Doppelte Jobwirkung | atomarer Claim, Lease/Fencing, lokales Journal, idempotente Completion. |
| Agent stirbt nach Hostwirkung | Journal; nur Completion wiederholen. |
| Facility-Repository wird gelöscht | keine Cross-FK; Orphan-Cleanup und Laufzeitvalidierung. |
| AgentPlugin wird deinstalliert | nur eigene Tabellen löschen; Facility-Daten und Backups unverändert. |
| API nicht erreichbar | harte Deadlines, Completion-Puffer, deterministischer Jobstatus. |

## 20. Freigabestatus

Die Produkt- und Architekturentscheidungen sind beantwortet. Verbindliche technische Konkretisierungen, Task-Reihenfolge und Gates stehen ausschließlich im Implementierungsplan.

## 21. Definition of Done

- Facility-only Shops erhalten keine Agent-Tabellen, Agent-UI, Agent-Routen oder Go-Abhängigkeit.
- Facility bleibt mit bestehenden Legacy-Hooks funktionsfähig.
- MuckiSafeAgentPlugin kann unabhängig installiert/deinstalliert werden und verändert keine Facility-Daten.
- Ein Host braucht nur MuckiAgent, Credential, Alias-Policy und einen Wakeup-Mount.
- Neue Backup-Actions benötigen keine systemd.path-Sonderunits und keine Container-Shellskripte.
- Pre/Post/Finally funktionieren deterministisch.
- `docker compose pull && up -d` verändert Agentbinary, Config, Credential und Journal nicht.
- Weder MuckiSafe-Image noch Linux-Produktivhost enthalten oder benötigen eine Go-Toolchain; Releases werden ausschließlich von GitHub Actions kompiliert.
- PHP-Unit-/Integrationstests und Go-Unit-Tests inklusive Race Detector laufen in GitHub Actions grün.
- Keine Facility-/DB-/S3-Secrets erreichen Agent oder Logs.
- Doppelte Wakeups/API-Retries erzeugen keine doppelte Hostwirkung.
- HTTPS funktioniert ohne Zusatzfreigabe; HTTP funktioniert nur mit der dokumentierten expliziten Option und behält HMAC-/Replay-Schutz vollständig bei.
- Erfolgreiche Jobs speichern keine Output-Tails; fehlgeschlagene Jobs höchstens 64 KiB je Stream.
- Bitwarden, Jellyfin, Immich und Paperless bestehen reale E2E-Tests.
