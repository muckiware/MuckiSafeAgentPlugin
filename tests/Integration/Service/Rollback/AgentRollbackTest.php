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
namespace MuckiSafeAgentPlugin\tests\Integration\Service\Rollback;

use Doctrine\DBAL\Connection;
use MuckiSafeAgentPlugin\Migration\Migration1795132800CreateAgentSchema;
use MuckiSafeAgentPlugin\Migration\Migration1795478400CreateRollbackState;
use MuckiSafeAgentPlugin\Service\Job\JobClaimer;
use MuckiSafeAgentPlugin\Service\Job\JobCreator;
use MuckiSafeAgentPlugin\Service\Job\JobDrainer;
use MuckiSafeAgentPlugin\Service\Rollback\AgentRollback;
use MuckiSafeAgentPlugin\Service\Rollback\RollbackActiveException;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Test\TestCaseBase\KernelTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Task 33: controlled rollback. A global, reversible switch stops job
 * creation and claims; the drain ends every open job atomically (queued and
 * never started claimed jobs time out with result code `rollback`, running
 * jobs are asked to stop); `unknown` is never touched. The status readback is
 * the gate of the runbook: hook removal may only continue once no job is
 * queued, claimed, running or cancel_requested.
 */
final class AgentRollbackTest extends TestCase
{
    use KernelTestBehaviour;

    private const TOKEN = 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';
    private const VERSION = 7;

