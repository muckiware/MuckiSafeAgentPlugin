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

class SecretCipher
{
    public function __construct(private readonly EncryptionKeyProvider $keyProvider)
    {
    }

    /** @return array{ciphertext: string, nonce: string, version: int} */
    public function encrypt(#[\SensitiveParameter] string $plaintext): array
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return [
            'ciphertext' => sodium_crypto_secretbox($plaintext, $nonce, $this->keyProvider->activeKey()),
            'nonce' => $nonce,
            'version' => $this->keyProvider->activeVersion(),
        ];
    }

    public function decrypt(
        #[\SensitiveParameter] string $ciphertext,
        #[\SensitiveParameter] string $nonce,
        int $version
    ): string
    {
        $key = $this->keyProvider->keyForVersion($version);
        if (strlen($nonce) !== SODIUM_CRYPTO_SECRETBOX_NONCEBYTES || strlen($ciphertext) < SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            throw new \RuntimeException('Secret decryption failed.');
        }
        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $key);
        if (!is_string($plaintext)) {
            throw new \RuntimeException('Secret decryption failed.');
        }
        return $plaintext;
    }

    public function validateVersion(int $version): void
    {
        $this->keyProvider->keyForVersion($version);
    }
}
