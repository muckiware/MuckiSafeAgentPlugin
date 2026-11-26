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
namespace MuckiSafeAgentPlugin\Service\Rollback;

use Doctrine\DBAL\Connection;

/**
 * Reads the rollback switch. Fails closed: a missing state row (migration not
 * run) is an error, never "inactive". With $shareLock the row is read with
 * FOR SHARE inside the caller's transaction, which serializes job creation
 * against AgentRollback::start() (that writes the row exclusively).
 */
final class RollbackSwitch
{
    public static function isActive(Connection $connection, bool $shareLock = false): bool
    {
        $active = $connection->fetchOne(
            'SELECT `active` FROM `muwa_agent_rollback` WHERE `id` = 1' . ($shareLock ? ' FOR SHARE' : '')
        );
        if ($active === false) {
            throw new \RuntimeException('Agent rollback state is missing; run the plugin migrations.');
        }

        return (int) $active === 1;
    }
}
