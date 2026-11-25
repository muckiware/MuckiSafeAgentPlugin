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
 * Task 15 RED: the admin API for hook configuration requires the
 * `muwa_agent.editor` privilege, returns the affected Facility fields on a
 * legacy conflict and never writes on rejection.
 */
final class RepositoryHookControllerHttpTest extends TestCase
{
    use KernelTestBehaviour;
    use AdminApiTestBehaviour;

    private const SAVE_URL = '/api/_action/muwa/agent/repository-hook';

    private Connection $connection;
    /** @var list<string> */
    private array $facilityRepositoryIds = [];
    private string $hostId;
    private string $actionId;

    protected function setUp(): void
    {
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->dropSchema();
        (new Migration1795132800CreateAgentSchema())->update($connection);
        $this->hostId = $this->insertHost();
        $this->actionId = $this->insertAction();
    }

    protected function tearDown(): void
    {
        foreach ($this->facilityRepositoryIds as $id) {
            $this->connection->executeStatement(
                'DELETE FROM `muwa_backup_repository` WHERE `id` = UNHEX(:id)',
                ['id' => $id]
            );
        }
        $this->dropSchema();
    }

    public function testUserWithoutEditorPrivilegeCannotSaveOrDelete(): void
    {
        $browser = $this->browserWithPermissions(['muwa_agent.viewer']);

        $browser->jsonRequest('POST', self::SAVE_URL, $this->payload($this->insertFacilityRepository('none')));
        self::assertContains($browser->getResponse()->getStatusCode(), [Response::HTTP_UNAUTHORIZED, Response::HTTP_FORBIDDEN]);
        $browser->jsonRequest('DELETE', self::SAVE_URL . '/' . Uuid::randomHex());
        self::assertContains($browser->getResponse()->getStatusCode(), [Response::HTTP_UNAUTHORIZED, Response::HTTP_FORBIDDEN]);

        self::assertSame(0, $this->hookCount());
    }

    public function testEditorSavesAndDeletesAHook(): void
    {
        $browser = $this->browserWithPermissions(['muwa_agent.editor']);

        $browser->jsonRequest('POST', self::SAVE_URL, $this->payload($this->insertFacilityRepository('none')));
        $payload = $this->json($browser, Response::HTTP_OK);
        self::assertIsString($payload['id']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/D', $payload['id']);
        self::assertSame(1, $this->hookCount());
        self::assertStringContainsString('no-store', (string) $browser->getResponse()->headers->get('Cache-Control'));

        $browser->jsonRequest('DELETE', self::SAVE_URL . '/' . $payload['id']);
        self::assertSame(['deleted' => true], $this->json($browser, Response::HTTP_OK));
        self::assertSame(0, $this->hookCount());

        $browser->jsonRequest('DELETE', self::SAVE_URL . '/' . $payload['id']);
        self::assertSame(['error' => 'hook_not_found'], $this->json($browser, Response::HTTP_NOT_FOUND));
    }

    public function testGenericApiCannotCreateOrDeleteHooksEvenWithDalPrivileges(): void
    {
        $editor = $this->browserWithPermissions(['muwa_agent.editor']);
        $repositoryId = $this->insertFacilityRepository('none');
        $editor->jsonRequest('POST', self::SAVE_URL, $this->payload($repositoryId));
        $hookId = (string) $this->json($editor, Response::HTTP_OK)['id'];
        $dal = $this->browserWithPermissions([
            'muwa_agent_repository_hook:read',
            'muwa_agent_repository_hook:create',
            'muwa_agent_repository_hook:update',
            'muwa_agent_repository_hook:delete',
        ]);

        $dal->jsonRequest('DELETE', '/api/muwa-agent-repository-hook/' . $hookId);
        self::assertContains($dal->getResponse()->getStatusCode(), [Response::HTTP_BAD_REQUEST, Response::HTTP_FORBIDDEN], (string) $dal->getResponse()->getContent());
        $dal->jsonRequest('POST', '/api/muwa-agent-repository-hook', array_merge($this->payload($repositoryId), ['phase' => 'post', 'parameters' => '{}']));
        self::assertContains($dal->getResponse()->getStatusCode(), [Response::HTTP_BAD_REQUEST, Response::HTTP_FORBIDDEN], (string) $dal->getResponse()->getContent());

        self::assertSame(1, $this->hookCount());
    }

    public function testLegacyConflictReturnsTheAffectedFacilityFields(): void
    {
        $browser = $this->browserWithPermissions(['muwa_agent.editor']);

        $browser->jsonRequest('POST', self::SAVE_URL, $this->payload($this->insertFacilityRepository('batch')));

        self::assertSame(
            ['error' => 'legacy_hook_conflict', 'fields' => ['preBackupCommandType']],
            $this->json($browser, Response::HTTP_CONFLICT)
        );
        self::assertSame(0, $this->hookCount());
    }

    public function testRejectionsMapToStableErrorCodes(): void
    {
        $browser = $this->browserWithPermissions(['muwa_agent.editor']);
        $repositoryId = $this->insertFacilityRepository('none');

        $cases = [
            [array_merge($this->payload($repositoryId), ['unexpected' => 1]), Response::HTTP_BAD_REQUEST, 'invalid_request'],
            [array_merge($this->payload($repositoryId), ['timeoutSeconds' => 5]), Response::HTTP_UNPROCESSABLE_ENTITY, 'timeout_out_of_range'],
            [$this->payload(Uuid::randomHex()), Response::HTTP_NOT_FOUND, 'facility_repository_not_found'],
            [array_merge($this->payload($repositoryId), ['agentHostId' => Uuid::randomHex()]), Response::HTTP_UNPROCESSABLE_ENTITY, 'agent_unavailable'],
            [array_merge($this->payload($repositoryId), ['agentActionId' => Uuid::randomHex()]), Response::HTTP_UNPROCESSABLE_ENTITY, 'action_unavailable'],
        ];
        foreach ($cases as [$payload, $status, $error]) {
            $browser->jsonRequest('POST', self::SAVE_URL, $payload);
            self::assertSame(['error' => $error], $this->json($browser, $status));
        }
        // Shopware's JsonRequestTransformerListener rejects malformed JSON before the controller runs.
        $browser->request('POST', self::SAVE_URL, [], [], ['CONTENT_TYPE' => 'application/json'], '{not json');
        self::assertSame(Response::HTTP_BAD_REQUEST, $browser->getResponse()->getStatusCode());

        self::assertSame(0, $this->hookCount());
    }

    /** @return array<string, mixed> */
    private function payload(string $repositoryId): array
    {
        return [
            'facilityRepositoryId' => $repositoryId,
            'phase' => 'pre',
            'agentHostId' => $this->hostId,
            'agentActionId' => $this->actionId,
            'parameters' => new \stdClass(),
        ];
    }

    /** @return array<string, mixed> */
    private function json(TestBrowser $browser, int $status): array
    {
        $content = (string) $browser->getResponse()->getContent();
        self::assertSame($status, $browser->getResponse()->getStatusCode(), $content);
        $payload = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        return $payload;
    }

    /** @param list<string> $permissions */
    private function browserWithPermissions(array $permissions): TestBrowser
    {
        $browser = KernelLifecycleManager::createBrowser(self::getKernel(), false);
        $browser->followRedirects();
        $browser->setServerParameters(['HTTP_ACCEPT' => ['application/vnd.api+json,application/json']]);
        // Deterministic e-mail per permission set; this test runs without a
        // transaction (setUp executes DDL), so remove a previous run's user.
        $this->connection->executeStatement(
            'DELETE FROM `user` WHERE `email` = :email',
            ['email' => Hasher::hash($permissions) . '@example.com']
        );
        $this->authorizeBrowser($browser, [], $permissions);

        return $browser;
    }

    private function hookCount(): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM `muwa_agent_repository_hook`');
    }

