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
use Shopware\Core\Framework\Uuid\Uuid;

class AgentProvisioner
{
    public function __construct(
        private readonly Connection $connection,
        private readonly SecretCipher $cipher,
        private readonly SecretWriteGuard $writeGuard,
        private readonly EncryptionKeyProvider $keyProvider
    ) {
    }

    /** @return array{id: string, keyId: string, secret: string} */
    public function provision(string $name): array
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 255) {
            throw new \InvalidArgumentException('Invalid agent name.');
        }
        return $this->writeGuard->synchronized(
            $this->keyProvider->activeVersion(),
            fn (): array => $this->connection->transactional(function () use ($name): array {
                $id = Uuid::randomHex();
                $keyId = bin2hex(random_bytes(16));
                $secretBytes = random_bytes(32);
                try {
                $encrypted = $this->cipher->encrypt($secretBytes);
                $this->connection->executeStatement(
                    'INSERT INTO `muwa_agent_host` '
                    . '(`id`, `name`, `enabled`, `key_id`, `secret_ciphertext`, `secret_nonce`, `secret_key_version`, `capabilities`, `created_at`) '
                    . 'VALUES (UNHEX(:id), :name, 1, :keyId, :ciphertext, :nonce, :version, :capabilities, :createdAt)',
                    [
                        'id' => $id,
                        'name' => $name,
                        'keyId' => $keyId,
                        'ciphertext' => $encrypted['ciphertext'],
                        'nonce' => $encrypted['nonce'],
                        'version' => $encrypted['version'],
                        'capabilities' => '[]',
                        'createdAt' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.v'),
                    ]
                );
                $this->writeGuard->markSecretMutation();
                return ['id' => $id, 'keyId' => $keyId, 'secret' => self::encodeSecret($secretBytes)];
                } finally {
                    sodium_memzero($secretBytes);
                }
            })
        );
    }

    public static function encodeSecret(#[\SensitiveParameter] string $secret): string
    {
        return rtrim(strtr(base64_encode($secret), '+/', '-_'), '=');
    }

    public static function decodeSecret(#[\SensitiveParameter] string $secret): string
    {
        if (!preg_match('/^[A-Za-z0-9_-]{43}$/D', $secret)) {
            throw new \InvalidArgumentException('Invalid agent secret.');
        }
        $decoded = base64_decode(strtr($secret, '-_', '+/') . '=', true);
        if (!is_string($decoded) || strlen($decoded) !== 32) {
            throw new \InvalidArgumentException('Invalid agent secret.');
        }
        return $decoded;
    }
}
