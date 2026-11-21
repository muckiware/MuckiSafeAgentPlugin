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
namespace MuckiSafeAgentPlugin\Exception;

/**
 * Thrown when the MuckiFacilityPlugin dependency of the MuckiSafeAgentPlugin
 * is not satisfied. Carries a stable reason code so callers (activation
 * lifecycle, admin health, agent API, subscribers) can fail closed into the
 * state `facility_unavailable` without inspecting message texts.
 */
final class FacilityDependencyException extends \RuntimeException
{
    /** Facility plugin is not present in this Shopware installation, or present but never installed. */
    public const REASON_NOT_INSTALLED = 'not_installed';

    /** Facility plugin is installed, but not active. */
    public const REASON_INACTIVE = 'inactive';

    /** Facility plugin is active, but older than the required minimum version. */
    public const REASON_INCOMPATIBLE_VERSION = 'incompatible_version';

    public function __construct(
        private readonly string $reason,
        string $message
    ) {
        parent::__construct($message);
    }

    public function getReason(): string
    {
        return $this->reason;
    }
}
