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
namespace MuckiSafeAgentPlugin\tests\Integration\Migration;

require_once dirname(__DIR__, 3) . '/src/Migration/Migration1795132800CreateAgentSchema.php';
require_once dirname(__DIR__, 3) . '/src/Core/Framework/DataAbstractionLayer/Field/Flag/Immutable.php';
foreach (glob(dirname(__DIR__, 3) . '/src/Core/Content/*/*Definition.php') ?: [] as $definitionFile) {
    require_once $definitionFile;
}
foreach (glob(dirname(__DIR__, 3) . '/src/Core/{Job,Run}/*.php', GLOB_BRACE) ?: [] as $enumFile) {
    require_once $enumFile;
}

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use MuckiSafeAgentPlugin\Core\Job\IdempotencyClass;
use MuckiSafeAgentPlugin\Core\Job\JobPhase;
use MuckiSafeAgentPlugin\Core\Job\JobStatus;
use MuckiSafeAgentPlugin\Core\Run\RunStatus;
use MuckiSafeAgentPlugin\Core\Framework\DataAbstractionLayer\Field\Flag\Immutable as CompatibilityImmutable;
use MuckiSafeAgentPlugin\Migration\Migration1795132800CreateAgentSchema;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Api\Context\AdminApiSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Field;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Flag;

use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\WriteProtected;
use Shopware\Core\Framework\DataAbstractionLayer\Write\WriteException;
use Shopware\Core\Framework\Test\TestCaseBase\KernelTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;

final class AgentSchemaTest extends TestCase
{
    use KernelTestBehaviour;

    private const TABLES = [
        'muwa_agent_nonce',
        'muwa_agent_job',
        'muwa_agent_backup_run',
        'muwa_agent_repository_hook',
        'muwa_agent_action',
        'muwa_agent_host',
    ];

    private Connection $connection;

    protected function setUp(): void
    {
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->dropSchema();
        (new Migration1795132800CreateAgentSchema())->update($this->connection);
    }

    protected function tearDown(): void
    {
        $this->dropSchema();
    }

    public function testCreatesCompleteSchemaWithoutFacilityForeignKeys(): void
    {
        foreach (self::TABLES as $table) {
            self::assertTrue($this->tableExists($table), $table . ' was not created');
        }

        $requiredColumns = [
            'muwa_agent_host' => ['id', 'name', 'enabled', 'key_id', 'secret_ciphertext', 'secret_nonce', 'secret_key_version', 'capabilities', 'version', 'last_seen_at', 'revoked_at', 'created_at', 'updated_at'],
            'muwa_agent_action' => ['id', 'technical_name', 'label', 'enabled', 'definition', 'definition_hash', 'parameter_schema', 'required_capabilities', 'default_timeout_seconds', 'max_timeout_seconds', 'retry_policy', 'idempotency_class', 'created_at', 'updated_at'],
            'muwa_agent_repository_hook' => ['id', 'facility_repository_id', 'phase', 'agent_host_id', 'agent_action_id', 'parameters', 'timeout_seconds', 'fail_on_error', 'enabled', 'created_at', 'updated_at'],
            'muwa_agent_backup_run' => ['id', 'facility_repository_id', 'source', 'idempotency_key', 'status', 'started_at', 'finished_at', 'primary_error_code', 'primary_error_summary', 'active_guard', 'created_at', 'updated_at'],
            'muwa_agent_job' => ['id', 'backup_run_id', 'agent_action_id', 'facility_repository_id', 'phase', 'action_definition', 'action_hash', 'payload', 'idempotency_key', 'status', 'target_agent_id', 'claim_token_hash', 'lease_version', 'attempt', 'max_attempts', 'available_at', 'phase_deadline', 'execution_deadline', 'stop_deadline', 'claimed_at', 'lease_expires_at', 'heartbeat_at', 'finished_at', 'completion_fingerprint', 'completion_agent_id', 'previous_claim_token_hash', 'accepted_lease_version', 'result_code', 'result_summary', 'stdout_tail', 'stderr_tail', 'active_agent_id', 'created_at', 'updated_at'],
            'muwa_agent_nonce' => ['id', 'agent_id', 'nonce', 'expires_at', 'created_at', 'updated_at'],
        ];

        foreach ($requiredColumns as $table => $columns) {
            $actual = array_map('strtolower', array_keys($this->connection->createSchemaManager()->listTableColumns($table)));
            foreach ($columns as $column) {
                self::assertContains($column, $actual, $table . '.' . $column . ' is missing');
            }
        }

        $foreignKeys = [];
        foreach (self::TABLES as $table) {
            foreach ($this->connection->createSchemaManager()->listTableForeignKeys($table) as $foreignKey) {
                $foreignKeys[] = $foreignKey->getForeignTableName();
            }
        }
        self::assertNotContains('muwa_backup_repository', $foreignKeys);
        self::assertSame([], array_values(array_filter($foreignKeys, static fn (string $table): bool => !str_starts_with($table, 'muwa_agent_'))));
    }

