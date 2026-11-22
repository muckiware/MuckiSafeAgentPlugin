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

namespace MuckiSafeAgentPlugin\Service\Auth;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use MuckiSafeAgentPlugin\Core\Content\AgentNonce\AgentNonceDefinition;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Replay protection over muwa_agent_nonce. The UNIQUE key (agent_id, nonce)
 * decides replays atomically: the INSERT happens only after a successful
 * signature verification and a duplicate insert is rejected as a replay.
 * Expiry is fixed to requestTimestamp + 60s window + 5s cleanup tolerance;
 * cleanup deletes only strictly expired rows in bounded batches.
 */
final class ReplayGuard
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Atomically remembers a request nonce for one agent. The UNIQUE key
     * (agent_id, nonce) makes a concurrent identical insert a replay even
     * across multiple PHP processes.
     *
     * @throws AgentAuthException with AgentErrorCode::ReplayDetected
     */
    public function remember(string $hexAgentId, string $nonce, int $requestTimestamp): void
    {
        SignatureFormat::assertKeyId($hexAgentId);
        SignatureFormat::assertNonce($nonce);

        try {
            $this->connection->executeStatement(
                'INSERT INTO `' . AgentNonceDefinition::ENTITY_NAME . '` '
                . '(`id`, `agent_id`, `nonce`, `expires_at`, `created_at`) '
                . 'VALUES (UNHEX(:id), UNHEX(:agentId), :nonce, :expiresAt, :createdAt)',
                [
                    'id' => Uuid::randomHex(),
                    'agentId' => $hexAgentId,
                    'nonce' => $nonce,
                    'expiresAt' => self::dbTimestamp(
                        $requestTimestamp + AgentProtocol::TIMESTAMP_WINDOW_SECONDS + AgentProtocol::REPLAY_CLEANUP_TOLERANCE_SECONDS
                    ),
                    'createdAt' => self::dbTimestamp(time()),
                ]
            );
        } catch (UniqueConstraintViolationException) {
            throw new AgentAuthException(
                AgentErrorCode::ReplayDetected,
                'Request nonce was already used.',
                null
            );
        }
    }

    /**
     * Deletes nonces whose expiry has strictly passed the given server time.
     * One call removes at most $batchSize rows, so a scheduler can drain a
     * large table in bounded steps. Returns the number of deleted rows.
     */
    public function cleanupExpired(int $serverNow, int $batchSize): int
    {
        if ($batchSize < 1) {
            throw new \InvalidArgumentException('batch size must be positive');
        }

        return (int) $this->connection->executeStatement(
            'DELETE FROM `' . AgentNonceDefinition::ENTITY_NAME . '` '
            . 'WHERE `expires_at` < :serverNow LIMIT :batchSize',
            [
                'serverNow' => self::dbTimestamp($serverNow),
                'batchSize' => $batchSize,
            ],
            [
                'serverNow' => \Doctrine\DBAL\ParameterType::STRING,
                'batchSize' => \Doctrine\DBAL\ParameterType::INTEGER,
            ]
        );
    }

    private static function dbTimestamp(int $unixTime): string
    {
        return (new \DateTimeImmutable('@' . $unixTime))->format('Y-m-d H:i:s.v');
    }
}
