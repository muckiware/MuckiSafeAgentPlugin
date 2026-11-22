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
use MuckiSafeAgentPlugin\Service\Secret\AgentProvisioner;
use MuckiSafeAgentPlugin\Service\Secret\MasterKeyReencryptor;
use MuckiSafeAgentPlugin\Service\Secret\SecretWriteGuard;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Test\TestCaseBase\AdminApiTestBehaviour;
use Shopware\Core\Framework\Test\TestCaseBase\KernelLifecycleManager;
use Shopware\Core\Framework\Test\TestCaseBase\KernelTestBehaviour;
use Shopware\Core\Framework\Test\TestCaseHelper\TestBrowser;
use Shopware\Core\Framework\Util\Hasher;
use Symfony\Component\HttpFoundation\Response;

final class AgentProvisionControllerHttpTest extends TestCase
{
    use KernelTestBehaviour;
    use AdminApiTestBehaviour;

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

    public function testAllActionsRejectUserWithoutProvisionerAndHaveNoDatabaseEffect(): void
    {
        $provisioner = self::getContainer()->get(AgentProvisioner::class);
        self::assertInstanceOf(AgentProvisioner::class, $provisioner);
        $agent = $provisioner->provision('protected-host');
        $before = $this->row($agent['id']);
        $browser = $this->browserWithPermissions(['muwa_agent.viewer']);

        $requests = [
            ['POST', '/api/_action/muwa/agent/provision', ['name' => 'forbidden-host']],
            ['POST', '/api/_action/muwa/agent/' . $agent['id'] . '/rotate', []],
            ['POST', '/api/_action/muwa/agent/' . $agent['id'] . '/revoke', []],
            ['POST', '/api/_action/muwa/agent/master-key/reencrypt', ['fromVersion' => 1]],
        ];
        foreach ($requests as [$method, $url, $payload]) {
            $browser->jsonRequest($method, $url, $payload);
            self::assertContains(
                $browser->getResponse()->getStatusCode(),
                [Response::HTTP_UNAUTHORIZED, Response::HTTP_FORBIDDEN],
                $url . ': ' . (string) $browser->getResponse()->getContent()
            );
        }

        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM `muwa_agent_host`'));
        $after = $this->row($agent['id']);
        self::assertSame($before['secret_ciphertext'], $after['secret_ciphertext']);
        self::assertSame($before['secret_nonce'], $after['secret_nonce']);
        self::assertSame($before['secret_key_version'], $after['secret_key_version']);
        self::assertNull($after['revoked_at']);
    }

    public function testSystemConfigOnlyUserCannotInfluenceOrAccessInternalSecretState(): void
    {
        $guard = new SecretWriteGuard($this->connection);
        $guard->advanceFence(3);
        $guard->markSecretMutation();
        $browser = $this->browserWithPermissions([
            'system_config:read',
            'system_config:create',
            'system_config:update',
            'system_config:delete',
        ]);

        $browser->jsonRequest('POST', '/api/_action/system-config', [
            SecretWriteGuard::FENCE_CONFIG_KEY => 1,
            SecretWriteGuard::MUTATION_CONFIG_KEY => 0,
            MasterKeyReencryptor::READBACK_CONFIG_PREFIX . '1' => [
                'activeVersion' => 3,
                'generation' => 1,
                'cursor' => str_repeat('f', 32),
            ],
        ]);
        self::assertContains($browser->getResponse()->getStatusCode(), [Response::HTTP_OK, Response::HTTP_NO_CONTENT], (string) $browser->getResponse()->getContent());
        self::assertSame(3, $guard->fenceVersion());
        self::assertSame(1, $guard->mutationGeneration());

        $state = $this->connection->fetchAssociative('SELECT * FROM `' . Migration1795305600CreateSecretState::TABLE_NAME . '`');
        self::assertIsArray($state);
        self::assertNull($state['readback_old_version']);
        self::assertNull($state['readback_cursor']);

        $browser->jsonRequest('GET', '/api/muwa-agent-secret-state');
        self::assertSame(Response::HTTP_NOT_FOUND, $browser->getResponse()->getStatusCode());

        $browser->jsonRequest('POST', '/api/_action/muwa/agent/master-key/reencrypt', ['fromVersion' => 1]);
        self::assertContains($browser->getResponse()->getStatusCode(), [Response::HTTP_UNAUTHORIZED, Response::HTTP_FORBIDDEN]);
    }

