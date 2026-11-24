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
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use MuckiSafeAgentPlugin\Api\JobClaim;
use MuckiSafeAgentPlugin\Api\JobClaimResponse;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;

/** Atomically selects and leases at most one job for one authenticated host. */
final class JobClaimer
{
    private const LEASE_SECONDS = 30;

    public function __construct(
        private readonly Connection $connection,
        private readonly ?\Closure $afterCandidateLocked = null,
        private readonly ?string $candidateJobId = null,
        private readonly ?\Closure $serverNowProvider = null
    ) {
    }

    public function claim(string $authenticatedHostId): JobClaimResponse
    {
        if (preg_match('/^[0-9a-f]{32}$/D', $authenticatedHostId) !== 1) {
            throw new \InvalidArgumentException('Invalid authenticated host.');
        }
        if ($this->candidateJobId !== null && preg_match('/^[0-9a-f]{32}$/D', $this->candidateJobId) !== 1) {
            throw new \InvalidArgumentException('Invalid candidate job.');
        }
        $candidateRestriction = $this->candidateJobId === null ? '' : 'AND j.`id` = UNHEX(:candidateJobId) ';
        $candidateParameters = ['hostId' => $authenticatedHostId, 'status' => 'queued', 'serverNow' => null];
        if ($this->candidateJobId !== null) {
            $candidateParameters['candidateJobId'] = $this->candidateJobId;
        }

        $this->connection->executeStatement('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $this->connection->beginTransaction();
        try {
            $serverNow = $this->serverNowProvider === null
                ? (string) $this->connection->fetchOne('SELECT UTC_TIMESTAMP(3)')
                : (string) ($this->serverNowProvider)();
            $candidateParameters['serverNow'] = $serverNow;
            $host = $this->connection->fetchAssociative(
                'SELECT `id` FROM `muwa_agent_host` WHERE `id` = UNHEX(:hostId) '
                . 'AND `enabled` = 1 AND `revoked_at` IS NULL',
                ['hostId' => $authenticatedHostId]
            );
            if ($host === false) {
                throw new JobClaimRejected('Authenticated host is revoked or unavailable.');
            }

            $row = $this->connection->fetchAssociative(
                'SELECT j.`id`, j.`phase`, j.`action_definition`, j.`action_hash`, j.`payload`, '
                . 'j.`lease_version`, j.`attempt`, j.`phase_deadline`, j.`execution_deadline`, j.`stop_deadline`, '
                . 'j.`idempotency_class` '
                . 'FROM `muwa_agent_job` j '
                . 'WHERE j.`target_agent_id` = UNHEX(:hostId) AND j.`status` = :status '
                . $candidateRestriction
                . 'AND j.`available_at` <= :serverNow AND j.`execution_deadline` > :serverNow '
                . 'AND j.`attempt` < j.`max_attempts` '
                . 'AND EXISTS (SELECT 1 FROM `muwa_agent_action` a '
                . 'INNER JOIN `muwa_agent_host` h ON h.`id` = j.`target_agent_id` '
                . 'WHERE a.`id` = j.`agent_action_id` AND a.`enabled` = 1 '
                . 'AND h.`enabled` = 1 AND h.`revoked_at` IS NULL '
                . 'AND JSON_TYPE(h.`capabilities`) = \'ARRAY\' '
                . 'AND JSON_TYPE(a.`required_capabilities`) = \'ARRAY\' '
                . 'AND JSON_CONTAINS(h.`capabilities`, a.`required_capabilities`) = 1) '
                . 'ORDER BY j.`available_at` ASC, j.`created_at` ASC, j.`id` ASC LIMIT 1 '
                . 'FOR UPDATE SKIP LOCKED',
                $candidateParameters
            );
            if ($row === false) {
                $this->connection->commit();
                return new JobClaimResponse(null);
            }

            if ($this->afterCandidateLocked !== null) {
                ($this->afterCandidateLocked)();
            }

            $token = sodium_bin2base64(random_bytes(32), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
            $tokenHash = hash('sha256', $token, true);
            $leaseVersion = (int) $row['lease_version'] + 1;
            $attempt = (int) $row['attempt'] + 1;
            $now = new \DateTimeImmutable($serverNow, new \DateTimeZone('UTC'));
            $executionDeadline = new \DateTimeImmutable((string) $row['execution_deadline'], new \DateTimeZone('UTC'));
            $leaseExpiresAt = $this->wireBoundary(
                min($now->modify('+' . self::LEASE_SECONDS . ' seconds'), $executionDeadline)
            );
            try {
                $parameters = json_decode((string) $row['payload'], false, 32, JSON_THROW_ON_ERROR);
            } catch (\JsonException $exception) {
                throw new \RuntimeException('Stored job parameters are invalid.', 0, $exception);
            }
            if (!$parameters instanceof \stdClass) {
                throw new \RuntimeException('Stored job parameters are not an object.');
            }

            $affected = $this->connection->executeStatement(
                'UPDATE `muwa_agent_job` j SET `status` = :claimed, `claim_token_hash` = :tokenHash, '
                . '`attempt` = :attempt, `lease_version` = :leaseVersion, `claimed_at` = :claimedAt, '
                . '`lease_expires_at` = :leaseExpiresAt, `updated_at` = :updatedAt '
                . 'WHERE j.`id` = :id AND j.`target_agent_id` = UNHEX(:hostId) AND j.`status` = :queued '
                . 'AND j.`available_at` <= :serverNow AND j.`execution_deadline` > :serverNow '
                . 'AND j.`attempt` < j.`max_attempts` '
                . 'AND EXISTS (SELECT 1 FROM `muwa_agent_action` a '
                . 'INNER JOIN `muwa_agent_host` h ON h.`id` = j.`target_agent_id` '
                . 'WHERE a.`id` = j.`agent_action_id` AND a.`enabled` = 1 '
                . 'AND h.`enabled` = 1 AND h.`revoked_at` IS NULL '
                . 'AND JSON_TYPE(h.`capabilities`) = \'ARRAY\' '
                . 'AND JSON_TYPE(a.`required_capabilities`) = \'ARRAY\' '
                . 'AND JSON_CONTAINS(h.`capabilities`, a.`required_capabilities`) = 1)',
                [
                    'claimed' => 'claimed',
                    'tokenHash' => $tokenHash,
                    'attempt' => $attempt,
                    'leaseVersion' => $leaseVersion,
                    'claimedAt' => $serverNow,
                    'leaseExpiresAt' => $leaseExpiresAt->format(Defaults::STORAGE_DATE_TIME_FORMAT),
                    'updatedAt' => $serverNow,
                    'id' => $row['id'],
                    'hostId' => $authenticatedHostId,
                    'queued' => 'queued',
                    'serverNow' => $serverNow,
                ]
            );
            if ($affected !== 1) {
                $this->connection->rollBack();
                return new JobClaimResponse(null);
            }

            $claim = new JobClaim(
                $this->wireUuid(Uuid::fromBytesToHex((string) $row['id'])),
                $token,
                $leaseVersion,
                $this->wireTime($leaseExpiresAt),
                $this->wireTime(new \DateTimeImmutable((string) $row['phase_deadline'], new \DateTimeZone('UTC'))),
                $this->wireTime($executionDeadline),
                $this->wireTime(new \DateTimeImmutable((string) $row['stop_deadline'], new \DateTimeZone('UTC'))),
                (string) $row['phase'],
                (string) $row['idempotency_class'],
                (string) $row['action_definition'],
                bin2hex((string) $row['action_hash']),
                $parameters
            );
            $this->connection->commit();

            return new JobClaimResponse($claim);
        } catch (UniqueConstraintViolationException $exception) {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
            if ($this->isActiveAgentGuardViolation($exception->getMessage())) {
                return new JobClaimResponse(null);
            }
            throw $exception;
        } catch (\Throwable $exception) {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
            throw $exception;
        }
    }

    private function isActiveAgentGuardViolation(string $message): bool
    {
        return preg_match(
            "~SQLSTATE\\[23000\\].*\\b1062 Duplicate entry .* for key '(?:muwa_agent_job\\.)?uniq_active_agent'$~D",
            $message
        ) === 1;
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

    private function wireTime(\DateTimeImmutable $time): string
    {
        return $time->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s\\Z');
    }

    private function wireUuid(string $hex): string
    {
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
    }
}
