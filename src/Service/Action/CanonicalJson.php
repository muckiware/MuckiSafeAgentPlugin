<?php declare(strict_types=1);
/**
 * MuckiSafeAgentPlugin
 * @category SW6 Plugin
 * @package MuckiSafeAgent
 * @copyright Copyright (c) 2026 by Muckiware
 * @license MIT
 * @author Muckiware
 */

namespace MuckiSafeAgentPlugin\Service\Action;

final class CanonicalJson
{
    private const int MAX_DEPTH = 32;

    public static function encode(mixed $value): string
    {
        return self::encodeValue($value, 0);
    }

    public static function hash(mixed $value): string
    {
        return hash('sha256', self::encode($value));
    }

    private static function encodeValue(mixed $value, int $depth): string
    {
        if ($depth > self::MAX_DEPTH) {
            throw new ActionDefinitionException('JSON nesting exceeds 32 levels.');
        }
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            throw new ActionDefinitionException('Floating-point numbers are forbidden.');
        }
        if (is_string($value)) {
            if (preg_match('//u', $value) !== 1) {
                throw new ActionDefinitionException('Strings must be valid UTF-8.');
            }
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS);
        }
        if ($value instanceof \stdClass) {
            $properties = get_object_vars($value);
            uksort($properties, static fn (string $left, string $right): int => strcmp($left, $right));
            $parts = [];
            foreach ($properties as $key => $item) {
                $parts[] = self::encodeValue($key, $depth + 1) . ':' . self::encodeValue($item, $depth + 1);
            }
            return '{' . implode(',', $parts) . '}';
        }
        if (is_array($value) && array_is_list($value)) {
            return '[' . implode(',', array_map(
                static fn (mixed $item): string => self::encodeValue($item, $depth + 1),
                $value
            )) . ']';
        }
        throw new ActionDefinitionException('Canonical JSON accepts only objects, arrays, strings, integers, booleans and null.');
    }
}
