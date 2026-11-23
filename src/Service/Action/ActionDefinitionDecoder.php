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

/** Strict raw JSON decoder used before map/object conversion loses duplicates. */
final class ActionDefinitionDecoder
{
    private int $offset = 0;
    private int $length = 0;

    public function __construct(private readonly ActionDefinitionValidator $validator = new ActionDefinitionValidator())
    {
    }

    public function decode(string $json): \stdClass
    {
        if ($json === '' || strlen($json) > 262144 || str_starts_with($json, "\xEF\xBB\xBF") || preg_match('//u', $json) !== 1) {
            throw new ActionDefinitionException('Action must be bounded non-BOM UTF-8 JSON.');
        }
        $this->offset = 0;
        $this->length = strlen($json);
        $this->scanValue($json, 0);
        $this->whitespace($json);
        if ($this->offset !== $this->length) {
            throw new ActionDefinitionException('Trailing JSON data is forbidden.');
        }
        try {
            $definition = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new ActionDefinitionException('Invalid action JSON.', previous: $exception);
        }
        if (!$definition instanceof \stdClass) {
            throw new ActionDefinitionException('Action must be an object.');
        }
        $this->validator->validate($definition);
        return $definition;
    }

    private function scanValue(string $json, int $depth): void
    {
        if ($depth > 32) {
            throw new ActionDefinitionException('JSON nesting exceeds 32 levels.');
        }
        $this->whitespace($json);
        $character = $json[$this->offset] ?? '';
        if ($character === '{') {
            $this->scanObject($json, $depth + 1);
            return;
        }
        if ($character === '[') {
            $this->scanArray($json, $depth + 1);
            return;
        }
        if ($character === '"') {
            $this->scanString($json);
            return;
        }
        foreach (['true', 'false', 'null'] as $literal) {
            if (substr($json, $this->offset, strlen($literal)) === $literal) {
                $this->offset += strlen($literal);
                return;
            }
        }
        $remaining = substr($json, $this->offset);
        if (preg_match('/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?/', $remaining, $match) === 1) {
            $token = $match[0];
            $this->offset += strlen($token);
            if ($token === '-0' || strpbrk($token, '.eE') !== false) {
                throw new ActionDefinitionException('Only canonical integers are allowed.');
            }
            $decoded = json_decode($token, false, 512, JSON_THROW_ON_ERROR);
            if (!is_int($decoded)) {
                throw new ActionDefinitionException('Integer outside platform range.');
            }
            return;
        }
        throw new ActionDefinitionException('Invalid JSON value.');
    }

    private function scanObject(string $json, int $depth): void
    {
        ++$this->offset;
        $this->whitespace($json);
        $keys = [];
        if (($json[$this->offset] ?? '') === '}') {
            ++$this->offset;
            return;
        }
        while (true) {
            $this->whitespace($json);
            if (($json[$this->offset] ?? '') !== '"') {
                throw new ActionDefinitionException('Object key must be a string.');
            }
            $rawKey = $this->scanString($json);
            try {
                $key = json_decode($rawKey, false, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $exception) {
                throw new ActionDefinitionException('Invalid object key.', previous: $exception);
            }
            if (isset($keys[$key])) {
                throw new ActionDefinitionException('Duplicate object member: ' . $key);
            }
            $keys[$key] = true;
            $this->whitespace($json);
            if (($json[$this->offset] ?? '') !== ':') {
                throw new ActionDefinitionException('Missing object colon.');
            }
            ++$this->offset;
            $this->scanValue($json, $depth);
            $this->whitespace($json);
            $separator = $json[$this->offset] ?? '';
            ++$this->offset;
            if ($separator === '}') {
                return;
            }
            if ($separator !== ',') {
                throw new ActionDefinitionException('Invalid object separator.');
            }
        }
    }

    private function scanArray(string $json, int $depth): void
    {
        ++$this->offset;
        $this->whitespace($json);
        if (($json[$this->offset] ?? '') === ']') {
            ++$this->offset;
            return;
        }
        while (true) {
            $this->scanValue($json, $depth);
            $this->whitespace($json);
            $separator = $json[$this->offset] ?? '';
            ++$this->offset;
            if ($separator === ']') {
                return;
            }
            if ($separator !== ',') {
                throw new ActionDefinitionException('Invalid array separator.');
            }
        }
    }

    /** Returns the raw JSON string token including quotes. */
    private function scanString(string $json): string
    {
        $start = $this->offset++;
        while ($this->offset < $this->length) {
            $byte = ord($json[$this->offset]);
            if ($byte < 0x20) {
                throw new ActionDefinitionException('Unescaped control character in string.');
            }
            if ($json[$this->offset] === '"') {
                ++$this->offset;
                return substr($json, $start, $this->offset - $start);
            }
            if ($json[$this->offset] === '\\') {
                ++$this->offset;
                $escape = $json[$this->offset] ?? '';
                if ($escape === 'u') {
                    $hex = substr($json, $this->offset + 1, 4);
                    if (strlen($hex) !== 4 || !ctype_xdigit($hex)) {
                        throw new ActionDefinitionException('Invalid Unicode escape.');
                    }
                    $this->offset += 5;
                    continue;
                }
                if (!str_contains('"\\/bfnrt', $escape)) {
                    throw new ActionDefinitionException('Invalid string escape.');
                }
            }
            ++$this->offset;
        }
        throw new ActionDefinitionException('Unterminated string.');
    }

    private function whitespace(string $json): void
    {
        while ($this->offset < $this->length && str_contains(" \t\r\n", $json[$this->offset])) {
            ++$this->offset;
        }
    }
}