    private Connection $connection;
    private \DateTimeImmutable $now;
    private string $runId;
    private string $actionId;

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
        $this->actionId = $this->insertAction();
    }

    protected function tearDown(): void
    {
        $this->dropSchema();
    }

    public function testMigrationCreatesOneInactiveStateRowAndIsRepeatable(): void
    {
        (new Migration1795478400CreateRollbackState())->update($this->connection);

        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM `muwa_agent_rollback`'));
        self::assertFalse($this->rollback()->isActive());
    }

    public function testFreshInstallationIsInactiveAndReady(): void
    {
        $status = $this->rollback()->status();

        self::assertFalse($status->active);
        self::assertNull($status->startedAt);
        self::assertSame(['queued' => 0, 'claimed' => 0, 'running' => 0, 'cancel_requested' => 0], $status->openJobs);
        self::assertSame(0, $status->unknownJobs);
        self::assertTrue($status->readyForHookRemoval);
    }

    public function testDrainTimesOutUnstartedJobsAndAsksRunningJobsToStop(): void
    {
        $queued = $this->insertJob('queued');
        $claimed = $this->insertJob('claimed');
        $running = $this->insertJob('running');

        $result = $this->drainer()->drain();

        self::assertSame(2, $result->timedOut);
        self::assertSame(1, $result->cancelRequested);
        foreach ([$queued, $claimed] as $jobId) {
            $row = $this->row($jobId);
            self::assertSame('timed_out', $row['status']);
            self::assertSame('rollback', $row['result_code']);
            self::assertSame($this->storage($this->now), $row['finished_at']);
            self::assertNull($row['claim_token_hash']);
            self::assertSame(self::VERSION + 1, (int) $row['lease_version']);
        }
        self::assertSame(hash('sha256', self::TOKEN, true), $this->row($claimed)['previous_claim_token_hash']);
        $row = $this->row($running);
        self::assertSame('cancel_requested', $row['status']);
        // The running agent keeps its token and lease to acknowledge the stop.
        self::assertSame(hash('sha256', self::TOKEN, true), $row['claim_token_hash']);
        self::assertSame(self::VERSION, (int) $row['lease_version']);
        self::assertNull($row['finished_at']);
    }

    public function testDrainNeverTouchesTerminalUnknownOrAlreadyCancelledJobs(): void
    {
        $untouched = [];
        foreach (['succeeded', 'failed', 'timed_out', 'expired', 'unknown', 'cancel_requested'] as $status) {
            $untouched[$status] = $this->insertJob($status);
        }
        $before = array_map(fn (string $jobId): array => $this->row($jobId), $untouched);

        $result = $this->drainer()->drain();

        self::assertSame(0, $result->timedOut + $result->cancelRequested);
        foreach ($untouched as $status => $jobId) {
            self::assertSame($before[$status], $this->row($jobId), $status);
        }
    }

    public function testDrainIsIdempotent(): void
    {
        $this->insertJob('queued');
        $this->drainer()->drain();

        $second = $this->drainer()->drain();

        self::assertSame(0, $second->timedOut + $second->cancelRequested);
    }

    public function testStartActivatesTheSwitchDrainsAndReportsWhatIsStillOpen(): void
    {
        $this->insertJob('queued');
        $this->insertJob('running');
        $this->insertJob('unknown');

        $status = $this->rollback()->start();

        self::assertTrue($status->active);
        self::assertSame($this->storage($this->now), $status->startedAt);
        self::assertSame(['queued' => 0, 'claimed' => 0, 'running' => 0, 'cancel_requested' => 1], $status->openJobs);
        self::assertSame(1, $status->unknownJobs);
        self::assertFalse($status->readyForHookRemoval);
        self::assertTrue($this->rollback()->isActive());
    }

    public function testRepeatedStartKeepsTheFirstStartTimeAndDrainsLateJobs(): void
    {
        $this->rollback()->start();
        $late = $this->insertJob('queued');
        $first = $this->rollback()->status()->startedAt;
        $this->now = $this->now->modify('+1 minute');

        $status = $this->rollback()->start();

        self::assertSame($first, $status->startedAt);
        self::assertSame('timed_out', $this->row($late)['status']);
        self::assertTrue($status->readyForHookRemoval);
    }

    public function testReadyOnlyWhenNoJobIsQueuedClaimedRunningOrCancelRequested(): void
    {
        $this->rollback()->start();
        foreach (['queued', 'claimed', 'running', 'cancel_requested'] as $status) {
            $jobId = $this->insertJob($status);
            self::assertFalse($this->rollback()->status()->readyForHookRemoval, $status);
            $this->connection->executeStatement(
                'UPDATE `muwa_agent_job` SET `status` = \'timed_out\' WHERE `id` = UNHEX(:id)',
                ['id' => $jobId]
            );
        }
        $this->insertJob('unknown');

        // unknown is assessed manually and never blocks or gets repeated.
        self::assertTrue($this->rollback()->status()->readyForHookRemoval);
    }

    public function testStopDeactivatesTheSwitchAndKeepsTheStartTimeForTheAudit(): void
    {
        $this->rollback()->start();
        $this->now = $this->now->modify('+5 minutes');

        $status = $this->rollback()->stop();

        self::assertFalse($status->active);
        self::assertSame($this->storage($this->now), $status->stoppedAt);
        self::assertFalse($this->rollback()->isActive());
    }

    /**
     * A run that is still open when the rollback starts will skip its later
     * phases; it is marked at once, so it never closes as `succeeded` even
     * if its hooks are removed before Facility reaches post/finally.
     */
    public function testStartMarksOpenRunsAndLeavesClosedRunsAlone(): void
    {
        $closed = $this->insertRun('succeeded');

        $this->rollback()->start();

        self::assertSame('rollback_active', $this->runErrorCode($this->runId));
        self::assertNull($this->runErrorCode($closed));
    }

    public function testStartNeverReplacesAnAlreadyRecordedPrimaryError(): void
    {
        $this->connection->executeStatement(
            'UPDATE `muwa_agent_backup_run` SET `primary_error_code` = \'backup_failed\' WHERE `id` = UNHEX(:id)',
            ['id' => $this->runId]
        );

        $this->rollback()->start();

        self::assertSame('backup_failed', $this->runErrorCode($this->runId));
    }

    /**
     * Switch and job creation are serialized: the creator reads the switch
     * with a shared lock inside its transaction, start() writes it with an
     * exclusive one. While the rollback is active no job is created at all.
     */
    public function testNoJobIsCreatedWhileTheRollbackIsActive(): void
    {
        $this->connection->executeStatement('UPDATE `muwa_agent_rollback` SET `active` = 1');
        $creator = self::getContainer()->get(JobCreator::class);
        self::assertInstanceOf(JobCreator::class, $creator);

        try {
            $creator->create(
                $this->runId,
                $this->actionId,
                Uuid::randomHex(),
                $this->insertHost(),
                'pre',
                new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
                new \DateTimeImmutable('+10 minutes', new \DateTimeZone('UTC')),
                3,
                Uuid::randomHex()
            );
            self::fail('A job must not be created during a rollback.');
        } catch (RollbackActiveException) {
        }
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM `muwa_agent_job`'));
    }

    public function testMissingStateRowFailsClosed(): void
    {
        $this->connection->executeStatement('DELETE FROM `muwa_agent_rollback`');

        $this->expectException(\RuntimeException::class);
        $this->rollback()->isActive();
    }

    public function testNoJobIsClaimedWhileTheRollbackIsActive(): void
    {
        $hostId = $this->insertHost();
        $this->insertJob('queued', $hostId);
        $this->connection->executeStatement('UPDATE `muwa_agent_rollback` SET `active` = 1');

        self::assertNull($this->claimer()->claim($hostId)->job);

        $this->connection->executeStatement('UPDATE `muwa_agent_rollback` SET `active` = 0');
        self::assertNotNull($this->claimer()->claim($hostId)->job);
    }

    private function rollback(): AgentRollback
    {
        return new AgentRollback($this->connection, $this->drainer(), fn (): \DateTimeImmutable => $this->now);
    }

    private function drainer(): JobDrainer
    {
        return new JobDrainer($this->connection, fn (): \DateTimeImmutable => $this->now);
    }

    private function claimer(): JobClaimer
    {
        return new JobClaimer($this->connection, serverNowProvider: fn (): string => $this->storage($this->now));
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

    /** Every job gets its own host unless one is given (one active job per agent). */
    private function insertJob(string $status, ?string $hostId = null): string
    {
        $jobId = Uuid::randomHex();
        $created = $this->storage($this->now->modify('-1 minute'));
        $executionDeadline = $this->now->modify('+9 minutes 45 seconds');
        $phaseDeadline = $executionDeadline->modify('+15 seconds');
        $unclaimed = $status === 'queued';
        $this->connection->insert('muwa_agent_job', [
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
            'target_agent_id' => Uuid::fromHexToBytes($hostId ?? $this->insertHost()),
            'claim_token_hash' => $unclaimed ? null : hash('sha256', self::TOKEN, true),
            'lease_version' => self::VERSION,
            'attempt' => $unclaimed ? 0 : 1,
            'max_attempts' => 3,
            'available_at' => $created,
            'phase_deadline' => $this->storage($phaseDeadline),
            'execution_deadline' => $this->storage($executionDeadline),
            'stop_deadline' => $this->storage($phaseDeadline->modify('-10 seconds')),
            'claimed_at' => $unclaimed ? null : $created,
            'lease_expires_at' => $unclaimed ? null : $this->storage($this->now->modify('+10 seconds')),
            'created_at' => $created,
        ]);

        return $jobId;
    }

    private function runErrorCode(string $runId): ?string
    {
        $code = $this->connection->fetchOne(
            'SELECT `primary_error_code` FROM `muwa_agent_backup_run` WHERE `id` = UNHEX(:id)',
            ['id' => $runId]
        );

        return $code === null || $code === false ? null : (string) $code;
    }

    private function insertRun(string $status = 'running'): string
    {
        $runId = Uuid::randomHex();
        $created = $this->storage($this->now->modify('-1 minute'));
        $this->connection->insert('muwa_agent_backup_run', [
            'id' => Uuid::fromHexToBytes($runId),
            'facility_repository_id' => Uuid::randomBytes(),
            'source' => 'facility',
            'idempotency_key' => Uuid::randomHex(),
            'status' => $status,
            'started_at' => $created,
            'finished_at' => $status === 'running' ? null : $created,
            'created_at' => $created,
        ]);

        return $runId;
    }

    private function insertAction(): string
    {
        $actionId = Uuid::randomHex();
        $this->connection->insert('muwa_agent_action', [
            'id' => Uuid::fromHexToBytes($actionId),
            'technical_name' => Uuid::randomHex(),
            'label' => 'Rollback action',
            'enabled' => 1,
            'definition' => '{"version":1,"steps":[]}',
            'definition_hash' => hash('sha256', '{"version":1,"steps":[]}', true),
            'required_capabilities' => '[]',
            'default_timeout_seconds' => 60,
            'max_timeout_seconds' => 600,
            'retry_policy' => 'none',
            'idempotency_class' => 'safe',
            'created_at' => $this->storage($this->now->modify('-1 day')),
        ]);

        return $actionId;
    }

    private function insertHost(): string
    {
        $id = Uuid::randomHex();
        $this->connection->insert('muwa_agent_host', [
            'id' => Uuid::fromHexToBytes($id),
            'name' => 'rollback-host',
            'enabled' => 1,
            'key_id' => 'rollback-' . $id,
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

    private function dropSchema(): void
    {
        $this->connection->executeStatement(
            'DROP TABLE IF EXISTS `muwa_agent_rollback`, `muwa_agent_nonce`, `muwa_agent_job`, `muwa_agent_backup_run`, '
            . '`muwa_agent_repository_hook`, `muwa_agent_action`, `muwa_agent_host`'
        );
    }
}
