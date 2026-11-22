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

class MasterKeyReencryptor
{
    public const READBACK_CONFIG_PREFIX = 'MuckiSafeAgentPlugin.secretReadbackState.';

    public function __construct(
        private readonly Connection $connection,
        private readonly SecretCipher $cipher,
        private readonly EncryptionKeyProvider $keyProvider,
        private readonly SecretWriteGuard $writeGuard
    ) {
    }

    /** @return array{processed: int, lastId: ?string, complete: bool, removalAllowed: bool} */
    public function reencryptBatch(int $fromVersion, int $batchSize = 100): array
    {
        $activeVersion = $this->validateVersions($fromVersion);
        $this->validateBatchSize($batchSize);

        return $this->writeGuard->synchronized($activeVersion, function () use ($fromVersion, $activeVersion, $batchSize): array {
            $migration = $this->reencryptRows($fromVersion, $batchSize);
            if ($migration['processed'] > 0) {
                $this->deleteReadbackState($fromVersion);
                return ['processed' => $migration['processed'], 'lastId' => $migration['lastId'], 'complete' => false, 'removalAllowed' => false];
            }
            $readback = $this->readbackBatch($fromVersion, $activeVersion, $batchSize);
            return [
                'processed' => $readback['processed'],
                'lastId' => $readback['lastId'],
                'complete' => $readback['allowed'],
                'removalAllowed' => $readback['allowed'],
            ];
        });
    }

    public function readbackAndCanRemove(int $oldVersion, int $batchSize = 100): bool
    {
        $activeVersion = $this->validateVersions($oldVersion);
        $this->validateBatchSize($batchSize);
        return $this->writeGuard->synchronized(
            $activeVersion,
            fn (): bool => $this->readbackBatch($oldVersion, $activeVersion, $batchSize)['allowed']
        );
    }

    private function validateVersions(int $oldVersion): int
    {
        $this->keyProvider->keyForVersion($oldVersion);
        $activeVersion = $this->keyProvider->activeVersion();
        if ($oldVersion === $activeVersion) {
            throw new \InvalidArgumentException('Source version is active.');
        }
        if ($activeVersion < $oldVersion) {
            throw new \InvalidArgumentException('Active version must be newer than source version.');
        }
        return $activeVersion;
    }

    private function validateBatchSize(int $batchSize): void
    {
        if ($batchSize < 1 || $batchSize > 1000) {
            throw new \InvalidArgumentException('Invalid re-encryption batch request.');
        }
    }

