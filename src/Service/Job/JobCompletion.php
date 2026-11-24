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
use MuckiSafeAgentPlugin\Api\JobCompleteRequest;
use MuckiSafeAgentPlugin\Api\JobCompleteResponse;
use MuckiSafeAgentPlugin\Service\Action\CanonicalJson;
use MuckiSafeAgentPlugin\Service\Auth\AgentProtocol;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Transactional, idempotent job completion. The terminal status transition,
 * the canonical completion fingerprint and the immutable audit fields —
 * bound agent, previous claim token hash, accepted lease version — are
 * persisted in one database transaction together with the bounded
 * diagnostics. Only an identical authenticated retry receives the stored
 * terminal status again (200); every contradicting repetition conflicts
 * (409). The row carries no secrets: only hashes of the claim token are
 * stored, and the response contains nothing beyond accepted/terminalStatus.
 */
final class JobCompletion
{
    public function __construct(
        private readonly Connection $connection,
        private readonly OutputTailNormalizer $outputNormalizer,
        private readonly JobTransitionGuard $guard,
        private readonly ?\Closure $timestamp = null
    ) {
    }

    public function complete(
        string $authenticatedHostId,
        string $jobId,
        #[\SensitiveParameter] JobCompleteRequest $request
    ): JobCompleteResponse {
        $normalized = $this->outputNormalizer->normalize($request);
        $fingerprint = hex2bin(CanonicalJson::hash((object) [
            'outcome' => $normalized->outcome,
            'resultCode' => $normalized->resultCode,
            'summary' => $normalized->summary,
            'stdoutEncoding' => $normalized->stdoutEncoding,
            'stdoutTail' => $normalized->stdoutTail,
            'stderrEncoding' => $normalized->stderrEncoding,
            'stderrTail' => $normalized->stderrTail,
        ]));
        if ($fingerprint === false) {
            throw new \LogicException('The completion fingerprint could not be encoded.');
        }

        $this->connection->beginTransaction();
        try {
            $row = $this->lockedCompletionRow($authenticatedHostId, $jobId);
            $serverNow = $this->serverNow();

            if ($row['completion_fingerprint'] !== null) {
                $terminalStatus = $this->identicalRetryStatus($row, $authenticatedHostId, $request, $fingerprint);
                $this->connection->commit();

                return new JobCompleteResponse($terminalStatus);
            }

            $this->assertLiveFence($row, $request);
            $terminalStatus = $this->guard->completionTerminalStatus(
                (string) $row['status'],
                $normalized->outcome,
                $this->rowTime($row, 'lease_expires_at'),
                $this->rowTime($row, 'phase_deadline'),
                $serverNow
            );
            $finishedAt = $this->storageTime($serverNow);
            $affected = $this->connection->executeStatement(
                'UPDATE `muwa_agent_job` SET '
                . '`status` = :terminal, `finished_at` = :finishedAt, '
                . '`completion_fingerprint` = :fingerprint, `completion_agent_id` = UNHEX(:agentId), '
                . '`previous_claim_token_hash` = :tokenHash, `accepted_lease_version` = :acceptedVersion, '
                . '`result_code` = :resultCode, `result_summary` = :resultSummary, '
                . '`stdout_tail` = :stdoutTail, `stderr_tail` = :stderrTail, '
                . '`claim_token_hash` = NULL, `lease_version` = `lease_version` + 1, `updated_at` = :finishedAt '
                . 'WHERE `id` = :id AND `status` IN (:running, :cancelRequested) '
                . 'AND `lease_version` = :acceptedVersion AND `claim_token_hash` = :tokenHash '
                . 'AND `target_agent_id` = UNHEX(:agentId)',
                [
                    'terminal' => $terminalStatus,
                    'finishedAt' => $finishedAt,
                    'fingerprint' => $fingerprint,
                    'agentId' => $authenticatedHostId,
                    'tokenHash' => hash('sha256', $request->claimToken, true),
                    'acceptedVersion' => $request->leaseVersion,
                    'resultCode' => $normalized->resultCode,
                    'resultSummary' => $normalized->summary,
                    'stdoutTail' => $normalized->stdoutTail,
                    'stderrTail' => $normalized->stderrTail,
                    'id' => $row['id'],
                    'running' => 'running',
                    'cancelRequested' => 'cancel_requested',
                ]
            );
            if ($affected !== 1) {
                throw new ClaimConflict('The completion lost its fence during the transition.');
            }
            $this->connection->commit();

            return new JobCompleteResponse($terminalStatus);
        } catch (\Throwable $exception) {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * The completion content is identical to the already accepted one and
     * the retry presents the very fence that was accepted: return the stored
     * terminal status again without rewriting anything.
     *
     * @param array<string, mixed> $row
     */
    private function identicalRetryStatus(
        array $row,
        string $authenticatedHostId,
        #[\SensitiveParameter] JobCompleteRequest $request,
        string $fingerprint
    ): string {
        if ($row['completion_agent_id'] === null
            || $row['previous_claim_token_hash'] === null
            || $row['accepted_lease_version'] === null
            || !hash_equals(Uuid::fromHexToBytes($authenticatedHostId), (string) $row['completion_agent_id'])
            || !hash_equals(hash('sha256', $request->claimToken, true), (string) $row['previous_claim_token_hash'])
            || (int) $row['accepted_lease_version'] !== $request->leaseVersion
            || !hash_equals($fingerprint, (string) $row['completion_fingerprint'])
        ) {
            throw new ClaimConflict('The completion contradicts the already accepted completion.');
        }

        return (string) $row['status'];
    }

    /** @param array<string, mixed> $row */
    private function assertLiveFence(array $row, #[\SensitiveParameter] JobCompleteRequest $request): void
    {
        if ($row['claim_token_hash'] === null
            || !hash_equals(hash('sha256', $request->claimToken, true), (string) $row['claim_token_hash'])
            || (int) $row['lease_version'] !== $request->leaseVersion
        ) {
            throw new ClaimConflict('The claim fence does not match.');
        }
    }

    /** @return array<string, mixed> */
    private function lockedCompletionRow(string $authenticatedHostId, string $jobId): array
    {
        if (preg_match('/^[0-9a-f]{32}$/D', $authenticatedHostId) !== 1
            || preg_match('#^' . AgentProtocol::UUID_PATTERN . '$#D', $jobId) !== 1
        ) {
            throw new ClaimConflict('The completion fence is invalid.');
        }
        $row = $this->connection->fetchAssociative(
            'SELECT j.`id`, j.`status`, j.`claim_token_hash`, j.`lease_version`, j.`lease_expires_at`, '
            . 'j.`phase_deadline`, j.`completion_fingerprint`, j.`completion_agent_id`, '
            . 'j.`previous_claim_token_hash`, j.`accepted_lease_version` '
            . 'FROM `muwa_agent_job` j '
            . 'INNER JOIN `muwa_agent_host` h ON h.`id` = j.`target_agent_id` '
            . 'WHERE j.`id` = UNHEX(:id) AND j.`target_agent_id` = UNHEX(:agentId) '
            . 'AND h.`enabled` = 1 AND h.`revoked_at` IS NULL FOR UPDATE',
            ['id' => str_replace('-', '', $jobId), 'agentId' => $authenticatedHostId]
        );
        if ($row === false) {
            throw new ClaimConflict('The completion target does not match the authenticated host.');
        }

        return $row;
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
    private function rowTime(array $row, string $field): \DateTimeImmutable
    {
        if (!isset($row[$field]) || !is_string($row[$field]) || $row[$field] === '') {
            throw new ClaimConflict('The claimed deadline is missing.');
        }

        return new \DateTimeImmutable($row[$field], new \DateTimeZone('UTC'));
    }

    private function storageTime(\DateTimeImmutable $time): string
    {
        return $time->setTimezone(new \DateTimeZone('UTC'))->format(Defaults::STORAGE_DATE_TIME_FORMAT);
    }
}
