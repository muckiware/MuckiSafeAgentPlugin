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

final class Migration1795132800CreateAgentSchema extends MigrationStep
{
    public const TABLES = [
        'muwa_agent_nonce',
        'muwa_agent_job',
        'muwa_agent_backup_run',
        'muwa_agent_repository_hook',
        'muwa_agent_action',
        'muwa_agent_host',
    ];

    public function getCreationTimestamp(): int
    {
        return 1795132800;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement(<<<'SQL'
CREATE TABLE IF NOT EXISTS `muwa_agent_host` (
    `id` BINARY(16) NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `enabled` TINYINT(1) NOT NULL DEFAULT 1,
    `key_id` VARCHAR(255) NOT NULL,
    `secret_ciphertext` VARBINARY(512) NOT NULL,
    `secret_nonce` BINARY(24) NOT NULL,
    `secret_key_version` INT UNSIGNED NOT NULL,
    `capabilities` JSON NOT NULL,
    `version` VARCHAR(64) NULL,
    `last_seen_at` DATETIME(3) NULL,
    `revoked_at` DATETIME(3) NULL,
    `created_at` DATETIME(3) NOT NULL,
    `updated_at` DATETIME(3) NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_agent_host_key_id` (`key_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $connection->executeStatement(<<<'SQL'
CREATE TABLE IF NOT EXISTS `muwa_agent_action` (
    `id` BINARY(16) NOT NULL,
    `technical_name` VARCHAR(255) NOT NULL,
    `label` VARCHAR(255) NOT NULL,
    `enabled` TINYINT(1) NOT NULL DEFAULT 1,
    `definition` LONGTEXT NOT NULL,
    `definition_hash` BINARY(32) NOT NULL,
    `parameter_schema` LONGTEXT NULL,
    `required_capabilities` JSON NOT NULL,
    `default_timeout_seconds` INT UNSIGNED NOT NULL,
    `max_timeout_seconds` INT UNSIGNED NOT NULL,
    `retry_policy` VARCHAR(64) NOT NULL,
    `idempotency_class` VARCHAR(32) NOT NULL,
    `created_at` DATETIME(3) NOT NULL,
    `updated_at` DATETIME(3) NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_agent_action_technical_name` (`technical_name`),
    CONSTRAINT `chk_agent_action_idempotency_class` CHECK (BINARY `idempotency_class` IN ('safe', 'non_retryable'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $connection->executeStatement(<<<'SQL'
CREATE TABLE IF NOT EXISTS `muwa_agent_repository_hook` (
    `id` BINARY(16) NOT NULL,
    `facility_repository_id` BINARY(16) NOT NULL,
    `phase` VARCHAR(16) NOT NULL,
    `agent_host_id` BINARY(16) NOT NULL,
    `agent_action_id` BINARY(16) NOT NULL,
    `parameters` LONGTEXT NOT NULL,
    `timeout_seconds` INT UNSIGNED NOT NULL,
    `fail_on_error` TINYINT(1) NOT NULL DEFAULT 1,
    `enabled` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME(3) NOT NULL,
    `updated_at` DATETIME(3) NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_repository_phase` (`facility_repository_id`, `phase`),
    KEY `idx_repository_hook_host` (`agent_host_id`),
    KEY `idx_repository_hook_action` (`agent_action_id`),
    CONSTRAINT `fk_repository_hook_host` FOREIGN KEY (`agent_host_id`) REFERENCES `muwa_agent_host` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_repository_hook_action` FOREIGN KEY (`agent_action_id`) REFERENCES `muwa_agent_action` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `chk_agent_repository_hook_phase` CHECK (BINARY `phase` IN ('pre', 'post', 'finally'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $connection->executeStatement(<<<'SQL'
CREATE TABLE IF NOT EXISTS `muwa_agent_backup_run` (
    `id` BINARY(16) NOT NULL,
    `facility_repository_id` BINARY(16) NOT NULL,
    `source` VARCHAR(32) NOT NULL,
    `idempotency_key` VARCHAR(255) NOT NULL,
    `status` VARCHAR(32) NOT NULL,
    `started_at` DATETIME(3) NOT NULL,
    `finished_at` DATETIME(3) NULL,
    `primary_error_code` VARCHAR(255) NULL,
    `primary_error_summary` VARCHAR(1024) NULL,
    `active_guard` TINYINT GENERATED ALWAYS AS (CASE WHEN `status` = 'running' THEN 1 ELSE NULL END) STORED,
    `created_at` DATETIME(3) NOT NULL,
    `updated_at` DATETIME(3) NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_backup_run_idempotency` (`idempotency_key`),
    UNIQUE KEY `uniq_active_guard` (`active_guard`),
    KEY `idx_backup_run_repository` (`facility_repository_id`),
    CONSTRAINT `chk_agent_backup_run_status` CHECK (BINARY `status` IN ('running', 'succeeded', 'failed', 'unknown'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $connection->executeStatement(<<<'SQL'
CREATE TABLE IF NOT EXISTS `muwa_agent_job` (
    `id` BINARY(16) NOT NULL,
    `backup_run_id` BINARY(16) NOT NULL,
    `agent_action_id` BINARY(16) NOT NULL,
    `facility_repository_id` BINARY(16) NOT NULL,
    `phase` VARCHAR(16) NOT NULL,
    `action_definition` LONGTEXT NOT NULL,
    `action_hash` BINARY(32) NOT NULL,
    `payload` LONGTEXT NOT NULL,
    `idempotency_class` VARCHAR(32) NOT NULL,
    `idempotency_key` VARCHAR(255) NOT NULL,
    `status` VARCHAR(32) NOT NULL,
    `target_agent_id` BINARY(16) NOT NULL,
    `claim_token_hash` BINARY(32) NULL,
    `lease_version` INT UNSIGNED NOT NULL DEFAULT 0,
    `attempt` INT UNSIGNED NOT NULL DEFAULT 0,
    `max_attempts` INT UNSIGNED NOT NULL,
    `available_at` DATETIME(3) NOT NULL,
    `phase_deadline` DATETIME(3) NOT NULL,
    `execution_deadline` DATETIME(3) NOT NULL,
    `stop_deadline` DATETIME(3) NOT NULL,
    `claimed_at` DATETIME(3) NULL,
    `lease_expires_at` DATETIME(3) NULL,
    `heartbeat_at` DATETIME(3) NULL,
    `finished_at` DATETIME(3) NULL,
    `completion_fingerprint` BINARY(32) NULL,
    `completion_agent_id` BINARY(16) NULL,
    `previous_claim_token_hash` BINARY(32) NULL,
    `accepted_lease_version` INT UNSIGNED NULL,
    `result_code` VARCHAR(255) NULL,
    `result_summary` VARCHAR(1024) NULL,
    `stdout_tail` MEDIUMTEXT NULL,
    `stderr_tail` MEDIUMTEXT NULL,
    `active_agent_id` BINARY(16) GENERATED ALWAYS AS (CASE WHEN `status` IN ('claimed','running','cancel_requested') THEN `target_agent_id` ELSE NULL END) STORED,
    `created_at` DATETIME(3) NOT NULL,
    `updated_at` DATETIME(3) NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_agent_job_idempotency` (`idempotency_key`),
    UNIQUE KEY `uniq_active_agent` (`active_agent_id`),
    KEY `idx_job_queue` (`status`, `available_at`, `execution_deadline`),
    KEY `idx_job_agent_lease` (`target_agent_id`, `status`, `lease_expires_at`),
    KEY `idx_job_run_phase` (`backup_run_id`, `phase`),
    KEY `idx_job_action` (`agent_action_id`),
    KEY `idx_job_completion_agent` (`completion_agent_id`),
    CONSTRAINT `fk_agent_job_run` FOREIGN KEY (`backup_run_id`) REFERENCES `muwa_agent_backup_run` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_agent_job_action` FOREIGN KEY (`agent_action_id`) REFERENCES `muwa_agent_action` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_agent_job_target` FOREIGN KEY (`target_agent_id`) REFERENCES `muwa_agent_host` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `fk_agent_job_completion_agent` FOREIGN KEY (`completion_agent_id`) REFERENCES `muwa_agent_host` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `chk_agent_job_phase` CHECK (BINARY `phase` IN ('pre', 'post', 'finally')),
    CONSTRAINT `chk_agent_job_idempotency_class` CHECK (BINARY `idempotency_class` IN ('safe', 'non_retryable')),
    CONSTRAINT `chk_agent_job_status` CHECK (BINARY `status` IN ('queued', 'claimed', 'running', 'cancel_requested', 'succeeded', 'failed', 'timed_out', 'expired', 'unknown'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $connection->executeStatement(<<<'SQL'
CREATE TABLE IF NOT EXISTS `muwa_agent_nonce` (
    `id` BINARY(16) NOT NULL,
    `agent_id` BINARY(16) NOT NULL,
    `nonce` VARBINARY(255) NOT NULL,
    `expires_at` DATETIME(3) NOT NULL,
    `created_at` DATETIME(3) NOT NULL,
    `updated_at` DATETIME(3) NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_agent_nonce` (`agent_id`, `nonce`),
    KEY `idx_agent_nonce_expiry` (`expires_at`),
    CONSTRAINT `fk_agent_nonce_host` FOREIGN KEY (`agent_id`) REFERENCES `muwa_agent_host` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->createImmutableTriggers($connection);
    }

    public function updateDestructive(Connection $connection): void
    {
    }

    private function createImmutableTriggers(Connection $connection): void
    {
        if (!(bool) $connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = :name',
            ['name' => 'muwa_agent_job_deadlines_before_insert']
        )) {
            $connection->executeStatement(<<<'SQL'
CREATE TRIGGER `muwa_agent_job_deadlines_before_insert`
BEFORE INSERT ON `muwa_agent_job`
FOR EACH ROW
BEGIN
    IF NOT (NEW.`execution_deadline` <=> DATE_SUB(NEW.`phase_deadline`, INTERVAL 15 SECOND))
       OR NOT (NEW.`stop_deadline` <=> DATE_SUB(NEW.`phase_deadline`, INTERVAL 10 SECOND)) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Job deadline formula is invalid';
    END IF;
END
SQL);
        }

        if (!(bool) $connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = :name',
            ['name' => 'muwa_agent_job_immutable_before_update']
        )) {
            $connection->executeStatement(<<<'SQL'
CREATE TRIGGER `muwa_agent_job_immutable_before_update`
BEFORE UPDATE ON `muwa_agent_job`
FOR EACH ROW
BEGIN
    IF NOT (OLD.`phase` <=> NEW.`phase`)
       OR NOT (OLD.`action_definition` <=> NEW.`action_definition`)
       OR NOT (OLD.`action_hash` <=> NEW.`action_hash`)
       OR NOT (OLD.`payload` <=> NEW.`payload`)
       OR NOT (OLD.`idempotency_class` <=> NEW.`idempotency_class`)
       OR NOT (OLD.`idempotency_key` <=> NEW.`idempotency_key`)
       OR NOT (OLD.`phase_deadline` <=> NEW.`phase_deadline`)
       OR NOT (OLD.`execution_deadline` <=> NEW.`execution_deadline`)
       OR NOT (OLD.`stop_deadline` <=> NEW.`stop_deadline`) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Immutable job field cannot be changed';
    END IF;
    IF (OLD.`completion_fingerprint` IS NOT NULL AND NOT (OLD.`completion_fingerprint` <=> NEW.`completion_fingerprint`))
       OR (OLD.`completion_agent_id` IS NOT NULL AND NOT (OLD.`completion_agent_id` <=> NEW.`completion_agent_id`))
       OR (OLD.`previous_claim_token_hash` IS NOT NULL AND NOT (OLD.`previous_claim_token_hash` <=> NEW.`previous_claim_token_hash`))
       OR (OLD.`accepted_lease_version` IS NOT NULL AND NOT (OLD.`accepted_lease_version` <=> NEW.`accepted_lease_version`)) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Immutable completion field cannot be changed';
    END IF;
END
SQL);
        }

        if (!(bool) $connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = :name',
            ['name' => 'muwa_agent_repository_hook_immutable_before_update']
        )) {
            $connection->executeStatement(<<<'SQL'
CREATE TRIGGER `muwa_agent_repository_hook_immutable_before_update`
BEFORE UPDATE ON `muwa_agent_repository_hook`
FOR EACH ROW
BEGIN
    IF NOT (OLD.`phase` <=> NEW.`phase`) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Immutable repository hook phase cannot be changed';
    END IF;
END
SQL);
        }
    }
}
