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
namespace MuckiSafeAgentPlugin\tests\Integration\Plugin;

require_once dirname(__DIR__, 3) . '/src/Migration/Migration1795132800CreateAgentSchema.php';
require_once dirname(__DIR__, 3) . '/src/Migration/Migration1795305600CreateSecretState.php';
require_once dirname(__DIR__, 3) . '/src/MuckiSafeAgentPlugin.php';

use Doctrine\DBAL\Connection;
use MuckiSafeAgentPlugin\Migration\Migration1795132800CreateAgentSchema;
use MuckiSafeAgentPlugin\Migration\Migration1795305600CreateSecretState;
use MuckiSafeAgentPlugin\MuckiSafeAgentPlugin;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Test\TestCaseBase\KernelTestBehaviour;

final class UninstallTest extends TestCase
{
    use KernelTestBehaviour;

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

    public function testKeepUserDataPreservesEveryAgentTable(): void
    {
        $this->uninstall(true);

        foreach (Migration1795132800CreateAgentSchema::TABLES as $table) {
            self::assertTrue($this->connection->createSchemaManager()->tablesExist([$table]), $table . ' must be preserved');
        }
        self::assertTrue($this->connection->createSchemaManager()->tablesExist([Migration1795305600CreateSecretState::TABLE_NAME]));
    }

    public function testUninstallWithoutKeepUserDataDropsEveryAgentTable(): void
    {
        $this->uninstall(false);

        foreach (Migration1795132800CreateAgentSchema::TABLES as $table) {
            self::assertFalse($this->connection->createSchemaManager()->tablesExist([$table]), $table . ' must be dropped');
        }
        self::assertFalse($this->connection->createSchemaManager()->tablesExist([Migration1795305600CreateSecretState::TABLE_NAME]));
    }

    private function uninstall(bool $keepUserData): void
    {
        $context = $this->createMock(UninstallContext::class);
        $context->method('keepUserData')->willReturn($keepUserData);

        $plugin = new MuckiSafeAgentPlugin(true, dirname(__DIR__, 3));
        $plugin->setContainer(self::getContainer());
        $plugin->uninstall($context);
    }

    private function dropSchema(): void
    {
        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS=0');
        foreach (Migration1795132800CreateAgentSchema::TABLES as $table) {
            $this->connection->executeStatement('DROP TABLE IF EXISTS `' . $table . '`');
        }
        $this->connection->executeStatement('DROP TABLE IF EXISTS `' . Migration1795305600CreateSecretState::TABLE_NAME . '`');
        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS=1');
    }
}
