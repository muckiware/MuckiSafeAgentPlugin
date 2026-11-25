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
namespace MuckiSafeAgentPlugin\Service\Action;

use MuckiSafeAgentPlugin\Core\Job\IdempotencyClass;
use MuckiSafeAgentPlugin\Core\Job\JobPhase;

/**
 * Admin payload of the action editor, validated field by field so the editor
 * can mark every rejected field at once. The definition stays raw JSON text;
 * ActionConfigurator decodes, validates and canonicalizes it. Capabilities,
 * hash, retry policy and parameter schema are server-derived, never input.
 */
final readonly class ActionDraft
{
    public const MAX_TIMEOUT_SECONDS = 1800;

    private const REQUIRED = ['technicalName', 'label', 'enabled', 'definition', 'defaultTimeoutSeconds', 'maxTimeoutSeconds', 'idempotencyClass'];
    private const OPTIONAL = ['id'];
    private const TECHNICAL_NAME_PATTERN = '/^[a-z][a-z0-9]*(?:[-_][a-z0-9]+)*$/D';

    private function __construct(
        public ?string $id,
        public string $technicalName,
        public string $label,
        public bool $enabled,
        public string $definition,
        public int $defaultTimeoutSeconds,
        public int $maxTimeoutSeconds,
        public IdempotencyClass $idempotencyClass
    ) {
    }

    /** @param array<mixed> $payload */
    public static function fromPayload(array $payload): self
    {
        $keys = array_keys($payload);
        if (array_diff(self::REQUIRED, $keys) !== [] || array_diff($keys, self::REQUIRED, self::OPTIONAL) !== []) {
            throw new ActionConfigurationException(ActionConfigurationException::INVALID_REQUEST, 'The action payload has missing or unknown fields.');
        }
        $invalid = array_keys(array_filter(self::fieldChecks($payload), static fn (bool $valid): bool => !$valid));
        if ($invalid !== []) {
            throw ActionConfigurationException::invalidFields($invalid);
        }

        return new self(
            $payload['id'] ?? null,
            $payload['technicalName'],
            trim($payload['label']),
            $payload['enabled'],
            $payload['definition'],
            $payload['defaultTimeoutSeconds'],
            $payload['maxTimeoutSeconds'],
            IdempotencyClass::from($payload['idempotencyClass'])
        );
    }

    /**
     * @param array<mixed> $payload
     * @return array<string, bool>
     */
    private static function fieldChecks(array $payload): array
    {
        $max = $payload['maxTimeoutSeconds'];
        $maxValid = is_int($max) && $max >= JobPhase::MIN_BUDGET_SECONDS && $max <= self::MAX_TIMEOUT_SECONDS;
        $default = $payload['defaultTimeoutSeconds'];
        $label = is_string($payload['label']) ? trim($payload['label']) : '';
        $name = $payload['technicalName'];

        return [
            'id' => !array_key_exists('id', $payload) || (is_string($payload['id']) && preg_match('/^[0-9a-f]{32}$/D', $payload['id']) === 1),
            'technicalName' => is_string($name) && strlen($name) <= 64 && preg_match(self::TECHNICAL_NAME_PATTERN, $name) === 1,
            'label' => $label !== '' && mb_strlen($label) <= 255,
            'enabled' => is_bool($payload['enabled']),
            'definition' => is_string($payload['definition']),
            'defaultTimeoutSeconds' => is_int($default) && $default >= JobPhase::MIN_BUDGET_SECONDS && (!$maxValid || $default <= $max),
            'maxTimeoutSeconds' => $maxValid,
            'idempotencyClass' => is_string($payload['idempotencyClass']) && IdempotencyClass::tryFrom($payload['idempotencyClass']) !== null,
        ];
    }
}
