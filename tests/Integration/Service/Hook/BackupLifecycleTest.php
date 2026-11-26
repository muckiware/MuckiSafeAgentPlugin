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
namespace MuckiSafeAgentPlugin\tests\Integration\Service\Hook;

use Doctrine\DBAL\Connection;
use MuckiFacilityPlugin\Events\AfterBackupCreatedEvent;
use MuckiFacilityPlugin\Events\BackupFinishedEvent;
use MuckiFacilityPlugin\Events\BeforeBackupCreatedEvent;
use MuckiFacilityPlugin\Subscriber\BackupCommandSubscriber;
use MuckiSafeAgentPlugin\Api\JobCompleteRequest;
use MuckiSafeAgentPlugin\Migration\Migration1795132800CreateAgentSchema;
use MuckiSafeAgentPlugin\Migration\Migration1795478400CreateRollbackState;
use MuckiSafeAgentPlugin\Service\Action\ActionDefinitionDecoder;
use MuckiSafeAgentPlugin\Service\Action\ActionValidator;
use MuckiSafeAgentPlugin\Service\Hook\AgentHookFailure;
use MuckiSafeAgentPlugin\Service\Hook\BackupRunCoordinator;
use MuckiSafeAgentPlugin\Service\Hook\HostAgentHookExecutor;
use MuckiSafeAgentPlugin\Service\Hook\LegacyHookConflictGuard;
use MuckiSafeAgentPlugin\Service\Job\JobClaimer;
use MuckiSafeAgentPlugin\Service\Job\JobCompletion;
use MuckiSafeAgentPlugin\Service\Job\JobCreator;
use MuckiSafeAgentPlugin\Service\Job\JobReaper;
use MuckiSafeAgentPlugin\Service\Job\JobTransitionGuard;
use MuckiSafeAgentPlugin\Service\Job\JobWaiter;
use MuckiSafeAgentPlugin\Service\Job\OutputTailNormalizer;
use MuckiSafeAgentPlugin\Service\Wakeup\WakeupWriter;
use MuckiSafeAgentPlugin\Subscriber\FacilityBackupAfterSubscriber;
use MuckiSafeAgentPlugin\Subscriber\FacilityBackupBeforeSubscriber;
use MuckiSafeAgentPlugin\Subscriber\FacilityBackupFinishedSubscriber;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Test\TestCaseBase\KernelTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Task 16 RED matrix: coordination of pre/post/finally host actions around a
 * Facility backup. Time is an injected clock; the waiter's sleeper advances
 * it and lets a simulated agent act, so budgets are measured without real
 * waiting.
 */
final class BackupLifecycleTest extends TestCase
{
    use KernelTestBehaviour;

    private const POLL_SECONDS = 1;

    private Connection $connection;
    private \DateTimeImmutable $now;
    private string $repositoryId;
    private string $hostId;
    private string $actionId;
    /** @var array<string, string> agent behaviour per phase */
    private array $agentModes = [];
    /** @var array{jobId: string, token: string, version: int, phase: string}|null */
    private ?array $claimed = null;
    /** @var array<string, int> */
    private array $claimsPerPhase = [];
    /** @var list<string> */
    private array $facilityRepositoryIds = [];
    private string $wakeupDirectory;
    /** @var list<array{jobId: string, wakeup: bool, transactionLevel: int}> what the agent saw per queued job */
    private array $wakeupObservations = [];

    protected function setUp(): void
    {
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->dropSchema();
        (new Migration1795132800CreateAgentSchema())->update($connection);
        (new Migration1795478400CreateRollbackState())->update($connection);
        $now = new \DateTimeImmutable((string) $connection->fetchOne('SELECT UTC_TIMESTAMP()'), new \DateTimeZone('UTC'));
        $this->now = $now->modify('+1 second');
        $this->repositoryId = $this->insertFacilityRepository('none', 'none');
        $this->hostId = $this->insertHost();
        $this->actionId = $this->insertAction('safe');
        $this->wakeupDirectory = sys_get_temp_dir() . '/muwa-lifecycle-wakeup-' . bin2hex(random_bytes(6));
        mkdir($this->wakeupDirectory, 0700);
    }

