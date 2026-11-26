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
require_once dirname(__DIR__, 4) . '/src/Migration/Migration1795478400CreateRollbackState.php';

use Doctrine\DBAL\Connection;
use MuckiSafeAgentPlugin\Migration\Migration1795132800CreateAgentSchema;
use MuckiSafeAgentPlugin\Migration\Migration1795478400CreateRollbackState;
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
 * Task 33: the rollback is operated through the admin API. The viewer role
 * reads the status (the runbook gate "no open jobs"); only the separate
 * `muwa_agent.rollback` privilege starts or stops it. Responses are never
 * cached.
 */
final class RollbackControllerHttpTest extends TestCase
{
    use KernelTestBehaviour;
    use AdminApiTestBehaviour;

    private const VIEWER = ['muwa_agent.viewer'];
    private const OPERATOR = ['muwa_agent.viewer', 'muwa_agent.rollback'];
    private const BASE = '/api/_action/muwa/agent/rollback';

    private Connection $connection;

    protected function setUp(): void
    {
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->dropSchema();
        (new Migration1795132800CreateAgentSchema())->update($connection);
        (new Migration1795478400CreateRollbackState())->update($connection);
    }

    protected function tearDown(): void
    {
        $this->dropSchema();
    }

    public function testViewerReadsTheStatusOfAnInactiveRollback(): void
    {
        $browser = $this->browserWithPermissions(self::VIEWER);

        $browser->jsonRequest('GET', self::BASE);

        $status = $this->decoded($browser, Response::HTTP_OK);
        self::assertFalse($status['active']);
        self::assertSame(['queued' => 0, 'claimed' => 0, 'running' => 0, 'cancel_requested' => 0], $status['openJobs']);
        self::assertSame(0, $status['unknownJobs']);
        self::assertTrue($status['readyForHookRemoval']);
        self::assertStringContainsString('no-store', (string) $browser->getResponse()->headers->get('Cache-Control'));
    }

    public function testStartDrainsAndTheGateOpensOnlyWhenNoJobIsOpen(): void
    {
        $queued = $this->insertJob('queued');
        $running = $this->insertJob('running');
        $this->insertJob('unknown');
        $browser = $this->browserWithPermissions(self::OPERATOR);

        $browser->jsonRequest('POST', self::BASE . '/start');

        $status = $this->decoded($browser, Response::HTTP_OK);
        self::assertTrue($status['active']);
        self::assertNotNull($status['startedAt']);
        self::assertSame(['queued' => 0, 'claimed' => 0, 'running' => 0, 'cancel_requested' => 1], $status['openJobs']);
        self::assertSame(1, $status['unknownJobs']);
        self::assertFalse($status['readyForHookRemoval']);
        self::assertSame('timed_out', $this->jobStatus($queued));
        self::assertSame('cancel_requested', $this->jobStatus($running));

        // The agent acknowledges the stop with a timeout completion.
        $this->connection->executeStatement(
            'UPDATE `muwa_agent_job` SET `status` = \'timed_out\' WHERE `id` = UNHEX(:id)',
            ['id' => $running]
        );
        $browser->jsonRequest('GET', self::BASE);
        $status = $this->decoded($browser, Response::HTTP_OK);
        self::assertSame(['queued' => 0, 'claimed' => 0, 'running' => 0, 'cancel_requested' => 0], $status['openJobs']);
        self::assertTrue($status['readyForHookRemoval']);
    }

    public function testStopEndsTheRollback(): void
    {
        $browser = $this->browserWithPermissions(self::OPERATOR);
        $browser->jsonRequest('POST', self::BASE . '/start');
        $this->decoded($browser, Response::HTTP_OK);

        $browser->jsonRequest('POST', self::BASE . '/stop');

        $status = $this->decoded($browser, Response::HTTP_OK);
        self::assertFalse($status['active']);
        self::assertNotNull($status['stoppedAt']);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT `active` FROM `muwa_agent_rollback`'));
    }

    public function testOnlyTheRollbackPrivilegeStartsOrStops(): void
    {
        $queued = $this->insertJob('queued');

        foreach ([self::VIEWER, ['muwa_agent.editor'], ['muwa_agent.provision', 'muwa_agent.revoke'], ['muwa_agent.executor']] as $permissions) {
            $browser = $this->browserWithPermissions($permissions);
            foreach (['start', 'stop'] as $action) {
                $browser->jsonRequest('POST', self::BASE . '/' . $action);
                self::assertContains(
                    $browser->getResponse()->getStatusCode(),
                    [Response::HTTP_UNAUTHORIZED, Response::HTTP_FORBIDDEN],
                    implode(',', $permissions) . ' ' . $action
                );
            }
        }
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT `active` FROM `muwa_agent_rollback`'));
        self::assertSame('queued', $this->jobStatus($queued));
    }

