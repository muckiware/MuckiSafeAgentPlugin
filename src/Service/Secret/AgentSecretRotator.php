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

class AgentSecretRotator
{
    public function __construct(
        private readonly Connection $connection,
        private readonly SecretCipher $cipher,
        private readonly SecretWriteGuard $writeGuard,
        private readonly EncryptionKeyProvider $keyProvider
    ) {
    }

    /** @return array{id: string, secret: string} */
    public function rotate(string $id): array
    {
        $this->assertId($id);
        return $this->writeGuard->synchronized(
            $this->keyProvider->activeVersion(),
            fn (): array => $this->connection->transactional(function () use ($id): array {
            $row = $this->connection->fetchAssociative(
                'SELECT `revoked_at`, `secret_key_version` FROM `muwa_agent_host` WHERE `id` = UNHEX(:id) FOR UPDATE',
                ['id' => $id]
            );
            if (!is_array($row)) {
                throw new \RuntimeException('Agent not found.');
            }
            if ($row['revoked_at'] !== null) {
                throw new \RuntimeException('Agent is revoked.');
            }
            $this->cipher->validateVersion((int) $row['secret_key_version']);
            $secret = random_bytes(32);
            try {
                $encrypted = $this->cipher->encrypt($secret);
                $this->connection->executeStatement(
                    'UPDATE `muwa_agent_host` SET `secret_ciphertext` = :ciphertext, `secret_nonce` = :nonce, '
                    . '`secret_key_version` = :version, `updated_at` = :updatedAt WHERE `id` = UNHEX(:id)',
                    [
                        'ciphertext' => $encrypted['ciphertext'],
                        'nonce' => $encrypted['nonce'],
                        'version' => $encrypted['version'],
                        'updatedAt' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.v'),
                        'id' => $id,
                    ]
                );
                $this->writeGuard->markSecretMutation();
                return ['id' => $id, 'secret' => AgentProvisioner::encodeSecret($secret)];
            } finally {
                sodium_memzero($secret);
            }
        }));
    }

    public function revoke(string $id): void
    {
        $this->assertId($id);
        $this->writeGuard->synchronizedWithoutVersion(function () use ($id): void {
            $affected = $this->connection->executeStatement(
                'UPDATE `muwa_agent_host` SET `revoked_at` = COALESCE(`revoked_at`, :revokedAt), `updated_at` = :updatedAt WHERE `id` = UNHEX(:id)',
                [
                    'revokedAt' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.v'),
                    'updatedAt' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.v'),
                    'id' => $id,
                ]
            );
            if ($affected === 0 && (int) $this->connection->fetchOne(
                'SELECT EXISTS(SELECT 1 FROM `muwa_agent_host` WHERE `id` = UNHEX(:id))',
                ['id' => $id]
            ) !== 1) {
                throw new \RuntimeException('Agent not found.');
            }
            if ($affected > 1) {
                throw new \RuntimeException('Agent revoke affected multiple rows.');
            }
        });
    }

    public function isCurrentSecret(string $id, #[\SensitiveParameter] string $candidate): bool
    {
        $this->assertId($id);
        $candidateBytes = AgentProvisioner::decodeSecret($candidate);
        try {
            $row = $this->connection->fetchAssociative(
                'SELECT `secret_ciphertext`, `secret_nonce`, `secret_key_version`, `enabled`, `revoked_at` '
                . 'FROM `muwa_agent_host` WHERE `id` = UNHEX(:id)',
                ['id' => $id]
            );
            if (!is_array($row) || !(bool) $row['enabled'] || $row['revoked_at'] !== null) {
                return false;
            }
            $stored = $this->cipher->decrypt(
                (string) $row['secret_ciphertext'],
                (string) $row['secret_nonce'],
                (int) $row['secret_key_version']
            );
            try {
                return hash_equals($stored, $candidateBytes);
            } finally {
                sodium_memzero($stored);
            }
        } finally {
            sodium_memzero($candidateBytes);
        }
    }

    private function assertId(string $id): void
    {
        if (!preg_match('/^[0-9a-f]{32}$/D', $id)) {
            throw new \InvalidArgumentException('Invalid agent id.');
        }
    }
}
