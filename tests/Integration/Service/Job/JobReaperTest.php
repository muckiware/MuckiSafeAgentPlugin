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
use MuckiSafeAgentPlugin\Migration\Migration1795132800CreateAgentSchema;
use MuckiSafeAgentPlugin\Migration\Migration1795478400CreateRollbackState;
use MuckiSafeAgentPlugin\Service\Job\ClaimConflict;
use MuckiSafeAgentPlugin\Service\Job\JobClaimer;
use MuckiSafeAgentPlugin\Service\Job\JobReaper;
use MuckiSafeAgentPlugin\Service\Job\JobTransitionGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Test\TestCaseBase\KernelTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Task 14 RED: server-side recovery of expired leases and deadline
 * enforcement. Safe requeue happens exclusively for claimed, never started
 * jobs; a job that ever reached `running` is never requeued; non-retryable
 * expiry becomes `unknown`; the attempt limit is honoured; the execution
 * cutoff atomically times out unstarted jobs and moves `running` to
 * `cancel_requested`; a missing acknowledgement at the phase deadline ends
 * terminally in `unknown`. All boundaries use an injected server clock.
 */
final class JobReaperTest extends TestCase
{
    use KernelTestBehaviour;

    private const TOKEN = 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';
    private const VERSION = 7;

    private Connection $connection;
    private \DateTimeImmutable $now;
    private string $actionId;
    private string $nonRetryableActionId;
    private string $runId;

    protected function setUp(): void
    {
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->dropSchema();
        (new Migration1795132800CreateAgentSchema())->update($connection);
        (new Migration1795478400CreateRollbackState())->update($connection);
        $this->now = new \DateTimeImmutable('2030-01-02 03:04:05.000', new \DateTimeZone('UTC'));
        $this->runId = $this->insertRun();
        $this->actionId = $this->insertAction('safe');
        $this->nonRetryableActionId = $this->insertAction('non_retryable');
    }

    protected function tearDown(): void
    {
        $this->dropSchema();
    }

    public function testExpiredNeverStartedSafeClaimIsRequeuedAndRevoked(): void
    {
        $jobId = $this->insertJob('claimed', ['lease_expires_at' => $this->storage($this->now)]);

        $result = $this->reaper()->reap();

        self::assertSame(1, $result->requeued);
        $row = $this->row($jobId);
        self::assertSame('queued', $row['status']);
        self::assertNull($row['claim_token_hash']);
        self::assertNull($row['lease_expires_at']);
        self::assertNull($row['claimed_at']);
        self::assertNull($row['finished_at']);
        self::assertNull($row['previous_claim_token_hash']);
        self::assertSame(self::VERSION + 1, (int) $row['lease_version']);
        self::assertSame(1, (int) $row['attempt']);
    }

    public function testRequeuedJobIsClaimableAgainWithANewFence(): void
    {
        $jobId = $this->insertJob('claimed', ['lease_expires_at' => $this->storage($this->now)]);
        $this->reaper()->reap();

        $claim = (new JobClaimer(
            $this->connection,
            null,
            null,
            fn (): string => $this->storage($this->now)
        ))->claim($this->hostOf($jobId))->job;

        self::assertNotNull($claim);
        self::assertSame(self::VERSION + 2, $claim->leaseVersion);
        self::assertNotSame(self::TOKEN, $claim->claimToken);
        self::assertSame(2, (int) $this->row($jobId)['attempt']);

        $this->expectException(ClaimConflict::class);
        $this->guard()->start($this->hostOf($jobId), $this->wireUuid($jobId), self::TOKEN, self::VERSION);
    }

    public function testLiveClaimIsNotTouchedOneMillisecondBeforeLeaseExpiry(): void
    {
        $jobId = $this->insertJob('claimed', ['lease_expires_at' => $this->storage($this->now->modify('+1 second'))]);
        $before = $this->row($jobId);

        $result = (new JobReaper(
            $this->connection,
            static fn (): string => '2030-01-02 03:04:05.999'
        ))->reap();

        self::assertSame(0, $result->total());
        self::assertSame($before, $this->row($jobId));
    }

    /** @return iterable<string, array{string}> */
    public static function startedStatuses(): iterable
    {
        yield 'running' => ['running'];
        yield 'cancel_requested' => ['cancel_requested'];
    }

