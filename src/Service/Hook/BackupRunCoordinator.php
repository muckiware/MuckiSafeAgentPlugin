<?php declare(strict_types=1);
/**
 * MuckiSafeAgentPlugin
 *
 * @category   SW6 Plugin
 * @package    MuckiSafeAgent
 * @copyright  Copyright (c) 2026 by Muckiware
 * @license    MIT
 * @author     Muckiware
 *
 */
namespace MuckiSafeAgentPlugin\Service\Hook;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Log\LoggerInterface;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Uuid\Uuid;

use MuckiSafeAgentPlugin\Core\Defaults as PluginDefaults;
use MuckiSafeAgentPlugin\Core\Job\JobPhase;

/**
 * Correlates one Facility backup with one agent BackupRun and its
 * pre/post/finally host actions.
 *
 * - before: without enabled agent hooks nothing happens (normal Facility
 *   shops keep their legacy hooks). Otherwise the legacy conflict is checked
 *   before the run is created and before any host side effect, then pre runs.
 * - after: post runs on the active run of the repository.
 * - finished: finally runs at most once with its own budget, then the run
 *   is closed exactly once: failed (a primary error is recorded), unknown
 *   (a job ended ambiguous) or succeeded.
 *
 * The first recorded primary error is never replaced. A failing hook with
 * failOnError is thrown into the Facility lifecycle; otherwise it is logged
 * and tolerated.
 */
final class BackupRunCoordinator
{
    /** @param (\Closure(): \DateTimeImmutable)|null $clock */
    public function __construct(
        protected LoggerInterface $logger,
        private readonly Connection $connection,
        private readonly LegacyHookConflictGuard $legacyGuard,
        private readonly HostAgentHookExecutor $executor,
        private readonly ?\Closure $clock = null
    ) {
    }

    public function before(string $repositoryId): void
    {
        $hooks = $this->enabledHooks($repositoryId);
        if ($hooks === []) {
            return;
        }
        try {
            $this->legacyGuard->assertNoConflict($repositoryId, Context::createDefaultContext());
        } catch (HookConfigurationException $exception) {
            throw new AgentHookFailure(AgentHookFailure::LEGACY_HOOK_CONFLICT, $exception->getMessage(), $exception);
        }
        $runId = $this->openRun($repositoryId);
        $this->runPhase($runId, $hooks, JobPhase::Pre);
    }

    public function after(string $repositoryId): void
    {
        $hooks = $this->enabledHooks($repositoryId);
        $runId = $this->activeRun($repositoryId);
        if ($runId === null) {
            if ($hooks !== []) {
                throw new AgentHookFailure(AgentHookFailure::RUN_MISSING, 'No active agent backup run for the repository.');
            }

            return;
        }
        $this->runPhase($runId, $hooks, JobPhase::Post);
    }

    public function finished(string $repositoryId, ?\Throwable $primaryError): void
    {
        $runId = $this->activeRun($repositoryId);
        if ($runId === null) {
            return;
        }
        if ($primaryError !== null && !$primaryError instanceof AgentHookFailure) {
            // Only the class name: Facility messages may contain paths or command output.
            $this->recordPrimaryError($runId, 'backup_failed', (new \ReflectionClass($primaryError))->getShortName());
        }
        try {
            $this->runPhase($runId, $this->enabledHooks($repositoryId), JobPhase::Finally);
        } finally {
            $this->closeRun($runId);
        }
    }

    /** @param array<string, ConfiguredHook> $hooks */
    private function runPhase(string $runId, array $hooks, JobPhase $phase): void
    {
        $hook = $hooks[$phase->value] ?? null;
        if ($hook === null) {
            return;
        }
        $outcome = $this->executor->execute($hook, $runId);
        if ($outcome->succeeded()) {
            return;
        }
        $failure = AgentHookFailure::phaseFailed($phase->value, $outcome->status);
        if (!$hook->failOnError) {
            $this->logger->warning($failure->getMessage() . ' (tolerated, failOnError=false)', PluginDefaults::DEFAULT_LOGGER_CONFIG);

            return;
        }
        $this->logger->error($failure->getMessage(), PluginDefaults::DEFAULT_LOGGER_CONFIG);
        $this->recordPrimaryError($runId, $failure->errorCode, $failure->getMessage());

        throw $failure;
    }