    protected function tearDown(): void
    {
        foreach ($this->facilityRepositoryIds as $id) {
            $this->connection->executeStatement('DELETE FROM `muwa_backup_repository` WHERE `id` = UNHEX(:id)', ['id' => $id]);
        }
        $this->dropSchema();
        foreach (glob($this->wakeupDirectory . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->wakeupDirectory)) {
            rmdir($this->wakeupDirectory);
        }
    }

    public function testSuccessfulBackupRunsPrePostAndFinallyOnOneRun(): void
    {
        $this->configureHooks(['pre' => 60, 'post' => 60, 'finally' => 60]);
        $this->agentModes = ['pre' => 'succeed', 'post' => 'succeed', 'finally' => 'succeed'];

        $this->coordinator()->before($this->repositoryId);
        $this->coordinator()->after($this->repositoryId);
        $this->coordinator()->finished($this->repositoryId, null);

        $run = $this->singleRun();
        self::assertSame('succeeded', $run['status']);
        self::assertNull($run['primary_error_code']);
        self::assertNotNull($run['finished_at']);
        self::assertSame(['pre' => 'succeeded', 'post' => 'succeeded', 'finally' => 'succeeded'], $this->jobStatuses());
    }

    public function testFailingPreHookWithFailOnErrorAbortsAndFinallyStillRuns(): void
    {
        $this->configureHooks(['pre' => 60, 'finally' => 60]);
        $this->agentModes = ['pre' => 'fail', 'finally' => 'succeed'];

        $failure = $this->expectHookFailure(fn () => $this->coordinator()->before($this->repositoryId));
        self::assertSame('pre_hook_failed', $failure->errorCode);
        $this->coordinator()->finished($this->repositoryId, $failure);

        $run = $this->singleRun();
        self::assertSame('failed', $run['status']);
        self::assertSame('pre_hook_failed', $run['primary_error_code']);
        self::assertSame(['pre' => 'failed', 'finally' => 'succeeded'], $this->jobStatuses());
    }

    public function testFailingPreHookWithoutFailOnErrorIsTolerated(): void
    {
        $this->configureHooks(['pre' => 60], false);
        $this->agentModes = ['pre' => 'fail'];

        $this->coordinator()->before($this->repositoryId);
        $this->coordinator()->after($this->repositoryId);
        $this->coordinator()->finished($this->repositoryId, null);

        self::assertSame('succeeded', $this->singleRun()['status']);
        self::assertSame(['pre' => 'failed'], $this->jobStatuses());
    }

    public function testCoreErrorIsRecordedAsPrimaryErrorAndFinallyRuns(): void
    {
        $this->configureHooks(['pre' => 60, 'post' => 60, 'finally' => 60]);
        $this->agentModes = ['pre' => 'succeed', 'finally' => 'succeed'];

        $this->coordinator()->before($this->repositoryId);
        $this->coordinator()->finished($this->repositoryId, new \RuntimeException('restic failed: secret path'));

        $run = $this->singleRun();
        self::assertSame('failed', $run['status']);
        self::assertSame('backup_failed', $run['primary_error_code']);
        self::assertSame('RuntimeException', $run['primary_error_summary']);
        self::assertSame(['pre' => 'succeeded', 'finally' => 'succeeded'], $this->jobStatuses());
    }

    public function testFailingPostHookIsThrownAndFinallyRuns(): void
    {
        $this->configureHooks(['post' => 60, 'finally' => 60]);
        $this->agentModes = ['post' => 'fail', 'finally' => 'succeed'];

        $this->coordinator()->before($this->repositoryId);
        $failure = $this->expectHookFailure(fn () => $this->coordinator()->after($this->repositoryId));
        self::assertSame('post_hook_failed', $failure->errorCode);
        $this->coordinator()->finished($this->repositoryId, $failure);

        self::assertSame('post_hook_failed', $this->singleRun()['primary_error_code']);
        self::assertSame(['post' => 'failed', 'finally' => 'succeeded'], $this->jobStatuses());
    }

