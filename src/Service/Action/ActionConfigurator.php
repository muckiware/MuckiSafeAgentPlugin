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
namespace MuckiSafeAgentPlugin\Service\Action;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use MuckiSafeAgentPlugin\Core\Job\IdempotencyClass;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Single write path for agent actions. The definition is decoded strictly,
 * validated against the V1 DSL and stored canonically together with its
 * SHA-256 hash, so JobCreator's hash re-check always holds. Required
 * capabilities are the sorted set of primitives the steps use; the retry
 * policy follows the idempotency class (HostAgentHookExecutor requeues only
 * `safe` actions). The technical name is immutable after creation.
 */
final class ActionConfigurator
{
    public const RETRY_POLICY_SAFE = 'safe_requeue';
    public const RETRY_POLICY_NONE = 'none';

    public function __construct(
        private readonly Connection $connection,
        private readonly ActionDefinitionDecoder $decoder,
        private readonly ActionValidator $validator
    ) {
    }

    /** @return array{canonical: string, hash: string, requiredCapabilities: list<string>} */
    public function validate(string $definitionJson): array
    {
        try {
            $definition = $this->decoder->decode($definitionJson);
            $canonical = $this->validator->canonicalize($definition);
        } catch (ActionDefinitionException $exception) {
            throw ActionConfigurationException::invalidDefinition($exception);
        }

        return [
            'canonical' => $canonical,
            'hash' => hash('sha256', $canonical),
            'requiredCapabilities' => $this->requiredCapabilities($definition),
        ];
    }

    /** @return string the action id */
    public function save(ActionDraft $draft): string
    {
        $validated = $this->validate($draft->definition);
        $row = [
            'label' => $draft->label,
            'enabled' => $draft->enabled ? 1 : 0,
            'definition' => $validated['canonical'],
            'definition_hash' => hash('sha256', $validated['canonical'], true),
            'parameter_schema' => null,
            'required_capabilities' => json_encode($validated['requiredCapabilities'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'default_timeout_seconds' => $draft->defaultTimeoutSeconds,
            'max_timeout_seconds' => $draft->maxTimeoutSeconds,
            'retry_policy' => $draft->idempotencyClass === IdempotencyClass::Safe ? self::RETRY_POLICY_SAFE : self::RETRY_POLICY_NONE,
            'idempotency_class' => $draft->idempotencyClass->value,
        ];

        return $draft->id === null ? $this->insert($draft, $row) : $this->update($draft, $draft->id, $row);
    }

    /** @param array<string, mixed> $row */
    private function insert(ActionDraft $draft, array $row): string
    {
        $id = Uuid::randomHex();
        try {
            $this->connection->insert('muwa_agent_action', $row + [
                'id' => Uuid::fromHexToBytes($id),
                'technical_name' => $draft->technicalName,
                'created_at' => (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
            ]);
        } catch (UniqueConstraintViolationException $exception) {
            throw new ActionConfigurationException(
                ActionConfigurationException::TECHNICAL_NAME_TAKEN,
                'The technical name is already in use.',
                ['technicalName'],
                null,
                $exception
            );
        }

        return $id;
    }

    /** @param array<string, mixed> $row */
    private function update(ActionDraft $draft, string $id, array $row): string
    {
        return $this->connection->transactional(function () use ($draft, $id, $row): string {
            $technicalName = $this->connection->fetchOne(
                'SELECT `technical_name` FROM `muwa_agent_action` WHERE `id` = UNHEX(:id) FOR UPDATE',
                ['id' => $id]
            );
            if ($technicalName === false) {
                throw new ActionConfigurationException(ActionConfigurationException::ACTION_NOT_FOUND, 'The action does not exist.');
            }
            if ($technicalName !== $draft->technicalName) {
                throw ActionConfigurationException::invalidFields(['technicalName']);
            }
            $row['updated_at'] = (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT);
            $this->connection->update('muwa_agent_action', $row, ['id' => Uuid::fromHexToBytes($id)]);

            return $id;
        });
    }

    /** @return list<string> */
    private function requiredCapabilities(\stdClass $definition): array
    {
        $primitives = [];
        foreach ($definition->steps as $step) {
            $primitives[$step->primitive] = true;
        }
        $capabilities = array_map('strval', array_keys($primitives));
        sort($capabilities, SORT_STRING);

        return $capabilities;
    }
}