    public function testRequiredColumnTypesAndNullabilityAreExact(): void
    {
        $expected = [
            'muwa_agent_host' => [
                'id' => 'binary(16)', 'name' => 'varchar(255)', 'enabled' => 'tinyint(1)', 'key_id' => 'varchar(255)',
                'secret_ciphertext' => 'varbinary(512)', 'secret_nonce' => 'binary(24)', 'secret_key_version' => 'int unsigned',
                'capabilities' => 'json', 'created_at' => 'datetime(3)',
            ],
            'muwa_agent_action' => [
                'id' => 'binary(16)', 'technical_name' => 'varchar(255)', 'label' => 'varchar(255)', 'enabled' => 'tinyint(1)',
                'definition' => 'longtext', 'definition_hash' => 'binary(32)', 'required_capabilities' => 'json',
                'default_timeout_seconds' => 'int unsigned', 'max_timeout_seconds' => 'int unsigned',
                'retry_policy' => 'varchar(64)', 'idempotency_class' => 'varchar(32)', 'created_at' => 'datetime(3)',
            ],
            'muwa_agent_repository_hook' => [
                'id' => 'binary(16)', 'facility_repository_id' => 'binary(16)', 'phase' => 'varchar(16)',
                'agent_host_id' => 'binary(16)', 'agent_action_id' => 'binary(16)', 'parameters' => 'longtext',
                'timeout_seconds' => 'int unsigned', 'fail_on_error' => 'tinyint(1)', 'enabled' => 'tinyint(1)', 'created_at' => 'datetime(3)',
            ],
            'muwa_agent_backup_run' => [
                'id' => 'binary(16)', 'facility_repository_id' => 'binary(16)', 'source' => 'varchar(32)',
                'idempotency_key' => 'varchar(255)', 'status' => 'varchar(32)', 'started_at' => 'datetime(3)', 'created_at' => 'datetime(3)',
            ],
            'muwa_agent_job' => [
                'id' => 'binary(16)', 'backup_run_id' => 'binary(16)', 'agent_action_id' => 'binary(16)',
                'facility_repository_id' => 'binary(16)', 'phase' => 'varchar(16)', 'action_definition' => 'longtext',
                'action_hash' => 'binary(32)', 'payload' => 'longtext', 'idempotency_key' => 'varchar(255)',
                'status' => 'varchar(32)', 'target_agent_id' => 'binary(16)', 'lease_version' => 'int unsigned',
                'attempt' => 'int unsigned', 'max_attempts' => 'int unsigned', 'available_at' => 'datetime(3)',
                'phase_deadline' => 'datetime(3)', 'execution_deadline' => 'datetime(3)', 'stop_deadline' => 'datetime(3)',
                'created_at' => 'datetime(3)',
            ],
            'muwa_agent_nonce' => [
                'id' => 'binary(16)', 'agent_id' => 'binary(16)', 'nonce' => 'varbinary(255)',
                'expires_at' => 'datetime(3)', 'created_at' => 'datetime(3)',
            ],
        ];

        foreach ($expected as $table => $columns) {
            $actual = [];
            foreach ($this->connection->fetchAllAssociative('SHOW COLUMNS FROM `' . $table . '`') as $column) {
                $actual[strtolower((string) $column['Field'])] = [
                    'type' => strtolower((string) $column['Type']),
                    'null' => (string) $column['Null'],
                ];
            }
            foreach ($columns as $name => $type) {
                self::assertSame(['type' => $type, 'null' => 'NO'], $actual[$name], $table . '.' . $name);
            }
        }
    }

    public function testUniqueIndexesAndInternalForeignKeysAreExact(): void
    {
        $expectedUnique = [
            'muwa_agent_host' => ['uniq_agent_host_key_id' => ['key_id']],
            'muwa_agent_action' => ['uniq_agent_action_technical_name' => ['technical_name']],
            'muwa_agent_repository_hook' => ['uniq_repository_phase' => ['facility_repository_id', 'phase']],
            'muwa_agent_backup_run' => ['uniq_active_guard' => ['active_guard'], 'uniq_backup_run_idempotency' => ['idempotency_key']],
            'muwa_agent_job' => ['uniq_active_agent' => ['active_agent_id'], 'uniq_agent_job_idempotency' => ['idempotency_key']],
            'muwa_agent_nonce' => ['uniq_agent_nonce' => ['agent_id', 'nonce']],
        ];
        foreach ($expectedUnique as $table => $expectedIndexes) {
            $actual = [];
            foreach ($this->connection->fetchAllAssociative('SHOW INDEX FROM `' . $table . '` WHERE Non_unique = 0') as $index) {
                $name = strtolower((string) $index['Key_name']);
                if ($name === 'primary') {
                    continue;
                }
                $actual[$name][(int) $index['Seq_in_index']] = strtolower((string) $index['Column_name']);
            }
            foreach ($actual as &$columns) {
                ksort($columns);
                $columns = array_values($columns);
            }
            unset($columns);
            ksort($actual);
            ksort($expectedIndexes);
            self::assertSame($expectedIndexes, $actual, $table . ' unique indexes');
        }

        $expectedForeignKeys = [
            'fk_repository_hook_host' => ['muwa_agent_repository_hook', 'agent_host_id', 'muwa_agent_host', 'id', 'RESTRICT', 'CASCADE'],
            'fk_repository_hook_action' => ['muwa_agent_repository_hook', 'agent_action_id', 'muwa_agent_action', 'id', 'RESTRICT', 'CASCADE'],
            'fk_agent_job_run' => ['muwa_agent_job', 'backup_run_id', 'muwa_agent_backup_run', 'id', 'CASCADE', 'CASCADE'],
            'fk_agent_job_action' => ['muwa_agent_job', 'agent_action_id', 'muwa_agent_action', 'id', 'RESTRICT', 'CASCADE'],
            'fk_agent_job_target' => ['muwa_agent_job', 'target_agent_id', 'muwa_agent_host', 'id', 'RESTRICT', 'RESTRICT'],
            'fk_agent_job_completion_agent' => ['muwa_agent_job', 'completion_agent_id', 'muwa_agent_host', 'id', 'RESTRICT', 'RESTRICT'],
            'fk_agent_nonce_host' => ['muwa_agent_nonce', 'agent_id', 'muwa_agent_host', 'id', 'CASCADE', 'CASCADE'],
        ];
        $rows = $this->connection->fetchAllAssociative(<<<'SQL'
SELECT rc.CONSTRAINT_NAME, kcu.TABLE_NAME, kcu.COLUMN_NAME, kcu.REFERENCED_TABLE_NAME,
       kcu.REFERENCED_COLUMN_NAME, rc.DELETE_RULE, rc.UPDATE_RULE
FROM information_schema.REFERENTIAL_CONSTRAINTS rc
INNER JOIN information_schema.KEY_COLUMN_USAGE kcu
  ON kcu.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA AND kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME
WHERE rc.CONSTRAINT_SCHEMA = DATABASE() AND kcu.TABLE_NAME LIKE 'muwa_agent_%'
SQL);
        $actualForeignKeys = [];
        foreach ($rows as $row) {
            $actualForeignKeys[strtolower((string) $row['CONSTRAINT_NAME'])] = [
                strtolower((string) $row['TABLE_NAME']), strtolower((string) $row['COLUMN_NAME']),
                strtolower((string) $row['REFERENCED_TABLE_NAME']), strtolower((string) $row['REFERENCED_COLUMN_NAME']),
                strtoupper((string) $row['DELETE_RULE']), strtoupper((string) $row['UPDATE_RULE']),
            ];
        }
        ksort($expectedForeignKeys);
        ksort($actualForeignKeys);
        self::assertSame($expectedForeignKeys, $actualForeignKeys);
    }