    /** @return array{processed: int, lastId: ?string} */
    private function reencryptRows(int $fromVersion, int $batchSize): array
    {
        $lastId = null;
        $processed = $this->connection->transactional(function () use ($fromVersion, $batchSize, &$lastId): int {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT `id`, `secret_ciphertext`, `secret_nonce`, `secret_key_version` FROM `muwa_agent_host` '
                . 'WHERE `secret_key_version` = :version ORDER BY `id` ASC LIMIT ' . $batchSize . ' FOR UPDATE',
                ['version' => $fromVersion]
            );
            foreach ($rows as $row) {
                $plaintext = $this->cipher->decrypt((string) $row['secret_ciphertext'], (string) $row['secret_nonce'], (int) $row['secret_key_version']);
                try {
                    $encrypted = $this->cipher->encrypt($plaintext);
                    $affected = $this->connection->executeStatement(
                        'UPDATE `muwa_agent_host` SET `secret_ciphertext` = :ciphertext, `secret_nonce` = :nonce, '
                        . '`secret_key_version` = :newVersion, `updated_at` = :updatedAt '
                        . 'WHERE `id` = :id AND `secret_key_version` = :oldVersion',
                        [
                            'ciphertext' => $encrypted['ciphertext'], 'nonce' => $encrypted['nonce'],
                            'newVersion' => $encrypted['version'], 'updatedAt' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.v'),
                            'id' => $row['id'], 'oldVersion' => $fromVersion,
                        ]
                    );
                    if ($affected !== 1) {
                        throw new \RuntimeException('Secret re-encryption lost update.');
                    }
                    $this->verifyWrittenRow((string) $row['id'], $plaintext);
                    $lastId = bin2hex((string) $row['id']);
                } finally {
                    sodium_memzero($plaintext);
                }
            }
            return count($rows);
        });
        return ['processed' => $processed, 'lastId' => $lastId];
    }

    private function verifyWrittenRow(string $id, string $expectedPlaintext): void
    {
        $row = $this->connection->fetchAssociative(
            'SELECT `secret_ciphertext`, `secret_nonce`, `secret_key_version` FROM `muwa_agent_host` WHERE `id` = :id',
            ['id' => $id]
        );
        if (!is_array($row)) {
            throw new \RuntimeException('Secret readback failed.');
        }
        $verified = $this->cipher->decrypt((string) $row['secret_ciphertext'], (string) $row['secret_nonce'], (int) $row['secret_key_version']);
        try {
            if (!hash_equals($expectedPlaintext, $verified)) {
                throw new \RuntimeException('Secret readback failed.');
            }
        } finally {
            sodium_memzero($verified);
        }
    }

    /** @return array{processed: int, lastId: ?string, allowed: bool} */
    private function readbackBatch(int $oldVersion, int $activeVersion, int $batchSize): array
    {
        $state = $this->readbackState($oldVersion);
        $generation = $this->writeGuard->mutationGeneration();
        $stateMatches = $state !== null
            && $state['activeVersion'] === $activeVersion
            && $state['generation'] === $generation;
        $cursor = $stateMatches ? $state['cursor'] : null;
        if ($state !== null && !$stateMatches) {
            $this->deleteReadbackState($oldVersion);
        }

        $sql = 'SELECT `id`, `secret_ciphertext`, `secret_nonce`, `secret_key_version` FROM `muwa_agent_host`';
        $parameters = [];
        if ($cursor !== null) {
            $sql .= ' WHERE `id` > UNHEX(:cursor)';
            $parameters['cursor'] = $cursor;
        }
        $sql .= ' ORDER BY `id` ASC LIMIT ' . $batchSize;
        $rows = $this->connection->fetchAllAssociative($sql, $parameters);
        $lastId = $cursor;
        foreach ($rows as $row) {
            $plaintext = $this->cipher->decrypt((string) $row['secret_ciphertext'], (string) $row['secret_nonce'], (int) $row['secret_key_version']);
            sodium_memzero($plaintext);
            $lastId = bin2hex((string) $row['id']);
        }

        if ($lastId !== null && (int) $this->connection->fetchOne(
            'SELECT EXISTS(SELECT 1 FROM `muwa_agent_host` WHERE `id` > UNHEX(:cursor) LIMIT 1)',
            ['cursor' => $lastId]
        ) === 1) {
            $this->writeReadbackState($oldVersion, $activeVersion, $generation, $lastId);
            return ['processed' => count($rows), 'lastId' => $lastId, 'allowed' => false];
        }

        if ((int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM `muwa_agent_host` WHERE `secret_key_version` = :version',
            ['version' => $oldVersion]
        ) !== 0) {
            $this->deleteReadbackState($oldVersion);
            return ['processed' => count($rows), 'lastId' => $lastId, 'allowed' => false];
        }

        $this->writeGuard->advanceFence($activeVersion);
        $this->deleteReadbackState($oldVersion);
        return ['processed' => count($rows), 'lastId' => $lastId, 'allowed' => true];
    }

    /** @return array{activeVersion: int, generation: int, cursor: string}|null */
    private function readbackState(int $oldVersion): ?array
    {
        $state = $this->connection->fetchAssociative(
            'SELECT `readback_old_version`, `readback_active_version`, `readback_generation`, `readback_cursor` '
            . 'FROM `' . Migration1795305600CreateSecretState::TABLE_NAME . '` WHERE `id` = :id',
            ['id' => Migration1795305600CreateSecretState::SINGLETON_ID]
        );
        if (!is_array($state)) {
            throw new \RuntimeException('Secret readback state is invalid.');
        }
        if ($state['readback_old_version'] === null
            && $state['readback_active_version'] === null
            && $state['readback_generation'] === null
            && $state['readback_cursor'] === null) {
            return null;
        }
        foreach (['readback_old_version', 'readback_active_version', 'readback_generation'] as $column) {
            if (!is_int($state[$column]) && (!is_string($state[$column]) || !preg_match('/^(0|[1-9][0-9]*)$/D', $state[$column]))) {
                throw new \RuntimeException('Secret readback state is invalid.');
            }
        }
        $storedOldVersion = (int) $state['readback_old_version'];
        $activeVersion = (int) $state['readback_active_version'];
        $generation = (int) $state['readback_generation'];
        $cursorBytes = $state['readback_cursor'];
        if ($storedOldVersion < 1 || $activeVersion < 1 || $generation < 0
            || !is_string($cursorBytes) || strlen($cursorBytes) !== 16) {
            throw new \RuntimeException('Secret readback state is invalid.');
        }
        if ($storedOldVersion !== $oldVersion) {
            return null;
        }
        return ['activeVersion' => $activeVersion, 'generation' => $generation, 'cursor' => bin2hex($cursorBytes)];
    }

    private function writeReadbackState(int $oldVersion, int $activeVersion, int $generation, string $cursor): void
    {
        if (!preg_match('/^[0-9a-f]{32}$/D', $cursor)) {
            throw new \RuntimeException('Secret readback state is invalid.');
        }
        $affected = $this->connection->executeStatement(
            'UPDATE `' . Migration1795305600CreateSecretState::TABLE_NAME . '` SET '
            . '`readback_old_version` = :oldVersion, `readback_active_version` = :activeVersion, '
            . '`readback_generation` = :generation, `readback_cursor` = UNHEX(:cursor), `updated_at` = :updatedAt '
            . 'WHERE `id` = :id',
            [
                'oldVersion' => $oldVersion,
                'activeVersion' => $activeVersion,
                'generation' => $generation,
                'cursor' => $cursor,
                'updatedAt' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.v'),
                'id' => Migration1795305600CreateSecretState::SINGLETON_ID,
            ]
        );
        if ($affected !== 1) {
            throw new \RuntimeException('Secret readback state update failed.');
        }
    }

    private function deleteReadbackState(int $oldVersion): void
    {
        $this->connection->executeStatement(
            'UPDATE `' . Migration1795305600CreateSecretState::TABLE_NAME . '` SET '
            . '`readback_old_version` = NULL, `readback_active_version` = NULL, '
            . '`readback_generation` = NULL, `readback_cursor` = NULL, `updated_at` = :updatedAt '
            . 'WHERE `id` = :id AND `readback_old_version` = :oldVersion',
            [
                'updatedAt' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.v'),
                'id' => Migration1795305600CreateSecretState::SINGLETON_ID,
                'oldVersion' => $oldVersion,
            ]
        );
    }
}
