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
namespace MuckiSafeAgentPlugin;

use Doctrine\DBAL\Connection;
use MuckiSafeAgentPlugin\Migration\Migration1795132800CreateAgentSchema;
use MuckiSafeAgentPlugin\Service\Plugin\FacilityDependencyGuard;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;

/**
 * Agent control plane for MuckiSafe — provisioning, actions, Facility hooks,
 * backup runs, job queue and agent API. Hard-depends on the MuckiFacilityPlugin
 * >= 0.11.0, see the FacilityDependencyGuard.
 */
class MuckiSafeAgentPlugin extends Plugin
{
    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);

        if ($uninstallContext->keepUserData()) {
            return;
        }

        $connection = $this->container->get(Connection::class);
        if (!$connection instanceof Connection) {
            throw new \RuntimeException('Doctrine connection is unavailable.');
        }

        foreach (Migration1795132800CreateAgentSchema::TABLES as $table) {
            $connection->executeStatement('DROP TABLE IF EXISTS `' . $table . '`');
        }
    }

    /**
     * Activation is only allowed with an installed and active
     * MuckiFacilityPlugin >= 0.11.0. Shopware's own requirement validation
     * (composer requirement muckiware/facility-plugin ^0.11) rejects
     * activation first; this guard is the second line of defence.
     */
    public function activate(ActivateContext $activateContext): void
    {
        /** @var FacilityDependencyGuard $guard */
        $guard = $this->container->get(FacilityDependencyGuard::class);
        $guard->assertFacilityActive();
    }
}
