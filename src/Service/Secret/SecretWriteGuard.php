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
namespace MuckiSafeAgentPlugin\Service\Secret;

use Doctrine\DBAL\Connection;
use MuckiSafeAgentPlugin\Migration\Migration1795305600CreateSecretState;

final class SecretWriteGuard
{
    /** Legacy reserved names. They are intentionally never read as security state. */
    public const FENCE_CONFIG_KEY = 'MuckiSafeAgentPlugin.secretMinimumWriteVersion';
    public const MUTATION_CONFIG_KEY = 'MuckiSafeAgentPlugin.secretMutationGeneration';

    private ?string $lockName = null;
    private int $lockDepth = 0;

    public function __construct(private readonly Connection $connection)
    {
    }

    /** @template T
     * @param \Closure(): T $operation
     * @return T
     */
    public function synchronized(int $writeVersion, \Closure $operation): mixed
    {
        return $this->withLock(function () use ($writeVersion, $operation): mixed {
            if ($writeVersion < $this->fenceVersion()) {
                throw new \RuntimeException('Encryption key version is below persistent fence.');
            }
            return $operation();
        });
    }

    /** @template T
     * @param \Closure(): T $operation
     * @return T
     */
    public function synchronizedWithoutVersion(\Closure $operation): mixed
    {
        return $this->withLock($operation);
    }

    public function fenceVersion(): int
    {
        return $this->stateValue('fence_version', 'Encryption version fence');
    }

    public function mutationGeneration(): int
    {
        return $this->stateValue('mutation_generation', 'Secret mutation generation');
    }

    public function markSecretMutation(): void
    {
        $this->withLock(function (): void {
            $affected = $this->connection->executeStatement(
                'UPDATE `' . Migration1795305600CreateSecretState::TABLE_NAME . '` '
                . 'SET `mutation_generation` = `mutation_generation` + 1, `updated_at` = :updatedAt WHERE `id` = :id',
                [
                    'updatedAt' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.v'),
                    'id' => Migration1795305600CreateSecretState::SINGLETON_ID,
                ]
            );
            if ($affected !== 1) {
                throw new \RuntimeException('Secret state is unavailable.');
            }
        });
    }

    public function advanceFence(int $version): void
    {
        if ($version < 1) {
            throw new \InvalidArgumentException('Invalid encryption version fence.');
        }

        $this->withLock(function () use ($version): void {
            $current = $this->fenceVersion();
            if ($version <= $current) {
                return;
            }
            $affected = $this->connection->executeStatement(
                'UPDATE `' . Migration1795305600CreateSecretState::TABLE_NAME . '` '
                . 'SET `fence_version` = :version, `updated_at` = :updatedAt '
                . 'WHERE `id` = :id AND `fence_version` < :version',
                [
                    'version' => $version,
                    'updatedAt' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.v'),
                    'id' => Migration1795305600CreateSecretState::SINGLETON_ID,
                ]
            );
            if ($affected !== 1) {
                throw new \RuntimeException('Encryption version fence update failed.');
            }
        });
    }

    public function lockName(): string
    {
        if ($this->lockName === null) {
            $database = (string) $this->connection->fetchOne('SELECT DATABASE()');
            $this->lockName = 'muwa-agent-secret-' . substr(hash('sha256', $database), 0, 32);
        }
        return $this->lockName;
    }

    private function stateValue(string $column, string $label): int
    {
        $value = $this->connection->fetchOne(
            'SELECT `' . $column . '` FROM `' . Migration1795305600CreateSecretState::TABLE_NAME . '` WHERE `id` = :id',
            ['id' => Migration1795305600CreateSecretState::SINGLETON_ID]
        );
        if (!is_int($value) && (!is_string($value) || !preg_match('/^(0|[1-9][0-9]*)$/D', $value))) {
            throw new \RuntimeException($label . ' is invalid.');
        }
        $integer = (int) $value;
        if ($integer < 0) {
            throw new \RuntimeException($label . ' is invalid.');
        }
        return $integer;
    }

    /** @template T
     * @param \Closure(): T $operation
     * @return T
     */
    private function withLock(\Closure $operation): mixed
    {
        if ($this->lockDepth > 0) {
            ++$this->lockDepth;
            try {
                return $operation();
            } finally {
                --$this->lockDepth;
            }
        }

        $acquired = (int) $this->connection->fetchOne('SELECT GET_LOCK(:name, 10)', ['name' => $this->lockName()]);
        if ($acquired !== 1) {
            throw new \RuntimeException('Could not acquire secret write lock.');
        }
        $this->lockDepth = 1;
        try {
            return $operation();
        } finally {
            $this->lockDepth = 0;
            $this->connection->fetchOne('SELECT RELEASE_LOCK(:name)', ['name' => $this->lockName()]);
        }
    }
}
