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

use MuckiSafeAgentPlugin\Core\Job\JobPhase;

/**
 * Strictly decoded hook configuration request. `timeoutSeconds` is the phase
 * budget of the hook: it is bounded by the server-side phase limits here and
 * by the action's maximum in RepositoryHookConfigurator.
 */
final readonly class RepositoryHookDraft
{
    private const REQUIRED = ['facilityRepositoryId', 'phase', 'agentHostId', 'agentActionId'];
    private const OPTIONAL = ['timeoutSeconds', 'failOnError', 'enabled', 'parameters'];

    private function __construct(
        public string $facilityRepositoryId,
        public JobPhase $phase,
        public string $agentHostId,
        public string $agentActionId,
        public int $timeoutSeconds,
        public bool $failOnError,
        public bool $enabled
    ) {
    }

    /** @param array<mixed> $payload */
    public static function fromPayload(array $payload): self
    {
        $keys = array_keys($payload);
        if (array_diff(self::REQUIRED, $keys) !== [] || array_diff($keys, self::REQUIRED, self::OPTIONAL) !== []) {
            throw self::invalid('The hook payload has missing or unknown fields.');
        }
        $phase = is_string($payload['phase']) ? JobPhase::tryFrom($payload['phase']) : null;
        if ($phase === null) {
            throw self::invalid('The hook phase is invalid.');
        }
        $parameters = $payload['parameters'] ?? [];
        if ($parameters !== [] && !($parameters instanceof \stdClass && get_object_vars($parameters) === [])) {
            throw self::invalid('V1 hook parameters must be an empty object.');
        }

        return new self(
            self::id($payload['facilityRepositoryId']),
            $phase,
            self::id($payload['agentHostId']),
            self::id($payload['agentActionId']),
            self::budget($payload['timeoutSeconds'] ?? $phase->defaultBudgetSeconds(), $phase),
            self::bool($payload['failOnError'] ?? true),
            self::bool($payload['enabled'] ?? true)
        );
    }

    private static function id(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^[0-9a-f]{32}$/D', $value) !== 1) {
            throw self::invalid('Hook identifiers must be lowercase hexadecimal UUIDs.');
        }

        return $value;
    }

    private static function budget(mixed $value, JobPhase $phase): int
    {
        if (!is_int($value)) {
            throw self::invalid('The hook budget must be an integer.');
        }
        if ($value < JobPhase::MIN_BUDGET_SECONDS || $value > $phase->maxBudgetSeconds()) {
            throw new HookConfigurationException(
                HookConfigurationException::TIMEOUT_OUT_OF_RANGE,
                'The hook budget is outside the phase limits.'
            );
        }

        return $value;
    }

    private static function bool(mixed $value): bool
    {
        if (!is_bool($value)) {
            throw self::invalid('Hook flags must be booleans.');
        }

        return $value;
    }

    private static function invalid(string $message): HookConfigurationException
    {
        return new HookConfigurationException(HookConfigurationException::INVALID_REQUEST, $message);
    }
}
