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
namespace MuckiSafeAgentPlugin\Service\Auth;

use MuckiSafeAgentPlugin\Exception\SignatureException;
use Symfony\Component\HttpFoundation\HeaderBag;

/**
 * Strict format checks for every MUWA-HMAC-V1 field. Nothing is trimmed or
 * normalized: a value is either already canonical or rejected.
 */
final class SignatureFormat
{
    public const KEY_BYTES = 32;

    private const INT64_MAX = '9223372036854775807';

    public static function assertKey(#[\SensitiveParameter] string $key): void
    {
        if (strlen($key) !== self::KEY_BYTES) {
            throw new SignatureException(SignatureException::INVALID_KEY);
        }
    }

    public static function assertKeyId(string $keyId): void
    {
        self::assertPattern('/^[0-9a-f]{32}$/D', $keyId, SignatureException::INVALID_KEY_ID);
    }

    public static function assertMethod(string $method): void
    {
        if ($method !== 'POST') {
            throw new SignatureException(SignatureException::INVALID_METHOD);
        }
    }

    public static function assertBodySha256(string $bodySha256): void
    {
        self::assertPattern('/^[0-9a-f]{64}$/D', $bodySha256, SignatureException::INVALID_BODY_HASH);
    }

    /** Non-negative decimal without sign or leading zeros (except "0") that fits into int64. */
    public static function assertTimestamp(string $timestamp): void
    {
        self::assertPattern('/^(0|[1-9][0-9]{0,18})$/D', $timestamp, SignatureException::INVALID_TIMESTAMP);
        if (strlen($timestamp) === strlen(self::INT64_MAX) && strcmp($timestamp, self::INT64_MAX) > 0) {
            throw new SignatureException(SignatureException::INVALID_TIMESTAMP);
        }
    }

    public static function assertNonce(string $nonce): void
    {
        self::assertPattern('/^[A-Za-z0-9_-]{32,64}$/D', $nonce, SignatureException::INVALID_NONCE);
    }

    public static function assertStatus(int $status): void
    {
        if ($status < 100 || $status > 599) {
            throw new SignatureException(SignatureException::INVALID_STATUS);
        }
    }

    public static function assertSignature(string $signature): void
    {
        self::assertPattern('/^[0-9a-f]{64}$/D', $signature, SignatureException::INVALID_SIGNATURE_FORMAT);
    }

    /** Returns the only value of a header that must occur exactly once. */
    public static function singleHeader(HeaderBag $headers, string $name): string
    {
        $values = $headers->all($name);
        if ($values === []) {
            throw new SignatureException(SignatureException::MISSING_HEADER);
        }
        if (count($values) > 1) {
            throw new SignatureException(SignatureException::DUPLICATE_HEADER);
        }
        return (string) $values[0];
    }

    private static function assertPattern(string $pattern, string $value, string $reason): void
    {
        if (preg_match($pattern, $value) !== 1) {
            throw new SignatureException($reason);
        }
    }
}
