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
namespace MuckiSafeAgentPlugin\Service\Secret;

/**
 * Agent secret keys from MUWA_AGENT_ENCRYPTION_KEYRING
 * ({"active":N,"keys":{"N":"<64 hex>"}}).
 *
 * The keyring is validated on first use, not while the service is built: the
 * authentication subscriber needs this service on every request, so a
 * missing or broken keyring would otherwise take down the whole application
 * instead of only the agent operations. Every access then fails closed with
 * EncryptionKeyringException, again on each call.
 */
final class EncryptionKeyProvider
{
    private const MISSING = 'missing';
    private const INVALID = 'invalid';

    /** @var array<int, string> */
    private array $keys = [];
    private int $active;
    private int $offset = 0;
    private string $json = '';
    private ?string $pending;
    private ?string $failure = null;

    public function __construct(#[\SensitiveParameter] string $keyringJson)
    {
        $this->pending = $keyringJson;
    }

    public function activeVersion(): int
    {
        $this->load();
        return $this->active;
    }

    public function activeKey(): string
    {
        $this->load();
        return $this->keys[$this->active];
    }

    public function keyForVersion(int $version): string
    {
        $this->load();
        if (!isset($this->keys[$version])) {
            throw new \RuntimeException('Unknown encryption key version.');
        }
        return $this->keys[$version];
    }

    /** @return list<int> */
    public function versions(): array
    {
        $this->load();
        return array_keys($this->keys);
    }

    private function load(): void
    {
        if ($this->failure !== null) {
            throw $this->failure === self::MISSING ? EncryptionKeyringException::notConfigured() : EncryptionKeyringException::invalid();
        }
        if ($this->pending === null) {
            return;
        }
        $keyringJson = $this->pending;
        $this->pending = null;
        if (trim($keyringJson) === '') {
            $this->failure = self::MISSING;
            throw EncryptionKeyringException::notConfigured();
        }
        try {
            $this->parse($keyringJson);
        } catch (\Throwable $exception) {
            $this->keys = [];
            $this->failure = self::INVALID;
            throw EncryptionKeyringException::invalid($exception);
        } finally {
            $this->json = '';
            $this->offset = 0;
        }
    }

    private function parse(#[\SensitiveParameter] string $keyringJson): void
    {
        $this->json = $keyringJson;
        $value = $this->parseValue();
        $this->skipWhitespace();
        if ($this->offset !== strlen($this->json) || !is_array($value) || array_is_list($value)) {
            throw new \UnexpectedValueException();
        }
        if (array_keys($value) !== ['active', 'keys'] && array_keys($value) !== ['keys', 'active']) {
            throw new \UnexpectedValueException();
        }
        if (!is_int($value['active'] ?? null) || $value['active'] < 1) {
            throw new \UnexpectedValueException();
        }
        $this->keys = $this->decodeKeys($value['keys'] ?? null);
        $this->active = $value['active'];
        if (!isset($this->keys[$this->active])) {
            throw new \UnexpectedValueException();
        }
    }

    /** @return array<int, string> */
    private function decodeKeys(#[\SensitiveParameter] mixed $rawKeys): array
    {
        if (!is_array($rawKeys) || $rawKeys === [] || array_is_list($rawKeys)) {
            throw new \UnexpectedValueException();
        }
        $keys = [];
        foreach ($rawKeys as $version => $hex) {
            $version = (string) $version;
            if (!preg_match('/^[1-9][0-9]*$/D', $version) || (string) (int) $version !== $version) {
                throw new \UnexpectedValueException();
            }
            if (!is_string($hex) || !preg_match('/^[0-9a-fA-F]{64}$/D', $hex)) {
                throw new \UnexpectedValueException();
            }
            $decoded = hex2bin($hex);
            if (!is_string($decoded) || strlen($decoded) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
                throw new \UnexpectedValueException();
            }
            $keys[(int) $version] = $decoded;
        }
        ksort($keys, SORT_NUMERIC);
        return $keys;
    }

    /** @return mixed */
    private function parseValue(): mixed
    {
        $this->skipWhitespace();
        $character = $this->json[$this->offset] ?? '';
        if ($character === '{') {
            return $this->parseObject();
        }
        if ($character === '[') {
            return $this->parseArray();
        }
        if ($character === '"') {
            return $this->parseString();
        }
        if ($character === '-' || ctype_digit($character)) {
            return $this->parseNumber();
        }
        foreach (['true' => true, 'false' => false, 'null' => null] as $literal => $value) {
            if (substr($this->json, $this->offset, strlen($literal)) === $literal) {
                $this->offset += strlen($literal);
                return $value;
            }
        }
        throw new \UnexpectedValueException();
    }

    /** @return array<string, mixed> */
    private function parseObject(): array
    {
        ++$this->offset;
        $result = [];
        $seen = [];
        $this->skipWhitespace();
        if (($this->json[$this->offset] ?? '') === '}') {
            ++$this->offset;
            return $result;
        }
        while (true) {
            $this->skipWhitespace();
            if (($this->json[$this->offset] ?? '') !== '"') {
                throw new \UnexpectedValueException();
            }
            $key = $this->parseString();
            if (isset($seen[$key])) {
                throw new \UnexpectedValueException('Duplicate object member.');
            }
            $seen[$key] = true;
            $this->skipWhitespace();
            if (($this->json[$this->offset] ?? '') !== ':') {
                throw new \UnexpectedValueException();
            }
            ++$this->offset;
            $result[$key] = $this->parseValue();
            $this->skipWhitespace();
            $delimiter = $this->json[$this->offset] ?? '';
            ++$this->offset;
            if ($delimiter === '}') {
                return $result;
            }
            if ($delimiter !== ',') {
                throw new \UnexpectedValueException();
            }
        }
    }

    /** @return list<mixed> */
    private function parseArray(): array
    {
        ++$this->offset;
        $result = [];
        $this->skipWhitespace();
        if (($this->json[$this->offset] ?? '') === ']') {
            ++$this->offset;
            return $result;
        }
        while (true) {
            $result[] = $this->parseValue();
            $this->skipWhitespace();
            $delimiter = $this->json[$this->offset] ?? '';
            ++$this->offset;
            if ($delimiter === ']') {
                return $result;
            }
            if ($delimiter !== ',') {
                throw new \UnexpectedValueException();
            }
        }
    }

    private function parseString(): string
    {
        $start = $this->offset;
        ++$this->offset;
        $escaped = false;
        while ($this->offset < strlen($this->json)) {
            $character = $this->json[$this->offset++];
            if (!$escaped && $character === '"') {
                $encoded = substr($this->json, $start, $this->offset - $start);
                $decoded = json_decode($encoded, true, 2, JSON_THROW_ON_ERROR);
                if (!is_string($decoded)) {
                    throw new \UnexpectedValueException();
                }
                return $decoded;
            }
            if (!$escaped && ord($character) < 0x20) {
                throw new \UnexpectedValueException();
            }
            if (!$escaped && $character === '\\') {
                $escaped = true;
            } else {
                $escaped = false;
            }
        }
        throw new \UnexpectedValueException();
    }

    private function parseNumber(): int|float
    {
        if (!preg_match('/-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?/A', substr($this->json, $this->offset), $matches)) {
            throw new \UnexpectedValueException();
        }
        $this->offset += strlen($matches[0]);
        $decoded = json_decode($matches[0], true, 2, JSON_THROW_ON_ERROR);
        if (!is_int($decoded) && !is_float($decoded)) {
            throw new \UnexpectedValueException();
        }
        return $decoded;
    }

    private function skipWhitespace(): void
    {
        while (isset($this->json[$this->offset]) && str_contains(" \t\r\n", $this->json[$this->offset])) {
            ++$this->offset;
        }
    }
}
