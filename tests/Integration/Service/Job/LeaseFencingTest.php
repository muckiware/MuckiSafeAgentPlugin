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
namespace MuckiSafeAgentPlugin\tests\Integration\Service\Job;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use MuckiSafeAgentPlugin\Migration\Migration1795132800CreateAgentSchema;
use MuckiSafeAgentPlugin\Service\Job\ClaimConflict;
use MuckiSafeAgentPlugin\Service\Job\JobTransitionGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Test\TestCaseBase\KernelTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;

final class LeaseFencingTest extends TestCase
{
    use KernelTestBehaviour;

    private const TOKEN = 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';
    private const VERSION = 7;

    private Connection $connection;
    private string $hostId;
    private string $otherHostId;
    private string $jobId;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->dropSchema();
        (new Migration1795132800CreateAgentSchema())->update($connection);
        $this->now = new \DateTimeImmutable('2030-01-02 03:04:05.000', new \DateTimeZone('UTC'));
        $this->hostId = $this->insertHost('lease-host');
        $this->otherHostId = $this->insertHost('other-host');
        $this->jobId = $this->insertClaimedJob();
    }

    protected function tearDown(): void
    {
        $this->dropSchema();
    }

    public function testStartAndHeartbeatRenewFromOneInjectedServerTimestampAndKeepDeadlinesImmutable(): void
    {
        $guard = $this->guard();
        $start = $guard->start($this->hostId, $this->wireUuid($this->jobId), self::TOKEN, self::VERSION);

        self::assertSame(self::VERSION, $start->leaseVersion);
        self::assertSame('2030-01-02T03:04:35Z', $start->leaseExpiresAt);
        self::assertSame('2030-01-02T03:14:05Z', $start->phaseDeadline);
        self::assertSame('2030-01-02T03:13:50Z', $start->executionDeadline);
        self::assertSame('2030-01-02T03:13:55Z', $start->stopDeadline);

        $heartbeat = $guard->heartbeat($this->hostId, $this->wireUuid($this->jobId), self::TOKEN, self::VERSION);
        self::assertFalse($heartbeat->stopRequested);
        self::assertNull($heartbeat->stopDeadline);
        self::assertSame('2030-01-02T03:04:35Z', $heartbeat->leaseExpiresAt);

        $row = $this->row();
        self::assertSame('running', $row['status']);
        self::assertSame('2030-01-02 03:04:05.000', $row['heartbeat_at']);
        self::assertSame('2030-01-02 03:14:05.000', $row['phase_deadline']);
        self::assertSame('2030-01-02 03:13:50.000', $row['execution_deadline']);
        self::assertSame('2030-01-02 03:13:55.000', $row['stop_deadline']);
    }

    public function testClaimedHeartbeatRenewsLeaseBeforeStart(): void
    {
        $response = $this->guard()->heartbeat(
            $this->hostId,
            $this->wireUuid($this->jobId),
            self::TOKEN,
            self::VERSION
        );

        self::assertFalse($response->stopRequested);
        self::assertSame('2030-01-02T03:04:35Z', $response->leaseExpiresAt);
        $row = $this->row();
        self::assertSame('claimed', $row['status']);
        self::assertSame('2030-01-02 03:04:35.000', $row['lease_expires_at']);
        self::assertSame('2030-01-02 03:04:05.000', $row['heartbeat_at']);
    }

    public function testFractionalServerTimeNeverExtendsPastTheSignedWireLease(): void
    {
        $fractionalNow = new \DateTimeImmutable('2030-01-02 03:04:05.900', new \DateTimeZone('UTC'));
        $start = (new JobTransitionGuard(
            $this->connection,
            static fn (): \DateTimeImmutable => $fractionalNow
        ))->start($this->hostId, $this->wireUuid($this->jobId), self::TOKEN, self::VERSION);

        self::assertSame('2030-01-02T03:04:35Z', $start->leaseExpiresAt);
        self::assertSame('2030-01-02 03:04:35.000', $this->row()['lease_expires_at']);

        $this->expectException(ClaimConflict::class);
        (new JobTransitionGuard(
            $this->connection,
            static fn (): \DateTimeImmutable => new \DateTimeImmutable(
                '2030-01-02 03:04:35.001',
                new \DateTimeZone('UTC')
            )
        ))->heartbeat($this->hostId, $this->wireUuid($this->jobId), self::TOKEN, self::VERSION);
    }

    public function testLaterHeartbeatRenewsAndCapsAtExecutionDeadline(): void
    {
        $this->guard()->start($this->hostId, $this->wireUuid($this->jobId), self::TOKEN, self::VERSION);
        $later = $this->now->modify('+20 seconds');
        $renewed = (new JobTransitionGuard(
            $this->connection,
            static fn (): \DateTimeImmutable => $later
        ))->heartbeat($this->hostId, $this->wireUuid($this->jobId), self::TOKEN, self::VERSION);
        self::assertSame('2030-01-02T03:04:55Z', $renewed->leaseExpiresAt);

        $nearCutoff = $this->now->modify('+9 minutes 35 seconds');
        $this->connection->update('muwa_agent_job', [
            'lease_expires_at' => $this->storage($nearCutoff->modify('+20 seconds')),
        ], ['id' => Uuid::fromHexToBytes($this->jobId)]);
        $capped = (new JobTransitionGuard(
            $this->connection,
            static fn (): \DateTimeImmutable => $nearCutoff
        ))->heartbeat($this->hostId, $this->wireUuid($this->jobId), self::TOKEN, self::VERSION);
        self::assertSame('2030-01-02T03:13:50Z', $capped->leaseExpiresAt);
    }

    public function testOldWorkerIsFencedAfterTokenAndVersionRotate(): void
    {
        $newToken = str_repeat('C', 43);
        $this->connection->update('muwa_agent_job', [
            'claim_token_hash' => hash('sha256', $newToken, true),
            'lease_version' => self::VERSION + 1,
        ], ['id' => Uuid::fromHexToBytes($this->jobId)]);

        try {
            $this->guard()->start($this->hostId, $this->wireUuid($this->jobId), self::TOKEN, self::VERSION);
            self::fail('The stale worker must be fenced.');
        } catch (ClaimConflict) {
            self::assertSame('claimed', $this->row()['status']);
        }

        $response = $this->guard()->start(
            $this->hostId,
            $this->wireUuid($this->jobId),
            $newToken,
            self::VERSION + 1
        );
        self::assertSame(self::VERSION + 1, $response->leaseVersion);
    }

    #[DataProvider('disabledOrRevokedHost')]
    public function testHostStateChangeAfterAuthenticationFailsClosed(bool $enabled, ?string $revokedAt): void
    {
        $before = $this->row();
        $this->connection->update('muwa_agent_host', [
            'enabled' => $enabled ? 1 : 0,
            'revoked_at' => $revokedAt,
        ], ['id' => Uuid::fromHexToBytes($this->hostId)]);

        try {
            $this->guard()->start($this->hostId, $this->wireUuid($this->jobId), self::TOKEN, self::VERSION);
            self::fail('Changed host state must fail closed.');
        } catch (ClaimConflict) {
            self::assertSame($before, $this->row());
        }
    }

    /** @return iterable<string, array{bool, ?string}> */
    public static function disabledOrRevokedHost(): iterable
    {
        yield 'disabled' => [false, null];
        yield 'revoked' => [true, '2030-01-02 03:04:04.000'];
    }

    public function testTwoConcurrentStartsProduceExactlyOneRunningTransition(): void
    {
        self::assertSame(['conflict', 'started'], $this->runConcurrentStarts());
        self::assertSame('running', $this->row()['status']);
        self::assertSame(self::VERSION, (int) $this->row()['lease_version']);
    }

    #[DataProvider('invalidFences')]
    public function testWrongAgentTokenOrVersionIsAClaimConflict(string $host, string $token, int $version): void
    {
        $hostId = $host === 'other' ? $this->otherHostId : $this->hostId;

        $this->expectException(ClaimConflict::class);
        $this->guard()->start($hostId, $this->wireUuid($this->jobId), $token, $version);
    }

    /** @return iterable<string, array{string, string, int}> */
    public static function invalidFences(): iterable
    {
        yield 'agent' => ['other', self::TOKEN, self::VERSION];
        yield 'token' => ['same', str_repeat('B', 43), self::VERSION];
        yield 'version' => ['same', self::TOKEN, self::VERSION - 1];
    }

    public function testStartRequiresStrictlyLiveLease(): void
    {
        $this->connection->update('muwa_agent_job', [
            'lease_expires_at' => $this->storage($this->now),
        ], ['id' => Uuid::fromHexToBytes($this->jobId)]);
        $this->expectException(ClaimConflict::class);
        $this->guard()->start($this->hostId, $this->wireUuid($this->jobId), self::TOKEN, self::VERSION);
    }

    public function testStartRequiresStrictlyFutureExecutionDeadline(): void
    {
        $this->connection->update('muwa_agent_job', [
            'lease_expires_at' => $this->storage($this->now->modify('+10 minutes')),
        ], ['id' => Uuid::fromHexToBytes($this->jobId)]);
        $this->expectException(ClaimConflict::class);
        (new JobTransitionGuard(
            $this->connection,
            fn (): \DateTimeImmutable => $this->now->modify('+9 minutes 45 seconds')
        ))->start($this->hostId, $this->wireUuid($this->jobId), self::TOKEN, self::VERSION);
    }

    public function testHeartbeatRequiresStrictlyFutureExecutionDeadline(): void
    {
        $this->connection->update('muwa_agent_job', [
            'status' => 'running',
            'lease_expires_at' => $this->storage($this->now->modify('+10 minutes')),
        ], ['id' => Uuid::fromHexToBytes($this->jobId)]);
        $this->expectException(ClaimConflict::class);
        (new JobTransitionGuard(
            $this->connection,
            fn (): \DateTimeImmutable => $this->now->modify('+9 minutes 45 seconds')
        ))->heartbeat($this->hostId, $this->wireUuid($this->jobId), self::TOKEN, self::VERSION);
    }

    public function testHeartbeatRequiresStrictlyLiveLease(): void
    {
        $this->connection->update('muwa_agent_job', [
            'status' => 'running',
            'lease_expires_at' => $this->storage($this->now),
        ], ['id' => Uuid::fromHexToBytes($this->jobId)]);
        $this->expectException(ClaimConflict::class);
        $this->guard()->heartbeat($this->hostId, $this->wireUuid($this->jobId), self::TOKEN, self::VERSION);
    }

    public function testStartLeaseIsCappedAtExecutionDeadline(): void
    {
        $executionDeadline = $this->now->modify('+9 minutes 45 seconds');
        $nearDeadline = $executionDeadline->modify('-10 seconds');
        $this->connection->update('muwa_agent_job', [
            'lease_expires_at' => $this->storage($executionDeadline),
        ], ['id' => Uuid::fromHexToBytes($this->jobId)]);

        $response = (new JobTransitionGuard(
            $this->connection,
            fn (): \DateTimeImmutable => $nearDeadline
        ))->start($this->hostId, $this->wireUuid($this->jobId), self::TOKEN, self::VERSION);

        self::assertSame('2030-01-02T03:13:50Z', $response->leaseExpiresAt);
        self::assertSame($this->storage($executionDeadline), $this->row()['lease_expires_at']);
    }

    public function testStartIsRejectedOnceCancellationWasRequested(): void
    {
        $this->connection->update('muwa_agent_job', [
            'status' => 'cancel_requested',
        ], ['id' => Uuid::fromHexToBytes($this->jobId)]);
        $this->expectException(ClaimConflict::class);
        $this->guard()->start($this->hostId, $this->wireUuid($this->jobId), self::TOKEN, self::VERSION);
    }

    public function testCancellationHeartbeatIsAcceptedThroughInclusiveStopDeadlineWithoutLeaseExtension(): void
    {
        $materializedStop = $this->now->modify('+9 minutes 50 seconds');
        $heartbeatNow = $materializedStop->modify('-3 seconds');
        $oldLease = $this->now->modify('-1 minute');
        $this->connection->update('muwa_agent_job', [
            'status' => 'cancel_requested',
            'lease_expires_at' => $this->storage($oldLease),
        ], ['id' => Uuid::fromHexToBytes($this->jobId)]);

        $response = (new JobTransitionGuard(
            $this->connection,
            fn (): \DateTimeImmutable => $heartbeatNow
        ))->heartbeat(
            $this->hostId,
            $this->wireUuid($this->jobId),
            self::TOKEN,
            self::VERSION
        );

        self::assertTrue($response->stopRequested);
        self::assertSame('2030-01-02T03:03:05Z', $response->leaseExpiresAt);
        self::assertSame('2030-01-02T03:13:55Z', $response->stopDeadline);
        self::assertSame($this->storage($oldLease), $this->row()['lease_expires_at']);

        $atDeadline = new JobTransitionGuard($this->connection, fn (): \DateTimeImmutable => $materializedStop);
        self::assertTrue($atDeadline->heartbeat(
            $this->hostId,
            $this->wireUuid($this->jobId),
            self::TOKEN,
            self::VERSION
        )->stopRequested);
    }

    public function testCancellationHeartbeatAfterStopDeadlineConflicts(): void
    {
        $this->connection->update('muwa_agent_job', [
            'status' => 'cancel_requested',
        ], ['id' => Uuid::fromHexToBytes($this->jobId)]);

        $this->expectException(ClaimConflict::class);
        (new JobTransitionGuard(
            $this->connection,
            fn (): \DateTimeImmutable => $this->now->modify('+9 minutes 50 seconds 1 millisecond')
        ))->heartbeat($this->hostId, $this->wireUuid($this->jobId), self::TOKEN, self::VERSION);
    }

    public function testAmbiguousCompletionPolicyMapsOnlyEligibleRunningOrCancellingWorkToUnknown(): void
    {
        $guard = $this->guard();
        self::assertSame('unknown', $guard->completionTerminalStatus(
            'running',
            'ambiguous',
            $this->now->modify('+1 minute'),
            $this->now->modify('+10 minutes')
        ));
        self::assertSame('unknown', $guard->completionTerminalStatus(
            'cancel_requested',
            'ambiguous',
            $this->now->modify('-1 minute'),
            $this->now
        ));

        $this->expectException(ClaimConflict::class);
        $guard->completionTerminalStatus(
            'cancel_requested',
            'succeeded',
            $this->now->modify('+1 minute'),
            $this->now
        );
    }

    /** @return list<string> */
    private function runConcurrentStarts(): array
    {
        $children = [];
        $results = [];
        try {
            for ($index = 0; $index < 2; ++$index) {
                $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
                self::assertNotFalse($pair);
                $pid = pcntl_fork();
                self::assertNotSame(-1, $pid);
                if ($pid === 0) {
                    fclose($pair[0]);
                    stream_set_timeout($pair[1], 20);
                    $connection = null;
                    try {
                        $connection = DriverManager::getConnection($this->connection->getParams());
                        fwrite($pair[1], "ready\n");
                        fflush($pair[1]);
                        if (trim((string) fgets($pair[1])) !== 'go') {
                            throw new \RuntimeException('Start barrier timed out.');
                        }
                        $guard = new JobTransitionGuard(
                            $connection,
                            fn (): \DateTimeImmutable => $this->now
                        );
                        try {
                            $guard->start(
                                $this->hostId,
                                $this->wireUuid($this->jobId),
                                self::TOKEN,
                                self::VERSION
                            );
                            fwrite($pair[1], "started\n");
                        } catch (ClaimConflict) {
                            fwrite($pair[1], "conflict\n");
                        }
                    } catch (\Throwable $exception) {
                        fwrite($pair[1], 'error:' . get_class($exception) . ':' . $exception->getMessage() . "\n");
                    } finally {
                        if ($connection instanceof Connection) {
                            $connection->close();
                        }
                        fflush($pair[1]);
                        fclose($pair[1]);
                    }
                    posix_kill(getmypid(), SIGKILL);
                    exit(1);
                }
                fclose($pair[1]);
                stream_set_timeout($pair[0], 20);
                $children[] = ['pid' => $pid, 'socket' => $pair[0]];
            }
            foreach ($children as $child) {
                self::assertSame('ready', trim((string) fgets($child['socket'])));
                fwrite($child['socket'], "go\n");
                fflush($child['socket']);
            }
            foreach ($children as $child) {
                $results[] = trim((string) fgets($child['socket']));
                self::assertFalse(stream_get_meta_data($child['socket'])['timed_out'], 'Start child timed out.');
            }
        } finally {
            foreach ($children as $child) {
                if (is_resource($child['socket'])) {
                    fclose($child['socket']);
                }
                $deadline = microtime(true) + 5.0;
                do {
                    $waited = pcntl_waitpid($child['pid'], $status, WNOHANG);
                    if ($waited === $child['pid']) {
                        continue 2;
                    }
                    usleep(10_000);
                } while (microtime(true) < $deadline);
                posix_kill($child['pid'], SIGKILL);
                pcntl_waitpid($child['pid'], $status);
            }
        }
        sort($results);

        return $results;
    }

    private function guard(): JobTransitionGuard
    {
        return new JobTransitionGuard($this->connection, fn (): \DateTimeImmutable => $this->now);
    }

    /** @return array<string, mixed> */
    private function row(): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM `muwa_agent_job` WHERE `id` = UNHEX(:id)',
            ['id' => $this->jobId]
        );
        self::assertIsArray($row);
        return $row;
    }

    private function insertClaimedJob(): string
    {
        $runId = Uuid::randomHex();
        $actionId = Uuid::randomHex();
        $jobId = Uuid::randomHex();
        $created = $this->storage($this->now->modify('-1 minute'));
        $this->connection->insert('muwa_agent_backup_run', [
            'id' => Uuid::fromHexToBytes($runId),
            'facility_repository_id' => Uuid::randomBytes(),
            'source' => 'facility',
            'idempotency_key' => Uuid::randomHex(),
            'status' => 'running',
            'started_at' => $created,
            'created_at' => $created,
        ]);
        $this->connection->insert('muwa_agent_action', [
            'id' => Uuid::fromHexToBytes($actionId),
            'technical_name' => Uuid::randomHex(),
            'label' => 'Lease action',
            'enabled' => 1,
            'definition' => '{"version":1,"steps":[]}',
            'definition_hash' => hash('sha256', '{"version":1,"steps":[]}', true),
            'required_capabilities' => '[]',
            'default_timeout_seconds' => 60,
            'max_timeout_seconds' => 600,
            'retry_policy' => 'none',
            'idempotency_class' => 'safe',
            'created_at' => $created,
        ]);
        $this->connection->insert('muwa_agent_job', [
            'id' => Uuid::fromHexToBytes($jobId),
            'backup_run_id' => Uuid::fromHexToBytes($runId),
            'agent_action_id' => Uuid::fromHexToBytes($actionId),
            'facility_repository_id' => Uuid::randomBytes(),
            'phase' => 'pre',
            'action_definition' => '{"version":1,"steps":[]}',
            'action_hash' => hash('sha256', '{"version":1,"steps":[]}', true),
            'payload' => '{}',
            'idempotency_class' => 'safe',
            'idempotency_key' => Uuid::randomHex(),
            'status' => 'claimed',
            'target_agent_id' => Uuid::fromHexToBytes($this->hostId),
            'claim_token_hash' => hash('sha256', self::TOKEN, true),
            'lease_version' => self::VERSION,
            'attempt' => 1,
            'max_attempts' => 3,
            'available_at' => $created,
            'phase_deadline' => $this->storage($this->now->modify('+10 minutes')),
            'execution_deadline' => $this->storage($this->now->modify('+9 minutes 45 seconds')),
            'stop_deadline' => $this->storage($this->now->modify('+9 minutes 50 seconds')),
            'claimed_at' => $created,
            'lease_expires_at' => $this->storage($this->now->modify('+10 seconds')),
            'created_at' => $created,
        ]);
        return $jobId;
    }

    private function insertHost(string $name): string
    {
        $id = Uuid::randomHex();
        $this->connection->insert('muwa_agent_host', [
            'id' => Uuid::fromHexToBytes($id),
            'name' => $name,
            'enabled' => 1,
            'key_id' => 'lease-' . $id,
            'secret_ciphertext' => random_bytes(32),
            'secret_nonce' => random_bytes(24),
            'secret_key_version' => 1,
            'capabilities' => '[]',
            'created_at' => $this->storage($this->now->modify('-1 day')),
        ]);
        return $id;
    }

    private function storage(\DateTimeImmutable $time): string
    {
        return $time->format(Defaults::STORAGE_DATE_TIME_FORMAT);
    }

    private function wireUuid(string $hex): string
    {
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
    }

    private function dropSchema(): void
    {
        $this->connection->executeStatement(
            'DROP TABLE IF EXISTS `muwa_agent_nonce`, `muwa_agent_job`, `muwa_agent_backup_run`, '
            . '`muwa_agent_repository_hook`, `muwa_agent_action`, `muwa_agent_host`'
        );
    }
}