    public function testEachActionRejectsAnotherActionsAcl(): void
    {
        $provisioner = self::getContainer()->get(AgentProvisioner::class);
        self::assertInstanceOf(AgentProvisioner::class, $provisioner);
        $agent = $provisioner->provision('acl-host');
        $requests = [
            ['muwa_agent.rotate', '/api/_action/muwa/agent/provision', ['name' => 'forbidden-host']],
            ['muwa_agent.revoke', '/api/_action/muwa/agent/' . $agent['id'] . '/rotate', []],
            ['muwa_agent.master_key_reencrypt', '/api/_action/muwa/agent/' . $agent['id'] . '/revoke', []],
            ['muwa_agent.provision', '/api/_action/muwa/agent/master-key/reencrypt', ['fromVersion' => 1]],
        ];
        foreach ($requests as $index => [$permission, $url, $payload]) {
            $browser = $this->browserWithPermissions([$permission, 'muwa_agent.test_cross_' . $index]);
            $browser->jsonRequest('POST', $url, $payload);
            self::assertContains($browser->getResponse()->getStatusCode(), [Response::HTTP_UNAUTHORIZED, Response::HTTP_FORBIDDEN], $url);
        }
    }

    public function testDedicatedActionAclsAllowOnlyTheirActionsAndSecretsAppearOnlyInImmediateResponses(): void
    {
        $browser = $this->browserWithPermissions(['muwa_agent.provision', 'muwa_agent_host:read', 'muwa_agent.test_positive_provision']);
        $browser->jsonRequest('POST', '/api/_action/muwa/agent/provision', ['name' => 'http-host']);
        $provisionResponse = $this->successfulPayload($browser);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $provisionResponse['secret']);
        self::assertStringContainsString('no-store', (string) $browser->getResponse()->headers->get('Cache-Control'));
        $id = (string) $provisionResponse['id'];

        $browser->jsonRequest('GET', '/api/muwa-agent-host/' . $id);
        self::assertSame(Response::HTTP_OK, $browser->getResponse()->getStatusCode(), (string) $browser->getResponse()->getContent());
        $dalBody = strtolower((string) $browser->getResponse()->getContent());
        foreach (['secret', 'ciphertext', 'nonce', 'key_version', 'keyring', strtolower((string) $provisionResponse['secret'])] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $dalBody);
        }

        $browser = $this->browserWithPermissions(['muwa_agent.rotate', 'muwa_agent.test_positive_rotate']);
        $browser->jsonRequest('POST', '/api/_action/muwa/agent/' . $id . '/rotate', []);
        $rotateResponse = $this->successfulPayload($browser);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $rotateResponse['secret']);
        self::assertNotSame($provisionResponse['secret'], $rotateResponse['secret']);
        self::assertStringContainsString('no-store', (string) $browser->getResponse()->headers->get('Cache-Control'));

        $browser = $this->browserWithPermissions(['muwa_agent.revoke', 'muwa_agent.test_positive_revoke']);
        $browser->jsonRequest('POST', '/api/_action/muwa/agent/' . $id . '/revoke', []);
        $revokeResponse = $this->successfulPayload($browser);
        self::assertSame(['revoked' => true], $revokeResponse);
        self::assertArrayNotHasKey('secret', $revokeResponse);

        $browser = $this->browserWithPermissions(['muwa_agent.master_key_reencrypt', 'muwa_agent.test_positive_reencrypt']);
        $browser->jsonRequest('POST', '/api/_action/muwa/agent/master-key/reencrypt', ['fromVersion' => 1, 'batchSize' => 10]);
        $reencryptResponse = $this->successfulPayload($browser);
        self::assertArrayNotHasKey('secret', $reencryptResponse);
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
        try {
            $this->authorizeBrowser($browser, [], $permissions);
        } catch (\Throwable $exception) {
            throw new \RuntimeException('Admin test authentication failed: ' . $exception->getMessage(), 0, $exception);
        }
        return $browser;
    }

    /** @return array<string, mixed> */
    private function successfulPayload(TestBrowser $browser): array
    {
        self::assertSame(Response::HTTP_OK, $browser->getResponse()->getStatusCode(), (string) $browser->getResponse()->getContent());
        $payload = json_decode((string) $browser->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        return $payload;
    }

    /** @return array<string, mixed> */
    private function row(string $id): array
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
