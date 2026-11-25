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
namespace MuckiSafeAgentPlugin\tests\Integration\Controller\Admin;

require_once dirname(__DIR__, 4) . '/src/Migration/Migration1795132800CreateAgentSchema.php';

use Doctrine\DBAL\Connection;
use MuckiSafeAgentPlugin\Migration\Migration1795132800CreateAgentSchema;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Test\TestCaseBase\AdminApiTestBehaviour;
use Shopware\Core\Framework\Test\TestCaseBase\KernelLifecycleManager;
use Shopware\Core\Framework\Test\TestCaseBase\KernelTestBehaviour;
use Shopware\Core\Framework\Test\TestCaseHelper\TestBrowser;
use Shopware\Core\Framework\Util\Hasher;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs and jobs are read-only in the administration. Status, phase, result
 * and timing are readable with the viewer role through the generic API;
 * stdout/stderr failure tails never leave the server that way and are served
 * only by the `muwa_agent.executor` protected output route.
 */
final class JobOutputControllerHttpTest extends TestCase
{
    use KernelTestBehaviour;
    use AdminApiTestBehaviour;

    private const VIEWER = ['muwa_agent.viewer', 'muwa_agent_job:read', 'muwa_agent_backup_run:read'];
    private const JSON_API = ['HTTP_ACCEPT' => 'application/vnd.api+json'];

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
        $this->seedParents();
    }

    protected function tearDown(): void
    {
        $this->dropSchema();
    }

    public function testViewerReadsJobsAndRunsWithoutFailureTails(): void
    {
        $jobId = $this->insertFailedJob();
        $browser = $this->browserWithPermissions(self::VIEWER);

        // Explicit per request: only the 6.7 TestBrowser keeps a preset Accept header in jsonRequest(), 6.6 forces application/json.
        $browser->jsonRequest('POST', '/api/search/muwa-agent-job', ['ids' => [$jobId]], self::JSON_API);
        $job = $this->decoded($browser, Response::HTTP_OK)['data'][0]['attributes'];
        self::assertSame('failed', $job['status']);
        self::assertSame('step_failed', $job['resultCode']);
        self::assertSame('pre', $job['phase']);
        foreach (['stdoutTail', 'stderrTail', 'actionHash', 'completionFingerprint'] as $hidden) {
            self::assertArrayNotHasKey($hidden, $job, $hidden);
        }
        self::assertStringNotContainsString('SECRET-STDOUT', (string) $browser->getResponse()->getContent());
        self::assertStringNotContainsString('SECRET-STDERR', (string) $browser->getResponse()->getContent());

        $browser->jsonRequest('POST', '/api/search/muwa-agent-backup-run', ['ids' => [$this->runId]], self::JSON_API);
        self::assertSame('failed', $this->decoded($browser, Response::HTTP_OK)['data'][0]['attributes']['status']);
    }

    public function testPlainJsonApiEncodesJobsWithoutBinaryFieldsOrTails(): void
    {
        $jobId = $this->insertFailedJob();
        $browser = $this->browserWithPermissions(self::VIEWER);

        $browser->jsonRequest('POST', '/api/search/muwa-agent-job', ['ids' => [$jobId]], ['HTTP_ACCEPT' => 'application/json']);

        $job = $this->decoded($browser, Response::HTTP_OK)['data'][0];
        self::assertSame('step_failed', $job['resultCode']);
        foreach (['stdoutTail', 'stderrTail', 'actionHash', 'completionFingerprint'] as $hidden) {
            self::assertArrayNotHasKey($hidden, $job, $hidden);
        }
        self::assertStringNotContainsString('SECRET-STDERR', (string) $browser->getResponse()->getContent());
    }

    public function testExecutorReadsFailureTailsThroughTheOutputRoute(): void
    {
        $jobId = $this->insertFailedJob();
        $browser = $this->browserWithPermissions(['muwa_agent.executor']);

        $browser->jsonRequest('GET', '/api/_action/muwa/agent/job/' . $jobId . '/output');

        self::assertSame(
            ['stdoutTail' => 'SECRET-STDOUT', 'stderrTail' => 'SECRET-STDERR'],
            $this->decoded($browser, Response::HTTP_OK)
        );
        self::assertStringContainsString('no-store', (string) $browser->getResponse()->headers->get('Cache-Control'));

        $browser->jsonRequest('GET', '/api/_action/muwa/agent/job/' . Uuid::randomHex() . '/output');
        self::assertSame(['error' => 'job_not_found'], $this->decoded($browser, Response::HTTP_NOT_FOUND));
        $browser->jsonRequest('GET', '/api/_action/muwa/agent/job/not-a-uuid/output');
        self::assertSame(['error' => 'job_not_found'], $this->decoded($browser, Response::HTTP_NOT_FOUND));
    }

    public function testRolesWithoutExecutorPrivilegeCannotReadFailureTails(): void
    {
        $jobId = $this->insertFailedJob();

        foreach ([self::VIEWER, ['muwa_agent.editor'], ['muwa_agent.provision', 'muwa_agent.revoke']] as $permissions) {
            $browser = $this->browserWithPermissions($permissions);
            $browser->jsonRequest('GET', '/api/_action/muwa/agent/job/' . $jobId . '/output');
            self::assertContains($browser->getResponse()->getStatusCode(), [Response::HTTP_UNAUTHORIZED, Response::HTTP_FORBIDDEN]);
            self::assertStringNotContainsString('SECRET-STDOUT', (string) $browser->getResponse()->getContent());
        }
    }

    public function testGenericApiCannotDeleteOrRewriteJobsAndRuns(): void
    {
        $jobId = $this->insertFailedJob();
        $browser = $this->browserWithPermissions([
            'muwa_agent_job:read', 'muwa_agent_job:update', 'muwa_agent_job:delete',
            'muwa_agent_backup_run:read', 'muwa_agent_backup_run:update', 'muwa_agent_backup_run:delete',
        ]);

        $browser->jsonRequest('DELETE', '/api/muwa-agent-job/' . $jobId);
        $this->assertRejected($browser, 'job delete');
        $browser->jsonRequest('DELETE', '/api/muwa-agent-backup-run/' . $this->runId);
        $this->assertRejected($browser, 'run delete');

        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM `muwa_agent_job`'));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM `muwa_agent_backup_run`'));
    }

    private function insertFailedJob(): string
    {
        $jobId = Uuid::randomHex();
        $now = new \DateTimeImmutable('2026-11-25 10:00:00');
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
            'status' => 'failed',
            'target_agent_id' => Uuid::fromHexToBytes($this->hostId),
            'lease_version' => 2,
            'attempt' => 1,
            'max_attempts' => 1,
            'available_at' => $this->storage($now),
            'phase_deadline' => $this->storage($now->modify('+10 minutes')),
            'execution_deadline' => $this->storage($now->modify('+9 minutes 45 seconds')),
            'stop_deadline' => $this->storage($now->modify('+9 minutes 50 seconds')),
            'finished_at' => $this->storage($now->modify('+2 minutes')),
            'completion_fingerprint' => hash('sha256', 'fingerprint', true),
            'completion_agent_id' => Uuid::fromHexToBytes($this->hostId),
            'accepted_lease_version' => 1,
            'result_code' => 'step_failed',
            'result_summary' => 'step 1 failed',
            'stdout_tail' => 'SECRET-STDOUT',
            'stderr_tail' => 'SECRET-STDERR',
            'created_at' => $this->storage($now),
        ]);

        return $jobId;
    }

    private function seedParents(): void
    {
        $created = $this->storage(new \DateTimeImmutable('2026-11-25 09:59:00'));
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
            'technical_name' => 'output-action',
            'label' => 'Output action',
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
            'name' => 'output-host',
            'enabled' => 1,
            'key_id' => 'output-' . $this->hostId,
            'secret_ciphertext' => random_bytes(32),
            'secret_nonce' => random_bytes(24),
            'secret_key_version' => 1,
            'capabilities' => '[]',
            'created_at' => $created,
        ]);
    }

    private function assertRejected(TestBrowser $browser, string $case): void
    {
        $status = $browser->getResponse()->getStatusCode();
        self::assertGreaterThanOrEqual(Response::HTTP_BAD_REQUEST, $status, $case . ': ' . (string) $browser->getResponse()->getContent());
        self::assertLessThan(Response::HTTP_INTERNAL_SERVER_ERROR, $status, $case . ': ' . (string) $browser->getResponse()->getContent());
    }

    /** @return array<string, mixed> */
    private function decoded(TestBrowser $browser, int $expectedStatus): array
    {
        self::assertSame($expectedStatus, $browser->getResponse()->getStatusCode(), (string) $browser->getResponse()->getContent());
        $payload = json_decode((string) $browser->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        return $payload;
    }

    /** @param list<string> $permissions */
    private function browserWithPermissions(array $permissions): TestBrowser
    {
        $browser = KernelLifecycleManager::createBrowser(self::getKernel(), false);
        $browser->followRedirects();
        $browser->setServerParameters(['HTTP_ACCEPT' => ['application/vnd.api+json,application/json']]);
        // authorizeBrowser() derives a deterministic e-mail from the permissions and this test runs without a
        // transaction (setUp executes DDL); remove the user of a previous run so repeated runs stay green.
        $this->connection->executeStatement(
            'DELETE FROM `user` WHERE `email` = :email',
            ['email' => Hasher::hash($permissions) . '@example.com']
        );
        $this->authorizeBrowser($browser, [], $permissions);

        return $browser;
    }

    private function storage(\DateTimeImmutable $time): string
    {
        return $time->format(Defaults::STORAGE_DATE_TIME_FORMAT);
    }

    private function dropSchema(): void
    {
        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS=0');
        foreach (['muwa_agent_nonce', 'muwa_agent_job', 'muwa_agent_backup_run', 'muwa_agent_repository_hook', 'muwa_agent_action', 'muwa_agent_host'] as $table) {
            $this->connection->executeStatement('DROP TABLE IF EXISTS `' . $table . '`');
        }
        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS=1');
    }
}
