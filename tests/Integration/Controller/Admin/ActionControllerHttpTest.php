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
require_once dirname(__DIR__, 4) . '/src/Migration/Migration1795305600CreateSecretState.php';

use Doctrine\DBAL\Connection;
use MuckiSafeAgentPlugin\Migration\Migration1795132800CreateAgentSchema;
use MuckiSafeAgentPlugin\Migration\Migration1795305600CreateSecretState;
use MuckiSafeAgentPlugin\Service\Action\CanonicalJson;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Test\TestCaseBase\AdminApiTestBehaviour;
use Shopware\Core\Framework\Test\TestCaseBase\KernelLifecycleManager;
use Shopware\Core\Framework\Test\TestCaseBase\KernelTestBehaviour;
use Shopware\Core\Framework\Test\TestCaseHelper\TestBrowser;
use Shopware\Core\Framework\Util\Hasher;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\HttpFoundation\Response;

/**
 * The action editor writes exclusively through the `muwa_agent.editor`
 * protected action routes: the server validates the DSL, stores the canonical
 * definition with its hash and derives the required capabilities. The generic
 * DAL API stays read-only for actions.
 */
final class ActionControllerHttpTest extends TestCase
{
    use KernelTestBehaviour;
    use AdminApiTestBehaviour;

    private const SAVE_URL = '/api/_action/muwa/agent/action';
    private const VALIDATE_URL = '/api/_action/muwa/agent/action/validate';
    private const EDITOR = ['muwa_agent.editor', 'muwa_agent_action:read'];

    private Connection $connection;

    protected function setUp(): void
    {
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->dropSchema();
        (new Migration1795132800CreateAgentSchema())->update($this->connection);
        (new Migration1795305600CreateSecretState())->update($this->connection);
    }

    protected function tearDown(): void
    {
        $this->dropSchema();
    }

    public function testEditorCreatesActionWithCanonicalDefinitionHashAndDerivedCapabilities(): void
    {
        $definition = $this->exampleDefinition('bitwarden');
        $browser = $this->browserWithPermissions(self::EDITOR);

        $browser->jsonRequest('POST', self::SAVE_URL, $this->payload(['definition' => $definition]));

        $body = $this->decoded($browser, Response::HTTP_OK);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', (string) $body['id']);
        self::assertStringContainsString('no-store', (string) $browser->getResponse()->headers->get('Cache-Control'));
        $row = $this->actionRow((string) $body['id']);
        $canonical = CanonicalJson::encode(json_decode($definition, false, 512, JSON_THROW_ON_ERROR));
        self::assertSame($canonical, $row['definition']);
        self::assertSame(hash('sha256', $canonical, true), $row['definition_hash']);
        self::assertSame(
            ['docker.container.exec', 'filesystem.acl.read', 'filesystem.assert-readable'],
            json_decode((string) $row['required_capabilities'], true, 512, JSON_THROW_ON_ERROR)
        );
        self::assertSame('bitwarden-backup', $row['technical_name']);
        self::assertSame('safe', $row['idempotency_class']);
        self::assertSame('safe_requeue', $row['retry_policy']);
        self::assertSame(300, (int) $row['default_timeout_seconds']);
        self::assertSame(600, (int) $row['max_timeout_seconds']);
        self::assertNull($row['parameter_schema']);
    }

    public function testInvalidDefinitionReturnsSchemaPathAndWritesNothing(): void
    {
        $browser = $this->browserWithPermissions(self::EDITOR);

        $cases = [
            '{"version":1,"steps":[{"primitive":"shell.exec","command":"rm -rf /"}]}' => '$.steps[0].primitive is unknown.',
            '{"version":2,"steps":[]}' => '$.version must be integer 1.',
            '{"version":1,"steps":[' => 'Invalid JSON value.',
        ];
        foreach ($cases as $definition => $detail) {
            $browser->jsonRequest('POST', self::SAVE_URL, $this->payload(['definition' => $definition]));
            $body = $this->decoded($browser, Response::HTTP_UNPROCESSABLE_ENTITY);
            self::assertSame('invalid_definition', $body['error'], $definition);
            self::assertSame($detail, $body['detail'], $definition);
        }

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM `muwa_agent_action`'));
    }

