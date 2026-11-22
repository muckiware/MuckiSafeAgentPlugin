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

namespace MuckiSafeAgentPlugin\tests\Integration\Service\Auth;

require_once dirname(__DIR__, 4) . '/src/Migration/Migration1795132800CreateAgentSchema.php';
require_once dirname(__DIR__, 4) . '/src/Migration/Migration1795305600CreateSecretState.php';
foreach (glob(dirname(__DIR__, 4) . '/src/Service/Auth/*.php') ?: [] as $authServiceFile) {
    require_once $authServiceFile;
}
foreach (glob(dirname(__DIR__, 4) . '/src/Service/Secret/*.php') ?: [] as $secretServiceFile) {
    require_once $secretServiceFile;
}

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use MuckiSafeAgentPlugin\Migration\Migration1795132800CreateAgentSchema;
use MuckiSafeAgentPlugin\Migration\Migration1795305600CreateSecretState;
use MuckiSafeAgentPlugin\Service\Auth\AgentAuthException;
use MuckiSafeAgentPlugin\Service\Auth\AgentErrorCode;
use MuckiSafeAgentPlugin\Service\Auth\ReplayGuard;
use MuckiSafeAgentPlugin\Service\Secret\AgentProvisioner;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Test\TestCaseBase\KernelTestBehaviour;

/**
 * Task 9 RED: replay protection over the muwa_agent_nonce table. The UNIQUE
 * key (agent_id, nonce) must decide replays atomically; expiry is fixed to
 * requestTimestamp + 60s window + 5s cleanup tolerance; cleanup only deletes
 * strictly expired nonces in batches.
 */
final class ReplayGuardTest extends TestCase
{
    use KernelTestBehaviour;

    private const REQUEST_TIMESTAMP = 1790000000;

    private Connection $connection;

    private ReplayGuard $guard;

    protected function setUp(): void
    {
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->dropSchema();
        (new Migration1795132800CreateAgentSchema())->update($this->connection);
        (new Migration1795305600CreateSecretState())->update($this->connection);
        $this->guard = new ReplayGuard($this->connection);
    }

    protected function tearDown(): void
    {
        $this->dropSchema();
    }

    private function dropSchema(): void
    {
        $this->connection->executeStatement(
            'DROP TABLE IF EXISTS `muwa_agent_nonce`, `muwa_agent_job`, `muwa_agent_backup_run`, '
            . '`muwa_agent_repository_hook`, `muwa_agent_action`, `muwa_agent_host`, `muwa_agent_secret_state`'
        );
    }

    /** @return array{id: string, keyId: string, secret: string} */
    private function provisionHost(string $name): array
    {
        $provisioner = self::getContainer()->get(AgentProvisioner::class);
        self::assertInstanceOf(AgentProvisioner::class, $provisioner);
        $agent = $provisioner->provision($name);
        self::assertIsArray($agent);

        return $agent;
    }

