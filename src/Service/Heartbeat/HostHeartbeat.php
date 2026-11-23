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
namespace MuckiSafeAgentPlugin\Service\Heartbeat;

use Doctrine\DBAL\Connection;

/** Persists one authenticated host heartbeat with a single atomic update. */
final class HostHeartbeat
{
    public const POLL_AFTER_SECONDS = 30;

    public function __construct(private readonly Connection $connection)
    {
    }

    /** @return array{serverTime: string, pollAfterSeconds: int} */
    public function record(string $hostId, HostHeartbeatRequest $heartbeat): array
    {
        if (preg_match('/^[0-9a-f]{32}$/D', $hostId) !== 1) {
            throw new \InvalidArgumentException('Invalid authenticated host.');
        }
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $timestamp = $now->format('Y-m-d H:i:s.v');
        $capabilities = json_encode($heartbeat->capabilities, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $affected = $this->connection->executeStatement(
            'UPDATE `muwa_agent_host` SET `version` = :version, `capabilities` = :capabilities, '
            . '`last_seen_at` = :lastSeenAt, '
            . '`updated_at` = CASE WHEN `updated_at` IS NULL OR `updated_at` < :updatedAt '
            . 'THEN :updatedAt ELSE DATE_ADD(`updated_at`, INTERVAL 1000 MICROSECOND) END '
            . 'WHERE `id` = UNHEX(:id) AND `enabled` = 1 AND `revoked_at` IS NULL',
            [
                'version' => $heartbeat->agentVersion,
                'capabilities' => $capabilities,
                'lastSeenAt' => $timestamp,
                'updatedAt' => $timestamp,
                'id' => $hostId,
            ]
        );
        if ($affected !== 1) {
            throw new HostHeartbeatRejected('Authenticated host is unavailable.');
        }

        return [
            'serverTime' => $now->format('Y-m-d\\TH:i:s\\Z'),
            'pollAfterSeconds' => self::POLL_AFTER_SECONDS,
        ];
    }
}