    public function testInvalidMetadataNamesTheAffectedFields(): void
    {
        $browser = $this->browserWithPermissions(self::EDITOR);

        $cases = [
            'technicalName' => ['technicalName' => 'Bitwarden Backup'],
            'label' => ['label' => '   '],
            'defaultTimeoutSeconds' => ['defaultTimeoutSeconds' => 900, 'maxTimeoutSeconds' => 600],
            'maxTimeoutSeconds' => ['maxTimeoutSeconds' => 1801],
            'idempotencyClass' => ['idempotencyClass' => 'maybe'],
            'enabled' => ['enabled' => 'yes'],
        ];
        foreach ($cases as $field => $change) {
            $browser->jsonRequest('POST', self::SAVE_URL, $this->payload($change));
            $body = $this->decoded($browser, Response::HTTP_BAD_REQUEST);
            self::assertSame('invalid_request', $body['error'], $field);
            self::assertContains($field, $body['fields'], $field);
        }

        $browser->jsonRequest('POST', self::SAVE_URL, $this->payload(['requiredCapabilities' => ['service.control']]));
        self::assertSame('invalid_request', $this->decoded($browser, Response::HTTP_BAD_REQUEST)['error']);

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM `muwa_agent_action`'));
    }

    public function testDuplicateTechnicalNameIsAConflict(): void
    {
        $browser = $this->browserWithPermissions(self::EDITOR);
        $browser->jsonRequest('POST', self::SAVE_URL, $this->payload());
        $this->decoded($browser, Response::HTTP_OK);

        $browser->jsonRequest('POST', self::SAVE_URL, $this->payload(['label' => 'Other']));

        self::assertSame('technical_name_taken', $this->decoded($browser, Response::HTTP_CONFLICT)['error']);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM `muwa_agent_action`'));
    }

    public function testUpdateReplacesDefinitionAndHashButKeepsTechnicalName(): void
    {
        $browser = $this->browserWithPermissions(self::EDITOR);
        $browser->jsonRequest('POST', self::SAVE_URL, $this->payload());
        $id = (string) $this->decoded($browser, Response::HTTP_OK)['id'];
        $newDefinition = $this->exampleDefinition('jellyfin');

        $browser->jsonRequest('POST', self::SAVE_URL, $this->payload([
            'id' => $id,
            'label' => 'Jellyfin',
            'definition' => $newDefinition,
            'idempotencyClass' => 'non_retryable',
            'enabled' => false,
        ]));
        self::assertSame($id, $this->decoded($browser, Response::HTTP_OK)['id']);

        $row = $this->actionRow($id);
        $canonical = CanonicalJson::encode(json_decode($newDefinition, false, 512, JSON_THROW_ON_ERROR));
        self::assertSame('Jellyfin', $row['label']);
        self::assertSame($canonical, $row['definition']);
        self::assertSame(hash('sha256', $canonical, true), $row['definition_hash']);
        self::assertSame('non_retryable', $row['idempotency_class']);
        self::assertSame('none', $row['retry_policy']);
        self::assertSame(0, (int) $row['enabled']);

        $browser->jsonRequest('POST', self::SAVE_URL, $this->payload(['id' => $id, 'technicalName' => 'renamed-action']));
        $body = $this->decoded($browser, Response::HTTP_BAD_REQUEST);
        self::assertSame(['technicalName'], $body['fields']);

        $browser->jsonRequest('POST', self::SAVE_URL, $this->payload(['id' => Uuid::randomHex()]));
        self::assertSame('action_not_found', $this->decoded($browser, Response::HTTP_NOT_FOUND)['error']);
    }

    public function testValidateReturnsCanonicalHashAndCapabilitiesWithoutWriting(): void
    {
        $definition = $this->exampleDefinition('paperless');
        $browser = $this->browserWithPermissions(self::EDITOR);

        $browser->jsonRequest('POST', self::VALIDATE_URL, ['definition' => $definition]);

        $body = $this->decoded($browser, Response::HTTP_OK);
        $canonical = CanonicalJson::encode(json_decode($definition, false, 512, JSON_THROW_ON_ERROR));
        self::assertSame($canonical, $body['canonical']);
        self::assertSame(hash('sha256', $canonical), $body['hash']);
        self::assertIsArray($body['requiredCapabilities']);
        self::assertNotSame([], $body['requiredCapabilities']);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM `muwa_agent_action`'));

        $browser->jsonRequest('POST', self::VALIDATE_URL, ['definition' => '{"version":1,"steps":[{"primitive":"shell.exec"}]}']);
        self::assertSame('$.steps[0].primitive is unknown.', $this->decoded($browser, Response::HTTP_UNPROCESSABLE_ENTITY)['detail']);
    }

