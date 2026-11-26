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

/** Jobs a rollback drain ended (timed out) or asked to stop (cancel requested). */
final readonly class DrainResult
{
    public function __construct(
        public int $timedOut,
        public int $cancelRequested
    ) {
    }
}
