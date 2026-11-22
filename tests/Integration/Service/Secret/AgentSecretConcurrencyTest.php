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
namespace MuckiSafeAgentPlugin\tests\Integration\Service\Secret;

require_once dirname(__DIR__, 4) . '/src/Migration/Migration1795132800CreateAgentSchema.php';
require_once dirname(__DIR__, 4) . '/src/Migration/Migration1795305600CreateSecretState.php';

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use MuckiSafeAgentPlugin\Migration\Migration1795132800CreateAgentSchema;
use MuckiSafeAgentPlugin\Migration\Migration1795305600CreateSecretState;
use MuckiSafeAgentPlugin\Service\Secret\AgentProvisioner;
use MuckiSafeAgentPlugin\Service\Secret\AgentSecretRotator;
use MuckiSafeAgentPlugin\Service\Secret\EncryptionKeyProvider;
use MuckiSafeAgentPlugin\Service\Secret\MasterKeyReencryptor;
use MuckiSafeAgentPlugin\Service\Secret\SecretCipher;
use MuckiSafeAgentPlugin\Service\Secret\SecretWriteGuard;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Test\TestCaseBase\KernelTestBehaviour;

final class AgentSecretConcurrencyTest extends TestCase
{
    use KernelTestBehaviour;

    private const KEY1 = '000102030405060708090a0b0c0d0e0f101112131415161718191a1b1c1d1e1f';
    private const KEY2 = 'ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff';

    private Connection $connection;

    protected function setUp(): void
    {
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->dropSchema();
        (new Migration1795132800CreateAgentSchema())->update($this->connection);
        (new Migration1795305600CreateSecretState())->update($this->connection);
        $this->connection->executeStatement("DELETE FROM `system_config` WHERE `configuration_key` = 'MuckiSafeAgentPlugin.secretMinimumWriteVersion' OR `configuration_key` LIKE 'MuckiSafeAgentPlugin.secretReadbackState.%' OR `configuration_key` = 'MuckiSafeAgentPlugin.secretMutationGeneration'");
    }

    protected function tearDown(): void
    {
        try {
            $guard = new SecretWriteGuard($this->connection);
            $this->connection->fetchOne('SELECT RELEASE_LOCK(:name)', ['name' => $guard->lockName()]);
        } catch (\Throwable) {
        }
        $this->connection->executeStatement("DELETE FROM `system_config` WHERE `configuration_key` = 'MuckiSafeAgentPlugin.secretMinimumWriteVersion' OR `configuration_key` LIKE 'MuckiSafeAgentPlugin.secretReadbackState.%' OR `configuration_key` = 'MuckiSafeAgentPlugin.secretMutationGeneration'");
        $this->dropSchema();
    }

    public function testConcurrentRotationsSerializeAndExactlyOneReturnedSecretIsCurrent(): void
    {
        $services = $this->services($this->connection, 1);
        $agent = $services['provisioner']->provision('host-a');
        $results = $this->runConcurrently([
            fn (Connection $connection): string => 'rotated:' . $this->services($connection, 1)['rotator']->rotate($agent['id'])['secret'],
            fn (Connection $connection): string => 'rotated:' . $this->services($connection, 1)['rotator']->rotate($agent['id'])['secret'],
        ]);

        self::assertCount(2, array_filter($results, static fn (string $result): bool => str_starts_with($result, 'rotated:')));
        $current = 0;
        foreach ($results as $result) {
            $secret = substr($result, strlen('rotated:'));
            $current += $services['rotator']->isCurrentSecret($agent['id'], $secret) ? 1 : 0;
        }
        self::assertSame(1, $current);
    }

    public function testConcurrentRotateAndRevokeEndRevokedWithNoValidSecret(): void
    {
        $services = $this->services($this->connection, 1);
        $agent = $services['provisioner']->provision('host-a');
        $results = $this->runConcurrently([
            fn (Connection $connection): string => 'rotated:' . $this->services($connection, 1)['rotator']->rotate($agent['id'])['secret'],
            function (Connection $connection) use ($agent): string {
                $this->services($connection, 1)['rotator']->revoke($agent['id']);
                return 'revoked';
            },
        ], true);

        self::assertContains('revoked', $results);
        self::assertNotNull($this->connection->fetchOne('SELECT `revoked_at` FROM `muwa_agent_host` WHERE `id` = UNHEX(:id)', ['id' => $agent['id']]));
        self::assertFalse($services['rotator']->isCurrentSecret($agent['id'], $agent['secret']));
        foreach ($results as $result) {
            if (str_starts_with($result, 'rotated:')) {
                self::assertFalse($services['rotator']->isCurrentSecret($agent['id'], substr($result, strlen('rotated:'))));
            }
        }
    }

