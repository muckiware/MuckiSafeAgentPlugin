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

use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Plugin\PluginEntity;
use Shopware\Core\Framework\Plugin\PluginLifecycleService;
use Shopware\Core\Framework\Test\TestCaseBase\KernelTestBehaviour;

use MuckiSafeAgentPlugin\Exception\FacilityDependencyException;
use MuckiSafeAgentPlugin\Service\Plugin\FacilityDependencyGuard;

/**
 * Plugin boot contract inside a real, installed Shopware (provisioned by
 * tests/Integration/run-shopware.sh with MuckiFacilityPlugin and this plugin
 * installed and activated):
 *
 *  - activation of this plugin requires an installed and active
 *    MuckiFacilityPlugin >= 0.11.0 — Shopware's own requirement validation
 *    (composer name `muckiware/facility-plugin`) rejects activation while
 *    Facility is missing or inactive, and the FacilityDependencyGuard
 *    enforces the same contract a second time inside activate();
 *  - the documented lifecycle is protected by Shopware: Facility cannot be
 *    deactivated or uninstalled while this plugin is active;
 *  - if Facility ends up inactive anyway (e.g. a direct database change),
 *    the guard reports the fail-closed state `facility_unavailable` instead
 *    of letting agent components create new jobs.
 */
final class PluginBootTest extends TestCase
{
    use KernelTestBehaviour;

    private const AGENT_PLUGIN_NAME = 'MuckiSafeAgentPlugin';

    private Context $context;

    protected function setUp(): void
    {
        $this->context = Context::createDefaultContext();
    }

    public function testFacilityAndAgentPluginAreInstalledAndActive(): void
    {
        $facility = $this->loadPlugin(FacilityDependencyGuard::FACILITY_PLUGIN_NAME);
        $agent = $this->loadPlugin(self::AGENT_PLUGIN_NAME);

        self::assertNotNull($facility->getInstalledAt());
        self::assertTrue($facility->getActive());
        self::assertNotNull($agent->getInstalledAt());
        self::assertTrue($agent->getActive());

        self::assertTrue(
            FacilityDependencyGuard::versionSatisfies($facility->getVersion()),
            sprintf(
                'Expected MuckiFacilityPlugin >= %s, found %s.',
                FacilityDependencyGuard::MIN_FACILITY_VERSION,
                $facility->getVersion()
            )
        );
    }

    public function testGuardConfirmsFacilityAvailability(): void
    {
        $guard = $this->guardFromContainer();

        $guard->assertFacilityActive();

        self::assertTrue($guard->isFacilityAvailable());
    }

    public function testFacilityCannotBeDeactivatedWhileAgentIsActive(): void
    {
        $facility = $this->loadPlugin(FacilityDependencyGuard::FACILITY_PLUGIN_NAME);

        try {
            $this->pluginLifecycleService()->deactivatePlugin($facility, $this->context);
            self::fail('MuckiFacilityPlugin must not be deactivatable while MuckiSafeAgentPlugin is active.');
        } catch (\Throwable $exception) {
            self::assertStringContainsString(
                FacilityDependencyGuard::FACILITY_PLUGIN_NAME,
                $exception->getMessage()
            );
            self::assertStringContainsString(self::AGENT_PLUGIN_NAME, $exception->getMessage());
        }

        self::assertTrue($this->loadPlugin(FacilityDependencyGuard::FACILITY_PLUGIN_NAME)->getActive());
    }

    public function testGuardFailsClosedWhenFacilityIsInactive(): void
    {
        $facility = $this->loadPlugin(FacilityDependencyGuard::FACILITY_PLUGIN_NAME);

        try {
            // Simulate Facility being switched off behind the lifecycle's back
            // (a direct database change). The guard must fail closed.
            $this->pluginRepository()->update(
                [['id' => $facility->getId(), 'active' => false]],
                $this->context
            );

            $guard = $this->guardFromContainer();

            self::assertFalse($guard->isFacilityAvailable());

            try {
                $guard->assertFacilityActive();
                self::fail('Guard must reject an inactive MuckiFacilityPlugin.');
            } catch (FacilityDependencyException $exception) {
                self::assertSame(FacilityDependencyException::REASON_INACTIVE, $exception->getReason());
            }
        } finally {
            $this->pluginRepository()->update(
                [['id' => $facility->getId(), 'active' => true]],
                $this->context
            );
        }

        self::assertTrue($this->loadPlugin(FacilityDependencyGuard::FACILITY_PLUGIN_NAME)->getActive());
    }

