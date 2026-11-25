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
use MuckiSafeAgentPlugin\Service\Secret\EncryptionKeyringException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EncryptionKeyProviderTest extends TestCase
{
    private const KEY1 = '000102030405060708090a0b0c0d0e0f101112131415161718191a1b1c1d1e1f';
    private const KEY2 = 'ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff';

    public function testValidKeyringProvidesBinaryKeysByVersion(): void
    {
        $provider = new EncryptionKeyProvider('{"active":2,"keys":{"1":"' . self::KEY1 . '","2":"' . self::KEY2 . '"}}');

        self::assertSame(2, $provider->activeVersion());
        self::assertSame(32, strlen($provider->activeKey()));
        self::assertSame(hex2bin(self::KEY1), $provider->keyForVersion(1));
        self::assertSame([1, 2], $provider->versions());
    }

    /** @return iterable<string, array{string}> */
    public static function invalidKeyrings(): iterable
    {
        yield 'malformed' => ['{"active":1'];
        yield 'array root' => ['[]'];
        yield 'missing active' => ['{"keys":{"1":"' . self::KEY1 . '"}}'];
        yield 'active zero' => ['{"active":0,"keys":{"1":"' . self::KEY1 . '"}}'];
        yield 'active string' => ['{"active":"1","keys":{"1":"' . self::KEY1 . '"}}'];
        yield 'active absent from keys' => ['{"active":2,"keys":{"1":"' . self::KEY1 . '"}}'];
        yield 'keys list' => ['{"active":1,"keys":[]}'];
        yield 'version zero' => ['{"active":1,"keys":{"0":"' . self::KEY1 . '","1":"' . self::KEY1 . '"}}'];
        yield 'non canonical version' => ['{"active":1,"keys":{"01":"' . self::KEY1 . '","1":"' . self::KEY1 . '"}}'];
        yield 'short key' => ['{"active":1,"keys":{"1":"00"}}'];
        yield 'non hex key' => ['{"active":1,"keys":{"1":"zzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzz"}}'];
        yield 'duplicate active' => ['{"active":1,"active":2,"keys":{"1":"' . self::KEY1 . '","2":"' . self::KEY2 . '"}}'];
        yield 'duplicate version' => ['{"active":1,"keys":{"1":"' . self::KEY1 . '","1":"' . self::KEY2 . '"}}'];
        yield 'unexpected top level' => ['{"active":1,"keys":{"1":"' . self::KEY1 . '"},"debug":true}'];
    }

    /**
     * The keyring is only validated on first use: a missing or broken keyring
     * must not break the container (and with it every request of the shop),
     * only the agent operations that need a key.
     */
    #[DataProvider('invalidKeyrings')]
    public function testInvalidKeyringsFailClosedOnEveryUse(string $json): void
    {
        $provider = new EncryptionKeyProvider($json);

        $this->assertEveryAccessFails($provider, 'Invalid encryption keyring.');
    }

    /** @return iterable<string, array{string}> */
    public static function missingKeyrings(): iterable
    {
        yield 'empty' => [''];
        yield 'whitespace' => ["  \n"];
    }

    #[DataProvider('missingKeyrings')]
    public function testMissingKeyringNamesTheVariableOnEveryUse(string $json): void
    {
        $provider = new EncryptionKeyProvider($json);

        $this->assertEveryAccessFails($provider, 'MUWA_AGENT_ENCRYPTION_KEYRING is not configured.');
    }

    private function assertEveryAccessFails(EncryptionKeyProvider $provider, string $message): void
    {
        $accessors = [
            'activeVersion' => static fn () => $provider->activeVersion(),
            'activeKey' => static fn () => $provider->activeKey(),
            'keyForVersion' => static fn () => $provider->keyForVersion(1),
            'versions' => static fn () => $provider->versions(),
        ];
        foreach ([1, 2] as $round) {
            foreach ($accessors as $name => $access) {
                try {
                    $access();
                    self::fail(sprintf('%s must fail closed (round %d).', $name, $round));
                } catch (EncryptionKeyringException $exception) {
                    self::assertSame($message, $exception->getMessage(), $name);
                    self::assertStringNotContainsString(self::KEY1, (string) $exception, $name);
                }
            }
        }
    }

    public function testUnknownVersionFailsClosedWithoutLeakingConfiguration(): void
    {
        $provider = new EncryptionKeyProvider('{"active":1,"keys":{"1":"' . self::KEY1 . '"}}');

        try {
            $provider->keyForVersion(99);
            self::fail('Unknown version must fail closed.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Unknown encryption key version.', $exception->getMessage());
            self::assertStringNotContainsString(self::KEY1, $exception->getMessage());
        }
    }
}
