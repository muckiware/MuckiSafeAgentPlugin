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
use MuckiSafeAgentPlugin\Core\Job\IdempotencyClass;
use MuckiSafeAgentPlugin\Service\Action\ActionDefinitionDecoder;
use MuckiSafeAgentPlugin\Service\Action\ActionValidator;
use MuckiSafeAgentPlugin\Service\Action\ParameterValidator;
use MuckiSafeAgentPlugin\Service\Rollback\RollbackActiveException;
use MuckiSafeAgentPlugin\Service\Rollback\RollbackSwitch;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;

/** Creates an immutable queued job snapshot from trusted server-side inputs. */
final class JobCreator
{
    private const PHASES = ['pre', 'post', 'finally'];

    public function __construct(
        private readonly Connection $connection,
        private readonly ActionDefinitionDecoder $definitionDecoder,
        private readonly ActionValidator $actionValidator,
        private readonly ParameterValidator $parameterValidator
    ) {
    }

    public function create(
        string $backupRunId,
        string $actionId,
        string $facilityRepositoryId,
        string $targetHostId,
        string $phase,
        \DateTimeImmutable $availableAt,
        \DateTimeImmutable $phaseDeadline,
        int $maxAttempts,
        string $idempotencyKey
    ): string {
        foreach ([$backupRunId, $actionId, $facilityRepositoryId, $targetHostId] as $id) {
            if (preg_match('/^[0-9a-f]{32}$/D', $id) !== 1) {
                throw new \InvalidArgumentException('Job identifiers must be lowercase hexadecimal UUIDs.');
            }
        }
        if (!in_array($phase, self::PHASES, true)) {
            throw new \InvalidArgumentException('Unsupported job phase.');
        }
        if ($maxAttempts < 1 || $maxAttempts > 2147483647) {
            throw new \InvalidArgumentException('maxAttempts is out of range.');
        }
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 255) {
            throw new \InvalidArgumentException('Invalid idempotency key.');
        }

        $phaseDeadline = $phaseDeadline->setTimezone(new \DateTimeZone('UTC'));
        $phaseDeadline = $phaseDeadline->setTime(
            (int) $phaseDeadline->format('H'),
            (int) $phaseDeadline->format('i'),
            (int) $phaseDeadline->format('s'),
            0
        );
        $availableAt = $availableAt->setTimezone(new \DateTimeZone('UTC'));
        $executionDeadline = $phaseDeadline->modify('-15 seconds');
        $stopDeadline = $phaseDeadline->modify('-10 seconds');
        if ($availableAt >= $executionDeadline) {
            throw new \InvalidArgumentException('Availability must precede the execution deadline.');
        }

        $this->connection->beginTransaction();
        try {
            // Serialized against AgentRollback::start(): no job during a rollback.
            if (RollbackSwitch::isActive($this->connection, true)) {
                throw new RollbackActiveException('Agent rollback is active: no job is created.');
            }
            $now = new \DateTimeImmutable((string) $this->connection->fetchOne('SELECT UTC_TIMESTAMP(3)'), new \DateTimeZone('UTC'));
            if ($executionDeadline <= $now) {
                throw new \InvalidArgumentException('Execution deadline must be in the future.');
            }

            $host = $this->connection->fetchAssociative(
                'SELECT `capabilities` FROM `muwa_agent_host` '
                . 'WHERE `id` = UNHEX(:id) AND `enabled` = 1 AND `revoked_at` IS NULL FOR UPDATE',
                ['id' => $targetHostId]
            );
            if ($host === false) {
                throw new \InvalidArgumentException('Target host is unavailable.');
            }
            $action = $this->connection->fetchAssociative(
                'SELECT `definition`, `definition_hash`, `required_capabilities`, `idempotency_class` '
                . 'FROM `muwa_agent_action` '
                . 'WHERE `id` = UNHEX(:id) AND `enabled` = 1 FOR UPDATE',
                ['id' => $actionId]
            );
            if ($action === false) {
                throw new \InvalidArgumentException('Action is unavailable.');
            }

            $hostCapabilities = $this->decodeStringList((string) $host['capabilities'], 'host capabilities');
            $requiredCapabilities = $this->decodeStringList((string) $action['required_capabilities'], 'action capabilities');
            if (array_diff($requiredCapabilities, $hostCapabilities) !== []) {
                throw new \InvalidArgumentException('Target host lacks a required capability.');
            }
            $idempotencyClass = IdempotencyClass::tryFrom((string) $action['idempotency_class']);
            if ($idempotencyClass === null) {
                throw new \InvalidArgumentException('Action idempotency class is invalid.');
            }

            $definition = $this->definitionDecoder->decode((string) $action['definition']);
            $canonical = $this->actionValidator->canonicalize($definition);
            $hash = hash('sha256', $canonical, true);
            if (!hash_equals($hash, (string) $action['definition_hash'])) {
                throw new \InvalidArgumentException('Stored action hash does not match its canonical definition.');
            }
            $parameters = new \stdClass();
            $this->parameterValidator->validate($parameters);

            $jobId = Uuid::randomHex();
            $timestamp = $now->format(Defaults::STORAGE_DATE_TIME_FORMAT);
            $this->connection->insert('muwa_agent_job', [
                'id' => Uuid::fromHexToBytes($jobId),
                'backup_run_id' => Uuid::fromHexToBytes($backupRunId),
                'agent_action_id' => Uuid::fromHexToBytes($actionId),
                'facility_repository_id' => Uuid::fromHexToBytes($facilityRepositoryId),
                'phase' => $phase,
                'action_definition' => $canonical,
                'action_hash' => $hash,
                'payload' => '{}',
                'idempotency_class' => $idempotencyClass->value,
                'idempotency_key' => $idempotencyKey,
                'status' => 'queued',
                'target_agent_id' => Uuid::fromHexToBytes($targetHostId),
                'lease_version' => 0,
                'attempt' => 0,
                'max_attempts' => $maxAttempts,
                'available_at' => $availableAt->format(Defaults::STORAGE_DATE_TIME_FORMAT),
                'phase_deadline' => $phaseDeadline->format(Defaults::STORAGE_DATE_TIME_FORMAT),
                'execution_deadline' => $executionDeadline->format(Defaults::STORAGE_DATE_TIME_FORMAT),
                'stop_deadline' => $stopDeadline->format(Defaults::STORAGE_DATE_TIME_FORMAT),
                'created_at' => $timestamp,
            ]);
            $this->connection->commit();

            return $jobId;
        } catch (\Throwable $exception) {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
            throw $exception;
        }
    }

    /** @return list<string> */
    private function decodeStringList(string $json, string $name): array
    {
        try {
            $values = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \InvalidArgumentException('Invalid ' . $name . '.', 0, $exception);
        }
        if (!is_array($values) || !array_is_list($values)) {
            throw new \InvalidArgumentException('Invalid ' . $name . '.');
        }
        foreach ($values as $value) {
            if (!is_string($value)) {
                throw new \InvalidArgumentException('Invalid ' . $name . '.');
            }
        }

        return $values;
    }
}
