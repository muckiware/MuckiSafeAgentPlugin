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

final readonly class JobClaim
{
    public function __construct(
        public string $id,
        #[\SensitiveParameter] public string $claimToken,
        public int $leaseVersion,
        public string $leaseExpiresAt,
        public string $phaseDeadline,
        public string $executionDeadline,
        public string $stopDeadline,
        public string $phase,
        public string $idempotencyClass,
        public string $definitionCanonical,
        public string $actionHash,
        public \stdClass $parameters
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'claimToken' => $this->claimToken,
            'leaseVersion' => $this->leaseVersion,
            'leaseExpiresAt' => $this->leaseExpiresAt,
            'phaseDeadline' => $this->phaseDeadline,
            'executionDeadline' => $this->executionDeadline,
            'stopDeadline' => $this->stopDeadline,
            'phase' => $this->phase,
            'idempotencyClass' => $this->idempotencyClass,
            'action' => [
                'definitionCanonical' => $this->definitionCanonical,
                'actionHash' => $this->actionHash,
            ],
            'parameters' => $this->parameters,
        ];
    }
}
