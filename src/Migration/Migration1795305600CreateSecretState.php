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
namespace MuckiSafeAgentPlugin\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

final class Migration1795305600CreateSecretState extends MigrationStep
{
    public const TABLE_NAME = 'muwa_agent_secret_state';
    public const SINGLETON_ID = 1;

    public function getCreationTimestamp(): int
    {
        return 1795305600;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement(<<<'SQL'
CREATE TABLE IF NOT EXISTS `muwa_agent_secret_state` (
    `id` TINYINT UNSIGNED NOT NULL,
    `fence_version` INT UNSIGNED NOT NULL DEFAULT 0,
    `mutation_generation` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `readback_old_version` INT UNSIGNED NULL,
    `readback_active_version` INT UNSIGNED NULL,
    `readback_generation` BIGINT UNSIGNED NULL,
    `readback_cursor` BINARY(16) NULL,
    `created_at` DATETIME(3) NOT NULL,
    `updated_at` DATETIME(3) NULL,
    PRIMARY KEY (`id`),
    CONSTRAINT `chk_agent_secret_state_singleton` CHECK (`id` = 1),
    CONSTRAINT `chk_agent_secret_state_readback` CHECK (
        (`readback_old_version` IS NULL AND `readback_active_version` IS NULL AND `readback_generation` IS NULL AND `readback_cursor` IS NULL)
        OR
        (`readback_old_version` IS NOT NULL AND `readback_active_version` IS NOT NULL AND `readback_generation` IS NOT NULL AND `readback_cursor` IS NOT NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $connection->executeStatement(
            'INSERT IGNORE INTO `muwa_agent_secret_state` (`id`, `created_at`) VALUES (:id, :createdAt)',
            [
                'id' => self::SINGLETON_ID,
                'createdAt' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.v'),
            ]
        );
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
