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

/** Duplicate-aware, bounded raw JSON object decoder shared by protocol DTOs. */
final class StrictJsonObjectDecoder
{
    private int $offset = 0;
    private int $length = 0;

    public function decode(string $json, int $maxBytes): \stdClass
    {
        if ($json === '' || strlen($json) > $maxBytes || str_starts_with($json, "\xEF\xBB\xBF") || preg_match('//u', $json) !== 1) {
            throw new \InvalidArgumentException('Invalid bounded UTF-8 JSON.');
        }
        $this->offset = 0;
        $this->length = strlen($json);
        $this->scanValue($json, 0);
        $this->whitespace($json);
        if ($this->offset !== $this->length) {
            throw new \InvalidArgumentException('Trailing JSON data is forbidden.');
        }
        try {
            $decoded = json_decode($json, false, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \InvalidArgumentException('Invalid JSON.', 0, $exception);
        }
        if (!$decoded instanceof \stdClass) {
            throw new \InvalidArgumentException('JSON root must be an object.');
        }

        return $decoded;
    }

    private function scanValue(string $json, int $depth): void
    {
        if ($depth > 32) {
            throw new \InvalidArgumentException('JSON nesting exceeds 32 levels.');
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
            $this->offset += strlen($match[0]);
            return;
        }
        throw new \InvalidArgumentException('Invalid JSON value.');
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
                throw new \InvalidArgumentException('Object key must be a string.');
            }
            $rawKey = $this->scanString($json);
            try {
                $key = json_decode($rawKey, false, 32, JSON_THROW_ON_ERROR);
            } catch (\JsonException $exception) {
                throw new \InvalidArgumentException('Invalid object key.', 0, $exception);
            }
            if (!is_string($key) || array_key_exists($key, $keys)) {
                throw new \InvalidArgumentException('Duplicate or invalid object member.');
            }
            $keys[$key] = true;
            $this->whitespace($json);
            if (($json[$this->offset] ?? '') !== ':') {
                throw new \InvalidArgumentException('Missing object colon.');
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
                throw new \InvalidArgumentException('Invalid object separator.');
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
                throw new \InvalidArgumentException('Invalid array separator.');
            }
        }
    }

    private function scanString(string $json): string
    {
        $start = $this->offset++;
        while ($this->offset < $this->length) {
            $byte = ord($json[$this->offset]);
            if ($byte < 0x20) {
                throw new \InvalidArgumentException('Unescaped control character in string.');
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
                        throw new \InvalidArgumentException('Invalid Unicode escape.');
                    }
                    $this->offset += 5;
                    continue;
                }
                if (!str_contains('"\\/bfnrt', $escape)) {
                    throw new \InvalidArgumentException('Invalid string escape.');
                }
            }
            ++$this->offset;
        }
        throw new \InvalidArgumentException('Unterminated string.');
    }

    private function whitespace(string $json): void
    {
        while ($this->offset < $this->length && str_contains(" \t\r\n", $json[$this->offset])) {
            ++$this->offset;
        }
    }
}