    #[DataProvider('startedStatuses')]
    public function testStartedJobIsNeverRequeuedAfterLeaseExpiry(string $status): void
    {
        $jobId = $this->insertJob($status, ['lease_expires_at' => $this->storage($this->now->modify('-1 minute'))]);
        $before = $this->row($jobId);

        $result = $this->reaper()->reap();

        self::assertSame(0, $result->total());
        self::assertSame($before, $this->row($jobId));
    }

    public function testExpiredNonRetryableClaimBecomesTerminalUnknown(): void
    {
        $jobId = $this->insertJob('claimed', [
            'lease_expires_at' => $this->storage($this->now->modify('-1 second')),
            'idempotency_class' => 'non_retryable',
            'agent_action_id' => Uuid::fromHexToBytes($this->nonRetryableActionId),
        ]);

        $result = $this->reaper()->reap();

        self::assertSame(1, $result->unknown);
        $row = $this->row($jobId);
        self::assertSame('unknown', $row['status']);
        self::assertSame($this->storage($this->now), $row['finished_at']);
        $this->assertRevoked($row);
        self::assertNull($row['result_code']);
        self::assertNull($row['stdout_tail']);
        self::assertNull($row['stderr_tail']);
    }

    public function testExpiredSafeClaimWithoutAttemptsLeftStaysTerminallyExpired(): void
    {
        $jobId = $this->insertJob('claimed', [
            'lease_expires_at' => $this->storage($this->now->modify('-1 second')),
            'attempt' => 3,
            'max_attempts' => 3,
        ]);

        $result = $this->reaper()->reap();

        self::assertSame(1, $result->expired);
        self::assertSame(0, $result->requeued);
        $row = $this->row($jobId);
        self::assertSame('expired', $row['status']);
        self::assertSame($this->storage($this->now), $row['finished_at']);
        $this->assertRevoked($row);
        self::assertNull($row['stdout_tail']);
        self::assertNull($row['stderr_tail']);
    }

    /** @return iterable<string, array{string}> */
    public static function unstartedStatuses(): iterable
    {
        yield 'queued' => ['queued'];
        yield 'claimed' => ['claimed'];
    }

    #[DataProvider('unstartedStatuses')]
    public function testExecutionCutoffTimesOutUnstartedJobsInclusively(string $status): void
    {
        $jobId = $this->insertJob($status, [
            'lease_expires_at' => $status === 'claimed' ? $this->storage($this->now) : null,
            'claim_token_hash' => $status === 'claimed' ? hash('sha256', self::TOKEN, true) : null,
            'lease_version' => $status === 'claimed' ? self::VERSION : 0,
        ], $this->now);

        $result = $this->reaper()->reap();

        self::assertSame(1, $result->timedOut);
        $row = $this->row($jobId);
        self::assertSame('timed_out', $row['status']);
        self::assertSame('deadline', $row['result_code']);
        self::assertSame($this->storage($this->now), $row['finished_at']);
        self::assertNull($row['claim_token_hash']);
        self::assertSame(
            $status === 'claimed' ? hash('sha256', self::TOKEN, true) : null,
            $row['previous_claim_token_hash']
        );
        self::assertSame($status === 'claimed' ? self::VERSION + 1 : 1, (int) $row['lease_version']);
    }

    public function testQueuedJobJustBeforeExecutionCutoffIsUntouched(): void
    {
        $jobId = $this->insertJob('queued', [
            'claim_token_hash' => null,
            'lease_version' => 0,
            'lease_expires_at' => null,
        ], $this->now);
        $before = $this->row($jobId);

        $result = (new JobReaper(
            $this->connection,
            static fn (): string => '2030-01-02 03:04:04.999'
        ))->reap();

        self::assertSame(0, $result->total());
        self::assertSame($before, $this->row($jobId));
    }

