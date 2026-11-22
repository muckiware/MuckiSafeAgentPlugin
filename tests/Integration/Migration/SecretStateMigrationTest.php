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

require_once dirname(__DIR__, 3) . '/src/Migration/Migration1795305600CreateSecretState.php';

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use MuckiSafeAgentPlugin\Migration\Migration1795305600CreateSecretState;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Test\TestCaseBase\KernelTestBehaviour;

final class SecretStateMigrationTest extends TestCase
{
    use KernelTestBehaviour;

    private Connection $connection;

    protected function setUp(): void
    {
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->dropTable();
        (new Migration1795305600CreateSecretState())->update($this->connection);
    }

    protected function tearDown(): void
    {
        $this->dropTable();
    }

    public function testCreatesPrivateSingletonStateWithExactColumnsAndDefaults(): void
    {
        $columns = [];
        foreach ($this->connection->fetchAllAssociative('SHOW COLUMNS FROM `' . Migration1795305600CreateSecretState::TABLE_NAME . '`') as $column) {
            $columns[(string) $column['Field']] = [
                'type' => strtolower((string) $column['Type']),
                'null' => (string) $column['Null'],
            ];
        }

        self::assertSame([
            'id' => ['type' => 'tinyint unsigned', 'null' => 'NO'],
            'fence_version' => ['type' => 'int unsigned', 'null' => 'NO'],
            'mutation_generation' => ['type' => 'bigint unsigned', 'null' => 'NO'],
            'readback_old_version' => ['type' => 'int unsigned', 'null' => 'YES'],
            'readback_active_version' => ['type' => 'int unsigned', 'null' => 'YES'],
            'readback_generation' => ['type' => 'bigint unsigned', 'null' => 'YES'],
            'readback_cursor' => ['type' => 'binary(16)', 'null' => 'YES'],
            'created_at' => ['type' => 'datetime(3)', 'null' => 'NO'],
            'updated_at' => ['type' => 'datetime(3)', 'null' => 'YES'],
        ], $columns);

        $row = $this->connection->fetchAssociative('SELECT * FROM `' . Migration1795305600CreateSecretState::TABLE_NAME . '`');
        self::assertIsArray($row);
        self::assertSame(1, (int) $row['id']);
        self::assertSame(0, (int) $row['fence_version']);
        self::assertSame(0, (int) $row['mutation_generation']);
        self::assertNull($row['readback_old_version']);
        self::assertNull($row['readback_cursor']);
        self::assertFalse(self::getContainer()->has('muwa_agent_secret_state.repository'));
    }

    public function testMigrationIsIdempotentAndPreservesExistingSecurityState(): void
    {
        $cursor = random_bytes(16);
        $this->connection->update(Migration1795305600CreateSecretState::TABLE_NAME, [
            'fence_version' => 7,
            'mutation_generation' => 9,
            'readback_old_version' => 1,
            'readback_active_version' => 7,
            'readback_generation' => 9,
            'readback_cursor' => $cursor,
        ], ['id' => Migration1795305600CreateSecretState::SINGLETON_ID]);

        (new Migration1795305600CreateSecretState())->update($this->connection);

        $row = $this->connection->fetchAssociative('SELECT * FROM `' . Migration1795305600CreateSecretState::TABLE_NAME . '`');
        self::assertIsArray($row);
        self::assertSame(7, (int) $row['fence_version']);
        self::assertSame(9, (int) $row['mutation_generation']);
        self::assertSame(1, (int) $row['readback_old_version']);
        self::assertSame(7, (int) $row['readback_active_version']);
        self::assertSame(9, (int) $row['readback_generation']);
        self::assertSame($cursor, $row['readback_cursor']);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM `' . Migration1795305600CreateSecretState::TABLE_NAME . '`'));
    }

    public function testDatabaseRejectsSecondSingletonAndPartialReadbackState(): void
    {
        try {
            $this->connection->insert(Migration1795305600CreateSecretState::TABLE_NAME, [
                'id' => 2,
                'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.v'),
            ]);
            self::fail('A second internal state row must be rejected.');
        } catch (DriverException $exception) {
            self::assertStringContainsString('check constraint', strtolower($exception->getMessage()));
        }

        try {
            $this->connection->update(Migration1795305600CreateSecretState::TABLE_NAME, [
                'readback_old_version' => 1,
            ], ['id' => Migration1795305600CreateSecretState::SINGLETON_ID]);
            self::fail('Partial readback state must be rejected.');
        } catch (DriverException $exception) {
            self::assertStringContainsString('check constraint', strtolower($exception->getMessage()));
        }
    }

    private function dropTable(): void
    {
        $this->connection->executeStatement('DROP TABLE IF EXISTS `' . Migration1795305600CreateSecretState::TABLE_NAME . '`');
    }
}
