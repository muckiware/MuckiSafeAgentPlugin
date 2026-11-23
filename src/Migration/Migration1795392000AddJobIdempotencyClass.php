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

/** Backfills and freezes the per-job idempotency class for existing installs. */
final class Migration1795392000AddJobIdempotencyClass extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1795392000;
    }

    public function update(Connection $connection): void
    {
        if (!(bool) $connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.TABLES '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table',
            ['table' => 'muwa_agent_job']
        )) {
            return;
        }

        $column = $connection->fetchAssociative(
            'SELECT IS_NULLABLE FROM information_schema.COLUMNS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column',
            ['table' => 'muwa_agent_job', 'column' => 'idempotency_class']
        );
        if ($column === false) {
            $connection->executeStatement(
                'ALTER TABLE `muwa_agent_job` ADD COLUMN `idempotency_class` VARCHAR(32) NULL AFTER `payload`'
            );
            $column = ['IS_NULLABLE' => 'YES'];
        }

        $connection->executeStatement(
            'UPDATE `muwa_agent_job` j INNER JOIN `muwa_agent_action` a ON a.`id` = j.`agent_action_id` '
            . 'SET j.`idempotency_class` = a.`idempotency_class` WHERE j.`idempotency_class` IS NULL'
        );
        if ((int) $connection->fetchOne(
            'SELECT COUNT(*) FROM `muwa_agent_job` WHERE `idempotency_class` IS NULL '
            . 'OR BINARY `idempotency_class` NOT IN (\'safe\', \'non_retryable\')'
        ) !== 0) {
            throw new \RuntimeException('Cannot materialize job idempotency classes.');
        }
        if (strtoupper((string) $column['IS_NULLABLE']) === 'YES') {
            $connection->executeStatement(
                'ALTER TABLE `muwa_agent_job` MODIFY COLUMN `idempotency_class` VARCHAR(32) NOT NULL'
            );
        }
        if (!(bool) $connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS '
            . 'WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = :table '
            . 'AND CONSTRAINT_NAME = :name AND CONSTRAINT_TYPE = \'CHECK\'',
            ['table' => 'muwa_agent_job', 'name' => 'chk_agent_job_idempotency_class']
        )) {
            $connection->executeStatement(
                'ALTER TABLE `muwa_agent_job` ADD CONSTRAINT `chk_agent_job_idempotency_class` '
                . 'CHECK (BINARY `idempotency_class` IN (\'safe\', \'non_retryable\'))'
            );
        }

        $mainTrigger = $connection->fetchOne(
            'SELECT ACTION_STATEMENT FROM information_schema.TRIGGERS '
            . 'WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = :name',
            ['name' => 'muwa_agent_job_immutable_before_update']
        );
        $snapshotProtectedByMainTrigger = is_string($mainTrigger)
            && str_contains(strtolower($mainTrigger), 'idempotency_class');
        $snapshotTriggerExists = (bool) $connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.TRIGGERS '
            . 'WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = :name',
            ['name' => 'muwa_agent_job_idempotency_immutable_before_update']
        );
        if (!$snapshotProtectedByMainTrigger && !$snapshotTriggerExists) {
            $connection->executeStatement(<<<'SQL'
CREATE TRIGGER `muwa_agent_job_idempotency_immutable_before_update`
BEFORE UPDATE ON `muwa_agent_job`
FOR EACH ROW
BEGIN
    IF NOT (OLD.`idempotency_class` <=> NEW.`idempotency_class`) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Immutable job idempotency class cannot be changed';
    END IF;
END
SQL);
        }
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
