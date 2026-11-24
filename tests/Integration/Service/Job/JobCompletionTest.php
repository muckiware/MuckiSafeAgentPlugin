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
use MuckiSafeAgentPlugin\Api\JobCompleteRequest;
use MuckiSafeAgentPlugin\Service\Action\CanonicalJson;
use MuckiSafeAgentPlugin\Service\Auth\AgentErrorCode;
use MuckiSafeAgentPlugin\Service\Job\ClaimConflict;
use MuckiSafeAgentPlugin\Service\Job\JobCompletePayloadException;
use MuckiSafeAgentPlugin\Service\Job\JobCompletion;
use MuckiSafeAgentPlugin\Service\Job\JobTransitionGuard;
use MuckiSafeAgentPlugin\Service\Job\OutputTailNormalizer;
use MuckiSafeAgentPlugin\Migration\Migration1795132800CreateAgentSchema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Test\TestCaseBase\KernelTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Task 13 RED: transactional, idempotent completion. The terminal transition,
 * the canonical completion fingerprint and the immutable audit fields
 * (bound agent, previous claim token hash, accepted lease version) are
 * written in one transaction; only an identical authenticated retry receives
 * the stored terminal status again, every contradicting repetition gets a
 * 409-grade ClaimConflict.
 */
final class JobCompletionTest extends TestCase
{
    use KernelTestBehaviour;

    private const TOKEN = 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';
    private const VERSION = 7;

