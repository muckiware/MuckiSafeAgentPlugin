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
use MuckiSafeAgentPlugin\Service\Action\ActionDefinitionDecoder;
use MuckiSafeAgentPlugin\Service\Action\ActionValidator;
use MuckiSafeAgentPlugin\Service\Job\JobClaimer;
use MuckiSafeAgentPlugin\Service\Job\JobCreator;
use MuckiSafeAgentPlugin\Service\Protocol\ProtocolSchemaValidator;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Test\TestCaseBase\KernelTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;

final class ConcurrentClaimTest extends TestCase
{
    use KernelTestBehaviour;

    private Connection $connection;
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
        $this->runId = $this->insertRun();
        $this->hostId = $this->insertHost('host-a', ['service.control']);
        $this->actionId = $this->insertAction(['service.control']);
    }

    protected function tearDown(): void
    {
        $this->dropSchema();
    }

    public function testOneHundredClaimsAcrossTwoIndependentConnectionsHaveOneWinnerAndNoSqlErrors(): void
    {
        $this->createJob($this->hostId, 'concurrent');
        $this->createJob($this->hostId, 'concurrent-second-row');
        $results = $this->runClaimChildren($this->hostId, 50);

        self::assertCount(100, $results);
        self::assertSame(1, count(array_filter($results, static fn (string $value): bool => $value === 'claimed')));
        self::assertSame(99, count(array_filter($results, static fn (string $value): bool => $value === 'null')));
        self::assertSame([], array_values(array_filter($results, static fn (string $value): bool => str_starts_with($value, 'error:'))));
    }

    public function testDifferentRowsForSameHostRaceThroughActiveAgentGuard(): void
    {
        $firstJob = $this->createJob($this->hostId, 'guard-race-first');
        $secondJob = $this->createJob($this->hostId, 'guard-race-second');

        $results = $this->runSimultaneousSingleClaims(
            [$this->hostId, $this->hostId],
            [$firstJob, $secondJob]
        );

        self::assertSame(['claimed', 'null'], $results);
        self::assertSame(1, (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM `muwa_agent_job` WHERE `target_agent_id` = UNHEX(:hostId) AND `status` = 'claimed'",
            ['hostId' => $this->hostId]
        ));
    }

    public function testActiveClaimedRunningAndCancelRequestedJobBlocksSecondSameHostJob(): void
    {
        $this->createJob($this->hostId, 'first');
        $this->createJob($this->hostId, 'second');
        $first = $this->claimer()->claim($this->hostId);
        self::assertNotNull($first->job);

        foreach (['claimed', 'running', 'cancel_requested'] as $status) {
            $this->connection->update(
                'muwa_agent_job',
                ['status' => $status],
                ['id' => Uuid::fromHexToBytes(str_replace('-', '', $first->job->id))]
            );
            self::assertNull($this->claimer()->claim($this->hostId)->job, $status);
        }
    }

    public function testTwoHostsSharingOneActionCanClaimConcurrently(): void
    {
        $secondHost = $this->insertHost('host-b', ['service.control']);
        $this->createJob($this->hostId, 'host-a-job');
        $this->createJob($secondHost, 'host-b-job');

        $results = $this->runSimultaneousSingleClaims([$this->hostId, $secondHost]);

        self::assertSame(['claimed', 'claimed'], $results);
    }

    public function testConditionalMutationFailsClosedWhenActionChangesAfterSelection(): void
    {
        $jobId = $this->createJob($this->hostId, 'action-race');
        $claimer = new JobClaimer(
            $this->connection,
            function (): void {
                $this->connection->update(
                    'muwa_agent_action',
                    ['enabled' => 0],
                    ['id' => Uuid::fromHexToBytes($this->actionId)]
                );
            }
        );

        self::assertNull($claimer->claim($this->hostId)->job);
        $row = $this->connection->fetchAssociative(
            'SELECT `status`, `claim_token_hash`, `attempt`, `lease_version` FROM `muwa_agent_job` WHERE `id` = UNHEX(:id)',
            ['id' => $jobId]
        );
        self::assertIsArray($row);
        self::assertSame('queued', $row['status']);
        self::assertNull($row['claim_token_hash']);
        self::assertSame(0, (int) $row['attempt']);
        self::assertSame(0, (int) $row['lease_version']);
    }

    public function testWrongTargetAndCapabilityRemovedAfterCreationReceiveNoJob(): void
    {
        $otherHost = $this->insertHost('host-b', ['service.control']);
        $this->createJob($this->hostId, 'targeted');
        self::assertNull($this->claimer()->claim($otherHost)->job);

        $this->connection->update('muwa_agent_host', ['capabilities' => '[]'], ['id' => Uuid::fromHexToBytes($this->hostId)]);
        self::assertNull($this->claimer()->claim($this->hostId)->job);
    }

    public function testNonArrayCapabilitiesFailClosed(): void
    {
        $jobId = $this->createJob($this->hostId, 'snapshot');
        $this->connection->update(
            'muwa_agent_host',
            ['capabilities' => '{"service.control":true}'],
            ['id' => Uuid::fromHexToBytes($this->hostId)]
        );
        self::assertNull($this->claimer()->claim($this->hostId)->job);

        $this->connection->update(
            'muwa_agent_host',
            ['capabilities' => '["service.control"]'],
            ['id' => Uuid::fromHexToBytes($this->hostId)]
        );
        $this->connection->update(
            'muwa_agent_action',
            ['required_capabilities' => '{"service.control":true}', 'idempotency_class' => 'non_retryable'],
            ['id' => Uuid::fromHexToBytes($this->actionId)]
        );
        self::assertNull($this->claimer()->claim($this->hostId)->job);

    }

    public function testIdempotencyClassIsSnapshotted(): void
    {
        $jobId = $this->createJob($this->hostId, 'snapshot');
        $snapshot = $this->connection->fetchAssociative(
            'SELECT `action_definition`, `action_hash` FROM `muwa_agent_job` WHERE `id` = UNHEX(:id)',
            ['id' => $jobId]
        );
        self::assertIsArray($snapshot);
        $replacement = '{"version":1,"steps":[{"primitive":"service.control","serviceAlias":"backup","operation":"start"}]}';
        $replacementCanonical = (new ActionValidator())->canonicalize((new ActionDefinitionDecoder())->decode($replacement));
        $this->connection->update(
            'muwa_agent_action',
            [
                'definition' => $replacementCanonical,
                'definition_hash' => hash('sha256', $replacementCanonical, true),
                'idempotency_class' => 'non_retryable',
            ],
            ['id' => Uuid::fromHexToBytes($this->actionId)]
        );
        $claim = $this->claimer()->claim($this->hostId)->job;
        self::assertNotNull($claim);
        self::assertSame($this->wireUuid($jobId), $claim->id);
        self::assertSame('safe', $claim->idempotencyClass);
        self::assertSame($snapshot['action_definition'], $claim->definitionCanonical);
        self::assertSame(bin2hex((string) $snapshot['action_hash']), $claim->actionHash);
    }

    public function testStoredParameterObjectIsReturnedWithoutFabrication(): void
    {
        $jobId = $this->insertJobSnapshot('{"mode":"verify","retries":2}');

        $response = $this->claimer()->claim($this->hostId);
        $claim = $response->job;

        self::assertNotNull($claim);
        self::assertSame($this->wireUuid($jobId), $claim->id);
        self::assertSame(['mode' => 'verify', 'retries' => 2], get_object_vars($claim->parameters));
        $schema = json_decode(
            (string) file_get_contents(dirname(__DIR__, 4) . '/resources/schema/protocol/v1/claim-response.schema.json'),
            false,
            32,
            JSON_THROW_ON_ERROR
        );
        $wire = json_decode(json_encode($response->toArray(), JSON_THROW_ON_ERROR), false, 32, JSON_THROW_ON_ERROR);
        self::assertInstanceOf(\stdClass::class, $schema);
        self::assertInstanceOf(\stdClass::class, $wire);
        self::assertNull((new ProtocolSchemaValidator())->firstViolation($schema, $wire));
    }

    public function testFutureUnavailableDeadlineAndAttemptLimitedJobsAreNotClaimed(): void
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $futureId = $this->createJob($this->hostId, 'future', $now->modify('+2 minutes'), $now->modify('+10 minutes'));
        self::assertNull($this->claimer()->claim($this->hostId)->job);
        $this->connection->delete('muwa_agent_job', ['id' => Uuid::fromHexToBytes($futureId)]);

        $attemptId = $this->createJob($this->hostId, 'attempt');
        $this->connection->update('muwa_agent_job', ['attempt' => 1, 'max_attempts' => 1], ['id' => Uuid::fromHexToBytes($attemptId)]);
        self::assertNull($this->claimer()->claim($this->hostId)->job);
        $this->connection->delete('muwa_agent_job', ['id' => Uuid::fromHexToBytes($attemptId)]);

        $this->createJob($this->hostId, 'expired', $now->modify('-2 minutes'), $now->modify('+16 seconds'));
        sleep(2);
        self::assertNull($this->claimer()->claim($this->hostId)->job);
    }

    public function testClaimPersistsOnlyTokenHashAndMutatesLeaseFromOneServerTime(): void
    {
        $jobId = $this->createJob($this->hostId, 'lease');
        $response = $this->claimer()->claim($this->hostId);
        self::assertNotNull($response->job);
        self::assertSame($this->wireUuid($jobId), $response->job->id);
        self::assertSame(1, preg_match('/^[A-Za-z0-9_-]{43}$/D', $response->job->claimToken));
        self::assertSame(1, $response->job->leaseVersion);

        $row = $this->connection->fetchAssociative('SELECT * FROM `muwa_agent_job` WHERE `id` = UNHEX(:id)', ['id' => $jobId]);
        self::assertIsArray($row);
        self::assertSame('claimed', $row['status']);
        self::assertSame(1, (int) $row['attempt']);
        self::assertSame(1, (int) $row['lease_version']);
        self::assertTrue(hash_equals(hash('sha256', $response->job->claimToken, true), (string) $row['claim_token_hash']));
        self::assertFalse(hash_equals($response->job->claimToken, (string) $row['claim_token_hash']));
        self::assertSame($row['claimed_at'], $row['updated_at']);
        self::assertNotNull($row['lease_expires_at']);
    }

    public function testLeaseIsExactlyThirtySecondsAndCappedAtExecutionDeadline(): void
    {
        $longJob = $this->createJob($this->hostId, 'long-lease');
        self::assertNotNull($this->claimer()->claim($this->hostId)->job);
        $longRow = $this->connection->fetchAssociative(
            'SELECT `claimed_at`, `lease_expires_at` FROM `muwa_agent_job` WHERE `id` = UNHEX(:id)',
            ['id' => $longJob]
        );
        self::assertIsArray($longRow);
        self::assertSame(
            (new \DateTimeImmutable((string) $longRow['claimed_at'], new \DateTimeZone('UTC')))
                ->modify('+30 seconds')->format(Defaults::STORAGE_DATE_TIME_FORMAT),
            $longRow['lease_expires_at']
        );

        $this->connection->update('muwa_agent_job', ['status' => 'succeeded'], ['id' => Uuid::fromHexToBytes($longJob)]);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $shortJob = $this->createJob(
            $this->hostId,
            'short-lease',
            $now->modify('-1 second'),
            $now->modify('+44 seconds')
        );
        self::assertNotNull($this->claimer()->claim($this->hostId)->job);
        $shortRow = $this->connection->fetchAssociative(
            'SELECT `execution_deadline`, `lease_expires_at` FROM `muwa_agent_job` WHERE `id` = UNHEX(:id)',
            ['id' => $shortJob]
        );
        self::assertIsArray($shortRow);
        self::assertSame($shortRow['execution_deadline'], $shortRow['lease_expires_at']);
    }

    private function claimer(?Connection $connection = null): JobClaimer
    {
        return new JobClaimer($connection ?? $this->connection);
    }

    private function createJob(
        string $hostId,
        string $key,
        ?\DateTimeImmutable $availableAt = null,
        ?\DateTimeImmutable $phaseDeadline = null
    ): string {
        $creator = self::getContainer()->get(JobCreator::class);
        self::assertInstanceOf(JobCreator::class, $creator);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return $creator->create(
            $this->runId,
            $this->actionId,
            Uuid::randomHex(),
            $hostId,
            'pre',
            $availableAt ?? $now->modify('-1 second'),
            $phaseDeadline ?? $now->modify('+10 minutes'),
            3,
            $key
        );
    }

    private function insertJobSnapshot(string $payload): string
    {
        $jobId = Uuid::randomHex();
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $phaseDeadline = $now->modify('+10 minutes');
        $action = $this->connection->fetchAssociative(
            'SELECT `definition`, `definition_hash`, `idempotency_class` FROM `muwa_agent_action` WHERE `id` = UNHEX(:id)',
            ['id' => $this->actionId]
        );
        self::assertIsArray($action);
        $this->connection->insert('muwa_agent_job', [
            'id' => Uuid::fromHexToBytes($jobId),
            'backup_run_id' => Uuid::fromHexToBytes($this->runId),
            'agent_action_id' => Uuid::fromHexToBytes($this->actionId),
            'facility_repository_id' => Uuid::randomBytes(),
            'phase' => 'pre',
            'action_definition' => $action['definition'],
            'action_hash' => $action['definition_hash'],
            'payload' => $payload,
            'idempotency_class' => $action['idempotency_class'],
            'idempotency_key' => Uuid::randomHex(),
            'status' => 'queued',
            'target_agent_id' => Uuid::fromHexToBytes($this->hostId),
            'lease_version' => 0,
            'attempt' => 0,
            'max_attempts' => 3,
            'available_at' => $now->modify('-1 second')->format(Defaults::STORAGE_DATE_TIME_FORMAT),
            'phase_deadline' => $phaseDeadline->format(Defaults::STORAGE_DATE_TIME_FORMAT),
            'execution_deadline' => $phaseDeadline->modify('-15 seconds')->format(Defaults::STORAGE_DATE_TIME_FORMAT),
            'stop_deadline' => $phaseDeadline->modify('-10 seconds')->format(Defaults::STORAGE_DATE_TIME_FORMAT),
            'created_at' => $now->format(Defaults::STORAGE_DATE_TIME_FORMAT),
        ]);

        return $jobId;
    }

    /** @param list<string> $hostIds
     *  @param list<string>|null $candidateJobIds
     *  @return list<string>
     */
    private function runSimultaneousSingleClaims(array $hostIds, ?array $candidateJobIds = null): array
    {
        $children = [];
        $results = [];
        try {
            foreach ($hostIds as $index => $hostId) {
                $candidateJobId = $candidateJobIds[$index] ?? null;
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
                            throw new \RuntimeException('Claim start barrier timed out.');
                        }
                        $claim = (new JobClaimer(
                            $connection,
                            static function () use ($pair): void {
                                fwrite($pair[1], "locked\n");
                                fflush($pair[1]);
                                if (trim((string) fgets($pair[1])) !== 'continue') {
                                    throw new \RuntimeException('Claim lock barrier timed out.');
                                }
                            },
                            $candidateJobId
                        ))->claim($hostId);
                        fwrite($pair[1], ($claim->job === null ? 'null' : 'claimed') . "\n");
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
                self::assertSame('locked', trim((string) fgets($child['socket'])));
            }
            foreach ($children as $child) {
                fwrite($child['socket'], "continue\n");
                fflush($child['socket']);
            }
            foreach ($children as $child) {
                $results[] = trim((string) fgets($child['socket']));
                self::assertFalse(stream_get_meta_data($child['socket'])['timed_out'], 'Claim child timed out.');
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

    /** @return list<string> */
    private function runClaimChildren(string $hostId, int $attemptsPerChild): array
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
                            throw new \RuntimeException('Claim barrier timed out.');
                        }
                        for ($attempt = 0; $attempt < $attemptsPerChild; ++$attempt) {
                            $claim = $this->claimer($connection)->claim($hostId);
                            fwrite($pair[1], ($claim->job === null ? 'null' : 'claimed') . "\n");
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
                while (($line = fgets($child['socket'])) !== false) {
                    $results[] = trim($line);
                }
                self::assertFalse(stream_get_meta_data($child['socket'])['timed_out'], 'Claim child timed out.');
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

        return $results;
    }

    private function insertRun(): string
    {
        $id = Uuid::randomHex();
        $now = (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT);
        $this->connection->insert('muwa_agent_backup_run', [
            'id' => Uuid::fromHexToBytes($id),
            'facility_repository_id' => Uuid::randomBytes(),
            'source' => 'facility',
            'idempotency_key' => Uuid::randomHex(),
            'status' => 'running',
            'started_at' => $now,
            'created_at' => $now,
        ]);
        return $id;
    }

    /** @param list<string> $capabilities */
    private function insertHost(string $name, array $capabilities): string
    {
        $id = Uuid::randomHex();
        $this->connection->insert('muwa_agent_host', [
            'id' => Uuid::fromHexToBytes($id),
            'name' => $name,
            'enabled' => 1,
            'key_id' => Uuid::randomHex(),
            'secret_ciphertext' => random_bytes(32),
            'secret_nonce' => random_bytes(24),
            'secret_key_version' => 1,
            'capabilities' => json_encode($capabilities, JSON_THROW_ON_ERROR),
            'created_at' => (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
        ]);
        return $id;
    }

    /** @param list<string> $required */
    private function insertAction(array $required): string
    {
        $id = Uuid::randomHex();
        $raw = '{"version":1,"steps":[{"primitive":"service.control","serviceAlias":"backup","operation":"stop"}]}';
        $definition = (new ActionDefinitionDecoder())->decode($raw);
        $canonical = (new ActionValidator())->canonicalize($definition);
        $this->connection->insert('muwa_agent_action', [
            'id' => Uuid::fromHexToBytes($id),
            'technical_name' => Uuid::randomHex(),
            'label' => 'Test action',
            'enabled' => 1,
            'definition' => $canonical,
            'definition_hash' => hash('sha256', $canonical, true),
            'required_capabilities' => json_encode($required, JSON_THROW_ON_ERROR),
            'default_timeout_seconds' => 60,
            'max_timeout_seconds' => 600,
            'retry_policy' => 'none',
            'idempotency_class' => 'safe',
            'created_at' => (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
        ]);
        return $id;
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