    public function testFailingFinallyHookIsThrownAfterTheRunWasClosed(): void
    {
        $this->configureHooks(['finally' => 60]);
        $this->agentModes = ['finally' => 'fail'];

        $this->coordinator()->before($this->repositoryId);
        $failure = $this->expectHookFailure(fn () => $this->coordinator()->finished($this->repositoryId, null));

        self::assertSame('finally_hook_failed', $failure->errorCode);
        $run = $this->singleRun();
        self::assertSame('failed', $run['status']);
        self::assertSame('finally_hook_failed', $run['primary_error_code']);
    }

    public function testFinallyFailureNeverReplacesTheRecordedPrimaryError(): void
    {
        $this->configureHooks(['pre' => 60, 'finally' => 60]);
        $this->agentModes = ['pre' => 'fail', 'finally' => 'fail'];

        $primary = $this->expectHookFailure(fn () => $this->coordinator()->before($this->repositoryId));
        $this->expectHookFailure(fn () => $this->coordinator()->finished($this->repositoryId, $primary));

        self::assertSame('pre_hook_failed', $this->singleRun()['primary_error_code']);
    }

    public function testFinallyRunsAtMostOnceAndTheRunIsClosedOnce(): void
    {
        $this->configureHooks(['finally' => 60]);
        $this->agentModes = ['finally' => 'succeed'];

        $this->coordinator()->before($this->repositoryId);
        $this->coordinator()->finished($this->repositoryId, null);
        $finishedAt = $this->singleRun()['finished_at'];
        $this->coordinator()->finished($this->repositoryId, null);

        self::assertSame(1, $this->claimsPerPhase['finally']);
        self::assertSame(['finally' => 'succeeded'], $this->jobStatuses());
        self::assertSame($finishedAt, $this->singleRun()['finished_at']);
    }

