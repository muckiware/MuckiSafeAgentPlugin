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
use MuckiSafeAgentPlugin\Api\JobCompleteRequest;
use MuckiSafeAgentPlugin\Service\Action\CanonicalJson;
use MuckiSafeAgentPlugin\Service\Job\ClaimConflict;
use MuckiSafeAgentPlugin\Service\Job\JobCompletion;
use MuckiSafeAgentPlugin\Service\Job\JobTransitionGuard;
use MuckiSafeAgentPlugin\Service\Job\OutputTailNormalizer;
use MuckiSafeAgentPlugin\Migration\Migration1795132800CreateAgentSchema;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Test\TestCaseBase\KernelTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Task 13 RED: two concurrent completions over independent DBAL connections
 * race on the same locked job row. Identical content must yield the same
 * terminal status for both workers (one commit, one idempotent
 * acknowledgement); contradicting content must yield exactly one accepted
 * completion and one 409-grade conflict.
 */
final class JobCompletionConcurrencyTest extends TestCase
{
    use KernelTestBehaviour;

    private const TOKEN = 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';
    private const VERSION = 7;

    private Connection $connection;
    private string $hostId;
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
        $this->hostId = $this->insertHost();
        $this->jobId = $this->insertRunningJob();
    }

    protected function tearDown(): void
    {
        $this->dropSchema();
    }

    public function testConcurrentIdenticalCompletionsReturnTheSameTerminalStatusWithExactlyOneWrite(): void
    {
        $results = $this->runConcurrentCompletions('Step 2 failed.', 'Step 2 failed.');

        self::assertSame(['ok:failed', 'ok:failed'], $results);
        $row = $this->row();
        self::assertSame('failed', $row['status']);
        self::assertSame('Step 2 failed.', $row['result_summary']);
        self::assertSame(self::VERSION + 1, (int) $row['lease_version']);
        self::assertNotNull($row['finished_at']);
        self::assertSame($this->expectedFingerprint('Step 2 failed.'), (string) $row['completion_fingerprint']);
    }

    public function testConcurrentContradictingCompletionsYieldExactlyOneAcceptedCompletion(): void
    {
        $results = $this->runConcurrentCompletions('Winner summary.', 'Loser summary.');
        self::assertSame(['conflict', 'ok:failed'], $results);

        $row = $this->row();
        $summary = (string) $row['result_summary'];
        self::assertContains($summary, ['Winner summary.', 'Loser summary.']);
        self::assertSame($this->expectedFingerprint($summary), (string) $row['completion_fingerprint']);
        self::assertSame(self::VERSION + 1, (int) $row['lease_version']);
    }

    /** @return list<string> */
    private function runConcurrentCompletions(string $firstSummary, string $secondSummary): array
    {
        $summaries = [$firstSummary, $secondSummary];
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
                            throw new \RuntimeException('Completion barrier timed out.');
                        }
                        $completion = new JobCompletion(
                            $connection,
                            new OutputTailNormalizer(),
                            new JobTransitionGuard($connection, fn (): \DateTimeImmutable => $this->now),
                            fn (): \DateTimeImmutable => $this->now
                        );
                        $request = new JobCompleteRequest(
                            self::TOKEN,
                            self::VERSION,
                            'failed',
                            'step_failed',
                            $summaries[$index],
                            'out',
                            'err',
                            'utf-8',
                            'utf-8'
                        );
                        try {
                            $response = $completion->complete($this->hostId, $this->wireUuid($this->jobId), $request);
                            fwrite($pair[1], 'ok:' . $response->terminalStatus . "\n");
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
                self::assertFalse(stream_get_meta_data($child['socket'])['timed_out'], 'Completion child timed out.');
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

    private function expectedFingerprint(string $summary): string
    {
        $normalized = (new OutputTailNormalizer())->normalize(new JobCompleteRequest(
            self::TOKEN,
            self::VERSION,
            'failed',
            'step_failed',
            $summary,
            'out',
            'err',
            'utf-8',
            'utf-8'
        ));

        return hex2bin(CanonicalJson::hash((object) [
            'outcome' => $normalized->outcome,
            'resultCode' => $normalized->resultCode,
            'summary' => $normalized->summary,
            'stdoutEncoding' => $normalized->stdoutEncoding,
            'stdoutTail' => $normalized->stdoutTail,
            'stderrEncoding' => $normalized->stderrEncoding,
            'stderrTail' => $normalized->stderrTail,
        ]));
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

    private function insertHost(): string
    {
        $id = Uuid::randomHex();
        $this->connection->insert('muwa_agent_host', [
            'id' => Uuid::fromHexToBytes($id),
            'name' => 'completion-race-host',
            'enabled' => 1,
            'key_id' => 'completion-' . $id,
            'secret_ciphertext' => random_bytes(32),
            'secret_nonce' => random_bytes(24),
            'secret_key_version' => 1,
            'capabilities' => '[]',
            'created_at' => $this->storage($this->now->modify('-1 day')),
        ]);

        return $id;
    }

    private function insertRunningJob(): string
    {
        $runId = Uuid::randomHex();
        $actionId = Uuid::randomHex();
        $jobId = Uuid::randomHex();
        $created = $this->storage($this->now->modify('-1 minute'));
        $definition = '{"version":1,"steps":[]}';
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
            'label' => 'Completion race action',
            'enabled' => 1,
            'definition' => $definition,
            'definition_hash' => hash('sha256', $definition, true),
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
            'action_definition' => $definition,
            'action_hash' => hash('sha256', $definition, true),
            'payload' => '{}',
            'idempotency_class' => 'safe',
            'idempotency_key' => Uuid::randomHex(),
            'status' => 'running',
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
            'heartbeat_at' => $created,
            'created_at' => $created,
        ]);

        return $jobId;
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
