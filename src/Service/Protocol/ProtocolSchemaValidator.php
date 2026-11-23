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
namespace MuckiSafeAgentPlugin\Service\Protocol;

/** Validator for the closed JSON-Schema subset used by protocol V1 DTOs. */
final class ProtocolSchemaValidator
{
    private const SUPPORTED_KEYWORDS = [
        '$schema', '$id', 'title', 'type', 'additionalProperties', 'required', 'properties',
        'minLength', 'maxLength', 'pattern', 'enum', 'minItems', 'maxItems', 'uniqueItems', 'items',
        'minimum', 'maximum',
    ];

    public function firstViolation(\stdClass $schema, mixed $value): ?string
    {
        foreach (array_keys(get_object_vars($schema)) as $keyword) {
            if (!in_array($keyword, self::SUPPORTED_KEYWORDS, true)) {
                throw new \LogicException('Unsupported protocol schema keyword ' . $keyword);
            }
        }
        if (isset($schema->type) && !$this->matchesType($schema->type, $value)) {
            return 'type';
        }
        if (isset($schema->enum) && !in_array($value, $schema->enum, true)) {
            return 'enum';
        }
        return match (true) {
            is_string($value) => $this->stringViolation($schema, $value),
            is_int($value) => $this->integerViolation($schema, $value),
            is_array($value) => $this->arrayViolation($schema, $value),
            $value instanceof \stdClass => $this->objectViolation($schema, $value),
            default => null,
        };
    }

    /** @param string|list<string> $type */
    private function matchesType(string|array $type, mixed $value): bool
    {
        foreach ((array) $type as $candidate) {
            if (match ($candidate) {
                'object' => $value instanceof \stdClass,
                'array' => is_array($value),
                'string' => is_string($value),
                'integer' => is_int($value),
                'boolean' => is_bool($value),
                'null' => $value === null,
                default => false,
            }) {
                return true;
            }
        }
        return false;
    }

    private function stringViolation(\stdClass $schema, string $value): ?string
    {
        $length = mb_strlen($value, 'UTF-8');
        if (isset($schema->minLength) && $length < $schema->minLength) {
            return 'minLength';
        }
        if (isset($schema->maxLength) && $length > $schema->maxLength) {
            return 'maxLength';
        }
        if (isset($schema->pattern) && preg_match('/' . str_replace('/', '\\/', $schema->pattern) . '/Du', $value) !== 1) {
            return 'pattern';
        }
        return null;
    }

    private function integerViolation(\stdClass $schema, int $value): ?string
    {
        if (isset($schema->minimum) && $value < $schema->minimum) {
            return 'minimum';
        }
        if (isset($schema->maximum) && $value > $schema->maximum) {
            return 'maximum';
        }
        return null;
    }

    /** @param array<mixed> $value */
    private function arrayViolation(\stdClass $schema, array $value): ?string
    {
        if (isset($schema->minItems) && count($value) < $schema->minItems) {
            return 'minItems';
        }
        if (isset($schema->maxItems) && count($value) > $schema->maxItems) {
            return 'maxItems';
        }
        $encoded = array_map(static fn (mixed $item): string => json_encode($item, JSON_THROW_ON_ERROR), $value);
        if (($schema->uniqueItems ?? false) === true && count(array_unique($encoded)) !== count($encoded)) {
            return 'uniqueItems';
        }
        foreach ($value as $item) {
            if (isset($schema->items) && $this->firstViolation($schema->items, $item) !== null) {
                return 'items';
            }
        }
        return null;
    }

    private function objectViolation(\stdClass $schema, \stdClass $value): ?string
    {
        $properties = isset($schema->properties) ? get_object_vars($schema->properties) : [];
        foreach ($schema->required ?? [] as $required) {
            if (!property_exists($value, $required)) {
                return 'required';
            }
        }
        foreach (get_object_vars($value) as $name => $propertyValue) {
            if (!array_key_exists($name, $properties)) {
                return ($schema->additionalProperties ?? true) === false ? 'additionalProperties' : null;
            }
            if ($this->firstViolation($properties[$name], $propertyValue) !== null) {
                return 'properties';
            }
        }
        return null;
    }
}