    public function testRepositoryWithoutAgentHooksIsUntouchedEvenWithLegacyHooks(): void
    {
        $legacyRepository = $this->insertFacilityRepository('batch', 'php');

        $this->coordinator()->before($legacyRepository);
        $this->coordinator()->after($legacyRepository);
        $this->coordinator()->finished($legacyRepository, null);

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM `muwa_agent_backup_run`'));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM `muwa_agent_job`'));
    }

    /**
     * Task 33: while the rollback switch is active a backup runs like a
     * Facility-only backup: no run, no job, and re-enabled legacy hooks do
     * not count as a conflict.
     */
    public function testActiveRollbackCreatesNoRunAndIgnoresReEnabledLegacyHooks(): void
    {
        $this->configureHooks(['pre' => 60, 'post' => 60, 'finally' => 60]);
        $this->connection->executeStatement(
            'UPDATE `muwa_backup_repository` SET `pre_backup_command_type` = \'php\' WHERE `id` = UNHEX(:id)',
            ['id' => $this->repositoryId]
        );
        $this->connection->executeStatement('UPDATE `muwa_agent_rollback` SET `active` = 1');

        $this->coordinator()->before($this->repositoryId);
        $this->coordinator()->after($this->repositoryId);
        $this->coordinator()->finished($this->repositoryId, null);

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM `muwa_agent_backup_run`'));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM `muwa_agent_job`'));
    }

    /**
     * A rollback started during a backup creates no further job (it would
     * never be claimed): post and finally are skipped and the run is closed
     * as failed with the primary error `rollback_active`.
     */
    public function testRollbackStartedDuringABackupSkipsLaterPhasesAndClosesTheRun(): void
    {
        $this->configureHooks(['pre' => 60, 'post' => 60, 'finally' => 60]);
        $this->agentModes = ['pre' => 'succeed', 'post' => 'succeed', 'finally' => 'succeed'];

        $this->coordinator()->before($this->repositoryId);
        $this->connection->executeStatement('UPDATE `muwa_agent_rollback` SET `active` = 1');
        $this->coordinator()->after($this->repositoryId);
        $this->coordinator()->finished($this->repositoryId, null);

        $run = $this->singleRun();
        self::assertSame('failed', $run['status']);
        self::assertSame('rollback_active', $run['primary_error_code']);
        self::assertNotNull($run['finished_at']);
        self::assertSame(['pre' => 'succeeded'], $this->jobStatuses());
    }

    public function testDisabledHooksCountAsNoHooks(): void
    {
        $this->configureHooks(['pre' => 60]);
        $this->connection->executeStatement('UPDATE `muwa_agent_repository_hook` SET `enabled` = 0');

        $this->coordinator()->before($this->repositoryId);

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM `muwa_agent_backup_run`'));
    }

    public function testLegacyHookAddedAfterConfigurationBlocksBeforeAnySideEffect(): void
    {
        $this->configureHooks(['pre' => 60]);
        $this->connection->executeStatement(
            'UPDATE `muwa_backup_repository` SET `post_backup_command_type` = \'batch\' WHERE `id` = UNHEX(:id)',
            ['id' => $this->repositoryId]
        );

        $failure = $this->expectHookFailure(fn () => $this->coordinator()->before($this->repositoryId));

        self::assertSame('legacy_hook_conflict', $failure->errorCode);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM `muwa_agent_backup_run`'));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM `muwa_agent_job`'));
        self::assertSame([], $this->claimsPerPhase);
    }

    public function testOrphanedRunWithoutActiveJobsIsClosedAsUnknownOnTheNextBackup(): void
    {
        $this->configureHooks(['pre' => 60]);
        $this->agentModes = ['pre' => 'succeed'];
        $this->coordinator()->before($this->repositoryId);
        // process restart: neither After nor Finished arrive for the first run

        $this->coordinator()->before($this->repositoryId);

        $runs = $this->connection->fetchAllAssociative(
            'SELECT `status`, `primary_error_code` FROM `muwa_agent_backup_run` ORDER BY `started_at`, `created_at`'
        );
        self::assertCount(2, $runs);
        self::assertContains(['status' => 'unknown', 'primary_error_code' => 'orphaned_run'], $runs);
        self::assertContains(['status' => 'running', 'primary_error_code' => null], $runs);
    }

    public function testRunWithAStillActiveJobFailsTheNextBackupClosed(): void
    {
        $this->configureHooks(['pre' => 60]);
        $this->agentModes = ['pre' => 'offline'];
        $runId = Uuid::randomHex();
        $this->connection->insert('muwa_agent_backup_run', [
            'id' => Uuid::fromHexToBytes($runId),
            'facility_repository_id' => Uuid::fromHexToBytes($this->repositoryId),
            'source' => 'facility',
            'idempotency_key' => Uuid::randomHex(),
            'status' => 'running',
            'started_at' => $this->storage($this->now),
            'created_at' => $this->storage($this->now),
        ]);
        $this->container(JobCreator::class)->create(
            $runId,
            $this->actionId,
            $this->repositoryId,
            $this->hostId,
            'pre',
            $this->now,
            $this->now->modify('+60 seconds'),
            1,
            $runId . ':pre'
        );

        $failure = $this->expectHookFailure(fn () => $this->coordinator()->before($this->repositoryId));

        self::assertSame('agent_run_active', $failure->errorCode);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM `muwa_agent_backup_run`'));
    }

    public function testOfflineAgentEndsAtTheExecutionCutoffWithinTheBudget(): void
    {
        $this->configureHooks(['pre' => 60]);
        $this->agentModes = ['pre' => 'offline'];
        $start = $this->now;

        $failure = $this->expectHookFailure(fn () => $this->coordinator()->before($this->repositoryId));

        self::assertSame('pre_hook_failed', $failure->errorCode);
        self::assertSame(['pre' => 'timed_out'], $this->jobStatuses());
        self::assertLessThanOrEqual(60 - 15 + self::POLL_SECONDS, $this->now->getTimestamp() - $start->getTimestamp());
    }

    public function testLostHeartbeatAfterStartEndsUnknownAtThePhaseDeadlineWithoutRequeue(): void
    {
        $this->configureHooks(['pre' => 60]);
        $this->agentModes = ['pre' => 'silent_after_start'];
        $start = $this->now;

        $this->expectHookFailure(fn () => $this->coordinator()->before($this->repositoryId));

        self::assertSame(['pre' => 'unknown'], $this->jobStatuses());
        self::assertSame(1, $this->claimsPerPhase['pre']);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT `attempt` FROM `muwa_agent_job`'));
        $elapsed = $this->now->getTimestamp() - $start->getTimestamp();
        self::assertGreaterThan(60, $elapsed);
        self::assertLessThanOrEqual(60 + 2 * self::POLL_SECONDS, $elapsed);
    }

    public function testWaiterFollowsTheSafeRequeueOfANeverStartedJob(): void
    {
        $this->configureHooks(['pre' => 120]);
        $this->agentModes = ['pre' => 'abandon_first_claim'];

        $this->coordinator()->before($this->repositoryId);

        self::assertSame(['pre' => 'succeeded'], $this->jobStatuses());
        self::assertSame(2, $this->claimsPerPhase['pre']);
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT `attempt` FROM `muwa_agent_job`'));
    }

    public function testNoNewStepCanBeginAfterTheWaiterReturned(): void
    {
        $this->configureHooks(['pre' => 60]);
        $this->agentModes = ['pre' => 'offline'];
        $this->expectHookFailure(fn () => $this->coordinator()->before($this->repositoryId));

        self::assertNull($this->claimer()->claim($this->hostId)->job);
    }

    public function testFinallyGetsItsOwnBudgetAfterAnExhaustedPrePhase(): void
    {
        $this->configureHooks(['pre' => 60, 'finally' => 90]);
        $this->agentModes = ['pre' => 'offline', 'finally' => 'succeed'];

        $primary = $this->expectHookFailure(fn () => $this->coordinator()->before($this->repositoryId));
        $finishedAt = $this->now;
        $this->coordinator()->finished($this->repositoryId, $primary);

        $deadline = (string) $this->connection->fetchOne(
            'SELECT `phase_deadline` FROM `muwa_agent_job` WHERE `phase` = \'finally\''
        );
        self::assertSame(90, (new \DateTimeImmutable($deadline, new \DateTimeZone('UTC')))->getTimestamp() - $finishedAt->getTimestamp());
        self::assertSame(['pre' => 'timed_out', 'finally' => 'succeeded'], $this->jobStatuses());
    }

    public function testStoredBudgetAboveThePhaseMaximumIsClampedServerSide(): void
    {
        $this->configureHooks(['pre' => 60]);
        $this->connection->executeStatement('UPDATE `muwa_agent_repository_hook` SET `timeout_seconds` = 99999');
        $this->agentModes = ['pre' => 'succeed'];
        $start = $this->now;

        $this->coordinator()->before($this->repositoryId);

        $deadline = (string) $this->connection->fetchOne('SELECT `phase_deadline` FROM `muwa_agent_job`');
        self::assertSame(1800, (new \DateTimeImmutable($deadline, new \DateTimeZone('UTC')))->getTimestamp() - $start->getTimestamp());
    }

    public function testSubscribersAreWiredAheadOfTheLegacyCommandSubscriber(): void
    {
        $dispatcher = $this->container(EventDispatcherInterface::class);
        $before = $this->container(FacilityBackupBeforeSubscriber::class);
        $legacy = $this->container(BackupCommandSubscriber::class);

        self::assertSame(1000, $dispatcher->getListenerPriority(BeforeBackupCreatedEvent::NAME, [$before, 'onBefore']));
        self::assertSame(0, $dispatcher->getListenerPriority(BeforeBackupCreatedEvent::NAME, [$legacy, 'onBeforeBackupCreated']));
        self::assertNotNull($dispatcher->getListenerPriority(
            AfterBackupCreatedEvent::NAME,
            [$this->container(FacilityBackupAfterSubscriber::class), 'onAfter']
        ));
        self::assertNotNull($dispatcher->getListenerPriority(
            BackupFinishedEvent::NAME,
            [$this->container(FacilityBackupFinishedSubscriber::class), 'onFinished']
        ));
    }

    public function testEveryCreatedJobIsSignalledOnlyAfterItsCommit(): void
    {
        $this->configureHooks(['pre' => 60, 'post' => 60, 'finally' => 60]);
        $this->agentModes = ['pre' => 'succeed', 'post' => 'succeed', 'finally' => 'succeed'];
        $level = $this->connection->getTransactionNestingLevel();

        $this->coordinator()->before($this->repositoryId);
        $this->coordinator()->after($this->repositoryId);
        $this->coordinator()->finished($this->repositoryId, null);

        self::assertCount(3, $this->wakeupObservations);
        foreach ($this->wakeupObservations as $observation) {
            self::assertTrue($observation['wakeup'], 'queued job ' . $observation['jobId'] . ' has no wakeup');
            self::assertSame($level, $observation['transactionLevel']);
        }
        $jobIds = $this->connection->fetchFirstColumn('SELECT LOWER(HEX(`id`)) FROM `muwa_agent_job`');
        self::assertCount(3, glob($this->wakeupDirectory . '/*.ready') ?: []);
        foreach ($jobIds as $jobId) {
            self::assertFileExists($this->wakeupPath((string) $jobId));
        }
        self::assertSame([], glob($this->wakeupDirectory . '/*.tmp') ?: []);
    }

    public function testJobThatIsNotCreatedIsNeverSignalled(): void
    {
        $this->configureHooks(['pre' => 60]);
        $this->connection->executeStatement('UPDATE `muwa_agent_host` SET `enabled` = 0');

        $this->expectHookFailure(fn () => $this->coordinator()->before($this->repositoryId));

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM `muwa_agent_job`'));
        self::assertSame([], array_values(array_diff(scandir($this->wakeupDirectory) ?: [], ['.', '..'])));
    }

    public function testWakeupWriterFailureKeepsTheCommittedJobAndPollingDeliversIt(): void
    {
        $this->configureHooks(['pre' => 60]);
        $this->agentModes = ['pre' => 'succeed'];
        rmdir($this->wakeupDirectory);

        $this->coordinator()->before($this->repositoryId);

        self::assertSame(['pre' => 'succeeded'], $this->jobStatuses());
        self::assertFalse($this->wakeupObservations[0]['wakeup']);
    }

    public function testCrashAfterCommitButBeforeTheWakeupLosesNoJob(): void
    {
        $runId = Uuid::randomHex();
        $this->connection->insert('muwa_agent_backup_run', [
            'id' => Uuid::fromHexToBytes($runId),
            'facility_repository_id' => Uuid::fromHexToBytes($this->repositoryId),
            'source' => 'facility',
            'idempotency_key' => Uuid::randomHex(),
            'status' => 'running',
            'started_at' => $this->storage($this->now),
            'created_at' => $this->storage($this->now),
        ]);
        // the process dies right after JobCreator committed: no WakeupWriter call happens
        $jobId = $this->container(JobCreator::class)->create(
            $runId,
            $this->actionId,
            $this->repositoryId,
            $this->hostId,
            'pre',
            $this->now,
            $this->now->modify('+60 seconds'),
            1,
            $runId . ':pre'
        );

        self::assertFileDoesNotExist($this->wakeupPath($jobId));
        $job = $this->claimer()->claim($this->hostId)->job;
        self::assertNotNull($job);
        self::assertSame($jobId, str_replace('-', '', $job->id));
    }

    private function wakeupPath(string $hexJobId): string
    {
        return $this->wakeupDirectory . '/' . substr($hexJobId, 0, 8) . '-' . substr($hexJobId, 8, 4) . '-'
            . substr($hexJobId, 12, 4) . '-' . substr($hexJobId, 16, 4) . '-' . substr($hexJobId, 20, 12) . '.ready';
    }

    private function coordinator(): BackupRunCoordinator
    {
        $clock = fn (): \DateTimeImmutable => $this->now;
        $waiter = new JobWaiter(
            $this->connection,
            new JobReaper($this->connection, $clock),
            $clock,
            function (int $seconds): void {
                $this->now = $this->now->modify('+' . $seconds . ' seconds');
                $this->agentTick();
            },
            self::POLL_SECONDS
        );
        $executor = new HostAgentHookExecutor(
            new NullLogger(),
            $this->connection,
            $this->container(JobCreator::class),
            $waiter,
            new WakeupWriter($this->wakeupDirectory, new NullLogger()),
            $clock
        );

        return new BackupRunCoordinator(
            new NullLogger(),
            $this->connection,
            $this->container(LegacyHookConflictGuard::class),
            $executor,
            $clock
        );
    }

    /** Simulated agent: acts once per waiter poll tick, according to the mode of the job's phase. */
    private function agentTick(): void
    {
        if ($this->claimed === null) {
            $queued = $this->connection->fetchAssociative(
                'SELECT `phase`, LOWER(HEX(`id`)) AS `id` FROM `muwa_agent_job` '
                . 'WHERE `target_agent_id` = UNHEX(:host) AND `status` = \'queued\'',
                ['host' => $this->hostId]
            );
            if ($queued === false) {
                return;
            }
            $this->observeWakeup((string) $queued['id']);
            if (($this->agentModes[(string) $queued['phase']] ?? 'offline') === 'offline') {
                return;
            }
            $job = $this->claimer()->claim($this->hostId)->job;
            if ($job === null) {
                return;
            }
            $this->claimsPerPhase[$job->phase] = ($this->claimsPerPhase[$job->phase] ?? 0) + 1;
            $this->claimed = ['jobId' => $job->id, 'token' => $job->claimToken, 'version' => $job->leaseVersion, 'phase' => $job->phase];
        }
        $claimed = $this->claimed;
        $mode = $this->agentModes[$claimed['phase']] ?? 'offline';
        if ($mode === 'offline') {
            return;
        }
        if ($mode === 'abandon_first_claim' && $this->claimsPerPhase[$claimed['phase']] === 1) {
            // crash before /start: the lease expires and the reaper requeues the safe job
            $this->claimed = null;

            return;
        }
        if ($mode === 'silent_after_start') {
            $this->guard()->start($this->hostId, $claimed['jobId'], $claimed['token'], $claimed['version']);
            $this->agentModes[$claimed['phase']] = 'offline';

            return;
        }
        $this->guard()->start($this->hostId, $claimed['jobId'], $claimed['token'], $claimed['version']);
        $failed = $mode === 'fail';
        (new JobCompletion($this->connection, new OutputTailNormalizer(), $this->guard(), fn (): \DateTimeImmutable => $this->now))
            ->complete($this->hostId, $claimed['jobId'], new JobCompleteRequest(
                $claimed['token'],
                $claimed['version'],
                $failed ? 'failed' : 'succeeded',
                $failed ? 'step_failed' : 'ok',
                $failed ? 'step failed' : 'done',
                null,
                null,
                null,
                null
            ));
        $this->claimed = null;
    }

    private function observeWakeup(string $jobId): void
    {
        foreach ($this->wakeupObservations as $observation) {
            if ($observation['jobId'] === $jobId) {
                return;
            }
        }
        $this->wakeupObservations[] = [
            'jobId' => $jobId,
            'wakeup' => is_file($this->wakeupPath($jobId)),
            'transactionLevel' => $this->connection->getTransactionNestingLevel(),
        ];
    }

    private function claimer(): JobClaimer
    {
        return new JobClaimer($this->connection, null, null, fn (): string => $this->storage($this->now));
    }

    private function guard(): JobTransitionGuard
    {
        return new JobTransitionGuard($this->connection, fn (): \DateTimeImmutable => $this->now);
    }

    private function expectHookFailure(\Closure $operation): AgentHookFailure
    {
        try {
            $operation();
        } catch (AgentHookFailure $failure) {
            return $failure;
        }
        self::fail('An agent hook failure was expected.');
    }

    /** @param array<string, int> $budgets */
    private function configureHooks(array $budgets, bool $failOnError = true): void
    {
        foreach ($budgets as $phase => $budget) {
            $this->connection->insert('muwa_agent_repository_hook', [
                'id' => Uuid::randomBytes(),
                'facility_repository_id' => Uuid::fromHexToBytes($this->repositoryId),
                'phase' => $phase,
                'agent_host_id' => Uuid::fromHexToBytes($this->hostId),
                'agent_action_id' => Uuid::fromHexToBytes($this->actionId),
                'parameters' => '{}',
                'timeout_seconds' => $budget,
                'fail_on_error' => $failOnError ? 1 : 0,
                'enabled' => 1,
                'created_at' => $this->storage($this->now),
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function singleRun(): array
    {
        $runs = $this->connection->fetchAllAssociative('SELECT * FROM `muwa_agent_backup_run`');
        self::assertCount(1, $runs);

        return $runs[0];
    }

    /** @return array<string, string> */
    private function jobStatuses(): array
    {
        $statuses = [];
        foreach ($this->connection->fetchAllAssociative('SELECT `phase`, `status` FROM `muwa_agent_job`') as $row) {
            $statuses[(string) $row['phase']] = (string) $row['status'];
        }
        $order = ['pre' => 0, 'post' => 1, 'finally' => 2];
        uksort($statuses, static fn (string $a, string $b): int => $order[$a] <=> $order[$b]);

        return $statuses;
    }

    /**
     * @template T of object
     * @param class-string<T> $id
     * @return T
     */
    private function container(string $id): object
    {
        $service = self::getContainer()->get($id);
        self::assertInstanceOf($id, $service);

        return $service;
    }

    private function insertFacilityRepository(string $preType, string $postType): string
    {
        $id = Uuid::randomHex();
        $this->connection->insert('muwa_backup_repository', [
            'id' => Uuid::fromHexToBytes($id),
            'name' => 'lifecycle-' . $id,
            'type' => 'local',
            'repository_path' => '/tmp/lifecycle-' . $id,
            'repository_password' => 'unused',
            'pre_backup_command_type' => $preType,
            'pre_backup_command_arguments' => '',
            'post_backup_command_type' => $postType,
            'post_backup_command_arguments' => '',
            'created_at' => $this->storage($this->now),
        ]);
        $this->facilityRepositoryIds[] = $id;

        return $id;
    }

    private function insertHost(): string
    {
        $id = Uuid::randomHex();
        $this->connection->insert('muwa_agent_host', [
            'id' => Uuid::fromHexToBytes($id),
            'name' => 'lifecycle-host',
            'enabled' => 1,
            'key_id' => 'lifecycle-' . $id,
            'secret_ciphertext' => random_bytes(32),
            'secret_nonce' => random_bytes(24),
            'secret_key_version' => 1,
            'capabilities' => '["backup"]',
            'created_at' => $this->storage($this->now),
        ]);

        return $id;
    }

    private function insertAction(string $idempotencyClass): string
    {
        $id = Uuid::randomHex();
        $raw = '{"version":1,"steps":[{"primitive":"service.control","serviceAlias":"backup","operation":"stop"}]}';
        $canonical = (new ActionValidator())->canonicalize((new ActionDefinitionDecoder())->decode($raw));
        $this->connection->insert('muwa_agent_action', [
            'id' => Uuid::fromHexToBytes($id),
            'technical_name' => 'lifecycle-' . $id,
            'label' => 'Lifecycle action',
            'enabled' => 1,
            'definition' => $canonical,
            'definition_hash' => hash('sha256', $canonical, true),
            'required_capabilities' => '["backup"]',
            'default_timeout_seconds' => 60,
            'max_timeout_seconds' => 1800,
            'retry_policy' => 'none',
            'idempotency_class' => $idempotencyClass,
            'created_at' => $this->storage($this->now),
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