    /**
     * A still running run at backup start belongs to a crashed process. It is
     * closed as `unknown` only when none of its jobs is active anymore; a run
     * with an active job may still have host side effects in flight, so the
     * new backup fails closed instead (the reaper ends that job by its phase
     * deadline at the latest).
     */
    private function openRun(string $repositoryId): string
    {
        $now = $this->storageNow();
        $orphans = $this->connection->executeStatement(
            'UPDATE `muwa_agent_backup_run` r SET r.`status` = \'unknown\', r.`finished_at` = :now, '
            . 'r.`primary_error_code` = COALESCE(r.`primary_error_code`, \'orphaned_run\'), r.`updated_at` = :now '
            . 'WHERE r.`status` = \'running\' AND NOT EXISTS (SELECT 1 FROM `muwa_agent_job` j '
            . 'WHERE j.`backup_run_id` = r.`id` AND j.`status` IN (\'queued\', \'claimed\', \'running\', \'cancel_requested\'))',
            ['now' => $now]
        );
        if ($orphans > 0) {
            $this->logger->warning('Closed ' . $orphans . ' orphaned agent backup run(s) as unknown.', PluginDefaults::DEFAULT_LOGGER_CONFIG);
        }

        $runId = Uuid::randomHex();
        try {
            $this->connection->insert('muwa_agent_backup_run', [
                'id' => Uuid::fromHexToBytes($runId),
                'facility_repository_id' => Uuid::fromHexToBytes($repositoryId),
                'source' => 'facility',
                'idempotency_key' => Uuid::randomHex(),
                'status' => 'running',
                'started_at' => $now,
                'created_at' => $now,
            ]);
        } catch (UniqueConstraintViolationException $exception) {
            throw new AgentHookFailure(
                AgentHookFailure::AGENT_RUN_ACTIVE,
                'Another agent backup run still has an active job.',
                $exception
            );
        }

        return $runId;
    }

    private function activeRun(string $repositoryId): ?string
    {
        $runId = $this->connection->fetchOne(
            'SELECT LOWER(HEX(`id`)) FROM `muwa_agent_backup_run` '
            . 'WHERE `status` = \'running\' AND `facility_repository_id` = UNHEX(:repositoryId)',
            ['repositoryId' => $repositoryId]
        );

        return is_string($runId) ? $runId : null;
    }

    private function recordPrimaryError(string $runId, string $code, string $summary): void
    {
        $this->connection->executeStatement(
            'UPDATE `muwa_agent_backup_run` SET `primary_error_code` = :code, `primary_error_summary` = :summary, '
            . '`updated_at` = :now WHERE `id` = UNHEX(:id) AND `primary_error_code` IS NULL',
            ['code' => $code, 'summary' => mb_substr($summary, 0, 1024), 'now' => $this->storageNow(), 'id' => $runId]
        );
    }

    private function closeRun(string $runId): void
    {
        $now = $this->storageNow();
        $this->connection->executeStatement(
            'UPDATE `muwa_agent_backup_run` r SET r.`status` = CASE '
            . 'WHEN r.`primary_error_code` IS NOT NULL THEN \'failed\' '
            . 'WHEN EXISTS (SELECT 1 FROM `muwa_agent_job` j WHERE j.`backup_run_id` = r.`id` AND j.`status` = \'unknown\') '
            . 'THEN \'unknown\' ELSE \'succeeded\' END, r.`finished_at` = :now, r.`updated_at` = :now '
            . 'WHERE r.`id` = UNHEX(:id) AND r.`status` = \'running\'',
            ['now' => $now, 'id' => $runId]
        );
    }

    /** @return array<string, ConfiguredHook> enabled hooks keyed by phase */
    private function enabledHooks(string $repositoryId): array
    {
        $hooks = [];
        foreach ($this->connection->fetchAllAssociative(
            'SELECT `phase`, LOWER(HEX(`agent_host_id`)) AS `host`, LOWER(HEX(`agent_action_id`)) AS `action`, '
            . '`timeout_seconds`, `fail_on_error` FROM `muwa_agent_repository_hook` '
            . 'WHERE `facility_repository_id` = UNHEX(:repositoryId) AND `enabled` = 1',
            ['repositoryId' => $repositoryId]
        ) as $row) {
            $phase = JobPhase::tryFrom((string) $row['phase']);
            if ($phase === null) {
                continue;
            }
            $hooks[$phase->value] = new ConfiguredHook(
                $repositoryId,
                $phase,
                (string) $row['host'],
                (string) $row['action'],
                (int) $row['timeout_seconds'],
                (bool) $row['fail_on_error']
            );
        }

        return $hooks;
    }

    private function storageNow(): string
    {
        $now = $this->clock !== null
            ? ($this->clock)()
            : new \DateTimeImmutable((string) $this->connection->fetchOne('SELECT UTC_TIMESTAMP(3)'), new \DateTimeZone('UTC'));

        return $now->setTimezone(new \DateTimeZone('UTC'))->format(Defaults::STORAGE_DATE_TIME_FORMAT);
    }
}