    public function testExecutionCutoffMovesRunningAtomicallyToCancelRequestedKeepingTheFence(): void
    {
        $jobId = $this->insertJob('running', [], $this->now);

        $result = $this->reaper()->reap();

        self::assertSame(1, $result->cancelRequested);
        $row = $this->row($jobId);
        self::assertSame('cancel_requested', $row['status']);
        self::assertSame(hash('sha256', self::TOKEN, true), $row['claim_token_hash']);
        self::assertSame(self::VERSION, (int) $row['lease_version']);
        self::assertNull($row['finished_at']);

        $heartbeat = $this->guard()->heartbeat(
            $this->hostOf($jobId),
            $this->wireUuid($jobId),
            self::TOKEN,
            self::VERSION
        );
        self::assertTrue($heartbeat->stopRequested);
        self::assertSame('2030-01-02T03:04:10Z', $heartbeat->stopDeadline);
    }

    public function testCancelRequestedIsKeptThroughTheInclusivePhaseDeadline(): void
    {
        $jobId = $this->insertJob('cancel_requested', [
            'lease_expires_at' => $this->storage($this->now->modify('-20 seconds')),
        ], $this->now->modify('-15 seconds'));
        $before = $this->row($jobId);

        $result = $this->reaper()->reap();

        self::assertSame(0, $result->total());
        self::assertSame($before, $this->row($jobId));
    }

    #[DataProvider('startedStatuses')]
    public function testMissingAcknowledgementAfterPhaseDeadlineEndsTerminallyUnknown(string $status): void
    {
        $jobId = $this->insertJob($status, [
            'lease_expires_at' => $this->storage($this->now->modify('-20 seconds')),
        ], $this->now->modify('-15 seconds'));

        $result = (new JobReaper(
            $this->connection,
            static fn (): string => '2030-01-02 03:04:05.001'
        ))->reap();

        self::assertSame(1, $result->unknown);
        $row = $this->row($jobId);
        self::assertSame('unknown', $row['status']);
        self::assertSame('deadline', $row['result_code']);
        self::assertSame('2030-01-02 03:04:05.001', $row['finished_at']);
        $this->assertRevoked($row);
    }

    /** @return iterable<string, array{string}> */
    public static function terminalStatuses(): iterable
    {
        foreach (['succeeded', 'failed', 'timed_out', 'expired', 'unknown'] as $status) {
            yield $status => [$status];
        }
    }

    #[DataProvider('terminalStatuses')]
    public function testTerminalJobsAreNeverTouched(string $status): void
    {
        $jobId = $this->insertJob($status, [
            'claim_token_hash' => null,
            'lease_expires_at' => $this->storage($this->now->modify('-1 hour')),
            'finished_at' => $this->storage($this->now->modify('-50 minutes')),
        ], $this->now->modify('-30 minutes'));
        $before = $this->row($jobId);

        $result = $this->reaper()->reap();

        self::assertSame(0, $result->total());
        self::assertSame($before, $this->row($jobId));
    }

    public function testReaperDrainsAllCandidatesInBoundedBatches(): void
    {
        $jobIds = [];
        for ($i = 0; $i < 5; ++$i) {
            $jobIds[] = $this->insertJob('claimed', ['lease_expires_at' => $this->storage($this->now)]);
        }

        $result = (new JobReaper($this->connection, fn (): string => $this->storage($this->now), 2))->reap();

        self::assertSame(5, $result->requeued);
        foreach ($jobIds as $jobId) {
            self::assertSame('queued', $this->row($jobId)['status']);
        }
    }

    public function testReaperStopsAfterTheMaximumNumberOfBatches(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            $this->insertJob('claimed', ['lease_expires_at' => $this->storage($this->now)]);
        }

        $result = (new JobReaper($this->connection, fn (): string => $this->storage($this->now), 2, 1))->reap();

