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
require_once dirname(__DIR__, 4) . '/src/Service/Secret/SecretWriteGuard.php';
foreach (glob(dirname(__DIR__, 4) . '/src/Service/Secret/*.php') ?: [] as $secretServiceFile) {
    require_once $secretServiceFile;
}

use Doctrine\DBAL\Connection;
use MuckiSafeAgentPlugin\Migration\Migration1795132800CreateAgentSchema;
use MuckiSafeAgentPlugin\Migration\Migration1795305600CreateSecretState;
use MuckiSafeAgentPlugin\Core\Content\AgentHost\AgentHostEntity;
use MuckiSafeAgentPlugin\Service\Secret\AgentProvisioner;
use MuckiSafeAgentPlugin\Service\Secret\AgentSecretRotator;
use MuckiSafeAgentPlugin\Service\Secret\EncryptionKeyProvider;
use MuckiSafeAgentPlugin\Service\Secret\MasterKeyReencryptor;
use MuckiSafeAgentPlugin\Service\Secret\SecretCipher;
use MuckiSafeAgentPlugin\Service\Secret\SecretWriteGuard;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Test\TestCaseBase\KernelTestBehaviour;

final class AgentSecretPersistenceTest extends TestCase
{
    use KernelTestBehaviour;

    private const KEY1 = '000102030405060708090a0b0c0d0e0f101112131415161718191a1b1c1d1e1f';
    private const KEY2 = 'ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff';

    private Connection $connection;

    protected function setUp(): void
    {
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->dropSchema();
        $this->connection->executeStatement("DELETE FROM `system_config` WHERE `configuration_key` = 'MuckiSafeAgentPlugin.secretMinimumWriteVersion' OR `configuration_key` LIKE 'MuckiSafeAgentPlugin.secretReadbackState.%' OR `configuration_key` = 'MuckiSafeAgentPlugin.secretMutationGeneration'");
        (new Migration1795132800CreateAgentSchema())->update($this->connection);
        (new Migration1795305600CreateSecretState())->update($this->connection);
    }

    protected function tearDown(): void
    {
        $this->connection->executeStatement("DELETE FROM `system_config` WHERE `configuration_key` = 'MuckiSafeAgentPlugin.secretMinimumWriteVersion' OR `configuration_key` LIKE 'MuckiSafeAgentPlugin.secretReadbackState.%' OR `configuration_key` = 'MuckiSafeAgentPlugin.secretMutationGeneration'");
        $this->dropSchema();
    }

    public function testReservedSystemConfigValuesCannotResetInternalFenceOrMutationGeneration(): void
    {
        $guard = new SecretWriteGuard($this->connection);
        $guard->advanceFence(3);
        $guard->markSecretMutation();

        $this->connection->executeStatement(
            'UPDATE `system_config` SET `configuration_value` = :value WHERE `configuration_key` = :key AND `sales_channel_id` IS NULL',
            ['value' => '{"_value":1}', 'key' => SecretWriteGuard::FENCE_CONFIG_KEY]
        );
        $this->connection->executeStatement(
            'UPDATE `system_config` SET `configuration_value` = :value WHERE `configuration_key` = :key AND `sales_channel_id` IS NULL',
            ['value' => '{"_value":0}', 'key' => SecretWriteGuard::MUTATION_CONFIG_KEY]
        );

        self::assertSame(3, $guard->fenceVersion());
        self::assertSame(1, $guard->mutationGeneration());
    }

    public function testProvisionStoresOnlyEncryptedMaterialAndDalCannotExposeIt(): void
    {
        $services = $this->services(1);
        $result = $services['provisioner']->provision('host-a');

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $result['secret']);
        self::assertStringNotContainsString('=', $result['secret']);
        $row = $this->row($result['id']);
        self::assertSame(1, (int) $row['secret_key_version']);
        self::assertSame(24, strlen((string) $row['secret_nonce']));
        self::assertSame(48, strlen((string) $row['secret_ciphertext']));
        self::assertNotSame($result['secret'], $row['secret_ciphertext']);
        self::assertNotSame($this->decode($result['secret']), $row['secret_ciphertext']);
        self::assertTrue($services['rotator']->isCurrentSecret($result['id'], $result['secret']));

