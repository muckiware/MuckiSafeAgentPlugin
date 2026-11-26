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

/**
 * The global rollback switch (Task 33) as one row in the plugin's own table,
 * so job creation and claims can read it in their own transactions.
 */
final class Migration1795478400CreateRollbackState extends MigrationStep
{
    public const TABLE_NAME = 'muwa_agent_rollback';

    public function getCreationTimestamp(): int
    {
        return 1795478400;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement(<<<'SQL'
CREATE TABLE IF NOT EXISTS `muwa_agent_rollback` (
    `id` TINYINT UNSIGNED NOT NULL,
    `active` TINYINT(1) NOT NULL DEFAULT 0,
    `started_at` DATETIME(3) NULL,
    `stopped_at` DATETIME(3) NULL,
    `updated_at` DATETIME(3) NULL,
    PRIMARY KEY (`id`),
    CONSTRAINT `chk.muwa_agent_rollback.single_row` CHECK (`id` = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        $connection->executeStatement('INSERT IGNORE INTO `muwa_agent_rollback` (`id`, `active`) VALUES (1, 0)');
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
