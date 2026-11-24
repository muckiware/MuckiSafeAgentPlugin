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
 * Rejected hook configuration. The error code is a stable, client-facing
 * identifier; `fields` names the affected Facility fields of a legacy
 * conflict and is empty otherwise.
 */
final class HookConfigurationException extends \RuntimeException
{
    public const INVALID_REQUEST = 'invalid_request';
    public const TIMEOUT_OUT_OF_RANGE = 'timeout_out_of_range';
    public const FACILITY_UNAVAILABLE = 'facility_unavailable';
    public const FACILITY_REPOSITORY_NOT_FOUND = 'facility_repository_not_found';
    public const LEGACY_HOOK_CONFLICT = 'legacy_hook_conflict';
    public const AGENT_UNAVAILABLE = 'agent_unavailable';
    public const CAPABILITY_MISSING = 'capability_missing';
    public const ACTION_UNAVAILABLE = 'action_unavailable';
    public const HOOK_NOT_FOUND = 'hook_not_found';

    /** @param list<string> $fields */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly array $fields = [],
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    /** @param list<string> $fields */
    public static function legacyConflict(array $fields): self
    {
        return new self(
            self::LEGACY_HOOK_CONFLICT,
            'The Facility repository still has active legacy hooks: ' . implode(', ', $fields) . '.',
            $fields
        );
    }
}
