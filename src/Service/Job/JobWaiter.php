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
namespace MuckiSafeAgentPlugin\Service\Job;

use Doctrine\DBAL\Connection;

/**
 * Blocks the Facility backup process until a job is terminal. A mere lease
 * expiry does not end the wait: the reaper runs on every poll, so a never
 * started safe job is followed through its requeue and new claim. The wait
 * is hard-bounded by the job's materialized phase deadline: once it has
 * passed, the reaper has moved every non-terminal job to a terminal state;
 * should that ever fail, the waiter still returns `unknown` instead of
 * extending the budget. Offline agents or API errors therefore never
 * prolong the time spent under Facility's backup lock.
 */
final class JobWaiter
{
    public const TERMINAL_STATUSES = ['succeeded', 'failed', 'timed_out', 'expired', 'unknown'];

    /**
     * @param (\Closure(): \DateTimeImmutable)|null $clock
     * @param (\Closure(int): void)|null $sleeper
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly JobReaper $reaper,
        private readonly ?\Closure $clock = null,
        private readonly ?\Closure $sleeper = null,
        private readonly int $pollIntervalSeconds = 1
    ) {
        if ($pollIntervalSeconds < 1) {
            throw new \InvalidArgumentException('The poll interval must be positive.');
        }
    }

    /** @return string the terminal job status */
    public function wait(string $jobId): string
    {
        while (true) {
            $this->reaper->reap();
            $row = $this->connection->fetchAssociative(
                'SELECT `status`, `phase_deadline` FROM `muwa_agent_job` WHERE `id` = UNHEX(:id)',
                ['id' => $jobId]
            );
            if ($row === false) {
                return 'unknown';
            }
            if (in_array($row['status'], self::TERMINAL_STATUSES, true)) {
                return (string) $row['status'];
            }
            $phaseDeadline = new \DateTimeImmutable((string) $row['phase_deadline'], new \DateTimeZone('UTC'));
            if ($this->now() > $phaseDeadline) {
                return 'unknown';
            }
            $this->sleep();
        }
    }

    private function now(): \DateTimeImmutable
    {
        if ($this->clock !== null) {
            return ($this->clock)();
        }

        return new \DateTimeImmutable((string) $this->connection->fetchOne('SELECT UTC_TIMESTAMP(3)'), new \DateTimeZone('UTC'));
    }

    private function sleep(): void
    {
        if ($this->sleeper !== null) {
            ($this->sleeper)($this->pollIntervalSeconds);

            return;
        }
        sleep($this->pollIntervalSeconds);
    }
}
