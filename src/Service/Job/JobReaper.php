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
 * Server-side recovery of expired leases and enforcement of the materialized
 * deadlines. Every transition is one conditional single-table UPDATE, so it
 * is atomic per row against the row-locking endpoint services: a start,
 * heartbeat or completion that commits first removes the row from the
 * reaper's WHERE set and vice versa.
 *
 * Boundaries match JobTransitionGuard (stored lease and deadlines are whole
 * seconds): a lease is expired at `lease_expires_at <= now`, the execution
 * cutoff applies at `execution_deadline <= now`, and a cancellation stays
 * acknowledgeable through the inclusive phase deadline (`phase_deadline < now`
 * ends it).
 *
 * Only a claimed, never started job is requeued; a job that ever reached
 * `running` is never requeued. Terminal revocation moves the claim token
 * hash into the immutable `previous_claim_token_hash` audit field and bumps
 * the lease version; requeue discards the hash because a later completion of
 * the requeued job must still be able to write that audit field once.
 */
final class JobReaper
{
    private const DEADLINE_RESULT_CODE = 'deadline';

    /** @param (\Closure(): (\DateTimeInterface|string))|null $timestamp */
    public function __construct(
        private readonly Connection $connection,
        private readonly ?\Closure $timestamp = null,
        private readonly int $batchSize = 500,
        private readonly int $maxBatches = 100
    ) {
        if ($batchSize < 1 || $maxBatches < 1) {
            throw new \InvalidArgumentException('Batch size and batch count must be positive.');
        }
    }

    public function reap(): ReapResult
    {
        $now = $this->serverNow();

        // Deadline transitions first, so an unstarted job past its cutoff is
        // timed out instead of being requeued for a lease expiry.
        $unknownAtPhaseDeadline = $this->drain(
            'SET `status` = \'unknown\', ' . $this->terminalRevocation(true)
            . 'WHERE `status` IN (\'running\', \'cancel_requested\') AND `phase_deadline` < :now',
            $now
        );
        $timedOut = $this->drain(
            'SET `status` = \'timed_out\', ' . $this->terminalRevocation(true)
            . 'WHERE `status` IN (\'queued\', \'claimed\') AND `execution_deadline` <= :now',
            $now
        );
        $cancelRequested = $this->drain(
            'SET `status` = \'cancel_requested\', `updated_at` = :now '
            . 'WHERE `status` = \'running\' AND `execution_deadline` <= :now',
            $now
        );

        $expiredClaim = 'WHERE `status` = \'claimed\' AND `lease_expires_at` <= :now '
            . 'AND `execution_deadline` > :now ';
        $requeued = $this->drain(
            'SET `status` = \'queued\', `claim_token_hash` = NULL, `lease_version` = `lease_version` + 1, '
            . '`claimed_at` = NULL, `lease_expires_at` = NULL, `heartbeat_at` = NULL, `updated_at` = :now '
            . $expiredClaim . 'AND `idempotency_class` = \'safe\' AND `attempt` < `max_attempts`',
            $now
        );
        $unknownNonRetryable = $this->drain(
            'SET `status` = \'unknown\', ' . $this->terminalRevocation(false)
            . $expiredClaim . 'AND `idempotency_class` = \'non_retryable\'',
            $now
        );
        $expired = $this->drain(
            'SET `status` = \'expired\', ' . $this->terminalRevocation(false)
            . $expiredClaim . 'AND `idempotency_class` = \'safe\' AND `attempt` >= `max_attempts`',
            $now
        );

        return new ReapResult(
            $requeued,
            $expired,
            $unknownAtPhaseDeadline + $unknownNonRetryable,
            $timedOut,
            $cancelRequested
        );
    }

    private function terminalRevocation(bool $deadline): string
    {
        return ($deadline ? '`result_code` = \'' . self::DEADLINE_RESULT_CODE . '\', ' : '')
            . '`finished_at` = :now, '
            . '`previous_claim_token_hash` = COALESCE(`previous_claim_token_hash`, `claim_token_hash`), '
            . '`claim_token_hash` = NULL, `lease_version` = `lease_version` + 1, `updated_at` = :now ';
    }

    /** Applies one transition in bounded batches until drained or the batch budget is used up. */
    private function drain(string $transition, string $now): int
    {
        $total = 0;
        for ($batch = 0; $batch < $this->maxBatches; ++$batch) {
            $affected = (int) $this->connection->executeStatement(
                'UPDATE `muwa_agent_job` ' . $transition . ' ORDER BY `id` LIMIT :batchSize',
                ['now' => $now, 'batchSize' => $this->batchSize],
                ['now' => ParameterType::STRING, 'batchSize' => ParameterType::INTEGER]
            );
            $total += $affected;
            if ($affected < $this->batchSize) {
                break;
            }
        }

        return $total;
    }

    private function serverNow(): string
    {
        $value = $this->timestamp === null
            ? (string) $this->connection->fetchOne('SELECT UTC_TIMESTAMP(3)')
            : ($this->timestamp)();
        if ($value instanceof \DateTimeInterface) {
            $value = \DateTimeImmutable::createFromInterface($value);
        } elseif ($value === '') {
            throw new \LogicException('Timestamp provider must return a timestamp.');
        } else {
            $value = new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
        }

        return $value->setTimezone(new \DateTimeZone('UTC'))->format(Defaults::STORAGE_DATE_TIME_FORMAT);
    }
}
