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
namespace MuckiSafeAgentPlugin\tests\Unit\Service\Secret;

use MuckiSafeAgentPlugin\Service\Secret\EncryptionKeyProvider;
use MuckiSafeAgentPlugin\Service\Secret\SecretCipher;
use PHPUnit\Framework\TestCase;

final class SecretCipherTest extends TestCase
{
    private const KEY = '000102030405060708090a0b0c0d0e0f101112131415161718191a1b1c1d1e1f';

    public function testEncryptDecryptUsesActiveVersionAndRandomNonce(): void
    {
        $cipher = $this->cipher();
        $plaintext = random_bytes(32);

        $first = $cipher->encrypt($plaintext);
        $second = $cipher->encrypt($plaintext);

        self::assertSame(1, $first['version']);
        self::assertSame(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES, strlen($first['nonce']));
        self::assertSame(strlen($plaintext) + SODIUM_CRYPTO_SECRETBOX_MACBYTES, strlen($first['ciphertext']));
        self::assertNotSame($first['nonce'], $second['nonce']);
        self::assertNotSame($first['ciphertext'], $second['ciphertext']);
        self::assertSame($plaintext, $cipher->decrypt($first['ciphertext'], $first['nonce'], $first['version']));
    }

    public function testCiphertextTamperingFailsClosed(): void
    {
        $cipher = $this->cipher();
        $encrypted = $cipher->encrypt(random_bytes(32));
        $encrypted['ciphertext'][0] = chr(ord($encrypted['ciphertext'][0]) ^ 1);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Secret decryption failed.');
        $cipher->decrypt($encrypted['ciphertext'], $encrypted['nonce'], $encrypted['version']);
    }

    public function testInvalidNonceFailsClosed(): void
    {
        $cipher = $this->cipher();
        $encrypted = $cipher->encrypt(random_bytes(32));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Secret decryption failed.');
        $cipher->decrypt($encrypted['ciphertext'], 'short', $encrypted['version']);
    }

    public function testUnknownVersionFailsClosed(): void
    {
        $cipher = $this->cipher();
        $encrypted = $cipher->encrypt(random_bytes(32));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unknown encryption key version.');
        $cipher->decrypt($encrypted['ciphertext'], $encrypted['nonce'], 2);
    }

    private function cipher(): SecretCipher
    {
        return new SecretCipher(new EncryptionKeyProvider('{"active":1,"keys":{"1":"' . self::KEY . '"}}'));
    }
}