    private Connection $connection;
    private string $hostId;
    private string $otherHostId;
    private string $jobId;
    private string $runId = '';
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->dropSchema();
        (new Migration1795132800CreateAgentSchema())->update($connection);
        $this->now = new \DateTimeImmutable('2030-01-02 03:04:05.000', new \DateTimeZone('UTC'));
        $this->hostId = $this->insertHost('completion-host');
        $this->otherHostId = $this->insertHost('other-host');
        $this->jobId = $this->insertLeasedJob('running');
    }

    protected function tearDown(): void
    {
        $this->dropSchema();
    }

    #[DataProvider('outcomeMatrix')]
    public function testOutcomeMatrixPersistsTerminalStatusDiagnosticsAndAuditInOneTransaction(
        string $outcome,
        string $resultCode,
        string $summary,
        ?string $stdoutTail,
        ?string $stdoutEncoding,
        ?string $stderrTail,
        ?string $stderrEncoding,
        string $terminalStatus,
        ?string $storedStdout,
        ?string $storedStderr
    ): void {
        $response = $this->completion()->complete(
            $this->hostId,
            $this->wireUuid($this->jobId),
            $this->request($outcome, $resultCode, $summary, $stdoutTail, $stdoutEncoding, $stderrTail, $stderrEncoding)
        );

        self::assertSame($terminalStatus, $response->terminalStatus);
        self::assertSame(['accepted' => true, 'terminalStatus' => $terminalStatus], $response->toArray());

        $row = $this->row();
        self::assertSame($terminalStatus, $row['status']);
        self::assertSame($resultCode, $row['result_code']);
        self::assertSame($summary, $row['result_summary']);
        self::assertSame($storedStdout, $row['stdout_tail']);
        self::assertSame($storedStderr, $row['stderr_tail']);
        self::assertSame($this->storage($this->now), $row['finished_at']);
        self::assertSame(Uuid::fromHexToBytes($this->hostId), $row['completion_agent_id']);
        self::assertSame(hash('sha256', self::TOKEN, true), $row['previous_claim_token_hash']);
        self::assertNull($row['claim_token_hash']);
        self::assertSame(self::VERSION, (int) $row['accepted_lease_version']);
        self::assertSame(self::VERSION + 1, (int) $row['lease_version']);
        self::assertSame(
            $this->fingerprint($outcome, $resultCode, $summary, $stdoutTail, $stdoutEncoding, $stderrTail, $stderrEncoding),
            (string) $row['completion_fingerprint']
        );
    }

    /** @return iterable<string, array{string, string, string, ?string, ?string, ?string, ?string, string, ?string, ?string}> */
    public static function outcomeMatrix(): iterable
    {
        yield 'succeeded ok without tails' => [
            'succeeded', 'ok', 'Done.', null, null, null, null, 'succeeded', null, null,
        ];
        yield 'failed step_failed with both tails' => [
            'failed', 'step_failed', 'Step 2 failed.', 'out', 'utf-8', base64_encode('err'), 'base64',
            'failed', 'out', 'err',
        ];
        yield 'timed_out deadline with stdout tail' => [
            'timed_out', 'deadline', 'Deadline hit.', 'late', 'utf-8', null, null, 'timed_out', 'late', null,
        ];
        yield 'ambiguous journal_started without tails' => [
            'ambiguous', 'journal_started', 'Journal exists.', null, null, null, null, 'unknown', null, null,
        ];
    }

    public function testIdenticalRetryAfterLostResponseReturnsStoredStatusWithoutRewritingTheRow(): void
    {
        $first = $this->completion()->complete($this->hostId, $this->wireUuid($this->jobId), $this->failedRequest());
        self::assertSame('failed', $first->terminalStatus);
        $rowAfterFirst = $this->row();

        $retry = $this->completion()->complete($this->hostId, $this->wireUuid($this->jobId), $this->failedRequest());

        self::assertSame('failed', $retry->terminalStatus);
        self::assertSame($rowAfterFirst, $this->row());
    }

    public function testContradictingRetryConflictsWithoutMutation(): void
    {
        $this->completion()->complete($this->hostId, $this->wireUuid($this->jobId), $this->failedRequest());
        $rowAfterFirst = $this->row();

        $contradiction = $this->request(
            'failed',
            'step_failed',
            'Different summary.',
            'out',
            'utf-8',
            null,
            null
        );

        $this->expectException(ClaimConflict::class);
        try {
            $this->completion()->complete($this->hostId, $this->wireUuid($this->jobId), $contradiction);
        } catch (ClaimConflict $exception) {
            self::assertSame($rowAfterFirst, $this->row());
            throw $exception;
        }
    }

    #[DataProvider('fenceMismatches')]
    public function testFenceMismatchesConflictWithoutMutation(string $token, int $version, bool $otherHost, bool $unknownJob): void
    {
        $rowBefore = $this->row();
        $hostId = $otherHost ? $this->otherHostId : $this->hostId;
        $jobId = $unknownJob ? '11111111-2222-3333-4444-555555555555' : $this->wireUuid($this->jobId);

        try {
            $this->completion()->complete($hostId, $jobId, new JobCompleteRequest($token, $version, 'failed', 'step_failed', 'x', null, null, null, null));
            self::fail('A mismatching fence must conflict.');
        } catch (ClaimConflict) {
            self::addToAssertionCount(1);
        }

        self::assertSame($rowBefore, $this->row());
    }

    /** @return iterable<string, array{string, int, bool, bool}> */
    public static function fenceMismatches(): iterable
    {
        yield 'wrong token' => [str_repeat('B', 43), self::VERSION, false, false];
        yield 'stale lease version' => [self::TOKEN, self::VERSION - 1, false, false];
        yield 'future lease version' => [self::TOKEN, self::VERSION + 1, false, false];
        yield 'wrong host' => [self::TOKEN, self::VERSION, true, false];
        yield 'unknown job' => [self::TOKEN, self::VERSION, false, true];
    }

    public function testCompletionFromNeverStartedClaimedJobConflicts(): void
    {
        $this->connection->update('muwa_agent_job', ['status' => 'claimed'], ['id' => Uuid::fromHexToBytes($this->jobId)]);

        $this->expectException(ClaimConflict::class);
        $this->completion()->complete($this->hostId, $this->wireUuid($this->jobId), $this->failedRequest());
    }

    public function testRunningCompletionWithExpiredLeaseConflicts(): void
    {
        $this->connection->update('muwa_agent_job', [
            'lease_expires_at' => $this->storage($this->now->modify('-1 second')),
        ], ['id' => Uuid::fromHexToBytes($this->jobId)]);

        $this->expectException(ClaimConflict::class);
        $this->completion()->complete($this->hostId, $this->wireUuid($this->jobId), $this->failedRequest());
    }

    #[DataProvider('unavailableHosts')]
    public function testDisabledOrRevokedHostConflictsOnCompletion(string $column, mixed $value): void
    {
        $this->connection->update('muwa_agent_host', [$column => $value], ['id' => Uuid::fromHexToBytes($this->hostId)]);

        $this->expectException(ClaimConflict::class);
        $this->completion()->complete($this->hostId, $this->wireUuid($this->jobId), $this->failedRequest());
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function unavailableHosts(): iterable
    {
        yield 'disabled host' => ['enabled', 0];
        yield 'revoked host' => ['revoked_at', '2030-01-02 03:04:05.000'];
    }

    public function testSucceededOrAmbiguousWithTailsIsRejectedAsPayloadErrorBeforeAnyMutation(): void
    {
        $rowBefore = $this->row();
        foreach (['succeeded' => 'ok', 'ambiguous' => 'journal_started'] as $outcome => $resultCode) {
            try {
                $this->completion()->complete(
                    $this->hostId,
                    $this->wireUuid($this->jobId),
                    $this->request($outcome, $resultCode, 'x', 'tail', 'utf-8', null, null)
                );
                self::fail($outcome . ' must not accept tails.');
            } catch (JobCompletePayloadException $exception) {
                self::assertSame(AgentErrorCode::PayloadInvalid, $exception->errorCode);
            }
        }

        self::assertSame($rowBefore, $this->row());
    }

    public function testCancellationAcceptsOnlyTimedOutAndAmbiguousCompletionAfterLeaseExpiry(): void
    {
        $timedOutHost = $this->insertHost('cancellation-timed-out-host');
        $timedOutJob = $this->cancellingJob($timedOutHost);
        self::assertSame(
            'timed_out',
            $this->completion()->complete(
                $timedOutHost,
                $this->wireUuid($timedOutJob),
                $this->request('timed_out', 'deadline', 'Killed.', null, null, null, null)
            )->terminalStatus
        );
        self::assertSame('timed_out', $this->jobStatus($timedOutJob));

        $ambiguousHost = $this->insertHost('cancellation-ambiguous-host');
        $ambiguousJob = $this->cancellingJob($ambiguousHost);
        self::assertSame(
            'unknown',
            $this->completion()->complete(
                $ambiguousHost,
                $this->wireUuid($ambiguousJob),
                $this->request('ambiguous', 'journal_started', 'Started once.', null, null, null, null)
            )->terminalStatus
        );

        foreach (['succeeded' => 'ok', 'failed' => 'step_failed'] as $outcome => $resultCode) {
            $conflictHostId = $this->insertHost('conflict-host-' . $outcome);
            $conflictJob = $this->cancellingJob($conflictHostId);
            try {
                $this->completion()->complete(
                    $conflictHostId,
                    $this->wireUuid($conflictJob),
                    $this->request($outcome, $resultCode, 'x', null, null, null, null)
                );
                self::fail('Cancellation must not accept ' . $outcome . '.');
            } catch (ClaimConflict) {
                self::assertSame('cancel_requested', $this->jobStatus($conflictJob));
            }
        }
    }

    public function testCancellationCompletionStaysValidAtTheExactPhaseDeadlineAndConflictsAfterwards(): void
    {
        $phaseDeadline = $this->now->modify('+10 minutes');
        $atDeadlineHost = $this->insertHost('deadline-edge-host');
        $atDeadlineJob = $this->cancellingJob($atDeadlineHost);
        $atDeadline = new JobCompletion(
            $this->connection,
            new OutputTailNormalizer(),
            $this->guard(),
            static fn (): \DateTimeImmutable => $phaseDeadline
        );
        self::assertSame(
            'unknown',
            $atDeadline->complete(
                $atDeadlineHost,
                $this->wireUuid($atDeadlineJob),
                $this->request('ambiguous', 'journal_started', 'At the edge.', null, null, null, null)
            )->terminalStatus
        );

        $pastDeadlineHost = $this->insertHost('deadline-past-host');
        $pastDeadlineJob = $this->cancellingJob($pastDeadlineHost);
        $pastDeadline = new JobCompletion(
            $this->connection,
            new OutputTailNormalizer(),
            $this->guard(),
            static fn (): \DateTimeImmutable => $phaseDeadline->modify('+1 second')
        );

        try {
            $pastDeadline->complete(
                $pastDeadlineHost,
                $this->wireUuid($pastDeadlineJob),
                $this->request('ambiguous', 'journal_started', 'Too late.', null, null, null, null)
            );
            self::fail('Completion past the phase deadline must conflict.');
        } catch (ClaimConflict) {
            self::addToAssertionCount(1);
        }
    }

    public function testCompletionAuditFieldsAreImmutableAtTheDatabaseLevel(): void
    {
        $this->completion()->complete($this->hostId, $this->wireUuid($this->jobId), $this->failedRequest());

        try {
            $this->connection->update('muwa_agent_job', [
                'completion_fingerprint' => random_bytes(32),
            ], ['id' => Uuid::fromHexToBytes($this->jobId)]);
            self::fail('The completion fingerprint must be database-immutable.');
        } catch (\Doctrine\DBAL\Exception $exception) {
            self::assertStringContainsString('Immutable completion field', $exception->getMessage());
        }

        try {
            $this->connection->update('muwa_agent_job', [
                'accepted_lease_version' => self::VERSION + 5,
            ], ['id' => Uuid::fromHexToBytes($this->jobId)]);
            self::fail('The accepted lease version must be database-immutable.');
        } catch (\Doctrine\DBAL\Exception $exception) {
            self::assertStringContainsString('Immutable completion field', $exception->getMessage());
        }
    }

    public function testStoredCompletionCarriesNoClaimSecrets(): void
    {
        $response = $this->completion()->complete($this->hostId, $this->wireUuid($this->jobId), $this->failedRequest());
        $encoded = json_encode($response->toArray(), JSON_THROW_ON_ERROR);
        self::assertIsString($encoded);
        self::assertStringNotContainsString(self::TOKEN, $encoded);

        $row = $this->row();
        $serialized = '';
        foreach ($row as $value) {
            $serialized .= (is_string($value) ? $value : var_export($value, true)) . '|';
        }
        self::assertStringNotContainsString(self::TOKEN, $serialized);
        self::assertSame(hash('sha256', self::TOKEN, true), $row['previous_claim_token_hash']);
        self::assertNull($row['claim_token_hash']);
    }

    private function cancellingJob(string $hostId): string
    {
        $jobId = $this->insertLeasedJob('cancel_requested', $hostId);
        $this->connection->update('muwa_agent_job', [
            'lease_expires_at' => $this->storage($this->now->modify('-1 minute')),
        ], ['id' => Uuid::fromHexToBytes($jobId)]);

        return $jobId;
    }

    private function jobStatus(string $jobId): string
    {
        return (string) $this->connection->fetchOne(
            'SELECT `status` FROM `muwa_agent_job` WHERE `id` = UNHEX(:id)',
            ['id' => $jobId]
        );
    }

    private function failedRequest(): JobCompleteRequest
    {
        return $this->request('failed', 'step_failed', 'Step 2 failed.', 'out', 'utf-8', base64_encode('err'), 'base64');
    }

    private function request(
        string $outcome,
        string $resultCode,
        string $summary,
        ?string $stdoutTail,
        ?string $stdoutEncoding,
        ?string $stderrTail,
        ?string $stderrEncoding
    ): JobCompleteRequest {
        return new JobCompleteRequest(
            self::TOKEN,
            self::VERSION,
            $outcome,
            $resultCode,
            $summary,
            $stdoutTail,
            $stderrTail,
            $stdoutEncoding,
            $stderrEncoding
        );
    }

    private function completion(): JobCompletion
    {
        return new JobCompletion(
            $this->connection,
            new OutputTailNormalizer(),
            $this->guard(),
            fn (): \DateTimeImmutable => $this->now
        );
    }

    private function guard(): JobTransitionGuard
    {
        return new JobTransitionGuard($this->connection, fn (): \DateTimeImmutable => $this->now);
    }

    private function fingerprint(
        string $outcome,
        string $resultCode,
        string $summary,
        ?string $stdoutTail,
        ?string $stdoutEncoding,
        ?string $stderrTail,
        ?string $stderrEncoding
    ): string {
        $normalized = (new OutputTailNormalizer())->normalize(new JobCompleteRequest(
            self::TOKEN,
            self::VERSION,
            $outcome,
            $resultCode,
            $summary,
            $stdoutTail,
            $stderrTail,
            $stdoutEncoding,
            $stderrEncoding
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

    private function insertHost(string $name): string
    {
        $id = Uuid::randomHex();
        $this->connection->insert('muwa_agent_host', [
            'id' => Uuid::fromHexToBytes($id),
            'name' => $name,
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

    private function insertLeasedJob(string $status, ?string $hostId = null): string
    {
        if ($this->runId === '') {
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
            $this->runId = $runId;
        }
        $actionId = Uuid::randomHex();
        $jobId = Uuid::randomHex();
        $created = $this->storage($this->now->modify('-1 minute'));
        $definition = '{"version":1,"steps":[]}';
        $this->connection->insert('muwa_agent_action', [
            'id' => Uuid::fromHexToBytes($actionId),
            'technical_name' => Uuid::randomHex(),
            'label' => 'Completion action',
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
            'backup_run_id' => Uuid::fromHexToBytes($this->runId),
            'agent_action_id' => Uuid::fromHexToBytes($actionId),
            'facility_repository_id' => Uuid::randomBytes(),
            'phase' => 'pre',
            'action_definition' => $definition,
            'action_hash' => hash('sha256', $definition, true),
            'payload' => '{}',
            'idempotency_class' => 'safe',
            'idempotency_key' => Uuid::randomHex(),
            'status' => $status,
            'target_agent_id' => Uuid::fromHexToBytes($hostId ?? $this->hostId),
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
