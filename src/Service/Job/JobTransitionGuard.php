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
use MuckiSafeAgentPlugin\Api\JobHeartbeatResponse;
use MuckiSafeAgentPlugin\Api\JobStartResponse;
use MuckiSafeAgentPlugin\Service\Auth\AgentProtocol;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Single authority for claimed-job fencing and transition eligibility.
 * Controllers and endpoint services deliberately contain no status policy.
 */
final class JobTransitionGuard
{
    private const LEASE_SECONDS = 30;
    private const STOP_GRACE_SECONDS = 5;

    /** @param (\Closure(): (\DateTimeInterface|string))|null $timestamp */
    public function __construct(
        private readonly Connection $connection,
        private readonly ?\Closure $timestamp = null
    ) {
    }

    public function start(
        string $authenticatedHostId,
        string $jobId,
        #[\SensitiveParameter] string $claimToken,
        int $leaseVersion
    ): JobStartResponse {
        return $this->transactional(function () use ($authenticatedHostId, $jobId, $claimToken, $leaseVersion): JobStartResponse {
            $row = $this->lockedFencedRow($authenticatedHostId, $jobId, $claimToken, $leaseVersion);
            $now = $this->serverNow();
            $leaseExpiresAt = $this->wireBoundary($this->date($row, 'lease_expires_at'));
            $executionDeadline = $this->wireBoundary($this->date($row, 'execution_deadline'));
            if ($row['status'] !== 'claimed' || $leaseExpiresAt <= $now || $executionDeadline <= $now) {
                throw new ClaimConflict('Claim is not eligible to start.');
            }
            $renewedUntil = $this->wireBoundary(
                min($now->modify('+' . self::LEASE_SECONDS . ' seconds'), $executionDeadline)
            );
            $this->connection->update('muwa_agent_job', [
                'status' => 'running',
                'lease_expires_at' => $this->storageTime($renewedUntil),
                'heartbeat_at' => $this->storageTime($now),
                'updated_at' => $this->storageTime($now),
            ], ['id' => $row['id']]);

            return new JobStartResponse(
                $leaseVersion,
                $this->wireTime($renewedUntil),
                $this->wireTime($this->date($row, 'phase_deadline')),
                $this->wireTime($executionDeadline),
                $this->wireTime($this->date($row, 'stop_deadline'))
            );
        });
    }

    public function heartbeat(
        string $authenticatedHostId,
        string $jobId,
        #[\SensitiveParameter] string $claimToken,
        int $leaseVersion
    ): JobHeartbeatResponse {
        return $this->transactional(function () use ($authenticatedHostId, $jobId, $claimToken, $leaseVersion): JobHeartbeatResponse {
            $row = $this->lockedFencedRow($authenticatedHostId, $jobId, $claimToken, $leaseVersion);
            $now = $this->serverNow();
            $leaseExpiresAt = $this->wireBoundary($this->date($row, 'lease_expires_at'));

            if ($row['status'] === 'cancel_requested') {
                $materializedStopDeadline = $this->wireBoundary($this->date($row, 'stop_deadline'));
                if ($now > $materializedStopDeadline) {
                    throw new ClaimConflict('Cancellation heartbeat is past the stop deadline.');
                }
                $effectiveStopDeadline = $this->wireBoundary(min(
                    $now->modify('+' . self::STOP_GRACE_SECONDS . ' seconds'),
                    $materializedStopDeadline
                ));
                $this->connection->update('muwa_agent_job', [
                    'heartbeat_at' => $this->storageTime($now),
                    'updated_at' => $this->storageTime($now),
                ], ['id' => $row['id']]);

                return new JobHeartbeatResponse(
                    $leaseVersion,
                    $this->wireTime($leaseExpiresAt),
                    true,
                    $this->wireTime($effectiveStopDeadline)
                );
            }

            $executionDeadline = $this->wireBoundary($this->date($row, 'execution_deadline'));
            if (!in_array($row['status'], ['claimed', 'running'], true)
                || $leaseExpiresAt <= $now
                || $executionDeadline <= $now
            ) {
                throw new ClaimConflict('Claim is not eligible for heartbeat renewal.');
            }
            $renewedUntil = $this->wireBoundary(
                min($now->modify('+' . self::LEASE_SECONDS . ' seconds'), $executionDeadline)
            );
            $this->connection->update('muwa_agent_job', [
                'lease_expires_at' => $this->storageTime($renewedUntil),
                'heartbeat_at' => $this->storageTime($now),
                'updated_at' => $this->storageTime($now),
            ], ['id' => $row['id']]);

            return new JobHeartbeatResponse($leaseVersion, $this->wireTime($renewedUntil), false);
        });
    }