    public function testCriticalForeignKeyAndUniqueIntegrityRulesAreEnforced(): void
    {
        [$runId, $actionId, $agentId] = $this->createJobParents();
        $validHook = [
            'id' => Uuid::randomBytes(), 'facility_repository_id' => Uuid::randomBytes(), 'phase' => JobPhase::Pre->value,
            'agent_host_id' => $agentId, 'agent_action_id' => $actionId, 'parameters' => '{}', 'timeout_seconds' => 60,
            'fail_on_error' => 1, 'enabled' => 1, 'created_at' => (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
        ];
        $this->connection->insert('muwa_agent_repository_hook', $validHook);

        try {
            $duplicate = $validHook;
            $duplicate['id'] = Uuid::randomBytes();
            $this->connection->insert('muwa_agent_repository_hook', $duplicate);
            self::fail('Repository and phase must be unique.');
        } catch (UniqueConstraintViolationException) {
            self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM muwa_agent_repository_hook'));
        }

        try {
            $invalid = $validHook;
            $invalid['id'] = Uuid::randomBytes();
            $invalid['facility_repository_id'] = Uuid::randomBytes();
            $invalid['agent_host_id'] = Uuid::randomBytes();
            $this->connection->insert('muwa_agent_repository_hook', $invalid);
            self::fail('Unknown agent_host_id must violate the internal foreign key.');
        } catch (ForeignKeyConstraintViolationException $exception) {
            self::assertNotSame('', $exception->getMessage());
        }

        $jobId = Uuid::randomBytes();
        $this->insertJob($this->connection, $runId, $actionId, $agentId, JobStatus::Queued->value, $jobId);
        $this->connection->delete('muwa_agent_backup_run', ['id' => $runId]);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM muwa_agent_job WHERE id = :id', ['id' => $jobId]));
    }

    public function testDefinesRequiredUniqueAndGeneratedGuardsExactly(): void
    {
        $hook = $this->createTableSql('muwa_agent_repository_hook');
        self::assertStringContainsString('UNIQUE KEY `uniq_repository_phase` (`facility_repository_id`,`phase`)', $hook);

        $run = $this->createTableSql('muwa_agent_backup_run');
        self::assertStringContainsString("`active_guard` tinyint GENERATED ALWAYS AS ((case when (`status` = _utf8mb4'running') then 1 else NULL end)) STORED", $run);
        self::assertStringContainsString('UNIQUE KEY `uniq_active_guard` (`active_guard`)', $run);

        $job = $this->createTableSql('muwa_agent_job');
        self::assertStringContainsString("`active_agent_id` binary(16) GENERATED ALWAYS AS ((case when (`status` in (_utf8mb4'claimed',_utf8mb4'running',_utf8mb4'cancel_requested')) then `target_agent_id` else NULL end)) STORED", $job);
        self::assertStringContainsString('UNIQUE KEY `uniq_active_agent` (`active_agent_id`)', $job);
        self::assertStringContainsString('KEY `idx_job_queue` (`status`,`available_at`,`execution_deadline`)', $job);
        self::assertStringContainsString('KEY `idx_job_agent_lease` (`target_agent_id`,`status`,`lease_expires_at`)', $job);
        self::assertStringContainsString('KEY `idx_job_run_phase` (`backup_run_id`,`phase`)', $job);
    }

    public function testEnumCheckConstraintsMatchDeclaredEnumsAndRejectInvalidValues(): void
    {
        $expectations = [
            'muwa_agent_action' => ['chk_agent_action_idempotency_class' => array_column(IdempotencyClass::cases(), 'value')],
            'muwa_agent_repository_hook' => ['chk_agent_repository_hook_phase' => array_column(JobPhase::cases(), 'value')],
            'muwa_agent_backup_run' => ['chk_agent_backup_run_status' => array_column(RunStatus::cases(), 'value')],
            'muwa_agent_job' => [
                'chk_agent_job_status' => array_column(JobStatus::cases(), 'value'),
                'chk_agent_job_phase' => array_column(JobPhase::cases(), 'value'),
            ],
        ];

        foreach ($expectations as $table => $constraints) {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT cc.CONSTRAINT_NAME, cc.CHECK_CLAUSE
                   FROM information_schema.CHECK_CONSTRAINTS cc
                   INNER JOIN information_schema.TABLE_CONSTRAINTS tc
                     ON tc.CONSTRAINT_SCHEMA = cc.CONSTRAINT_SCHEMA
                    AND tc.CONSTRAINT_NAME = cc.CONSTRAINT_NAME
                  WHERE tc.CONSTRAINT_SCHEMA = DATABASE() AND tc.TABLE_NAME = :table',
                ['table' => $table]
            );
            $actual = [];
            foreach ($rows as $row) {
                preg_match_all("/'([^']+)'/", (string) $row['CHECK_CLAUSE'], $matches);
                $values = array_map(static fn (string $value): string => rtrim($value, '\\'), $matches[1]);
                sort($values);
                $actual[strtolower((string) $row['CONSTRAINT_NAME'])] = $values;
            }
            foreach ($constraints as $name => $values) {
                sort($values);
                self::assertArrayHasKey($name, $actual);
                self::assertSame($values, $actual[$name]);
            }
        }

        [$runId, $actionId, $agentId] = $this->createJobParents();
        $hookId = Uuid::randomBytes();
        $this->connection->insert('muwa_agent_repository_hook', [
            'id' => $hookId,
            'facility_repository_id' => Uuid::randomBytes(),
            'phase' => JobPhase::Pre->value,
            'agent_host_id' => $agentId,
            'agent_action_id' => $actionId,
            'parameters' => '{}',
            'timeout_seconds' => 60,
            'fail_on_error' => 1,
            'enabled' => 1,
            'created_at' => (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
        ]);
        $jobId = Uuid::randomBytes();
        $this->insertJob($this->connection, $runId, $actionId, $agentId, JobStatus::Queued->value, $jobId);

        $this->assertCheckRejects(
            fn (): int => $this->connection->update('muwa_agent_action', ['idempotency_class' => 'SAFE'], ['id' => $actionId])
        );
        $invalidHook = [
            'id' => Uuid::randomBytes(),
            'facility_repository_id' => Uuid::randomBytes(),
            'phase' => 'before',
            'agent_host_id' => $agentId,
            'agent_action_id' => $actionId,
            'parameters' => '{}',
            'timeout_seconds' => 60,
            'fail_on_error' => 1,
            'enabled' => 1,
            'created_at' => (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
        ];
        $this->assertCheckRejects(
            fn (): int => $this->connection->insert('muwa_agent_repository_hook', $invalidHook)
        );
        $this->assertCheckRejects(
            fn (): int => $this->connection->update('muwa_agent_backup_run', ['status' => 'RUNNING'], ['id' => $runId])
        );
        $this->assertCheckRejects(
            fn (): int => $this->connection->update('muwa_agent_job', ['status' => 'runing'], ['id' => $jobId])
        );
        $this->assertCheckRejects(
            fn (): int => $this->insertJob(
                $this->connection,
                $runId,
                $actionId,
                $agentId,
                JobStatus::Queued->value,
                Uuid::randomBytes(),
                'cleanup'
            )
        );

        self::assertSame(IdempotencyClass::Safe->value, $this->connection->fetchOne('SELECT idempotency_class FROM muwa_agent_action WHERE id = :id', ['id' => $actionId]));
        self::assertSame(JobPhase::Pre->value, $this->connection->fetchOne('SELECT phase FROM muwa_agent_repository_hook WHERE id = :id', ['id' => $hookId]));
        self::assertSame(RunStatus::Succeeded->value, $this->connection->fetchOne('SELECT status FROM muwa_agent_backup_run WHERE id = :id', ['id' => $runId]));
        self::assertSame(JobStatus::Queued->value, $this->connection->fetchOne('SELECT status FROM muwa_agent_job WHERE id = :id', ['id' => $jobId]));
        self::assertSame(JobPhase::Pre->value, $this->connection->fetchOne('SELECT phase FROM muwa_agent_job WHERE id = :id', ['id' => $jobId]));
    }

    public function testDatabaseFallbackEnforcesImmutableFieldsForShopware66(): void
    {
        [$runId, $actionId, $agentId] = $this->createJobParents();
        $jobId = Uuid::randomBytes();
        $this->insertJob($this->connection, $runId, $actionId, $agentId, JobStatus::Queued->value, $jobId);
        $originalDeadline = $this->connection->fetchOne('SELECT phase_deadline FROM muwa_agent_job WHERE id = :id', ['id' => $jobId]);

        try {
            $this->connection->update('muwa_agent_job', ['phase_deadline' => '2026-12-31 23:59:59.999'], ['id' => $jobId]);
            self::fail('Database fallback must reject deadline changes on Shopware 6.6.');
        } catch (DriverException $exception) {
            self::assertStringContainsString('immutable', strtolower($exception->getMessage()));
        }
        self::assertSame($originalDeadline, $this->connection->fetchOne('SELECT phase_deadline FROM muwa_agent_job WHERE id = :id', ['id' => $jobId]));

        $fingerprint = hash('sha256', 'first', true);
        $previousClaimTokenHash = hash('sha256', 'claim-a', true);
        $this->connection->update('muwa_agent_job', [
            'completion_fingerprint' => $fingerprint,
            'completion_agent_id' => $agentId,
            'previous_claim_token_hash' => $previousClaimTokenHash,
            'accepted_lease_version' => 7,
        ], ['id' => $jobId]);

        $immutableAuditChanges = [
            ['completion_fingerprint' => hash('sha256', 'second', true)],
            ['completion_agent_id' => Uuid::randomBytes()],
            ['previous_claim_token_hash' => hash('sha256', 'claim-b', true)],
            ['previous_claim_token_hash' => null],
            ['accepted_lease_version' => 8],
        ];
        foreach ($immutableAuditChanges as $change) {
            try {
                $this->connection->update('muwa_agent_job', $change, ['id' => $jobId]);
                self::fail('Database fallback must make every completion audit field write-once.');
            } catch (DriverException $exception) {
                self::assertStringContainsString('immutable', strtolower($exception->getMessage()));
            }
        }

        $storedAudit = $this->connection->fetchAssociative(
            'SELECT completion_fingerprint, completion_agent_id, previous_claim_token_hash, accepted_lease_version FROM muwa_agent_job WHERE id = :id',
            ['id' => $jobId]
        );
        self::assertIsArray($storedAudit);
        self::assertSame($fingerprint, $storedAudit['completion_fingerprint']);
        self::assertSame($agentId, $storedAudit['completion_agent_id']);
        self::assertSame($previousClaimTokenHash, $storedAudit['previous_claim_token_hash']);
        self::assertSame(7, (int) $storedAudit['accepted_lease_version']);

        $hookId = Uuid::randomBytes();
        $this->connection->insert('muwa_agent_repository_hook', [
            'id' => $hookId, 'facility_repository_id' => Uuid::randomBytes(), 'phase' => JobPhase::Pre->value,
            'agent_host_id' => $agentId, 'agent_action_id' => $actionId, 'parameters' => '{}', 'timeout_seconds' => 60,
            'fail_on_error' => 1, 'enabled' => 1, 'created_at' => (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
        ]);
        try {
            $this->connection->update('muwa_agent_repository_hook', ['phase' => JobPhase::Post->value], ['id' => $hookId]);
            self::fail('Database fallback must reject repository-hook phase changes.');
        } catch (DriverException $exception) {
            self::assertStringContainsString('immutable', strtolower($exception->getMessage()));
        }
        self::assertSame(JobPhase::Pre->value, $this->connection->fetchOne('SELECT phase FROM muwa_agent_repository_hook WHERE id = :id', ['id' => $hookId]));
    }

    public function testDeadlineFormulaIsEnforcedAtPersistenceBoundary(): void
    {
        [$runId, $actionId, $agentId] = $this->createJobParents();
        $now = new \DateTimeImmutable('2026-11-21 12:00:00.000');
        $phaseDeadline = $now->modify('+10 minutes');

        $this->insertJob(
            $this->connection,
            $runId,
            $actionId,
            $agentId,
            JobStatus::Queued->value,
            null,
            JobPhase::Pre->value,
            [
                'phase' => $phaseDeadline,
                'execution' => $phaseDeadline->modify('-15 seconds'),
                'stop' => $phaseDeadline->modify('-10 seconds'),
            ]
        );

        try {
            $this->insertJob(
                $this->connection,
                $runId,
                $actionId,
                $agentId,
                JobStatus::Queued->value,
                null,
                JobPhase::Post->value,
                [
                    'phase' => $phaseDeadline,
                    'execution' => $phaseDeadline->modify('-14 seconds'),
                    'stop' => $phaseDeadline->modify('-10 seconds'),
                ]
            );
            self::fail('Persistence must reject a job whose execution-deadline formula is invalid.');
        } catch (DriverException $exception) {
            self::assertStringContainsString('deadline', strtolower($exception->getMessage()));
        }

        try {
            $this->insertJob(
                $this->connection,
                $runId,
                $actionId,
                $agentId,
                JobStatus::Queued->value,
                null,
                JobPhase::Finally->value,
                [
                    'phase' => $phaseDeadline,
                    'execution' => $phaseDeadline->modify('-15 seconds'),
                    'stop' => $phaseDeadline->modify('-9 seconds'),
                ]
            );
            self::fail('Persistence must reject a job whose stop-deadline formula is invalid.');
        } catch (DriverException $exception) {
            self::assertStringContainsString('deadline', strtolower($exception->getMessage()));
        }
    }

    public function testStopDeadlineIsRequiredInSchemaAndDal(): void
    {
        $column = $this->connection->fetchAssociative("SHOW COLUMNS FROM `muwa_agent_job` WHERE Field = 'stop_deadline'");
        self::assertIsArray($column);
        self::assertSame('datetime(3)', strtolower((string) $column['Type']));
        self::assertSame('NO', $column['Null']);
        self::assertTrue($this->definitionField(
            new \MuckiSafeAgentPlugin\Core\Content\AgentJob\AgentJobDefinition(),
            'stop_deadline'
        )->is(Required::class));
    }

    public function testServerManagedDalFieldsHaveVersionCompatibleProtectionFlags(): void
    {
        $job = new \MuckiSafeAgentPlugin\Core\Content\AgentJob\AgentJobDefinition();
        $run = new \MuckiSafeAgentPlugin\Core\Content\AgentBackupRun\AgentBackupRunDefinition();
        $hook = new \MuckiSafeAgentPlugin\Core\Content\AgentRepositoryHook\AgentRepositoryHookDefinition();

        foreach (['backup_run_id', 'agent_action_id', 'facility_repository_id', 'phase', 'action_definition',
            'action_hash', 'payload', 'idempotency_key', 'status', 'target_agent_id', 'lease_version', 'attempt',
            'max_attempts', 'available_at', 'phase_deadline', 'execution_deadline', 'stop_deadline', 'claimed_at', 'lease_expires_at',
            'heartbeat_at', 'finished_at', 'completion_fingerprint', 'completion_agent_id',
            'accepted_lease_version', 'result_code', 'result_summary', 'stdout_tail', 'stderr_tail'] as $fieldName) {
            $flag = $this->definitionField($job, $fieldName)->getFlag(WriteProtected::class);
            self::assertInstanceOf(WriteProtected::class, $flag, $fieldName . ' must be server-managed');
            self::assertTrue($flag->isAllowed(Context::SYSTEM_SCOPE));
        }
        foreach (['idempotency_key', 'phase_deadline', 'execution_deadline', 'stop_deadline', 'action_definition',
            'action_hash', 'payload', 'completion_fingerprint', 'completion_agent_id', 'accepted_lease_version'] as $fieldName) {
            self::assertTrue($this->definitionField($job, $fieldName)->is($this->immutableFlagClass()), $fieldName . ' must be write-once');
        }
        foreach (['facility_repository_id', 'source', 'idempotency_key', 'status', 'started_at', 'finished_at', 'primary_error_code', 'primary_error_summary'] as $fieldName) {
            $flag = $this->definitionField($run, $fieldName)->getFlag(WriteProtected::class);
            self::assertInstanceOf(WriteProtected::class, $flag, 'run.' . $fieldName . ' must be server-managed');
            self::assertTrue($flag->isAllowed(Context::SYSTEM_SCOPE));
        }
        self::assertTrue($this->definitionField($hook, 'phase')->is($this->immutableFlagClass()));
        self::assertInstanceOf(WriteProtected::class, $this->definitionField($hook, 'phase')->getFlag(WriteProtected::class));
    }

    public function testAdminApiCannotMutateServerManagedJobFields(): void
    {
        [$runId, $actionId, $agentId] = $this->createJobParents();
        $jobId = Uuid::randomHex();
        $now = new \DateTimeImmutable('2026-11-21 12:00:00.000');
        $this->jobRepository()->create([[
            'id' => $jobId,
            'backupRunId' => Uuid::fromBytesToHex($runId),
            'agentActionId' => Uuid::fromBytesToHex($actionId),
            'facilityRepositoryId' => Uuid::randomHex(),
            'phase' => JobPhase::Pre->value,
            'actionDefinition' => '{}',
            'actionHash' => hash('sha256', '{}', true),
            'payload' => '{}',
            'idempotencyKey' => Uuid::randomHex(),
            'status' => JobStatus::Queued->value,
            'targetAgentId' => Uuid::fromBytesToHex($agentId),
            'leaseVersion' => 0,
            'attempt' => 0,
            'maxAttempts' => 1,
            'availableAt' => $now,
            'phaseDeadline' => $now->modify('+10 minutes'),
            'executionDeadline' => $now->modify('+9 minutes 45 seconds'),
            'stopDeadline' => $now->modify('+9 minutes 50 seconds'),
        ]], Context::createDefaultContext());

        $adminContext = Context::createDefaultContext(new AdminApiSource(Uuid::randomHex()));
        try {
            $this->jobRepository()->update([[
                'id' => $jobId,
                'status' => JobStatus::Running->value,
                'resultCode' => 'forged',
            ]], $adminContext);
            self::fail('Admin API scope must not mutate server-managed status/result fields.');
        } catch (WriteException $exception) {
            self::assertStringContainsString('write-protected', strtolower($exception->getMessage()));
        }

        $stored = $this->connection->fetchAssociative(
            'SELECT status, result_code FROM muwa_agent_job WHERE id = :id',
            ['id' => Uuid::fromHexToBytes($jobId)]
        );
        self::assertIsArray($stored);
        self::assertSame(JobStatus::Queued->value, $stored['status']);
        self::assertNull($stored['result_code']);
    }

    public function testImmutableJobFieldsRejectDifferentDalUpdatesAndPreserveStoredValues(): void
    {
        [$runId, $actionId, $agentId] = $this->createJobParents();
        $jobId = Uuid::randomHex();
        $now = new \DateTimeImmutable('2026-11-21 12:00:00.000');
        $phaseDeadline = $now->modify('+10 minutes');
        $executionDeadline = $phaseDeadline->modify('-15 seconds');
        $stopDeadline = $phaseDeadline->modify('-10 seconds');
        $repository = $this->jobRepository();
        $context = Context::createDefaultContext();

        $repository->create([[
            'id' => $jobId,
            'backupRunId' => Uuid::fromBytesToHex($runId),
            'agentActionId' => Uuid::fromBytesToHex($actionId),
            'facilityRepositoryId' => Uuid::randomHex(),
            'phase' => 'pre',
            'actionDefinition' => '{}',
            'actionHash' => hash('sha256', '{}', true),
            'payload' => '{}',
            'idempotencyKey' => Uuid::randomHex(),
            'status' => 'queued',
            'targetAgentId' => Uuid::fromBytesToHex($agentId),
            'leaseVersion' => 0,
            'attempt' => 0,
            'maxAttempts' => 1,
            'availableAt' => $now,
            'phaseDeadline' => $phaseDeadline,
            'executionDeadline' => $executionDeadline,
            'stopDeadline' => $stopDeadline,
        ]], $context);

        try {
            $repository->update([[
                'id' => $jobId,
                'phaseDeadline' => $phaseDeadline->modify('+1 second'),
                'executionDeadline' => $executionDeadline->modify('+1 second'),
                'stopDeadline' => $stopDeadline->modify('+1 second'),
            ]], $context);
            self::fail('Deadline fields must reject a second, differing DAL write.');
        } catch (WriteException|DriverException $exception) {
            self::assertStringContainsString('immutable', strtolower($exception->getMessage()));
        }

        $fingerprint = hash('sha256', 'completion-a', true);
        $repository->update([[
            'id' => $jobId,
            'completionFingerprint' => $fingerprint,
            'completionAgentId' => Uuid::fromBytesToHex($agentId),
            'acceptedLeaseVersion' => 7,
        ]], $context);

        try {
            $repository->update([[
                'id' => $jobId,
                'completionFingerprint' => hash('sha256', 'completion-b', true),
                'completionAgentId' => Uuid::randomHex(),
                'acceptedLeaseVersion' => 8,
            ]], $context);
            self::fail('Completion audit fields must reject a second, differing DAL write.');
        } catch (WriteException|DriverException $exception) {
            self::assertStringContainsString('immutable', strtolower($exception->getMessage()));
        }

        $stored = $this->connection->fetchAssociative(
            'SELECT phase_deadline, execution_deadline, stop_deadline, completion_fingerprint, completion_agent_id, accepted_lease_version FROM muwa_agent_job WHERE id = :id',
            ['id' => Uuid::fromHexToBytes($jobId)]
        );
        self::assertIsArray($stored);
        self::assertSame($phaseDeadline->format(Defaults::STORAGE_DATE_TIME_FORMAT), $stored['phase_deadline']);
        self::assertSame($executionDeadline->format(Defaults::STORAGE_DATE_TIME_FORMAT), $stored['execution_deadline']);
        self::assertSame($stopDeadline->format(Defaults::STORAGE_DATE_TIME_FORMAT), $stored['stop_deadline']);
        self::assertSame($fingerprint, $stored['completion_fingerprint']);
        self::assertSame($agentId, $stored['completion_agent_id']);
        self::assertSame(7, (int) $stored['accepted_lease_version']);
    }

    public function testDalDefinitionsExposeDomainFieldsButHideSecurityInternals(): void
    {
        $entities = [
            [new \MuckiSafeAgentPlugin\Core\Content\AgentHost\AgentHostDefinition(), ['name', 'enabled', 'key_id', 'capabilities']],
            [new \MuckiSafeAgentPlugin\Core\Content\AgentAction\AgentActionDefinition(), ['technical_name', 'label', 'definition', 'definition_hash']],
            [new \MuckiSafeAgentPlugin\Core\Content\AgentRepositoryHook\AgentRepositoryHookDefinition(), ['facility_repository_id', 'phase', 'agent_host_id', 'agent_action_id']],
            [new \MuckiSafeAgentPlugin\Core\Content\AgentBackupRun\AgentBackupRunDefinition(), ['facility_repository_id', 'source', 'idempotency_key', 'status']],
            [new \MuckiSafeAgentPlugin\Core\Content\AgentJob\AgentJobDefinition(), ['backup_run_id', 'agent_action_id', 'target_agent_id', 'phase_deadline', 'execution_deadline', 'stop_deadline', 'completion_fingerprint', 'completion_agent_id', 'accepted_lease_version']],
            [new \MuckiSafeAgentPlugin\Core\Content\AgentNonce\AgentNonceDefinition(), ['agent_id', 'nonce', 'expires_at']],
        ];

        $storageNamesByEntity = [];
        foreach ($entities as [$definition, $visibleFields]) {
            $storageNames = [];
            $method = new \ReflectionMethod($definition, 'defineFields');
            $fields = $method->invoke($definition);
            foreach ($fields as $field) {
                $storageNames[] = $field->getStorageName();
            }
            $storageNamesByEntity[$definition->getEntityName()] = $storageNames;
            foreach ($visibleFields as $field) {
                self::assertContains($field, $storageNames, $definition->getEntityName() . '.' . $field . ' must be exposed');
            }
        }

        foreach (['secret_ciphertext', 'secret_nonce', 'secret_key_version'] as $hidden) {
            self::assertNotContains($hidden, $storageNamesByEntity['muwa_agent_host'], $hidden . ' must stay outside normal DAL');
        }
        self::assertNotContains('active_guard', $storageNamesByEntity['muwa_agent_backup_run']);
        foreach (['claim_token_hash', 'previous_claim_token_hash', 'active_agent_id'] as $hidden) {
            self::assertNotContains($hidden, $storageNamesByEntity['muwa_agent_job'], $hidden . ' must stay outside normal DAL');
        }
    }

    public function testDalRepositoriesAreRegisteredInThePluginContainer(): void
    {
        foreach (['host', 'action', 'repository_hook', 'backup_run', 'job', 'nonce'] as $suffix) {
            self::assertInstanceOf(
                EntityRepository::class,
                self::getContainer()->get('muwa_agent_' . $suffix . '.repository')
            );
        }
    }

    public function testConcurrentRunningRunsAllowExactlyOneWinner(): void
    {
        $results = $this->runConcurrently([
            fn (Connection $connection) => $this->insertRun($connection, Uuid::randomBytes()),
            fn (Connection $connection) => $this->insertRun($connection, Uuid::randomBytes()),
        ]);

        sort($results);
        self::assertSame(['committed', 'unique_violation'], $results);
    }

    public function testConcurrentActiveJobsForSameAgentAllowExactlyOneWinner(): void
    {
        [$runId, $actionId, $agentId] = $this->createJobParents();
        $results = $this->runConcurrently([
            fn (Connection $connection) => $this->insertJob($connection, $runId, $actionId, $agentId),
            fn (Connection $connection) => $this->insertJob($connection, $runId, $actionId, $agentId),
        ]);

        sort($results);
        self::assertSame(['committed', 'unique_violation'], $results);
    }

    public function testConcurrentActiveJobsForDifferentAgentsBothSucceed(): void
    {
        [$runId, $actionId, $firstAgentId] = $this->createJobParents();
        $secondAgentId = Uuid::randomBytes();
        $this->insertHost($secondAgentId, 'second-agent');

        $results = $this->runConcurrently([
            fn (Connection $connection) => $this->insertJob($connection, $runId, $actionId, $firstAgentId),
            fn (Connection $connection) => $this->insertJob($connection, $runId, $actionId, $secondAgentId),
        ]);

        sort($results);
        self::assertSame(['committed', 'committed'], $results);
    }

    /** @param list<\Closure(Connection): mixed> $operations
     *  @return list<string>
     */
    private function runConcurrently(array $operations): array
    {
        /** @var list<array{pid: int, socket: resource, reaped: bool}> $children */
        $children = [];
        $results = [];

        try {
            foreach ($operations as $operation) {
                $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
                self::assertNotFalse($pair);
                $pid = pcntl_fork();
                self::assertNotSame(-1, $pid);

                if ($pid === 0) {
                    fclose($pair[0]);
                    stream_set_timeout($pair[1], 10);
                    $connection = null;
                    try {
                        $connection = DriverManager::getConnection($this->connection->getParams());
                        $connection->executeStatement('SET SESSION innodb_lock_wait_timeout = 5');
                        $connection->beginTransaction();
                        fwrite($pair[1], "ready\n");
                        fflush($pair[1]);

                        if (trim((string) fgets($pair[1])) !== 'commit') {
                            throw new \RuntimeException('Parallel barrier timed out.');
                        }

                        $operation($connection);
                        $connection->commit();
                        fwrite($pair[1], "committed\n");
                    } catch (UniqueConstraintViolationException) {
                        if ($connection->isTransactionActive()) {
                            $connection->rollBack();
                        }
                        fwrite($pair[1], "unique_violation\n");
                    } catch (\Throwable $exception) {
                        if ($connection instanceof Connection && $connection->isTransactionActive()) {
                            $connection->rollBack();
                        }
                        fwrite($pair[1], 'error:' . $exception->getMessage() . "\n");
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
                stream_set_timeout($pair[0], 10);
                $children[] = ['pid' => $pid, 'socket' => $pair[0], 'reaped' => false];
            }

            foreach ($children as $child) {
                self::assertSame('ready', trim((string) fgets($child['socket'])), 'Child connection did not reach the transaction barrier.');
                self::assertFalse(stream_get_meta_data($child['socket'])['timed_out']);
            }
            foreach ($children as $child) {
                fwrite($child['socket'], "commit\n");
                fflush($child['socket']);
            }
            foreach ($children as $child) {
                $result = trim((string) fgets($child['socket']));
                self::assertFalse(stream_get_meta_data($child['socket'])['timed_out'], 'Parallel transaction timed out.');
                self::assertStringStartsNotWith('error:', $result);
                $results[] = $result;
            }
        } finally {
            foreach ($children as $index => $child) {
                if (is_resource($child['socket'])) {
                    fclose($child['socket']);
                }

                $deadline = microtime(true) + 5.0;
                do {
                    $waited = pcntl_waitpid($child['pid'], $status, WNOHANG);
                    if ($waited === $child['pid']) {
                        $children[$index]['reaped'] = true;
                        break;
                    }
                    usleep(10_000);
                } while (microtime(true) < $deadline);

                if (!$children[$index]['reaped']) {
                    posix_kill($child['pid'], SIGKILL);
                    pcntl_waitpid($child['pid'], $status);
                    $children[$index]['reaped'] = true;
                }
            }
        }

        return $results;
    }

    /** @return array{string, string, string} */
    private function createJobParents(): array
    {
        $runId = Uuid::randomBytes();
        $actionId = Uuid::randomBytes();
        $agentId = Uuid::randomBytes();
        $this->insertRun($this->connection, $runId, 'succeeded');
        $this->insertHost($agentId, 'first-agent');
        $this->connection->insert('muwa_agent_action', [
            'id' => $actionId,
            'technical_name' => 'test-action',
            'label' => 'Test',
            'enabled' => 1,
            'definition' => '{}',
            'definition_hash' => hash('sha256', '{}', true),
            'required_capabilities' => '[]',
            'default_timeout_seconds' => 60,
            'max_timeout_seconds' => 120,
            'retry_policy' => 'none',
            'idempotency_class' => 'safe',
            'created_at' => (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
        ]);

        return [$runId, $actionId, $agentId];
    }

    private function insertHost(string $id, string $keyId): void
    {
        $this->connection->insert('muwa_agent_host', [
            'id' => $id,
            'name' => $keyId,
            'enabled' => 1,
            'key_id' => $keyId,
            'secret_ciphertext' => random_bytes(32),
            'secret_nonce' => random_bytes(24),
            'secret_key_version' => 1,
            'capabilities' => '[]',
            'created_at' => (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
        ]);
    }

    private function insertRun(Connection $connection, string $id, string $status = 'running'): void
    {
        $connection->insert('muwa_agent_backup_run', [
            'id' => $id,
            'facility_repository_id' => Uuid::randomBytes(),
            'source' => 'facility',
            'idempotency_key' => Uuid::randomHex(),
            'status' => $status,
            'started_at' => (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
            'created_at' => (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
        ]);
    }

    /** @param null|array{phase: \DateTimeImmutable, execution: \DateTimeImmutable, stop: \DateTimeImmutable} $deadlines */
    private function insertJob(
        Connection $connection,
        string $runId,
        string $actionId,
        string $agentId,
        string $status = 'claimed',
        ?string $jobId = null,
        string $phase = 'pre',
        ?array $deadlines = null
    ): int {
        $now = new \DateTimeImmutable();
        $phaseDeadline = $deadlines['phase'] ?? $now->modify('+10 minutes');
        $executionDeadline = $deadlines['execution'] ?? $phaseDeadline->modify('-15 seconds');
        $stopDeadline = $deadlines['stop'] ?? $phaseDeadline->modify('-10 seconds');

        return $connection->insert('muwa_agent_job', [
            'id' => $jobId ?? Uuid::randomBytes(),
            'backup_run_id' => $runId,
            'agent_action_id' => $actionId,
            'facility_repository_id' => Uuid::randomBytes(),
            'phase' => $phase,
            'action_definition' => '{}',
            'action_hash' => hash('sha256', '{}', true),
            'payload' => '{}',
            'idempotency_key' => Uuid::randomHex(),
            'status' => $status,
            'target_agent_id' => $agentId,
            'lease_version' => 1,
            'attempt' => 1,
            'max_attempts' => 1,
            'available_at' => $now->format(Defaults::STORAGE_DATE_TIME_FORMAT),
            'phase_deadline' => $phaseDeadline->format(Defaults::STORAGE_DATE_TIME_FORMAT),
            'execution_deadline' => $executionDeadline->format(Defaults::STORAGE_DATE_TIME_FORMAT),
            'stop_deadline' => $stopDeadline->format(Defaults::STORAGE_DATE_TIME_FORMAT),
            'created_at' => $now->format(Defaults::STORAGE_DATE_TIME_FORMAT),
        ]);
    }

    /** @param \Closure(): int $operation */
    private function assertCheckRejects(\Closure $operation): void
    {
        try {
            $operation();
            self::fail('Database CHECK constraint must reject the invalid value.');
        } catch (DriverException $exception) {
            self::assertStringContainsString('check constraint', strtolower($exception->getMessage()));
        }
    }

    /** @return EntityRepository<\MuckiSafeAgentPlugin\Core\Content\AgentJob\AgentJobCollection> */
    private function jobRepository(): EntityRepository
    {
        $repository = self::getContainer()->get('muwa_agent_job.repository');
        self::assertInstanceOf(EntityRepository::class, $repository);

        return $repository;
    }

    /** @return class-string<Flag> */
    private function immutableFlagClass(): string
    {
        $native = 'Shopware\\Core\\Framework\\DataAbstractionLayer\\Field\\Flag\\Immutable';

        return class_exists($native) ? $native : CompatibilityImmutable::class;
    }

    private function definitionField(object $definition, string $storageName): Field
    {
        $method = new \ReflectionMethod($definition, 'defineFields');
        $fields = $method->invoke($definition);
        foreach ($fields as $field) {
            if ($field->getStorageName() === $storageName) {
                return $field;
            }
        }

        self::fail('Missing DAL field ' . $storageName);
    }

    private function createTableSql(string $table): string
    {
        $row = $this->connection->fetchAssociative('SHOW CREATE TABLE `' . $table . '`');
        self::assertIsArray($row);

        return (string) ($row['Create Table'] ?? '');
    }

    private function tableExists(string $table): bool
    {
        return $this->connection->createSchemaManager()->tablesExist([$table]);
    }

    private function dropSchema(): void
    {
        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS=0');
        foreach (self::TABLES as $table) {
            $this->connection->executeStatement('DROP TABLE IF EXISTS `' . $table . '`');
        }
        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS=1');
    }
}
