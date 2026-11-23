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

final readonly class JobClaimResponse
{
    public function __construct(public ?JobClaim $job)
    {
    }

    /** @return array{job: null|array<string, mixed>} */
    public function toArray(): array
    {
        return ['job' => $this->job?->toArray()];
    }
}
