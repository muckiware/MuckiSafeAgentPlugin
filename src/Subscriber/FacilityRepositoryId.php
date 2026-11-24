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
namespace MuckiSafeAgentPlugin\Subscriber;

use MuckiFacilityPlugin\Entity\BackupRepositorySettings;

/** Normalizes the Facility repository id of a lifecycle event to lowercase 32-digit hex. */
final class FacilityRepositoryId
{
    public static function fromSettings(BackupRepositorySettings $settings): string
    {
        $id = strtolower(str_replace('-', '', $settings->getBackupRepositoryId()));
        if (preg_match('/^[0-9a-f]{32}$/D', $id) !== 1) {
            throw new \InvalidArgumentException('The Facility backup repository id is invalid.');
        }

        return $id;
    }
}
