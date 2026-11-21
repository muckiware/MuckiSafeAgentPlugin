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
namespace MuckiSafeAgentPlugin\Core\Job;

enum JobStatus: string
{
    case Queued = 'queued';
    case Claimed = 'claimed';
    case Running = 'running';
    case CancelRequested = 'cancel_requested';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case TimedOut = 'timed_out';
    case Expired = 'expired';
    case Unknown = 'unknown';
}
