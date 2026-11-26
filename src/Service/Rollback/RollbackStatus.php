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
namespace MuckiSafeAgentPlugin\Service\Rollback;

/** Readback of the rollback switch and the open jobs (runbook gate). */
final readonly class RollbackStatus
{
    public bool $readyForHookRemoval;

    /** @param array{queued: int, claimed: int, running: int, cancel_requested: int} $openJobs */
    public function __construct(
        public bool $active,
        public ?string $startedAt,
        public ?string $stoppedAt,
        public array $openJobs,
        public int $unknownJobs
    ) {
        $this->readyForHookRemoval = array_sum($openJobs) === 0;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'active' => $this->active,
            'startedAt' => $this->startedAt,
            'stoppedAt' => $this->stoppedAt,
            'openJobs' => $this->openJobs,
            'unknownJobs' => $this->unknownJobs,
            'readyForHookRemoval' => $this->readyForHookRemoval,
        ];
    }
}
