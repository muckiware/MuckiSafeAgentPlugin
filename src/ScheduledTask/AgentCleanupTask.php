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
namespace MuckiSafeAgentPlugin\ScheduledTask;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

/**
 * Runs the job reaper and the stale-state cleanup. The interval is short
 * because lease recovery and the execution cutoff work on a 30 second lease.
 */
final class AgentCleanupTask extends ScheduledTask
{
    public static function getTaskName(): string
    {
        return 'muwa_agent.cleanup';
    }

    public static function getDefaultInterval(): int
    {
        return 60;
    }
}
