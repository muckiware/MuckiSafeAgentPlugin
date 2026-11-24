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
namespace MuckiSafeAgentPlugin\Api;

final readonly class JobCompleteResponse
{
    public function __construct(
        public string $terminalStatus
    ) {
    }

    /** @return array{accepted: true, terminalStatus: string} */
    public function toArray(): array
    {
        return [
            'accepted' => true,
            'terminalStatus' => $this->terminalStatus,
        ];
    }
}
