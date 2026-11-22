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

use MuckiSafeAgentPlugin\Service\Secret\AgentProvisioner;
use MuckiSafeAgentPlugin\Service\Secret\AgentSecretRotator;
use MuckiSafeAgentPlugin\Service\Secret\EncryptionKeyProvider;
use MuckiSafeAgentPlugin\Service\Secret\SecretCipher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SensitiveParameterTest extends TestCase
{
    /** @return iterable<string, array{class-string, string, string}> */
    public static function sensitiveParameters(): iterable
    {
        yield 'keyring json' => [EncryptionKeyProvider::class, '__construct', 'keyringJson'];
        yield 'cipher plaintext' => [SecretCipher::class, 'encrypt', 'plaintext'];
        yield 'cipher ciphertext' => [SecretCipher::class, 'decrypt', 'ciphertext'];
        yield 'cipher nonce' => [SecretCipher::class, 'decrypt', 'nonce'];
        yield 'encode secret' => [AgentProvisioner::class, 'encodeSecret', 'secret'];
        yield 'decode secret' => [AgentProvisioner::class, 'decodeSecret', 'secret'];
        yield 'candidate secret' => [AgentSecretRotator::class, 'isCurrentSecret', 'candidate'];
    }

    #[DataProvider('sensitiveParameters')]
    public function testSecretBearingParametersAreMarkedSensitive(string $class, string $method, string $parameter): void
    {
        $reflection = new \ReflectionParameter([$class, $method], $parameter);
        self::assertCount(1, $reflection->getAttributes(\SensitiveParameter::class));
    }

    public function testExceptionTraceRedactsInvalidKeyringWhenArgumentsAreEnabled(): void
    {
        $previous = ini_set('zend.exception_ignore_args', '0');
        try {
            new EncryptionKeyProvider('TOP-SECRET-KEYRING');
            self::fail('Invalid keyring must throw.');
        } catch (\InvalidArgumentException $exception) {
            $args = $exception->getTrace()[0]['args'] ?? [];
            self::assertCount(1, $args);
            self::assertInstanceOf(\SensitiveParameterValue::class, $args[0]);
            self::assertStringNotContainsString('TOP-SECRET-KEYRING', $exception->__toString());
        } finally {
            if ($previous !== false) {
                ini_set('zend.exception_ignore_args', $previous);
            }
        }
    }

    public function testExceptionTraceRedactsInvalidEncodedSecretWhenArgumentsAreEnabled(): void
    {
        $previous = ini_set('zend.exception_ignore_args', '0');
        try {
            AgentProvisioner::decodeSecret('TOP-SECRET-CANDIDATE');
            self::fail('Invalid secret must throw.');
        } catch (\InvalidArgumentException $exception) {
            $args = $exception->getTrace()[0]['args'] ?? [];
            self::assertCount(1, $args);
            self::assertInstanceOf(\SensitiveParameterValue::class, $args[0]);
            self::assertStringNotContainsString('TOP-SECRET-CANDIDATE', $exception->__toString());
        } finally {
            if ($previous !== false) {
                ini_set('zend.exception_ignore_args', $previous);
            }
        }
    }
}