    private function nonceRowCount(): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM `muwa_agent_nonce`');
    }

    public function testRememberPersistsNonceWithWindowExpiryFromRequestTimestamp(): void
    {
        $agent = $this->provisionHost('replay-persist');

        $this->guard->remember($agent['id'], str_repeat('n', 43), self::REQUEST_TIMESTAMP);

        $row = $this->connection->fetchAssociative(
            'SELECT `agent_id`, `nonce`, `expires_at` FROM `muwa_agent_nonce` WHERE `agent_id` = UNHEX(:id)',
            ['id' => $agent['id']]
        );
        self::assertIsArray($row);
        self::assertSame($agent['id'], bin2hex((string) $row['agent_id']));
        self::assertSame(str_repeat('n', 43), (string) $row['nonce']);
        // requestTimestamp + 60s window + 5s cleanup tolerance
        $expectedExpiry = (new \DateTimeImmutable('@' . (self::REQUEST_TIMESTAMP + 65)))->format('Y-m-d H:i:s.v');
        self::assertSame($expectedExpiry, (string) $row['expires_at']);
    }

    public function testIdenticalNonceForSameAgentIsRejectedAsReplay(): void
    {
        $agent = $this->provisionHost('replay-same');

        $this->guard->remember($agent['id'], str_repeat('r', 32), self::REQUEST_TIMESTAMP);

        try {
            $this->guard->remember($agent['id'], str_repeat('r', 32), self::REQUEST_TIMESTAMP);
            self::fail('identical nonce was accepted twice');
        } catch (AgentAuthException $exception) {
            self::assertSame(AgentErrorCode::ReplayDetected, $exception->errorCode);
        }
        self::assertSame(1, $this->nonceRowCount());
    }

    public function testSameNonceForDifferentAgentsIsNotAReplay(): void
    {
        $first = $this->provisionHost('replay-agent-a');
        $second = $this->provisionHost('replay-agent-b');

        $this->guard->remember($first['id'], str_repeat('s', 40), self::REQUEST_TIMESTAMP);
        $this->guard->remember($second['id'], str_repeat('s', 40), self::REQUEST_TIMESTAMP);

        // UNIQUE key is (agent_id, nonce): one nonce row per agent
        self::assertSame(2, $this->nonceRowCount());
    }

    public function testCleanupKeepsEntryUntilExpiryHasPassed(): void
    {
        $agent = $this->provisionHost('replay-cleanup');

        $this->guard->remember($agent['id'], str_repeat('c', 33), self::REQUEST_TIMESTAMP);
        $expiry = self::REQUEST_TIMESTAMP + 65;

        // expires_at == serverNow is still protected: cleanup deletes only expires_at < serverNow
        self::assertSame(0, $this->guard->cleanupExpired($expiry, 100));
        self::assertSame(1, $this->nonceRowCount());
        self::assertSame(0, $this->guard->cleanupExpired($expiry - 1, 100));
        self::assertSame(1, $this->nonceRowCount());

        self::assertSame(1, $this->guard->cleanupExpired($expiry + 1, 100));
        self::assertSame(0, $this->nonceRowCount());
        self::assertSame(0, $this->guard->cleanupExpired($expiry + 1, 100));
    }

    public function testCleanupDeletesExpiredNoncesInBatches(): void
    {
        $agent = $this->provisionHost('replay-batch');

        for ($i = 0; $i < 5; $i++) {
            $this->guard->remember($agent['id'], 'batchnonce' . sprintf('%04d', $i) . 'paddingpaddingpadding', self::REQUEST_TIMESTAMP);
        }
        $serverNow = self::REQUEST_TIMESTAMP + 65 + 1;
        self::assertSame(5, $this->nonceRowCount());

        self::assertSame(2, $this->guard->cleanupExpired($serverNow, 2));
        self::assertSame(3, $this->nonceRowCount());
        self::assertSame(2, $this->guard->cleanupExpired($serverNow, 2));
        self::assertSame(1, $this->nonceRowCount());
        self::assertSame(1, $this->guard->cleanupExpired($serverNow, 2));
        self::assertSame(0, $this->nonceRowCount());
        self::assertSame(0, $this->guard->cleanupExpired($serverNow, 2));
    }

    public function testCleanupDoesNotTouchRowsOfFutureRequests(): void
    {
        $agent = $this->provisionHost('replay-future');

        $this->guard->remember($agent['id'], str_repeat('f', 34), self::REQUEST_TIMESTAMP + 600);

        self::assertSame(0, $this->guard->cleanupExpired(self::REQUEST_TIMESTAMP, 100));
        self::assertSame(1, $this->nonceRowCount());
    }

    /**
     * Two processes with independent database connections insert the identical
     * (agent_id, nonce) pair at the same time. The UNIQUE key must reject
     * exactly one of them as a replay.
     */
    public function testConcurrentIdenticalNonceAllowsExactlyOneWinner(): void
    {
        $agent = $this->provisionHost('replay-race');
        $nonce = 'race-nonce-' . bin2hex(random_bytes(8)) . 'padding';
        $outcomes = $this->runConcurrentlyRemember($agent['id'], $nonce);

        self::assertCount(2, $outcomes);
        self::assertSame(['ok', 'replay'], $outcomes, 'exactly one winner expected, got: ' . implode(',', $outcomes));
        self::assertSame(1, $this->nonceRowCount());
    }

    /**
     * Forks two children that each build their own ReplayGuard on an own
     * connection, synchronize over a socket barrier and then insert the same
     * nonce simultaneously. Socket waits are bounded and every child is
     * reaped in a finally block.
     *
     * @return list<string> sorted outcomes: 'ok' or 'replay'
     */
    private function runConcurrentlyRemember(string $hexAgentId, string $nonce): array
    {
        $children = [];
        $outcomes = [];
        try {
            for ($i = 0; $i < 2; $i++) {
                $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
                self::assertIsArray($pair);
                [$parentSocket, $childSocket] = $pair;
                $pid = pcntl_fork();
                self::assertNotSame(-1, $pid);
                if ($pid === 0) {
                    fclose($parentSocket);
                    $outcome = 'crash';
                    try {
                        stream_set_timeout($childSocket, 15);
                        $connection = DriverManager::getConnection($this->connection->getParams());
                        $guard = new ReplayGuard($connection);
                        fwrite($childSocket, "ready\n");
                        fflush($childSocket);
                        fgets($childSocket);
                        try {
                            $guard->remember($hexAgentId, $nonce, self::REQUEST_TIMESTAMP);
                            $outcome = 'ok';
                        } catch (AgentAuthException $exception) {
                            $outcome = $exception->errorCode === AgentErrorCode::ReplayDetected ? 'replay' : 'error:' . $exception->errorCode?->value;
                        }
                    } catch (\Throwable $throwable) {
                        $outcome = 'error:' . $throwable->getMessage();
                    }
                    fwrite($childSocket, $outcome . "\n");
                    fflush($childSocket);
                    fclose($childSocket);
                    // SIGKILL skips PHP destructors on purpose: they would
                    // close the inherited parent PDO socket and take the
                    // parent's MySQL connection down with it
                    posix_kill(getmypid(), SIGKILL);
                }
                fclose($childSocket);
                stream_set_timeout($parentSocket, 15);
                $children[] = ['pid' => $pid, 'socket' => $parentSocket];
            }

            foreach ($children as $child) {
                self::assertNotFalse(fgets($child['socket']), 'child never signalled ready');
            }
            foreach ($children as $child) {
                fwrite($child['socket'], "go\n");
                fflush($child['socket']);
            }
            foreach ($children as $child) {
                $line = fgets($child['socket']);
                self::assertNotFalse($line, 'child never reported an outcome');
                $outcomes[] = trim($line);
            }
        } finally {
            foreach ($children as $child) {
                fclose($child['socket']);
                pcntl_waitpid($child['pid'], $status);
            }
        }

        sort($outcomes);

        return $outcomes;
    }
}