    private function insertFacilityRepository(string $preType): string
    {
        $id = Uuid::randomHex();
        $this->connection->insert('muwa_backup_repository', [
            'id' => Uuid::fromHexToBytes($id),
            'name' => 'hook-http-' . $id,
            'type' => 'local',
            'repository_path' => '/tmp/hook-http-' . $id,
            'repository_password' => 'unused',
            'pre_backup_command_type' => $preType,
            'pre_backup_command_arguments' => '',
            'post_backup_command_type' => 'none',
            'post_backup_command_arguments' => '',
            'created_at' => (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
        ]);
        $this->facilityRepositoryIds[] = $id;

        return $id;
    }

    private function insertHost(): string
    {
        $id = Uuid::randomHex();
        $this->connection->insert('muwa_agent_host', [
            'id' => Uuid::fromHexToBytes($id),
            'name' => 'hook-http-host',
            'enabled' => 1,
            'key_id' => 'hook-http-' . $id,
            'secret_ciphertext' => random_bytes(32),
            'secret_nonce' => random_bytes(24),
            'secret_key_version' => 1,
            'capabilities' => '[]',
            'created_at' => (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
        ]);

        return $id;
    }

    private function insertAction(): string
    {
        $id = Uuid::randomHex();
        $this->connection->insert('muwa_agent_action', [
            'id' => Uuid::fromHexToBytes($id),
            'technical_name' => 'hook-http-action-' . $id,
            'label' => 'Hook action',
            'enabled' => 1,
            'definition' => '{"version":1,"steps":[]}',
            'definition_hash' => hash('sha256', '{"version":1,"steps":[]}', true),
            'required_capabilities' => '[]',
            'default_timeout_seconds' => 600,
            'max_timeout_seconds' => 1800,
            'retry_policy' => 'none',
            'idempotency_class' => 'safe',
            'created_at' => (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
        ]);

        return $id;
    }

    private function dropSchema(): void
    {
        $this->connection->executeStatement(
            'DROP TABLE IF EXISTS `muwa_agent_nonce`, `muwa_agent_job`, `muwa_agent_backup_run`, '
            . '`muwa_agent_repository_hook`, `muwa_agent_action`, `muwa_agent_host`'
        );
    }
}