    /**
     * Central completion policy seam for Task 13. It performs no persistence.
     *
     * @throws ClaimConflict when the status/outcome/deadline combination is ineligible
     */
    public function completionTerminalStatus(
        string $status,
        string $outcome,
        \DateTimeImmutable $leaseExpiresAt,
        \DateTimeImmutable $phaseDeadline
    ): string {
        $now = $this->serverNow();
        if ($status === 'running' && $this->wireBoundary($leaseExpiresAt) > $now) {
            return match ($outcome) {
                'ambiguous' => 'unknown',
                default => throw new ClaimConflict('Task 12 exposes only ambiguous completion policy for running jobs.'),
            };
        }
        if ($status === 'cancel_requested' && $now <= $this->wireBoundary($phaseDeadline)) {
            return match ($outcome) {
                'timed_out' => 'timed_out',
                'ambiguous' => 'unknown',
                default => throw new ClaimConflict('Cancellation accepts only timed-out or ambiguous completion.'),
            };
        }
        throw new ClaimConflict('Claim is not eligible for completion.');
    }

    /** @return array<string, mixed> */
    private function lockedFencedRow(
        string $authenticatedHostId,
        string $jobId,
        #[\SensitiveParameter] string $claimToken,
        int $leaseVersion
    ): array {
        if (preg_match('/^[0-9a-f]{32}$/D', $authenticatedHostId) !== 1
            || preg_match('#^' . AgentProtocol::UUID_PATTERN . '$#D', $jobId) !== 1
            || preg_match('/^[A-Za-z0-9_-]{43}$/D', $claimToken) !== 1
            || $leaseVersion < 1
        ) {
            throw new ClaimConflict('Claim fence is invalid.');
        }
        $row = $this->connection->fetchAssociative(
            'SELECT j.`id`, j.`status`, j.`target_agent_id`, j.`claim_token_hash`, j.`lease_version`, '
            . 'j.`lease_expires_at`, j.`phase_deadline`, j.`execution_deadline`, j.`stop_deadline` '
            . 'FROM `muwa_agent_job` j INNER JOIN `muwa_agent_host` h ON h.`id` = j.`target_agent_id` '
            . 'WHERE j.`id` = UNHEX(:id) AND h.`enabled` = 1 AND h.`revoked_at` IS NULL FOR UPDATE',
            ['id' => str_replace('-', '', $jobId)]
        );
        if ($row === false
            || !hash_equals(Uuid::fromHexToBytes($authenticatedHostId), (string) $row['target_agent_id'])
            || $row['claim_token_hash'] === null
            || !hash_equals(hash('sha256', $claimToken, true), (string) $row['claim_token_hash'])
            || (int) $row['lease_version'] !== $leaseVersion
        ) {
            throw new ClaimConflict('Claim fence does not match.');
        }
        return $row;
    }

    /**
     * @template T
     * @param \Closure(): T $operation
     * @return T
     */
    private function transactional(\Closure $operation): mixed
    {
        $this->connection->beginTransaction();
        try {
            $result = $operation();
            $this->connection->commit();
            return $result;
        } catch (\Throwable $exception) {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
            throw $exception;
        }
    }

    private function serverNow(): \DateTimeImmutable
    {
        $value = $this->timestamp === null
            ? (string) $this->connection->fetchOne('SELECT UTC_TIMESTAMP(3)')
            : ($this->timestamp)();
        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value)->setTimezone(new \DateTimeZone('UTC'));
        }
        if ($value === '') {
            throw new \LogicException('Timestamp provider must return a timestamp.');
        }
        return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
    }

    /** @param array<string, mixed> $row */
    private function date(array $row, string $field): \DateTimeImmutable
    {
        if (!isset($row[$field]) || !is_string($row[$field]) || $row[$field] === '') {
            throw new ClaimConflict('Claim deadline is missing.');
        }
        return new \DateTimeImmutable($row[$field], new \DateTimeZone('UTC'));
    }

    private function wireBoundary(\DateTimeImmutable $time): \DateTimeImmutable
    {
        $utc = $time->setTimezone(new \DateTimeZone('UTC'));

        return $utc->setTime(
            (int) $utc->format('H'),
            (int) $utc->format('i'),
            (int) $utc->format('s'),
            0
        );
    }

    private function storageTime(\DateTimeImmutable $time): string
    {
        return $time->setTimezone(new \DateTimeZone('UTC'))->format(Defaults::STORAGE_DATE_TIME_FORMAT);
    }

    private function wireTime(\DateTimeImmutable $time): string
    {
        return $time->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s\\Z');
    }
}
