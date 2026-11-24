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
namespace MuckiSafeAgentPlugin\tests\Integration\ScheduledTask;

use Doctrine\DBAL\Connection;
use MuckiSafeAgentPlugin\Migration\Migration1795132800CreateAgentSchema;
use MuckiSafeAgentPlugin\ScheduledTask\AgentCleanupTask;
use MuckiSafeAgentPlugin\ScheduledTask\AgentCleanupTaskHandler;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\Registry\TaskRegistry;
use Shopware\Core\Framework\Test\TestCaseBase\KernelTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Task 14 RED: the scheduled cleanup is wired into the container and one run
 * reaps expired leases, deletes strictly expired nonces and prunes old
 * failure tails against the real server clock.
 */
final class AgentCleanupTaskHandlerTest extends TestCase
{
    use KernelTestBehaviour;

    private Connection $connection;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->dropSchema();
        (new Migration1795132800CreateAgentSchema())->update($connection);
        $this->now = new \DateTimeImmutable(
            (string) $connection->fetchOne('SELECT UTC_TIMESTAMP(3)'),
            new \DateTimeZone('UTC')
        );
    }

    protected function tearDown(): void
    {
        $this->dropSchema();
    }

    public function testTaskIsRegisteredWithAShortInterval(): void
    {
        self::assertSame('muwa_agent.cleanup', AgentCleanupTask::getTaskName());
        self::assertSame(60, AgentCleanupTask::getDefaultInterval());
        $registry = self::getContainer()->get(TaskRegistry::class);
        self::assertInstanceOf(TaskRegistry::class, $registry);
        $registry->registerTasks();
        self::assertSame(1, (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM `scheduled_task` WHERE `name` = :name',
            ['name' => AgentCleanupTask::getTaskName()]
        ));
    }

    public function testOneRunReapsLeasesDeletesExpiredNoncesAndPrunesOldTails(): void
    {
        $hostId = $this->insertHost();
        $runId = $this->insertRun();
        $actionId = $this->insertAction();
        $expiredClaim = $this->insertJob($runId, $actionId, $this->insertHost(), [
            'status' => 'claimed',
            'claim_token_hash' => hash('sha256', 'token', true),
            'lease_version' => 1,
            'attempt' => 1,
            'lease_expires_at' => $this->storage($this->now->modify('-1 minute')),
        ]);
        $oldFailure = $this->insertJob($runId, $actionId, $hostId, [
            'status' => 'failed',
            'finished_at' => $this->storage($this->now->modify('-31 days')),
            'result_code' => 'step_failed',
            'stdout_tail' => 'out',
            'stderr_tail' => 'err',
        ]);
        $this->insertNonce($hostId, 'expired', $this->now->modify('-1 minute'));
        $this->insertNonce($hostId, 'live', $this->now->modify('+5 minutes'));

        $handler = self::getContainer()->get(AgentCleanupTaskHandler::class);
        self::assertInstanceOf(AgentCleanupTaskHandler::class, $handler);
        $handler->run();

        self::assertSame('queued', $this->field($expiredClaim, 'status'));
        self::assertNull($this->field($oldFailure, 'stdout_tail'));
        self::assertSame('step_failed', $this->field($oldFailure, 'result_code'));
        self::assertSame(['live'], $this->connection->fetchFirstColumn('SELECT `nonce` FROM `muwa_agent_nonce`'));
    }

    private function field(string $jobId, string $field): mixed
    {
        return $this->connection->fetchOne(
            'SELECT `' . $field . '` FROM `muwa_agent_job` WHERE `id` = UNHEX(:id)',
            ['id' => $jobId]
        );
    }

    /** @param array<string, mixed> $overrides */
    private function insertJob(string $runId, string $actionId, string $hostId, array $overrides): string
    {
        $jobId = Uuid::randomHex();
        $created = $this->storage($this->now->modify('-40 days'));
        $phaseDeadline = $this->now->setTime(
            (int) $this->now->format('H'),
            (int) $this->now->format('i'),
            (int) $this->now->format('s')
        )->modify('+10 minutes');
        $this->connection->insert('muwa_agent_job', array_merge([
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
            'target_agent_id' => Uuid::fromHexToBytes($hostId),
            'lease_version' => 0,
            'attempt' => 0,
            'max_attempts' => 3,
            'available_at' => $created,
            'phase_deadline' => $this->storage($phaseDeadline),
            'execution_deadline' => $this->storage($phaseDeadline->modify('-15 seconds')),
            'stop_deadline' => $this->storage($phaseDeadline->modify('-10 seconds')),
            'created_at' => $created,
        ], $overrides));

        return $jobId;
    }

    private function insertNonce(string $hostId, string $nonce, \DateTimeImmutable $expiresAt): void
    {
        $this->connection->insert('muwa_agent_nonce', [
            'id' => Uuid::randomBytes(),
            'agent_id' => Uuid::fromHexToBytes($hostId),
            'nonce' => $nonce,
            'expires_at' => $this->storage($expiresAt),
            'created_at' => $this->storage($this->now->modify('-2 minutes')),
        ]);
    }

    private function insertRun(): string
    {
        $runId = Uuid::randomHex();
        $this->connection->insert('muwa_agent_backup_run', [
            'id' => Uuid::fromHexToBytes($runId),
            'facility_repository_id' => Uuid::randomBytes(),
            'source' => 'facility',
            'idempotency_key' => Uuid::randomHex(),
            'status' => 'running',
            'started_at' => $this->storage($this->now->modify('-1 minute')),
            'created_at' => $this->storage($this->now->modify('-1 minute')),
        ]);

        return $runId;
    }

    private function insertAction(): string
    {
        $actionId = Uuid::randomHex();
        $this->connection->insert('muwa_agent_action', [
            'id' => Uuid::fromHexToBytes($actionId),
            'technical_name' => Uuid::randomHex(),
            'label' => 'Cleanup action',
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
            'name' => 'cleanup-host',
            'enabled' => 1,
            'key_id' => 'cleanup-' . $id,
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
            'DROP TABLE IF EXISTS `muwa_agent_nonce`, `muwa_agent_job`, `muwa_agent_backup_run`, '
            . '`muwa_agent_repository_hook`, `muwa_agent_action`, `muwa_agent_host`'
        );
    }
}
