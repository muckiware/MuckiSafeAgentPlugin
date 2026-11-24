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

/**
 * Thrown into the Facility backup lifecycle when an agent hook blocks the
 * backup. The error code is also persisted as the run's primary error.
 */
final class AgentHookFailure extends \RuntimeException
{
    public const LEGACY_HOOK_CONFLICT = 'legacy_hook_conflict';
    public const AGENT_RUN_ACTIVE = 'agent_run_active';
    public const RUN_MISSING = 'agent_run_missing';

    public function __construct(public readonly string $errorCode, string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public static function phaseFailed(string $phase, string $status): self
    {
        return new self($phase . '_hook_failed', sprintf('Agent %s hook ended with status %s.', $phase, $status));
    }
}
