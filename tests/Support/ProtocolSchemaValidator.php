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
namespace MuckiSafeAgentPlugin\tests\Support;

/**
 * Minimal JSON-Schema validator for exactly the keyword subset declared in
 * tests/Fixtures/protocol/dtos-v1.json. The Go agent tests implement the same
 * subset (agent/internal/api/schema_test.go); any other keyword in a protocol
 * schema fails loudly so that both sides cannot silently drift apart.
 */
final class ProtocolSchemaValidator
{
    /** @param list<string> $supportedKeywords */
    public function __construct(private readonly array $supportedKeywords)
    {
    }

    /** Returns the first violated keyword or null when the value is valid. */
    public function firstViolation(\stdClass $schema, mixed $value): ?string
    {
        $this->assertSupported($schema);
        if (isset($schema->type) && !$this->matchesType($schema->type, $value)) {
            return 'type';
        }
        if (property_exists($schema, 'const') && $value !== $schema->const) {
            return 'const';
        }
        if (isset($schema->enum) && !in_array($value, $schema->enum, true)) {
            return 'enum';
        }
        $baseViolation = match (true) {
            is_string($value) => $this->stringViolation($schema, $value),
            is_int($value) => $this->integerViolation($schema, $value),
            is_array($value) => $this->arrayViolation($schema, $value),
            $value instanceof \stdClass => $this->objectViolation($schema, $value),
            default => null,
        };
        if ($baseViolation !== null) {
            return $baseViolation;
        }
        if (isset($schema->oneOf)) {
            $matches = 0;
            foreach ($schema->oneOf as $subschema) {
                if (!$subschema instanceof \stdClass) {
                    throw new \LogicException('Schema oneOf member must be an object.');
                }
                if ($this->firstViolation($subschema, $value) === null) {
                    ++$matches;
                }
            }
            if ($matches !== 1) {
                return 'oneOf';
            }
        }
        if (isset($schema->not)) {
            if (!$schema->not instanceof \stdClass) {
                throw new \LogicException('Schema not member must be an object.');
            }
            if ($this->firstViolation($schema->not, $value) === null) {
                return 'not';
            }
        }
        return null;
    }

    private function assertSupported(\stdClass $schema): void
    {
        foreach (array_keys(get_object_vars($schema)) as $keyword) {
            if (!in_array($keyword, $this->supportedKeywords, true)) {
                throw new \LogicException('Unsupported schema keyword ' . $keyword);
            }
        }
    }

    /** @param string|list<string> $type */
    private function matchesType(string|array $type, mixed $value): bool
    {
        foreach ((array) $type as $candidate) {
            $matches = match ($candidate) {
                'object' => $value instanceof \stdClass,
                'array' => is_array($value),
                'string' => is_string($value),
                'integer' => is_int($value),
                'boolean' => is_bool($value),
                'null' => $value === null,
                default => throw new \LogicException('Unsupported schema type ' . $candidate),
            };
            if ($matches) {
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
        if (isset($schema->pattern) && preg_match('/' . str_replace('/', '\/', $schema->pattern) . '/Du', $value) !== 1) {
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
            $violation = isset($schema->items) ? $this->firstViolation($schema->items, $item) : null;
            if ($violation !== null) {
                return $violation;
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
                if (($schema->additionalProperties ?? true) === false) {
                    return 'additionalProperties';
                }
                continue;
            }
            $violation = $this->firstViolation($properties[$name], $propertyValue);
            if ($violation !== null) {
                return $violation;
            }
        }
        return null;
    }
}
