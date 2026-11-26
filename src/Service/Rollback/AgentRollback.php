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
namespace MuckiSafeAgentPlugin\Service\Rollback;

use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;

use MuckiSafeAgentPlugin\Service\Hook\BackupRunCoordinator;
use MuckiSafeAgentPlugin\Service\Job\JobDrainer;

/**
 * The global, reversible rollback switch of Task 33. start() also marks
 * every open run with `rollback_active`.
 *
 * While it is active the BackupRunCoordinator creates no agent runs, the
 * JobCreator refuses every job (it reads the switch with a shared lock, the
 * exclusive write here serializes both) and no job is claimed. start() first
 * commits the switch, then drains open jobs; it is repeatable and keeps the
 * first start time. The
 * status readback is the runbook gate: hook removal continues only once no
 * job is queued, claimed, running or cancel_requested. `unknown` jobs are
 * reported for manual assessment and never repeated.
 */
final class AgentRollback
{
    private const OPEN_STATES = ['queued', 'claimed', 'running', 'cancel_requested'];

    /** @param (\Closure(): (\DateTimeInterface|string))|null $timestamp */
    public function __construct(
        private readonly Connection $connection,
        private readonly JobDrainer $drainer,
        private readonly ?\Closure $timestamp = null
    ) {
    }

    public function isActive(): bool
    {
        return RollbackSwitch::isActive($this->connection);
    }

    public function start(): RollbackStatus
    {
        $now = $this->serverNow();
        $this->connection->executeStatement(
            'UPDATE `muwa_agent_rollback` SET '
            . '`started_at` = IF(`active` = 1, `started_at`, :now), '
            . '`stopped_at` = IF(`active` = 1, `stopped_at`, NULL), '
            . '`active` = 1, `updated_at` = :now WHERE `id` = 1',
            ['now' => $now]
        );
        // Open runs skip their remaining phases from now on: mark them, so
        // they never close as succeeded (also if their hooks are removed
        // before Facility reaches post/finally).
        $this->connection->executeStatement(
            'UPDATE `muwa_agent_backup_run` SET `primary_error_code` = :code, '
            . '`primary_error_summary` = :summary, `updated_at` = :now '
            . 'WHERE `status` = \'running\' AND `primary_error_code` IS NULL',
            ['code' => BackupRunCoordinator::ROLLBACK_ACTIVE, 'summary' => 'Agent rollback started during the run.', 'now' => $now]
        );
        $this->drainer->drain();

        return $this->status();
    }

    public function stop(): RollbackStatus
    {
        $now = $this->serverNow();
        $this->connection->executeStatement(
            'UPDATE `muwa_agent_rollback` SET `active` = 0, `stopped_at` = :now, `updated_at` = :now '
            . 'WHERE `id` = 1 AND `active` = 1',
            ['now' => $now]
        );

        return $this->status();
    }

    public function status(): RollbackStatus
    {
        $state = $this->connection->fetchAssociative(
            'SELECT `active`, `started_at`, `stopped_at` FROM `muwa_agent_rollback` WHERE `id` = 1'
        );
        if ($state === false) {
            throw new \RuntimeException('Rollback state is missing; run the plugin migrations.');
        }
        $counts = $this->connection->fetchAllKeyValue(
            'SELECT `status`, COUNT(*) FROM `muwa_agent_job` '
            . 'WHERE `status` IN (\'queued\', \'claimed\', \'running\', \'cancel_requested\', \'unknown\') GROUP BY `status`'
        );
        $open = [];
        foreach (self::OPEN_STATES as $status) {
            $open[$status] = (int) ($counts[$status] ?? 0);
        }

        return new RollbackStatus(
            (int) $state['active'] === 1,
            $state['started_at'] === null ? null : (string) $state['started_at'],
            $state['stopped_at'] === null ? null : (string) $state['stopped_at'],
            $open,
            (int) ($counts['unknown'] ?? 0)
        );
    }

    private function serverNow(): string
    {
        $value = $this->timestamp === null
            ? (string) $this->connection->fetchOne('SELECT UTC_TIMESTAMP(3)')
            : ($this->timestamp)();
        if (!$value instanceof \DateTimeInterface) {
            $value = new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
        }

        return \DateTimeImmutable::createFromInterface($value)
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format(Defaults::STORAGE_DATE_TIME_FORMAT);
    }
}
