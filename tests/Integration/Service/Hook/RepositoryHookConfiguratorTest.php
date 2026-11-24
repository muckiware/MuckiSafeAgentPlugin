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
namespace MuckiSafeAgentPlugin\tests\Integration\Service\Hook;

use Doctrine\DBAL\Connection;
use MuckiSafeAgentPlugin\Core\Content\AgentHost\AgentHostEntity;
use MuckiSafeAgentPlugin\Core\Content\AgentRepositoryHook\AgentRepositoryHookEntity;
use MuckiSafeAgentPlugin\Migration\Migration1795132800CreateAgentSchema;
use MuckiSafeAgentPlugin\Service\Hook\HookConfigurationException;
use MuckiSafeAgentPlugin\Service\Hook\RepositoryHookConfigurator;
use MuckiSafeAgentPlugin\Service\Hook\RepositoryHookDraft;
use MuckiSafeAgentPlugin\Service\Plugin\FacilityDependencyGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Api\Context\AdminApiSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Write\WriteException;
use Shopware\Core\Framework\Test\TestCaseBase\KernelTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Task 15 RED: targeted hook configuration. The Facility repository must
 * exist; there is exactly one hook per repository and phase; the target
 * agent must exist, be enabled, not revoked and capable; an active Facility
 * legacy pre or post hook blocks every save without touching Facility values;
 * the generic admin API cannot bypass these checks.
 */
final class RepositoryHookConfiguratorTest extends TestCase
{
    use KernelTestBehaviour;

    private Connection $connection;
    private Context $context;
    /** @var list<string> */
    private array $facilityRepositoryIds = [];
    private string $repositoryId;
    private string $hostId;
    private string $actionId;

