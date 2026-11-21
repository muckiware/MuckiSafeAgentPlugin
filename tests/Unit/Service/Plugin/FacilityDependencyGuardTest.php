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
namespace MuckiSafeAgentPlugin\tests\Unit\Service\Plugin;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\Plugin\PluginCollection;
use Shopware\Core\Framework\Plugin\PluginDefinition;
use Shopware\Core\Framework\Plugin\PluginEntity;
use Shopware\Core\Framework\Uuid\Uuid;

use MuckiSafeAgentPlugin\Exception\FacilityDependencyException;
use MuckiSafeAgentPlugin\Service\Plugin\FacilityDependencyGuard;

/**
 * Unit contract of the Facility dependency guard. The plugin may only
 * activate with an installed, active MuckiFacilityPlugin at version
 * >= 0.11.0 (the version that ships the neutral BackupFinishedEvent).
 * Missing, never-installed, inactive or outdated Facility states must each
 * produce a clear, reason-coded FacilityDependencyException, and the guard
 * must report `facility_unavailable` for every one of them so later
 * admin/API/subscriber components can fail closed without creating new jobs.
 *
 * Runs without a Shopware kernel: the plugin repository is mocked, which is
 * why no database is needed (unit mode in tests/bootstrap.php).
 */
final class FacilityDependencyGuardTest extends TestCase
{
    public function testAssertFacilityActivePassesWithFacilityAtMinimumVersion(): void
    {
        $guard = new FacilityDependencyGuard($this->pluginRepository(
            $this->facilityPlugin('v0.11.0', active: true, installed: true)
        ));

        $guard->assertFacilityActive();

        self::assertTrue($guard->isFacilityAvailable());
    }

    public function testAssertFacilityActivePassesWithNewerFacilityVersion(): void
    {
        $guard = new FacilityDependencyGuard($this->pluginRepository(
            $this->facilityPlugin('v0.11.5', active: true, installed: true)
        ));

        $guard->assertFacilityActive();

        self::assertTrue($guard->isFacilityAvailable());
    }

    public function testAssertFacilityActivePassesWithPlainVersionString(): void
    {
        $guard = new FacilityDependencyGuard($this->pluginRepository(
            $this->facilityPlugin('0.12.0', active: true, installed: true)
        ));

        $guard->assertFacilityActive();

        self::assertTrue($guard->isFacilityAvailable());
    }

    public function testAssertFacilityActiveRejectsMissingFacility(): void
    {
        $guard = new FacilityDependencyGuard($this->pluginRepository());

        try {
            $guard->assertFacilityActive();
            self::fail('Missing MuckiFacilityPlugin must not pass the guard.');
        } catch (FacilityDependencyException $exception) {
            self::assertSame(FacilityDependencyException::REASON_NOT_INSTALLED, $exception->getReason());
            self::assertStringContainsString(FacilityDependencyGuard::FACILITY_PLUGIN_NAME, $exception->getMessage());
            self::assertStringContainsString(FacilityDependencyGuard::FACILITY_COMPOSER_NAME, $exception->getMessage());
        }

        self::assertFalse($guard->isFacilityAvailable());
    }

    public function testAssertFacilityActiveRejectsNeverInstalledFacility(): void
    {
        $guard = new FacilityDependencyGuard($this->pluginRepository(
            $this->facilityPlugin('v0.11.0', active: true, installed: false)
        ));

        try {
            $guard->assertFacilityActive();
            self::fail('A never-installed MuckiFacilityPlugin must not pass the guard.');
        } catch (FacilityDependencyException $exception) {
            self::assertSame(FacilityDependencyException::REASON_NOT_INSTALLED, $exception->getReason());
        }

        self::assertFalse($guard->isFacilityAvailable());
    }

    public function testAssertFacilityActiveRejectsInactiveFacility(): void
    {
        $guard = new FacilityDependencyGuard($this->pluginRepository(
            $this->facilityPlugin('v0.11.0', active: false, installed: true)
        ));

        try {
            $guard->assertFacilityActive();
            self::fail('An inactive MuckiFacilityPlugin must not pass the guard.');
        } catch (FacilityDependencyException $exception) {
            self::assertSame(FacilityDependencyException::REASON_INACTIVE, $exception->getReason());
            self::assertStringContainsString(FacilityDependencyGuard::FACILITY_PLUGIN_NAME, $exception->getMessage());
        }

        self::assertFalse($guard->isFacilityAvailable());
    }

    public function testAssertFacilityActiveRejectsOutdatedFacility(): void
    {
        $guard = new FacilityDependencyGuard($this->pluginRepository(
            $this->facilityPlugin('v0.10.1', active: true, installed: true)
        ));

        try {
            $guard->assertFacilityActive();
            self::fail('MuckiFacilityPlugin below v0.11.0 must not pass the guard.');
        } catch (FacilityDependencyException $exception) {
            self::assertSame(FacilityDependencyException::REASON_INCOMPATIBLE_VERSION, $exception->getReason());
            self::assertStringContainsString('v0.10.1', $exception->getMessage());
            self::assertStringContainsString(FacilityDependencyGuard::MIN_FACILITY_VERSION, $exception->getMessage());
        }

        self::assertFalse($guard->isFacilityAvailable());
    }

    #[DataProvider('provideVersionSatisfiesCases')]
    public function testVersionSatisfies(string $installedVersion, bool $expected): void
    {
        self::assertSame($expected, FacilityDependencyGuard::versionSatisfies($installedVersion));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function provideVersionSatisfiesCases(): iterable
    {
        yield 'minimum version with v prefix' => ['v0.11.0', true];
        yield 'minimum version plain' => ['0.11.0', true];
        yield 'patch above minimum' => ['v0.11.5', true];
        yield 'minor above minimum' => ['0.12.0', true];
        yield 'major above minimum' => ['v1.0.0', true];
        yield 'patch below minimum' => ['v0.10.9', false];
        yield 'minor below minimum' => ['0.10.99', false];
        yield 'legacy version' => ['v0.7.0', false];
    }

    private function facilityPlugin(string $version, bool $active, bool $installed): PluginEntity
    {
        $facility = new PluginEntity();
        $facility->setUniqueIdentifier(Uuid::randomHex());
        $facility->setName(FacilityDependencyGuard::FACILITY_PLUGIN_NAME);
        $facility->setBaseClass('MuckiFacilityPlugin\\MuckiFacilityPlugin');
        $facility->setComposerName(FacilityDependencyGuard::FACILITY_COMPOSER_NAME);
        $facility->setVersion($version);
        $facility->setActive($active);
        $facility->setInstalledAt($installed ? new \DateTimeImmutable('2026-11-21 09:00:00') : null);

        return $facility;
    }

    /**
     * The guard searches `plugin.repository` with an EqualsFilter on the
     * plugin name; the mock accepts any criteria and answers with the given
     * entities.
     */
    private function pluginRepository(PluginEntity ...$plugins): EntityRepository
    {
        $collection = new PluginCollection(array_values($plugins));

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')->willReturnCallback(
            static function (Criteria $criteria, Context $context) use ($collection): EntitySearchResult {
                return new EntitySearchResult(
                    PluginDefinition::ENTITY_NAME,
                    $collection->count(),
                    $collection,
                    null,
                    $criteria,
                    $context
                );
            }
        );

        return $repository;
    }
}
