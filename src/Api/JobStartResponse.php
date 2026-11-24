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

final readonly class JobStartResponse
{
    public function __construct(
        public int $leaseVersion,
        public string $leaseExpiresAt,
        public string $phaseDeadline,
        public string $executionDeadline,
        public string $stopDeadline
    ) {
    }

    /** @return array{leaseVersion: int, leaseExpiresAt: string, phaseDeadline: string, executionDeadline: string, stopDeadline: string} */
    public function toArray(): array
    {
        return [
            'leaseVersion' => $this->leaseVersion,
            'leaseExpiresAt' => $this->leaseExpiresAt,
            'phaseDeadline' => $this->phaseDeadline,
            'executionDeadline' => $this->executionDeadline,
            'stopDeadline' => $this->stopDeadline,
        ];
    }
}