    protected function setUp(): void
    {
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->context = Context::createDefaultContext();
        $this->dropSchema();
        (new Migration1795132800CreateAgentSchema())->update($connection);
        $this->repositoryId = $this->insertFacilityRepository('none', 'none');
        $this->hostId = $this->insertHost(['backup']);
        $this->actionId = $this->insertAction(['backup'], 1800);
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

    public function testSaveCreatesATargetedHookWithExplicitAgentHostAssociation(): void
    {
        $hookId = $this->configurator()->save($this->draft(['timeoutSeconds' => 900, 'failOnError' => false]), $this->context);

        $criteria = (new Criteria([$hookId]))->addAssociation('agentHost');
        $hook = $this->hookRepository()->search($criteria, $this->context)->first();
        self::assertInstanceOf(AgentRepositoryHookEntity::class, $hook);
        self::assertSame($this->repositoryId, $hook->getFacilityRepositoryId());
        self::assertSame('pre', $hook->getPhase());
        self::assertSame($this->hostId, $hook->getAgentHostId());
        self::assertSame($this->actionId, $hook->getAgentActionId());
        self::assertSame('{}', $hook->getParameters());
        self::assertSame(900, $hook->getTimeoutSeconds());
        self::assertFalse($hook->getFailOnError());
        self::assertTrue($hook->getEnabled());
        self::assertInstanceOf(AgentHostEntity::class, $hook->getAgentHost());
        self::assertSame($this->hostId, $hook->getAgentHost()->getId());
    }

    public function testSavingTheSamePhaseAgainReplacesTheOneHookOfThatPhase(): void
    {
        $first = $this->configurator()->save($this->draft(), $this->context);
        $otherHost = $this->insertHost(['backup']);
        $second = $this->configurator()->save($this->draft(['agentHostId' => $otherHost, 'enabled' => false]), $this->context);
        $post = $this->configurator()->save($this->draft(['phase' => 'post']), $this->context);

        self::assertSame($first, $second);
        self::assertNotSame($first, $post);
        self::assertSame(1, $this->hookCount('pre'));
        self::assertSame(1, $this->hookCount('post'));
        $row = $this->hookRow($first);
        self::assertSame($otherHost, Uuid::fromBytesToHex((string) $row['agent_host_id']));
        self::assertSame(0, (int) $row['enabled']);
    }

    public function testMissingFacilityRepositoryIsRejected(): void
    {
        $this->assertRejected($this->draft(['facilityRepositoryId' => Uuid::randomHex()]), 'facility_repository_not_found');
    }

    /** @return iterable<string, array{string, string, string, list<string>}> */
    public static function legacyConfigurations(): iterable
    {
        yield 'legacy pre blocks pre hook' => ['batch', 'none', 'pre', ['preBackupCommandType']];
        yield 'legacy post blocks pre hook' => ['none', 'php', 'pre', ['postBackupCommandType']];
        yield 'legacy pre blocks finally hook' => ['php', 'none', 'finally', ['preBackupCommandType']];
        yield 'both legacy hooks are reported' => ['batch', 'php', 'post', ['preBackupCommandType', 'postBackupCommandType']];
    }

    /** @param list<string> $fields */
    #[DataProvider('legacyConfigurations')]
    public function testActiveLegacyHookBlocksSavingAndLeavesFacilityValuesUntouched(
        string $pre,
        string $post,
        string $phase,
        array $fields
    ): void {
        $repositoryId = $this->insertFacilityRepository($pre, $post);
        $before = $this->facilityRow($repositoryId);

        try {
            $this->configurator()->save(
                $this->draft(['facilityRepositoryId' => $repositoryId, 'phase' => $phase, 'enabled' => false]),
                $this->context
            );
            self::fail('An active legacy hook must block saving.');
        } catch (HookConfigurationException $exception) {
            self::assertSame('legacy_hook_conflict', $exception->errorCode);
            self::assertSame($fields, $exception->fields);
        }

        self::assertSame(0, $this->hookCount());
        self::assertSame($before, $this->facilityRow($repositoryId));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function unavailableHosts(): iterable
    {
        yield 'disabled' => [['enabled' => 0]];
        yield 'revoked' => [['revoked_at' => '2026-01-01 00:00:00.000']];
    }

    /** @param array<string, mixed> $state */
    #[DataProvider('unavailableHosts')]
    public function testUnavailableTargetAgentIsRejected(array $state): void
    {
        $this->connection->update('muwa_agent_host', $state, ['id' => Uuid::fromHexToBytes($this->hostId)]);

        $this->assertRejected($this->draft(), 'agent_unavailable');
    }

    public function testMissingTargetAgentIsRejected(): void
    {
        $this->assertRejected($this->draft(['agentHostId' => Uuid::randomHex()]), 'agent_unavailable');
    }

    public function testTargetAgentWithoutRequiredCapabilityIsRejected(): void
    {
        $this->assertRejected($this->draft(['agentHostId' => $this->insertHost(['other'])]), 'capability_missing');
    }

    public function testDisabledOrMissingActionIsRejected(): void
    {
        $this->assertRejected($this->draft(['agentActionId' => Uuid::randomHex()]), 'action_unavailable');

        $this->connection->update('muwa_agent_action', ['enabled' => 0], ['id' => Uuid::fromHexToBytes($this->actionId)]);
        $this->assertRejected($this->draft(), 'action_unavailable');
    }

    public function testBudgetAboveTheActionMaximumIsRejected(): void
    {
        $actionId = $this->insertAction(['backup'], 300);

        $this->assertRejected($this->draft(['agentActionId' => $actionId, 'timeoutSeconds' => 301]), 'timeout_out_of_range');
        self::assertNotSame('', $this->configurator()->save(
            $this->draft(['agentActionId' => $actionId, 'timeoutSeconds' => 300]),
            $this->context
        ));
    }

    public function testDeleteRemovesTheHookAndUnknownHooksAreReported(): void
    {
        $hookId = $this->configurator()->save($this->draft(), $this->context);

        $this->configurator()->delete($hookId, $this->context);
        self::assertSame(0, $this->hookCount());

        try {
            $this->configurator()->delete($hookId, $this->context);
            self::fail('Deleting an unknown hook must be reported.');
        } catch (HookConfigurationException $exception) {
            self::assertSame('hook_not_found', $exception->errorCode);
        }
    }

    public function testInactiveFacilityFailsClosed(): void
    {
        $pluginId = (string) $this->connection->fetchOne(
            'SELECT LOWER(HEX(`id`)) FROM `plugin` WHERE `name` = :name',
            ['name' => FacilityDependencyGuard::FACILITY_PLUGIN_NAME]
        );
        $this->connection->executeStatement('UPDATE `plugin` SET `active` = 0 WHERE `id` = UNHEX(:id)', ['id' => $pluginId]);
        try {
            $this->assertRejected($this->draft(), 'facility_unavailable');
        } finally {
            $this->connection->executeStatement('UPDATE `plugin` SET `active` = 1 WHERE `id` = UNHEX(:id)', ['id' => $pluginId]);
        }
    }

    public function testGenericAdminApiCannotBypassTheConfigurator(): void
    {
        $hookId = $this->configurator()->save($this->draft(), $this->context);
        $before = $this->hookRow($hookId);
        // A full admin passes every ACL check, so only the write protection can stop the bypass.
        $adminSource = new AdminApiSource(Uuid::randomHex());
        $adminSource->setIsAdmin(true);
        $adminContext = Context::createDefaultContext($adminSource);

        foreach ([
            ['enabled' => false],
            ['agentHostId' => $this->insertHost([])],
            ['agentActionId' => $this->insertAction([], 600)],
            ['facilityRepositoryId' => $this->insertFacilityRepository('batch', 'batch')],
            ['timeoutSeconds' => 99999],
            ['failOnError' => false],
            ['parameters' => '{"path":"/etc"}'],
        ] as $change) {
            try {
                $this->hookRepository()->update([array_merge(['id' => $hookId], $change)], $adminContext);
                self::fail('Admin API must not write ' . (string) array_key_first($change) . '.');
            } catch (WriteException $exception) {
                self::assertStringContainsString('write-protected', strtolower($exception->getMessage()));
            }
        }

        self::assertSame($before, $this->hookRow($hookId));
    }

    private function assertRejected(RepositoryHookDraft $draft, string $errorCode): void
    {
        try {
            $this->configurator()->save($draft, $this->context);
            self::fail('Saving must be rejected with ' . $errorCode . '.');
        } catch (HookConfigurationException $exception) {
            self::assertSame($errorCode, $exception->errorCode);
        }
        self::assertSame(0, $this->hookCount());
    }

    /** @param array<string, mixed> $overrides */
    private function draft(array $overrides = []): RepositoryHookDraft
    {
        return RepositoryHookDraft::fromPayload(array_merge([
            'facilityRepositoryId' => $this->repositoryId,
            'phase' => 'pre',
            'agentHostId' => $this->hostId,
            'agentActionId' => $this->actionId,
        ], $overrides));
    }

    private function configurator(): RepositoryHookConfigurator
    {
        $configurator = self::getContainer()->get(RepositoryHookConfigurator::class);
        self::assertInstanceOf(RepositoryHookConfigurator::class, $configurator);

        return $configurator;
    }

    private function hookRepository(): EntityRepository
    {
        $repository = self::getContainer()->get('muwa_agent_repository_hook.repository');
        self::assertInstanceOf(EntityRepository::class, $repository);

        return $repository;
    }

    private function hookCount(?string $phase = null): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM `muwa_agent_repository_hook`' . ($phase === null ? '' : ' WHERE `phase` = :phase'),
            $phase === null ? [] : ['phase' => $phase]
        );
    }

