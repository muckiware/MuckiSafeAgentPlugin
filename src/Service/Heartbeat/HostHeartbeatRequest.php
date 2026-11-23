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

final readonly class HostHeartbeatRequest
{
    /** @param list<string> $capabilities */
    public function __construct(
        public string $agentVersion,
        public string $platform,
        public string $arch,
        public array $capabilities,
        public string $agentTime
    ) {
    }
}
