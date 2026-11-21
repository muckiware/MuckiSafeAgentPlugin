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
namespace MuckiSafeAgentPlugin\Service\Plugin;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Plugin\PluginEntity;

use MuckiSafeAgentPlugin\Exception\FacilityDependencyException;

/**
 * Guards the hard dependency of this plugin on the MuckiFacilityPlugin.
 *
 * The MuckiSafeAgentPlugin may only be activated when MuckiFacilityPlugin is
 * installed, active and at least version 0.11.0 (the first version shipping
 * the neutral BackupFinishedEvent). Beyond activation, every runtime
 * component that creates new jobs (admin, agent API, Facility event
 * subscribers) must consult this guard first: if Facility is unavailable,
 * they fail closed into the state self::STATE_FACILITY_UNAVAILABLE, create
 * no new jobs and never delete existing ones.
 *
 * This guard is deliberately a second line of defence — Shopware's own
 * requirement validation already rejects activation while the composer
 * requirement `muckiware/facility-plugin: ^0.11` is unmet, and composer
 * itself refuses to remove the facility package while this plugin is
 * installed. The guard exists because composer and the activation lifecycle
 * cannot protect the running system against later state changes.
 */
final class FacilityDependencyGuard
{
    /** Fail-closed state reported by admin, API and subscribers when this guard rejects. */
    public const STATE_FACILITY_UNAVAILABLE = 'facility_unavailable';

    public const FACILITY_PLUGIN_NAME = 'MuckiFacilityPlugin';

    public const FACILITY_COMPOSER_NAME = 'muckiware/facility-plugin';

    public const MIN_FACILITY_VERSION = '0.11.0';

    /**
     * @param EntityRepository<\Shopware\Core\Framework\Plugin\PluginCollection> $pluginRepository
     */
    public function __construct(
        private readonly EntityRepository $pluginRepository
    ) {
    }

    /**
     * @throws FacilityDependencyException when the Facility dependency is not satisfied
     */
    public function assertFacilityActive(): void
    {
        $facility = $this->findFacilityPlugin();

        if ($facility === null) {
            throw new FacilityDependencyException(
                FacilityDependencyException::REASON_NOT_INSTALLED,
                sprintf(
                    '%s requires the %s (%s), which is not present in this Shopware installation. '
                    . 'Install and activate %s ^%s first, then activate %s.',
                    'MuckiSafeAgentPlugin',
                    self::FACILITY_PLUGIN_NAME,
                    self::FACILITY_COMPOSER_NAME,
                    self::FACILITY_COMPOSER_NAME,
                    self::MIN_FACILITY_VERSION,
                    'MuckiSafeAgentPlugin'
                )
            );
        }

        if ($facility->getInstalledAt() === null) {
            throw new FacilityDependencyException(
                FacilityDependencyException::REASON_NOT_INSTALLED,
                sprintf(
                    '%s requires the %s to be installed, but it is only present as an inactive checkout. '
                    . 'Run bin/console plugin:install --activate %s first.',
                    'MuckiSafeAgentPlugin',
                    self::FACILITY_PLUGIN_NAME,
                    self::FACILITY_PLUGIN_NAME
                )
            );
        }

        if ($facility->getActive() !== true) {
            throw new FacilityDependencyException(
                FacilityDependencyException::REASON_INACTIVE,
                sprintf(
                    '%s requires an active %s. Activate %s first, then activate %s. '
                    . 'Lifecycle note: always deactivate or uninstall %s before the %s.',
                    'MuckiSafeAgentPlugin',
                    self::FACILITY_PLUGIN_NAME,
                    self::FACILITY_PLUGIN_NAME,
                    'MuckiSafeAgentPlugin',
                    'MuckiSafeAgentPlugin',
                    self::FACILITY_PLUGIN_NAME
                )
            );
        }

        $installedVersion = $facility->getVersion();

        if (!self::versionSatisfies($installedVersion)) {
            throw new FacilityDependencyException(
                FacilityDependencyException::REASON_INCOMPATIBLE_VERSION,
                sprintf(
                    '%s requires the %s in version >= %s (the version shipping the neutral BackupFinishedEvent), '
                    . 'but version %s is installed. Update %s first.',
                    'MuckiSafeAgentPlugin',
                    self::FACILITY_PLUGIN_NAME,
                    self::MIN_FACILITY_VERSION,
                    $installedVersion,
                    self::FACILITY_PLUGIN_NAME
                )
            );
        }
    }

    /**
     * Fail-closed availability check for runtime components: admin modules,
     * agent API and Facility event subscribers call this before creating new
     * jobs. False means: report the state self::STATE_FACILITY_UNAVAILABLE,
     * create no new jobs, keep existing jobs untouched.
     */
    public function isFacilityAvailable(): bool
    {
        try {
            $this->assertFacilityActive();

            return true;
        } catch (FacilityDependencyException) {
            return false;
        }
    }

    /**
     * Accepts the raw plugin version as stored by Shopware (the facility
     * ships its version with a leading "v", e.g. "v0.11.0").
     */
    public static function versionSatisfies(string $installedVersion): bool
    {
        $normalized = ltrim(trim($installedVersion), 'vV');

        return version_compare($normalized, self::MIN_FACILITY_VERSION, '>=');
    }

    private function findFacilityPlugin(): ?PluginEntity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('name', self::FACILITY_PLUGIN_NAME));

        $facility = $this->pluginRepository
            ->search($criteria, Context::createDefaultContext())
            ->first();

        return $facility instanceof PluginEntity ? $facility : null;
    }
}
