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
use Doctrine\DBAL\ParameterType;
use Shopware\Core\Defaults;

/**
 * Ends every open job for a controlled rollback (Task 33), with the same
 * atomic single-table transitions as JobReaper, but immediately instead of at
 * a deadline:
 *
 * - `queued` and never started `claimed` jobs become `timed_out` with result
 *   code `rollback`; their claim is revoked, so a late start or completion
 *   is fenced off.
 * - `running` jobs become `cancel_requested`: the agent keeps token and lease
 *   only to acknowledge the stop (stopRequested in the lease heartbeat) and
 *   reports a timeout or ambiguous completion; the reaper ends them at the
 *   phase deadline at the latest.
 * - Terminal jobs, `unknown` and `cancel_requested` are never touched.
 */
final class JobDrainer
{
    public const RESULT_CODE = 'rollback';

    /** @param (\Closure(): (\DateTimeInterface|string))|null $timestamp */
    public function __construct(
        private readonly Connection $connection,
        private readonly ?\Closure $timestamp = null
    ) {
    }

    public function drain(): DrainResult
    {
        $now = $this->serverNow();
        $timedOut = (int) $this->connection->executeStatement(
            'UPDATE `muwa_agent_job` SET `status` = \'timed_out\', `result_code` = :resultCode, '
            . '`finished_at` = :now, '
            . '`previous_claim_token_hash` = COALESCE(`previous_claim_token_hash`, `claim_token_hash`), '
            . '`claim_token_hash` = NULL, `lease_version` = `lease_version` + 1, `updated_at` = :now '
            . 'WHERE `status` IN (\'queued\', \'claimed\')',
            ['now' => $now, 'resultCode' => self::RESULT_CODE],
            ['now' => ParameterType::STRING, 'resultCode' => ParameterType::STRING]
        );
        $cancelRequested = (int) $this->connection->executeStatement(
            'UPDATE `muwa_agent_job` SET `status` = \'cancel_requested\', `updated_at` = :now '
            . 'WHERE `status` = \'running\'',
            ['now' => $now]
        );

        return new DrainResult($timedOut, $cancelRequested);
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
