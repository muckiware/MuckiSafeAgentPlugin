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
namespace MuckiSafeAgentPlugin\tests\Integration\Subscriber;

require_once dirname(__DIR__, 3) . '/src/Migration/Migration1795132800CreateAgentSchema.php';
require_once dirname(__DIR__, 3) . '/src/Migration/Migration1795305600CreateSecretState.php';

use Doctrine\DBAL\Connection;
use MuckiSafeAgentPlugin\Migration\Migration1795132800CreateAgentSchema;
use MuckiSafeAgentPlugin\Migration\Migration1795305600CreateSecretState;
use MuckiSafeAgentPlugin\Service\Secret\AgentProvisioner;
use MuckiSafeAgentPlugin\Service\Secret\AgentSecretRotator;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Test\TestCaseBase\AdminApiTestBehaviour;
use Shopware\Core\Framework\Test\TestCaseBase\KernelLifecycleManager;
use Shopware\Core\Framework\Test\TestCaseBase\KernelTestBehaviour;
use Shopware\Core\Framework\Test\TestCaseHelper\TestBrowser;
use Shopware\Core\Framework\Util\Hasher;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\HttpFoundation\Response;

/**
 * Agent hosts and replay nonces are maintained exclusively by the plugin
 * services. Even a role holding the generic DAL write privileges must not
 * revive a revoked agent, swap its key id or forge nonces through the
 * generic admin API.
 */
final class AgentEntityWriteGuardHttpTest extends TestCase
{
    use KernelTestBehaviour;
    use AdminApiTestBehaviour;

    private const DAL_WRITE_PRIVILEGES = [
        'muwa_agent_host:read',
        'muwa_agent_host:create',
        'muwa_agent_host:update',
        'muwa_agent_host:delete',
        'muwa_agent_nonce:read',
        'muwa_agent_nonce:create',
        'muwa_agent_nonce:update',
        'muwa_agent_nonce:delete',
    ];

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

    public function testGenericApiCannotReviveRevokedAgentOrChangeItsIdentity(): void
    {
        $agent = $this->provisioner()->provision('revoked-host');
        $this->rotator()->revoke($agent['id']);
        $before = $this->hostRow($agent['id']);
        $browser = $this->browserWithPermissions(self::DAL_WRITE_PRIVILEGES);

        foreach ([['revokedAt' => null], ['enabled' => true, 'keyId' => bin2hex(random_bytes(16))], ['name' => 'renamed']] as $change) {
            $browser->jsonRequest('PATCH', '/api/muwa-agent-host/' . $agent['id'], $change);
            $this->assertRejected($browser, (string) json_encode($change));
        }

        self::assertSame($before, $this->hostRow($agent['id']));
    }

    public function testGenericApiCannotCreateOrDeleteAgentHosts(): void
    {
        $agent = $this->provisioner()->provision('kept-host');
        $browser = $this->browserWithPermissions(self::DAL_WRITE_PRIVILEGES);

        $browser->jsonRequest('POST', '/api/muwa-agent-host', [
            'id' => Uuid::randomHex(),
            'name' => 'forged-host',
            'enabled' => true,
            'keyId' => bin2hex(random_bytes(16)),
            'capabilities' => [],
        ]);
        $this->assertRejected($browser, 'create');

        $browser->jsonRequest('DELETE', '/api/muwa-agent-host/' . $agent['id']);
        $this->assertRejected($browser, 'delete');

        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM `muwa_agent_host`'));
    }

    public function testGenericApiCannotForgeOrDeleteReplayNonces(): void
    {
        $agent = $this->provisioner()->provision('nonce-host');
        $nonceId = Uuid::randomHex();
        $this->connection->executeStatement(
            'INSERT INTO `muwa_agent_nonce` (`id`, `agent_id`, `nonce`, `expires_at`, `created_at`) '
            . 'VALUES (UNHEX(:id), UNHEX(:agentId), :nonce, :expiresAt, :createdAt)',
            [
                'id' => $nonceId,
                'agentId' => $agent['id'],
                'nonce' => random_bytes(16),
                'expiresAt' => (new \DateTimeImmutable('+5 minutes'))->format('Y-m-d H:i:s.v'),
                'createdAt' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.v'),
            ]
        );
        $browser = $this->browserWithPermissions(self::DAL_WRITE_PRIVILEGES);

        $browser->jsonRequest('POST', '/api/muwa-agent-nonce', [
            'agentId' => $agent['id'],
            'nonce' => base64_encode(random_bytes(16)),
            'expiresAt' => (new \DateTimeImmutable('+5 minutes'))->format(\DATE_ATOM),
        ]);
        $this->assertRejected($browser, 'nonce create');

        $browser->jsonRequest('DELETE', '/api/muwa-agent-nonce/' . $nonceId);
        $this->assertRejected($browser, 'nonce delete');

        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM `muwa_agent_nonce`'));
    }

    public function testReadPrivilegeStillListsAgentsWithoutSecretMaterial(): void
    {
        $agent = $this->provisioner()->provision('listed-host');
        $browser = $this->browserWithPermissions(['muwa_agent_host:read']);

        $browser->jsonRequest('POST', '/api/search/muwa-agent-host', []);

        self::assertSame(Response::HTTP_OK, $browser->getResponse()->getStatusCode(), (string) $browser->getResponse()->getContent());
        $body = (string) $browser->getResponse()->getContent();
        self::assertStringContainsString($agent['id'], $body);
        self::assertStringNotContainsString($agent['secret'], $body);
        self::assertStringNotContainsString('ciphertext', strtolower($body));
    }

    private function assertRejected(TestBrowser $browser, string $case): void
    {
        $status = $browser->getResponse()->getStatusCode();
        self::assertGreaterThanOrEqual(Response::HTTP_BAD_REQUEST, $status, $case . ': ' . (string) $browser->getResponse()->getContent());
        self::assertLessThan(Response::HTTP_INTERNAL_SERVER_ERROR, $status, $case . ': ' . (string) $browser->getResponse()->getContent());
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

    private function provisioner(): AgentProvisioner
    {
        $provisioner = self::getContainer()->get(AgentProvisioner::class);
        self::assertInstanceOf(AgentProvisioner::class, $provisioner);

        return $provisioner;
    }

    private function rotator(): AgentSecretRotator
    {
        $rotator = self::getContainer()->get(AgentSecretRotator::class);
        self::assertInstanceOf(AgentSecretRotator::class, $rotator);

        return $rotator;
    }

    /** @return array<string, mixed> */
    private function hostRow(string $id): array
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM `muwa_agent_host` WHERE `id` = UNHEX(:id)', ['id' => $id]);
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
