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
namespace MuckiSafeAgentPlugin\Service\Job;

/** Number of jobs each reaper transition moved during one run. */
final readonly class ReapResult
{
    public function __construct(
        public int $requeued,
        public int $expired,
        public int $unknown,
        public int $timedOut,
        public int $cancelRequested
    ) {
    }

    public function total(): int
    {
        return $this->requeued + $this->expired + $this->unknown + $this->timedOut + $this->cancelRequested;
    }
}
