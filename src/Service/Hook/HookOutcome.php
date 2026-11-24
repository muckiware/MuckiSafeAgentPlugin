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
namespace MuckiSafeAgentPlugin\Service\Hook;

/** Terminal result of one executed hook; `jobId` is null when no job could be created. */
final readonly class HookOutcome
{
    public function __construct(
        public string $status,
        public ?string $jobId
    ) {
    }

    public function succeeded(): bool
    {
        return $this->status === 'succeeded';
    }
}
