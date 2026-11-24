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

final readonly class JobHeartbeatResponse
{
    public function __construct(
        public int $leaseVersion,
        public string $leaseExpiresAt,
        public bool $stopRequested,
        public ?string $stopDeadline = null
    ) {
        if ($stopRequested !== ($stopDeadline !== null)) {
            throw new \InvalidArgumentException('Stop deadline must be present exactly when stop is requested.');
        }
    }

    /** @return array{leaseVersion: int, leaseExpiresAt: string, stopRequested: bool, stopDeadline?: string} */
    public function toArray(): array
    {
        $response = [
            'leaseVersion' => $this->leaseVersion,
            'leaseExpiresAt' => $this->leaseExpiresAt,
            'stopRequested' => $this->stopRequested,
        ];
        if ($this->stopDeadline !== null) {
            $response['stopDeadline'] = $this->stopDeadline;
        }
        return $response;
    }
}
