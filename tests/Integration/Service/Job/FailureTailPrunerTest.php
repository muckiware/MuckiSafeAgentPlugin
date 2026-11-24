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
use MuckiSafeAgentPlugin\Service\Job\FailureTailPruner;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Test\TestCaseBase\KernelTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Task 14 RED: failure tails are nulled 30 days after `finished_at` in
 * bounded batches; result code, redacted summary, the completion audit and
 * younger tails stay untouched.
 */
final class FailureTailPrunerTest extends TestCase
{
    use KernelTestBehaviour;

    private Connection $connection;
    private \DateTimeImmutable $now;
    private string $runId;
    private string $actionId;
    private string $hostId;

    protected function setUp(): void
    {
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->dropSchema();
        (new Migration1795132800CreateAgentSchema())->update($connection);
        $this->now = new \DateTimeImmutable('2030-02-01 00:00:00.000', new \DateTimeZone('UTC'));
        $this->seedParents();
    }

    protected function tearDown(): void
    {
        $this->dropSchema();
    }

    public function testTailsOlderThanThirtyDaysAreNulledWhileResultCodeAndSummaryRemain(): void
    {
        $jobId = $this->insertFinishedJob('failed', $this->now->modify('-30 days -1 millisecond'));

        $pruned = $this->pruner()->prune();

        self::assertSame(1, $pruned);
        $row = $this->row($jobId);
        self::assertNull($row['stdout_tail']);
        self::assertNull($row['stderr_tail']);
        self::assertSame('step_failed', $row['result_code']);
        self::assertSame('redacted summary', $row['result_summary']);
        self::assertSame(hash('sha256', 'fingerprint', true), $row['completion_fingerprint']);
        self::assertSame('failed', $row['status']);
    }

    public function testTailsExactlyThirtyDaysOldAreKept(): void
    {
        $jobId = $this->insertFinishedJob('timed_out', $this->now->modify('-30 days'));

        self::assertSame(0, $this->pruner()->prune());
        self::assertSame('stdout', $this->row($jobId)['stdout_tail']);
        self::assertSame('stderr', $this->row($jobId)['stderr_tail']);
    }

    public function testPrunerDrainsAllOldTailsInBoundedBatches(): void
    {
        $jobIds = [];
        for ($i = 0; $i < 5; ++$i) {
            $jobIds[] = $this->insertFinishedJob('failed', $this->now->modify('-40 days'));
        }

        self::assertSame(5, (new FailureTailPruner($this->connection, fn (): string => $this->storage($this->now), 2))->prune());
        foreach ($jobIds as $jobId) {
            self::assertNull($this->row($jobId)['stdout_tail']);
        }
    }

    public function testPrunerStopsAfterTheMaximumNumberOfBatches(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            $this->insertFinishedJob('failed', $this->now->modify('-40 days'));
        }

        self::assertSame(2, (new FailureTailPruner($this->connection, fn (): string => $this->storage($this->now), 2, 1))->prune());
    }

    private function pruner(): FailureTailPruner
    {
        return new FailureTailPruner($this->connection, fn (): \DateTimeImmutable => $this->now);
    }

    /** @return array<string, mixed> */
    private function row(string $jobId): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM `muwa_agent_job` WHERE `id` = UNHEX(:id)',
            ['id' => $jobId]
        );
        self::assertIsArray($row);

        return $row;
    }

    private function insertFinishedJob(string $status, \DateTimeImmutable $finishedAt): string
    {
        $jobId = Uuid::randomHex();
        $created = $this->storage($finishedAt->modify('-10 minutes'));
        $phaseDeadline = $finishedAt->setTime(
            (int) $finishedAt->format('H'),
            (int) $finishedAt->format('i'),
            (int) $finishedAt->format('s')
        )->modify('+5 minutes');
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
            'target_agent_id' => Uuid::fromHexToBytes($this->hostId),
            'lease_version' => 2,
            'attempt' => 1,
            'max_attempts' => 1,
            'available_at' => $created,
            'phase_deadline' => $this->storage($phaseDeadline),
            'execution_deadline' => $this->storage($phaseDeadline->modify('-15 seconds')),
            'stop_deadline' => $this->storage($phaseDeadline->modify('-10 seconds')),
            'finished_at' => $this->storage($finishedAt),
            'completion_fingerprint' => hash('sha256', 'fingerprint', true),
            'completion_agent_id' => Uuid::fromHexToBytes($this->hostId),
            'previous_claim_token_hash' => hash('sha256', 'token', true),
            'accepted_lease_version' => 1,
            'result_code' => 'step_failed',
            'result_summary' => 'redacted summary',
            'stdout_tail' => 'stdout',
            'stderr_tail' => 'stderr',
            'created_at' => $created,
        ]);

        return $jobId;
    }

    private function seedParents(): void
    {
        $created = $this->storage($this->now->modify('-60 days'));
        $this->runId = Uuid::randomHex();
        $this->actionId = Uuid::randomHex();
        $this->hostId = Uuid::randomHex();
        $this->connection->insert('muwa_agent_backup_run', [
            'id' => Uuid::fromHexToBytes($this->runId),
            'facility_repository_id' => Uuid::randomBytes(),
            'source' => 'facility',
            'idempotency_key' => Uuid::randomHex(),
            'status' => 'failed',
            'started_at' => $created,
            'created_at' => $created,
        ]);
        $this->connection->insert('muwa_agent_action', [
            'id' => Uuid::fromHexToBytes($this->actionId),
            'technical_name' => Uuid::randomHex(),
            'label' => 'Pruner action',
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
        $this->connection->insert('muwa_agent_host', [
            'id' => Uuid::fromHexToBytes($this->hostId),
            'name' => 'pruner-host',
            'enabled' => 1,
            'key_id' => 'pruner-' . $this->hostId,
            'secret_ciphertext' => random_bytes(32),
            'secret_nonce' => random_bytes(24),
            'secret_key_version' => 1,
            'capabilities' => '[]',
            'created_at' => $created,
        ]);
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