    public function testRolesWithoutEditorPrivilegeCannotSaveOrValidate(): void
    {
        foreach ([['muwa_agent.viewer', 'muwa_agent_action:read'], ['muwa_agent.provisioner', 'muwa_agent.provision']] as $permissions) {
            $browser = $this->browserWithPermissions($permissions);
            $browser->jsonRequest('POST', self::SAVE_URL, $this->payload());
            self::assertContains($browser->getResponse()->getStatusCode(), [Response::HTTP_UNAUTHORIZED, Response::HTTP_FORBIDDEN]);
            $browser->jsonRequest('POST', self::VALIDATE_URL, ['definition' => $this->exampleDefinition('bitwarden')]);
            self::assertContains($browser->getResponse()->getStatusCode(), [Response::HTTP_UNAUTHORIZED, Response::HTTP_FORBIDDEN]);
        }

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM `muwa_agent_action`'));
    }

    public function testViewerCanReadSavedActionsThroughTheGenericApi(): void
    {
        $browser = $this->browserWithPermissions(self::EDITOR);
        $browser->jsonRequest('POST', self::SAVE_URL, $this->payload());
        $id = (string) $this->decoded($browser, Response::HTTP_OK)['id'];
        $viewer = $this->browserWithPermissions(['muwa_agent.viewer', 'muwa_agent_action:read']);

        $viewer->jsonRequest('POST', '/api/search/muwa-agent-action', ['ids' => [$id]]);

        $body = $this->decoded($viewer, Response::HTTP_OK);
        $attributes = $body['data'][0]['attributes'];
        self::assertSame('bitwarden-backup', $attributes['technicalName']);
        self::assertSame(['docker.container.exec', 'filesystem.acl.read', 'filesystem.assert-readable'], $attributes['requiredCapabilities']);
        self::assertArrayNotHasKey('definitionHash', $attributes);
    }

    public function testGenericApiCannotWriteActionsEvenWithDalPrivileges(): void
    {
        $browser = $this->browserWithPermissions(self::EDITOR);
        $browser->jsonRequest('POST', self::SAVE_URL, $this->payload());
        $id = (string) $this->decoded($browser, Response::HTTP_OK)['id'];
        $before = $this->actionRow($id);
        $dal = $this->browserWithPermissions([
            'muwa_agent_action:read', 'muwa_agent_action:create', 'muwa_agent_action:update', 'muwa_agent_action:delete',
        ]);

        $dal->jsonRequest('PATCH', '/api/muwa-agent-action/' . $id, ['definition' => '{"version":1,"steps":[]}', 'enabled' => false]);
        $this->assertRejected($dal, 'update');
        $dal->jsonRequest('POST', '/api/muwa-agent-action', [
            'technicalName' => 'forged', 'label' => 'Forged', 'enabled' => true, 'definition' => '{}',
            'definitionHash' => str_repeat('0', 64), 'requiredCapabilities' => [], 'defaultTimeoutSeconds' => 60,
            'maxTimeoutSeconds' => 60, 'retryPolicy' => 'none', 'idempotencyClass' => 'safe',
        ]);
        $this->assertRejected($dal, 'create');
        $dal->jsonRequest('DELETE', '/api/muwa-agent-action/' . $id);
        $this->assertRejected($dal, 'delete');

        self::assertSame($before, $this->actionRow($id));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM `muwa_agent_action`'));
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'technicalName' => 'bitwarden-backup',
            'label' => 'Bitwarden backup',
            'enabled' => true,
            'definition' => $this->exampleDefinition('bitwarden'),
            'defaultTimeoutSeconds' => 300,
            'maxTimeoutSeconds' => 600,
            'idempotencyClass' => 'safe',
        ], $overrides);
    }

    private function exampleDefinition(string $name): string
    {
        $json = file_get_contents(dirname(__DIR__, 4) . '/examples/actions/' . $name . '.json');
        self::assertIsString($json);

        return $json;
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

    /** @return array<string, mixed> */
    private function actionRow(string $id): array
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM `muwa_agent_action` WHERE `id` = UNHEX(:id)', ['id' => $id]);
        self::assertIsArray($row);

        return $row;
    }

    private function dropSchema(): void
    {
        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS=0');
        foreach (['muwa_agent_nonce', 'muwa_agent_job', 'muwa_agent_backup_run', 'muwa_agent_repository_hook', 'muwa_agent_action', 'muwa_agent_host', Migration1795305600CreateSecretState::TABLE_NAME] as $table) {
            $this->connection->executeStatement('DROP TABLE IF EXISTS `' . $table . '`');
        }
        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS=1');
    }
}