    public function testGateBlocksOldWriterThenPersistentFenceRejectsVersionOne(): void
    {
        $guard = new SecretWriteGuard($this->connection);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT GET_LOCK(:name, 2)', ['name' => $guard->lockName()]));
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($pair);
        $pid = pcntl_fork();
        self::assertNotSame(-1, $pid);
        if ($pid === 0) {
            fclose($pair[0]);
            stream_set_timeout($pair[1], 10);
            $connection = DriverManager::getConnection($this->connection->getParams());
            fwrite($pair[1], "ready\n");
            fflush($pair[1]);
            fgets($pair[1]);
            try {
                $this->services($connection, 1)['provisioner']->provision('old-writer');
                fwrite($pair[1], "unexpected-success\n");
            } catch (\Throwable $exception) {
                fwrite($pair[1], 'error:' . $exception->getMessage() . "\n");
            } finally {
                fflush($pair[1]);
                $connection->close();
                fclose($pair[1]);
            }
            posix_kill(getmypid(), SIGKILL);
            exit(1);
        }

        fclose($pair[1]);
        stream_set_timeout($pair[0], 10);
        try {
            self::assertSame('ready', trim((string) fgets($pair[0])));
            fwrite($pair[0], "go\n");
            fflush($pair[0]);
            usleep(200_000);
            $read = [$pair[0]];
            $write = $except = [];
            self::assertSame(0, stream_select($read, $write, $except, 0, 0), 'Old writer was not blocked by advisory lock.');

            $new = $this->services($this->connection, 2);
            self::assertTrue($new['reencryptor']->readbackAndCanRemove(1));
            self::assertSame(2, $guard->fenceVersion());
            self::assertSame(1, (int) $this->connection->fetchOne('SELECT RELEASE_LOCK(:name)', ['name' => $guard->lockName()]));

            $result = trim((string) fgets($pair[0]));
            self::assertSame('error:Encryption key version is below persistent fence.', $result);
            self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM `muwa_agent_host` WHERE `secret_key_version` = 1'));
        } finally {
            fclose($pair[0]);
            $deadline = microtime(true) + 5.0;
            do {
                $waited = pcntl_waitpid($pid, $status, WNOHANG);
                if ($waited === $pid) {
                    break;
                }
                usleep(10_000);
            } while (microtime(true) < $deadline);
            if ($waited !== $pid) {
                posix_kill($pid, SIGKILL);
                pcntl_waitpid($pid, $status);
            }
        }
    }

    /** @return array{provisioner: AgentProvisioner, rotator: AgentSecretRotator, reencryptor: MasterKeyReencryptor} */
    private function services(Connection $connection, int $active): array
    {
        $provider = new EncryptionKeyProvider('{"active":' . $active . ',"keys":{"1":"' . self::KEY1 . '","2":"' . self::KEY2 . '"}}');
        $cipher = new SecretCipher($provider);
        $guard = new SecretWriteGuard($connection);
        return [
            'provisioner' => new AgentProvisioner($connection, $cipher, $guard, $provider),
            'rotator' => new AgentSecretRotator($connection, $cipher, $guard, $provider),
            'reencryptor' => new MasterKeyReencryptor($connection, $cipher, $provider, $guard),
        ];
    }

    /** @param list<\Closure(Connection): string> $operations
     * @return list<string>
     */
    private function runConcurrently(array $operations, bool $allowOperationError = false): array
    {
        $children = [];
        try {
            foreach ($operations as $operation) {
                $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
                self::assertNotFalse($pair);
                $pid = pcntl_fork();
                self::assertNotSame(-1, $pid);
                if ($pid === 0) {
                    fclose($pair[0]);
                    stream_set_timeout($pair[1], 10);
                    $connection = DriverManager::getConnection($this->connection->getParams());
                    fwrite($pair[1], "ready\n");
                    fflush($pair[1]);
                    fgets($pair[1]);
                    try {
                        fwrite($pair[1], $operation($connection) . "\n");
                    } catch (\Throwable $exception) {
                        fwrite($pair[1], 'error:' . $exception->getMessage() . "\n");
                    } finally {
                        fflush($pair[1]);
                        $connection->close();
                        fclose($pair[1]);
                    }
                    posix_kill(getmypid(), SIGKILL);
                    exit(1);
                }
                fclose($pair[1]);
                stream_set_timeout($pair[0], 10);
                $children[] = ['pid' => $pid, 'socket' => $pair[0]];
            }
            foreach ($children as $child) {
                self::assertSame('ready', trim((string) fgets($child['socket'])));
            }
            foreach ($children as $child) {
                fwrite($child['socket'], "go\n");
                fflush($child['socket']);
            }
            $results = [];
            foreach ($children as $child) {
                $result = trim((string) fgets($child['socket']));
                self::assertFalse(stream_get_meta_data($child['socket'])['timed_out']);
                if (!$allowOperationError) {
                    self::assertStringStartsNotWith('error:', $result);
                }
                $results[] = $result;
            }
            return $results;
        } finally {
            foreach ($children as $child) {
                if (is_resource($child['socket'])) {
                    fclose($child['socket']);
                }
                $deadline = microtime(true) + 5.0;
                do {
                    $waited = pcntl_waitpid($child['pid'], $status, WNOHANG);
                    if ($waited === $child['pid']) {
                        break;
                    }
                    usleep(10_000);
                } while (microtime(true) < $deadline);
                if ($waited !== $child['pid']) {
                    posix_kill($child['pid'], SIGKILL);
                    pcntl_waitpid($child['pid'], $status);
                }
            }
        }
    }

    private function dropSchema(): void
    {
        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS=0');
        foreach (['muwa_agent_nonce', 'muwa_agent_job', 'muwa_agent_backup_run', 'muwa_agent_repository_hook', 'muwa_agent_action', 'muwa_agent_host', Migration1795305600CreateSecretState::TABLE_NAME] as $table) {
            $this->connection->executeStatement('DROP TABLE IF EXISTS `' . $table . '`');
        }
        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS=1');
    }
}