    public function testAgentActivationRequiresActiveFacility(): void
    {
        $lifecycle = $this->pluginLifecycleService();

        try {
            // (1) Uninstall the agent (uninstall deactivates it first).
            $lifecycle->uninstallPlugin($this->loadPlugin(self::AGENT_PLUGIN_NAME), $this->context, true);

            // (2) With the agent gone, Facility can be deactivated.
            $lifecycle->deactivatePlugin(
                $this->loadPlugin(FacilityDependencyGuard::FACILITY_PLUGIN_NAME),
                $this->context
            );

            // (3) Install the agent again: allowed, but activation must fail
            //     with a clear error while Facility is inactive.
            $agent = $this->loadPlugin(self::AGENT_PLUGIN_NAME);
            self::assertNull($agent->getInstalledAt());

            $lifecycle->installPlugin($agent, $this->context);

            $agent = $this->loadPlugin(self::AGENT_PLUGIN_NAME);
            self::assertNotNull($agent->getInstalledAt());
            self::assertFalse($agent->getActive());

            try {
                $lifecycle->activatePlugin($agent, $this->context);
                self::fail('MuckiSafeAgentPlugin activation must fail without an active MuckiFacilityPlugin.');
            } catch (\Throwable $exception) {
                self::assertStringContainsString(
                    FacilityDependencyGuard::FACILITY_COMPOSER_NAME,
                    $exception->getMessage()
                );
            }

            self::assertFalse($this->loadPlugin(self::AGENT_PLUGIN_NAME)->getActive());

            // (4) Re-activating Facility first makes the agent activatable.
            $lifecycle->activatePlugin(
                $this->loadPlugin(FacilityDependencyGuard::FACILITY_PLUGIN_NAME),
                $this->context
            );
            $lifecycle->activatePlugin($this->loadPlugin(self::AGENT_PLUGIN_NAME), $this->context);

            self::assertTrue($this->loadPlugin(self::AGENT_PLUGIN_NAME)->getActive());
            self::assertTrue($this->loadPlugin(FacilityDependencyGuard::FACILITY_PLUGIN_NAME)->getActive());
        } finally {
            // Leave both plugins in the harness' end state: installed+active.
            $facility = $this->loadPlugin(FacilityDependencyGuard::FACILITY_PLUGIN_NAME);
            if (!$facility->getActive()) {
                $lifecycle->activatePlugin($facility, $this->context);
            }

            $agent = $this->loadPlugin(self::AGENT_PLUGIN_NAME);
            if ($agent->getInstalledAt() === null) {
                $lifecycle->installPlugin($agent, $this->context);
            }
            if (!$agent->getActive()) {
                $lifecycle->activatePlugin($agent, $this->context);
            }
        }
    }

    private function pluginRepository(): EntityRepository
    {
        $repository = self::getContainer()->get('plugin.repository');
        self::assertInstanceOf(EntityRepository::class, $repository);

        return $repository;
    }

    private function pluginLifecycleService(): PluginLifecycleService
    {
        $lifecycle = self::getContainer()->get(PluginLifecycleService::class);
        self::assertInstanceOf(PluginLifecycleService::class, $lifecycle);

        return $lifecycle;
    }

    private function guardFromContainer(): FacilityDependencyGuard
    {
        $guard = self::getContainer()->get(FacilityDependencyGuard::class);
        self::assertInstanceOf(FacilityDependencyGuard::class, $guard);

        return $guard;
    }

    private function loadPlugin(string $name): PluginEntity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('name', $name));

        $plugin = $this->pluginRepository()->search($criteria, $this->context)->first();
        self::assertInstanceOf(PluginEntity::class, $plugin, sprintf('Plugin "%s" not found.', $name));

        return $plugin;
    }
}
