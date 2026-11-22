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

require_once dirname(__DIR__, 4) . '/src/Migration/Migration1795305600CreateSecretState.php';
foreach (glob(dirname(__DIR__, 4) . '/src/Service/Secret/*.php') ?: [] as $secretServiceFile) {
    require_once $secretServiceFile;
}

use Doctrine\DBAL\Connection;
use MuckiSafeAgentPlugin\Service\Secret\AgentSecretRotator;
use MuckiSafeAgentPlugin\Service\Secret\EncryptionKeyProvider;
use MuckiSafeAgentPlugin\Service\Secret\SecretCipher;
use MuckiSafeAgentPlugin\Service\Secret\SecretWriteGuard;
use PHPUnit\Framework\TestCase;

final class AgentSecretRotatorTest extends TestCase
{
    private const KEYRING = '{"active":1,"keys":{"1":"000102030405060708090a0b0c0d0e0f101112131415161718191a1b1c1d1e1f"}}';

    public function testRepeatedRevokeTreatsZeroAffectedRowsAsSuccessWhenAgentStillExists(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturnCallback(static function (string $sql): int|string {
            if ($sql === 'SELECT DATABASE()') {
                return 'db_test';
            }
            if (str_starts_with($sql, 'SELECT GET_LOCK')) {
                return 1;
            }
            if (str_starts_with($sql, 'SELECT RELEASE_LOCK')) {
                return 1;
            }
            if (str_starts_with($sql, 'SELECT EXISTS')) {
                return 1;
            }
            throw new \LogicException('Unexpected SQL: ' . $sql);
        });
        $connection->method('executeStatement')->willReturn(0);
        $provider = new EncryptionKeyProvider(self::KEYRING);
        $rotator = new AgentSecretRotator(
            $connection,
            new SecretCipher($provider),
            new SecretWriteGuard($connection),
            $provider
        );

        $rotator->revoke(str_repeat('a', 32));

        self::addToAssertionCount(1);
    }
}