        self::assertSame(2, $result->requeued);
        self::assertSame(3, (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM `muwa_agent_job` WHERE `status` = \'claimed\''
        ));
    }

    public function testInvalidBatchConfigurationIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new JobReaper($this->connection, null, 0);
    }

    /** @param array<string, mixed> $row */
    private function assertRevoked(array $row): void
    {
        self::assertNull($row['claim_token_hash']);
        self::assertSame(hash('sha256', self::TOKEN, true), $row['previous_claim_token_hash']);
        self::assertSame(self::VERSION + 1, (int) $row['lease_version']);
        self::assertNull($row['completion_fingerprint']);
        self::assertNull($row['accepted_lease_version']);
    }

    private function reaper(): JobReaper
    {
        return new JobReaper($this->connection, fn (): \DateTimeImmutable => $this->now);
    }

    private function guard(): JobTransitionGuard
    {
        return new JobTransitionGuard($this->connection, fn (): \DateTimeImmutable => $this->now);
    }

    /** @return array<string, mixed> */
    private function row(string $jobId): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM `muwa_agent_job` WHERE `id` = UNHEX(:id)',
            ['id' => $jobId]
        );
        self::assertIsArray($row);
        unset($row['updated_at']);

        return $row;
    }

    private function hostOf(string $jobId): string
    {
        return Uuid::fromBytesToHex((string) $this->connection->fetchOne(
            'SELECT `target_agent_id` FROM `muwa_agent_job` WHERE `id` = UNHEX(:id)',
            ['id' => $jobId]
        ));
    }

    /**
     * Every job gets its own host: the active-agent guard allows only one
     * claimed/running/cancel_requested job per agent.
     *
     * @param array<string, mixed> $overrides
     */
    private function insertJob(string $status, array $overrides, ?\DateTimeImmutable $executionDeadline = null): string
    {
        $jobId = Uuid::randomHex();
        $created = $this->storage($this->now->modify('-1 minute'));
        $executionDeadline ??= $this->now->modify('+9 minutes 45 seconds');
        $phaseDeadline = $executionDeadline->modify('+15 seconds');
        $this->connection->insert('muwa_agent_job', array_merge([
            'id' => Uuid::fromHexToBytes($jobId),
            'backup_run_id' => Uuid::fromHexToBytes($this->runId),
            'agent_action_id' => Uuid::fromHexToBytes($this->actionId),
            'facility_repository_id' => Uuid::randomBytes(),
            'phase' => 'pre',
            'action_definition' => '{"version":1,"steps":[]}',
            'action_hash' => hash('sha256', '{"version":1,"steps":[]}', true),
            'payload' => '{}',
            'idempotency_class' => 'safe',
            'idempotency_key' => Uuid::randomHex(),
            'status' => $status,
            'target_agent_id' => Uuid::fromHexToBytes($this->insertHost()),
            'claim_token_hash' => hash('sha256', self::TOKEN, true),
            'lease_version' => self::VERSION,
            'attempt' => 1,
            'max_attempts' => 3,
            'available_at' => $created,
            'phase_deadline' => $this->storage($phaseDeadline),
            'execution_deadline' => $this->storage($executionDeadline),
            'stop_deadline' => $this->storage($phaseDeadline->modify('-10 seconds')),
            'claimed_at' => $created,
            'lease_expires_at' => $this->storage($this->now->modify('+10 seconds')),
            'created_at' => $created,
        ], $overrides));

        return $jobId;
    }

    private function insertRun(): string
    {
        $runId = Uuid::randomHex();
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

        return $runId;
    }

    private function insertAction(string $idempotencyClass): string
    {
        $actionId = Uuid::randomHex();
        $this->connection->insert('muwa_agent_action', [
            'id' => Uuid::fromHexToBytes($actionId),
            'technical_name' => Uuid::randomHex(),
            'label' => 'Reaper action',
            'enabled' => 1,
            'definition' => '{"version":1,"steps":[]}',
            'definition_hash' => hash('sha256', '{"version":1,"steps":[]}', true),
            'required_capabilities' => '[]',
            'default_timeout_seconds' => 60,
            'max_timeout_seconds' => 600,
            'retry_policy' => 'none',
            'idempotency_class' => $idempotencyClass,
            'created_at' => $this->storage($this->now->modify('-1 day')),
        ]);

        return $actionId;
    }

    private function insertHost(): string
    {
        $id = Uuid::randomHex();
        $this->connection->insert('muwa_agent_host', [
            'id' => Uuid::fromHexToBytes($id),
            'name' => 'reaper-host',
            'enabled' => 1,
            'key_id' => 'reaper-' . $id,
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
            'DROP TABLE IF EXISTS `muwa_agent_rollback`, `muwa_agent_nonce`, `muwa_agent_job`, `muwa_agent_backup_run`, '
            . '`muwa_agent_repository_hook`, `muwa_agent_action`, `muwa_agent_host`'
        );
    }
}
