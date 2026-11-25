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
namespace MuckiSafeAgentPlugin\tests\Integration\Service\Secret;

require_once dirname(__DIR__, 4) . '/src/Migration/Migration1795132800CreateAgentSchema.php';
require_once dirname(__DIR__, 4) . '/src/Migration/Migration1795305600CreateSecretState.php';

use Doctrine\DBAL\Connection;
use MuckiSafeAgentPlugin\Controller\Admin\AgentProvisionController;
use MuckiSafeAgentPlugin\Migration\Migration1795132800CreateAgentSchema;
use MuckiSafeAgentPlugin\Migration\Migration1795305600CreateSecretState;
use MuckiSafeAgentPlugin\Service\Secret\AgentProvisioner;
use MuckiSafeAgentPlugin\Service\Secret\AgentSecretRotator;
use MuckiSafeAgentPlugin\Service\Secret\EncryptionKeyProvider;
use MuckiSafeAgentPlugin\Service\Secret\MasterKeyReencryptor;
use MuckiSafeAgentPlugin\Service\Secret\SecretCipher;
use MuckiSafeAgentPlugin\Service\Secret\SecretWriteGuard;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shopware\Core\Framework\Test\TestCaseBase\KernelTestBehaviour;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * MuckiSafe installations upgraded without MUWA_AGENT_ENCRYPTION_KEYRING must
 * keep running: the services are built without error, and only the agent
 * operations that need a key fail closed with a dedicated error and no
 * database effect.
 */
final class MissingKeyringTest extends TestCase
{
    use KernelTestBehaviour;

    private Connection $connection;

    protected function setUp(): void
    {
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->dropSchema();
        (new Migration1795132800CreateAgentSchema())->update($connection);
        (new Migration1795305600CreateSecretState())->update($connection);
    }

    protected function tearDown(): void
    {
        $this->dropSchema();
    }

    public function testProvisioningAndReencryptionReportAnUnavailableKeyringWithoutDatabaseEffect(): void
    {
        foreach (['', '{"active":1'] as $keyring) {
            $controller = $this->controller($keyring);

            $provision = $controller->provision(Request::create('/', 'POST', content: '{"name":"edge-01"}'));
            $reencrypt = $controller->reencrypt(Request::create('/', 'POST', content: '{"fromVersion":1}'));

            foreach ([$provision, $reencrypt] as $response) {
                self::assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $response->getStatusCode());
                self::assertSame('{"error":"keyring_unavailable"}', $response->getContent());
                self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
            }
        }
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM `muwa_agent_host`'));
    }

    public function testTheContainerConfigurationToleratesAMissingVariable(): void
    {
        $services = (string) file_get_contents(dirname(__DIR__, 4) . '/src/Resources/config/services.xml');

        // default:: yields null instead of throwing EnvNotFoundException while
        // the service is built; string: turns it into "" ("not configured").
        self::assertStringContainsString('%env(string:default::MUWA_AGENT_ENCRYPTION_KEYRING)%', $services);
        self::assertStringNotContainsString('%env(string:MUWA_AGENT_ENCRYPTION_KEYRING)%', $services);
    }

    private function controller(string $keyring): AgentProvisionController
    {
        $keys = new EncryptionKeyProvider($keyring);
        $cipher = new SecretCipher($keys);
        $guard = new SecretWriteGuard($this->connection);

        return new AgentProvisionController(
            new NullLogger(),
            new AgentProvisioner($this->connection, $cipher, $guard, $keys),
            new AgentSecretRotator($this->connection, $cipher, $guard, $keys),
            new MasterKeyReencryptor($this->connection, $cipher, $keys, $guard)
        );
    }

    private function dropSchema(): void
    {
        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS=0');
        foreach (array_merge(Migration1795132800CreateAgentSchema::TABLES, [Migration1795305600CreateSecretState::TABLE_NAME]) as $table) {
            $this->connection->executeStatement('DROP TABLE IF EXISTS `' . $table . '`');
        }
        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS=1');
    }
}