        $repository = self::getContainer()->get('muwa_agent_host.repository');
        self::assertInstanceOf(EntityRepository::class, $repository);
        $entity = $repository->search(new Criteria([$result['id']]), Context::createDefaultContext())->first();
        self::assertInstanceOf(AgentHostEntity::class, $entity);
        $json = json_encode($entity, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('secret', strtolower($json));
        self::assertStringNotContainsString($result['secret'], $json);
    }

    public function testRotationAtomicallyInvalidatesOldSecretAndRevokeDisablesCurrentSecret(): void
    {
        $services = $this->services(1);
        $provisioned = $services['provisioner']->provision('host-a');
        $rotated = $services['rotator']->rotate($provisioned['id']);

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $rotated['secret']);
        self::assertNotSame($provisioned['secret'], $rotated['secret']);
        self::assertFalse($services['rotator']->isCurrentSecret($provisioned['id'], $provisioned['secret']));
        self::assertTrue($services['rotator']->isCurrentSecret($provisioned['id'], $rotated['secret']));

        $services['rotator']->revoke($provisioned['id']);
        $firstRevokedAt = $this->row($provisioned['id'])['revoked_at'];
        $services['rotator']->revoke($provisioned['id']);
        self::assertFalse($services['rotator']->isCurrentSecret($provisioned['id'], $rotated['secret']));
        self::assertNotNull($firstRevokedAt);
        self::assertSame($firstRevokedAt, $this->row($provisioned['id'])['revoked_at']);
    }

    public function testRemovalReadbackRejectsActiveVersionForEmptyAndPopulatedTables(): void
    {
        $services = $this->services(2);

        foreach ([false, true] as $populate) {
            if ($populate) {
                $services['provisioner']->provision('active-host');
            }
            try {
                $services['reencryptor']->readbackAndCanRemove(2);
                self::fail('Active encryption key must never be removable.');
            } catch (\InvalidArgumentException $exception) {
                self::assertSame('Source version is active.', $exception->getMessage());
            }
        }
    }

    public function testReencryptionAndBoundedReadbackResumeFromServerOwnedCursor(): void
    {
        $old = $this->services(1);
        $agents = [
            $old['provisioner']->provision('host-a'),
            $old['provisioner']->provision('host-b'),
            $old['provisioner']->provision('host-c'),
        ];
        $new = $this->services(2);

        $first = $new['reencryptor']->reencryptBatch(1, 2);
        self::assertSame(2, $first['processed']);
        self::assertFalse($first['complete']);
        self::assertFalse($first['removalAllowed']);

        $second = $new['reencryptor']->reencryptBatch(1, 2);
        self::assertSame(1, $second['processed']);
        self::assertFalse($second['complete']);
        self::assertFalse($second['removalAllowed']);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM `muwa_agent_host` WHERE `secret_key_version` = 1'));

        $readbackFirst = $new['reencryptor']->reencryptBatch(1, 2);
        self::assertSame(2, $readbackFirst['processed']);
        self::assertFalse($readbackFirst['complete']);
        self::assertFalse($readbackFirst['removalAllowed']);
        $generation = (new SecretWriteGuard($this->connection))->mutationGeneration();
        $state = $this->connection->fetchAssociative(
            'SELECT `readback_old_version`, `readback_active_version`, `readback_generation`, LOWER(HEX(`readback_cursor`)) AS `cursor` '
            . 'FROM `' . Migration1795305600CreateSecretState::TABLE_NAME . '`'
        );
        self::assertIsArray($state);
        self::assertSame(1, (int) $state['readback_old_version']);
        self::assertSame(2, (int) $state['readback_active_version']);
        self::assertSame($generation, (int) $state['readback_generation']);
        self::assertSame($readbackFirst['lastId'], $state['cursor']);

        $this->connection->executeStatement(
            'DELETE FROM `system_config` WHERE `configuration_key` = :key AND `sales_channel_id` IS NULL',
            ['key' => MasterKeyReencryptor::READBACK_CONFIG_PREFIX . '1']
        );
        $this->connection->insert('system_config', [
            'id' => random_bytes(16),
            'configuration_key' => MasterKeyReencryptor::READBACK_CONFIG_PREFIX . '1',
            'configuration_value' => json_encode(['_value' => [
                'activeVersion' => 2,
                'generation' => $generation,
                'cursor' => str_repeat('f', 32),
            ]], JSON_THROW_ON_ERROR),
            'sales_channel_id' => null,
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.v'),
        ]);

        $resumed = $this->services(2)['reencryptor']->reencryptBatch(1, 2);
        self::assertSame(1, $resumed['processed']);
        self::assertTrue($resumed['complete']);
        self::assertTrue($resumed['removalAllowed']);
        self::assertSame(1, (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM `system_config` WHERE `configuration_key` = :key',
            ['key' => MasterKeyReencryptor::READBACK_CONFIG_PREFIX . '1']
        ));
        $completedState = $this->connection->fetchAssociative(
            'SELECT `fence_version`, `readback_old_version`, `readback_active_version`, `readback_generation`, `readback_cursor` '
            . 'FROM `' . Migration1795305600CreateSecretState::TABLE_NAME . '`'
        );
        self::assertIsArray($completedState);
        self::assertSame(2, (int) $completedState['fence_version']);
        self::assertNull($completedState['readback_old_version']);
        self::assertNull($completedState['readback_active_version']);
        self::assertNull($completedState['readback_generation']);
        self::assertNull($completedState['readback_cursor']);
        foreach ($agents as $agent) {
            self::assertTrue($new['rotator']->isCurrentSecret($agent['id'], $agent['secret']));
        }
    }

    public function testReadbackRestartsWhenASecretWriteOccursBetweenBatches(): void
    {
        $old = $this->services(1);
        $old['provisioner']->provision('host-a');
        $old['provisioner']->provision('host-b');
        $old['provisioner']->provision('host-c');
        $new = $this->services(2);

        self::assertFalse($new['reencryptor']->reencryptBatch(1, 100)['complete']);
        self::assertFalse($new['reencryptor']->reencryptBatch(1, 2)['complete']);

        $new['provisioner']->provision('parallel-host');
        $restarted = $new['reencryptor']->reencryptBatch(1, 2);
        self::assertSame(2, $restarted['processed']);
        self::assertFalse($restarted['complete']);
        self::assertFalse($restarted['removalAllowed']);

        $finished = $new['reencryptor']->reencryptBatch(1, 2);
        self::assertSame(2, $finished['processed']);
        self::assertTrue($finished['complete']);
        self::assertTrue($finished['removalAllowed']);
    }

    public function testReencryptionRejectsDowngradeAndActiveVersionBelowPersistentFence(): void
    {
        $downgrade = $this->services(1);
        try {
            $downgrade['reencryptor']->reencryptBatch(2, 10);
            self::fail('Active key version below source version must be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('Active version must be newer than source version.', $exception->getMessage());
        }

        (new SecretWriteGuard($this->connection))->advanceFence(3);
        $belowFence = $this->services(2);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Encryption key version is below persistent fence.');
        $belowFence['reencryptor']->reencryptBatch(1, 10);
    }

    public function testReencryptionFailureRollsBackWholeBatch(): void
    {
        $old = $this->services(1);
        $a = $old['provisioner']->provision('host-a');
        $old['provisioner']->provision('host-b');
        $this->connection->executeStatement(
            'UPDATE `muwa_agent_host` SET `secret_ciphertext` = CONCAT(CHAR(0), SUBSTRING(`secret_ciphertext`, 2)) WHERE `id` = UNHEX(:id)',
            ['id' => $a['id']]
        );
        $new = $this->services(2);

        try {
            $new['reencryptor']->reencryptBatch(1, 100);
            self::fail('Tampered batch must fail.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Secret decryption failed.', $exception->getMessage());
        }

        self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM `muwa_agent_host` WHERE `secret_key_version` = 1'));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Secret decryption failed.');
        $new['reencryptor']->readbackAndCanRemove(1);
    }

    public function testRotationRejectsUnknownStoredVersionWithoutChangingEncryptedMaterial(): void
    {
        $services = $this->services(1);
        $agent = $services['provisioner']->provision('host-a');
        $this->connection->executeStatement('UPDATE `muwa_agent_host` SET `secret_key_version` = 99 WHERE `id` = UNHEX(:id)', ['id' => $agent['id']]);
        $before = $this->row($agent['id']);

        try {
            $services['rotator']->rotate($agent['id']);
            self::fail('Rotation must reject an unknown stored key version.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Unknown encryption key version.', $exception->getMessage());
        }

        $after = $this->row($agent['id']);
        self::assertSame($before['secret_ciphertext'], $after['secret_ciphertext']);
        self::assertSame($before['secret_nonce'], $after['secret_nonce']);
        self::assertSame((int) $before['secret_key_version'], (int) $after['secret_key_version']);
    }

    public function testCurrentSecretCheckRejectsUnknownStoredVersion(): void
    {
        $services = $this->services(1);
        $agent = $services['provisioner']->provision('host-a');
        $this->connection->executeStatement('UPDATE `muwa_agent_host` SET `secret_key_version` = 99 WHERE `id` = UNHEX(:id)', ['id' => $agent['id']]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unknown encryption key version.');
        $services['rotator']->isCurrentSecret($agent['id'], $agent['secret']);
    }

    public function testRemovalReadbackRejectsTamperedActiveCiphertextAfterOldReferencesReachZero(): void
    {
        $old = $this->services(1);
        $agent = $old['provisioner']->provision('host-a');
        $new = $this->services(2);
        $result = $new['reencryptor']->reencryptBatch(1, 100);
        self::assertFalse($result['complete']);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM `muwa_agent_host` WHERE `secret_key_version` = 1'));
        $this->connection->executeStatement(
            'UPDATE `muwa_agent_host` SET `secret_ciphertext` = CONCAT(CHAR(0), SUBSTRING(`secret_ciphertext`, 2)) WHERE `id` = UNHEX(:id)',
            ['id' => $agent['id']]
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Secret decryption failed.');
        $new['reencryptor']->readbackAndCanRemove(1);
    }

    /** @return array{provisioner: AgentProvisioner, rotator: AgentSecretRotator, reencryptor: MasterKeyReencryptor} */
    private function services(int $active): array
    {
        $provider = new EncryptionKeyProvider('{"active":' . $active . ',"keys":{"1":"' . self::KEY1 . '","2":"' . self::KEY2 . '"}}');
        $cipher = new SecretCipher($provider);
        $guard = new SecretWriteGuard($this->connection);

        return [
            'provisioner' => new AgentProvisioner($this->connection, $cipher, $guard, $provider),
            'rotator' => new AgentSecretRotator($this->connection, $cipher, $guard, $provider),
            'reencryptor' => new MasterKeyReencryptor($this->connection, $cipher, $provider, $guard),
        ];
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
        foreach (['muwa_agent_nonce', 'muwa_agent_job', 'muwa_agent_backup_run', 'muwa_agent_repository_hook', 'muwa_agent_action', 'muwa_agent_host', Migration1795305600CreateSecretState::TABLE_NAME] as $table) {
            $this->connection->executeStatement('DROP TABLE IF EXISTS `' . $table . '`');
        }
    }

    private function decode(string $secret): string
    {
        $decoded = base64_decode(strtr($secret, '-_', '+/') . '=', true);
        self::assertIsString($decoded);
        return $decoded;
    }
}