    public function testStatusNeedsTheViewerRole(): void
    {
        $browser = $this->browserWithPermissions(['muwa_agent_job:read']);

        $browser->jsonRequest('GET', self::BASE);

        self::assertContains($browser->getResponse()->getStatusCode(), [Response::HTTP_UNAUTHORIZED, Response::HTTP_FORBIDDEN]);
    }

    private function jobStatus(string $jobId): string
    {
        return (string) $this->connection->fetchOne(
            'SELECT `status` FROM `muwa_agent_job` WHERE `id` = UNHEX(:id)',
            ['id' => $jobId]
        );
    }

    /** @return array<string, mixed> */
    private function decoded(TestBrowser $browser, int $expectedStatus): array
    {
        $content = (string) $browser->getResponse()->getContent();
        self::assertSame($expectedStatus, $browser->getResponse()->getStatusCode(), $content);
        $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function insertJob(string $status): string
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $jobId = Uuid::randomHex();
        $hostId = Uuid::randomHex();
        $this->connection->insert('muwa_agent_host', [
            'id' => Uuid::fromHexToBytes($hostId),
            'name' => 'rollback-http',
            'enabled' => 1,
            'key_id' => 'rollback-' . $hostId,
            'secret_ciphertext' => random_bytes(32),
            'secret_nonce' => random_bytes(24),
            'secret_key_version' => 1,
            'capabilities' => '[]',
            'created_at' => $this->storage($now),
        ]);
        $runId = Uuid::randomBytes();
        $this->connection->insert('muwa_agent_backup_run', [
            'id' => $runId,
            'facility_repository_id' => Uuid::randomBytes(),
            'source' => 'facility',
            'idempotency_key' => Uuid::randomHex(),
            'status' => 'failed',
            'started_at' => $this->storage($now),
            'created_at' => $this->storage($now),
        ]);
        $actionId = Uuid::randomBytes();
        $this->connection->insert('muwa_agent_action', [
            'id' => $actionId,
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
            'created_at' => $this->storage($now),
        ]);
        $deadline = $now->modify('+10 minutes');
        $this->connection->insert('muwa_agent_job', [
            'id' => Uuid::fromHexToBytes($jobId),
            'backup_run_id' => $runId,
            'agent_action_id' => $actionId,
            'facility_repository_id' => Uuid::randomBytes(),
            'phase' => 'pre',
            'action_definition' => '{"version":1,"steps":[]}',
            'action_hash' => hash('sha256', '{"version":1,"steps":[]}', true),
            'payload' => '{}',
            'idempotency_class' => 'safe',
            'idempotency_key' => Uuid::randomHex(),
            'status' => $status,
            'target_agent_id' => Uuid::fromHexToBytes($hostId),
            'claim_token_hash' => $status === 'queued' ? null : hash('sha256', 'token', true),
            'lease_version' => 1,
            'attempt' => $status === 'queued' ? 0 : 1,
            'max_attempts' => 3,
            'available_at' => $this->storage($now),
            'phase_deadline' => $this->storage($deadline),
            'execution_deadline' => $this->storage($deadline->modify('-15 seconds')),
            'stop_deadline' => $this->storage($deadline->modify('-10 seconds')),
            'created_at' => $this->storage($now),
        ]);

        return $jobId;
    }

    /** @param list<string> $permissions */
    private function browserWithPermissions(array $permissions): TestBrowser
    {
        $browser = KernelLifecycleManager::createBrowser(self::getKernel(), false);
        $browser->followRedirects();
        $browser->setServerParameters(['HTTP_ACCEPT' => ['application/json']]);
        // Deterministic e-mail per permission set; this test runs without a transaction (DDL in setUp).
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
        foreach (['muwa_agent_rollback', 'muwa_agent_nonce', 'muwa_agent_job', 'muwa_agent_backup_run', 'muwa_agent_repository_hook', 'muwa_agent_action', 'muwa_agent_host'] as $table) {
            $this->connection->executeStatement('DROP TABLE IF EXISTS `' . $table . '`');
        }
        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS=1');
    }
}