    /** @return array<string, mixed> */
    private function hookRow(string $hookId): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM `muwa_agent_repository_hook` WHERE `id` = UNHEX(:id)',
            ['id' => $hookId]
        );
        self::assertIsArray($row);

        return $row;
    }

    /** @return array<string, mixed> */
    private function facilityRow(string $repositoryId): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM `muwa_backup_repository` WHERE `id` = UNHEX(:id)',
            ['id' => $repositoryId]
        );
        self::assertIsArray($row);

        return $row;
    }

    private function insertFacilityRepository(string $preType, string $postType): string
    {
        $id = Uuid::randomHex();
        $this->connection->insert('muwa_backup_repository', [
            'id' => Uuid::fromHexToBytes($id),
            'name' => 'hook-test-' . $id,
            'type' => 'local',
            'repository_path' => '/tmp/hook-test-' . $id,
            'repository_password' => 'unused',
            'pre_backup_command_type' => $preType,
            'pre_backup_command_arguments' => '',
            'post_backup_command_type' => $postType,
            'post_backup_command_arguments' => '',
            'created_at' => (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
        ]);
        $this->facilityRepositoryIds[] = $id;

        return $id;
    }

    /** @param list<string> $capabilities */
    private function insertHost(array $capabilities): string
    {
        $id = Uuid::randomHex();
        $this->connection->insert('muwa_agent_host', [
            'id' => Uuid::fromHexToBytes($id),
            'name' => 'hook-host',
            'enabled' => 1,
            'key_id' => 'hook-' . $id,
            'secret_ciphertext' => random_bytes(32),
            'secret_nonce' => random_bytes(24),
            'secret_key_version' => 1,
            'capabilities' => json_encode($capabilities, JSON_THROW_ON_ERROR),
            'created_at' => (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
        ]);

        return $id;
    }

    /** @param list<string> $requiredCapabilities */
    private function insertAction(array $requiredCapabilities, int $maxTimeoutSeconds): string
    {
        $id = Uuid::randomHex();
        $this->connection->insert('muwa_agent_action', [
            'id' => Uuid::fromHexToBytes($id),
            'technical_name' => 'hook-action-' . $id,
            'label' => 'Hook action',
            'enabled' => 1,
            'definition' => '{"version":1,"steps":[]}',
            'definition_hash' => hash('sha256', '{"version":1,"steps":[]}', true),
            'required_capabilities' => json_encode($requiredCapabilities, JSON_THROW_ON_ERROR),
            'default_timeout_seconds' => min(600, $maxTimeoutSeconds),
            'max_timeout_seconds' => $maxTimeoutSeconds,
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
