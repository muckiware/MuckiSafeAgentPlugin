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
        yield 'empty' => [''];
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

    #[DataProvider('invalidKeyrings')]
    public function testInvalidKeyringsFailClosed(string $json): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid encryption keyring');

        new EncryptionKeyProvider($json);
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
