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
namespace MuckiSafeAgentPlugin\Service\Heartbeat;

use MuckiSafeAgentPlugin\Service\Auth\AgentErrorCode;

final class HostHeartbeatPayloadException extends \InvalidArgumentException
{
    public function __construct(public readonly AgentErrorCode $errorCode)
    {
        parent::__construct('Invalid heartbeat payload.');
    }
}
