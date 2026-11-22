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
 * MUWA-HMAC-V1 request signature:
 *
 *   MUWA-HMAC-V1-REQUEST\nMETHOD\nPATH\nSHA256_HEX(BODY)\nTIMESTAMP\nNONCE
 *
 * LF only between fields, no trailing LF; HMAC-SHA256 with the decoded
 * 32-byte agent secret, lowercase hex. Mirrored by agent/internal/api/signing.go.
 */
final class RequestSignature
{
    public const DOMAIN = 'MUWA-HMAC-V1-REQUEST';

    public const HEADER_KEY = 'X-Mucki-Agent-Key';
    public const HEADER_TIMESTAMP = 'X-Mucki-Agent-Timestamp';
    public const HEADER_NONCE = 'X-Mucki-Agent-Nonce';
    public const HEADER_SIGNATURE = 'X-Mucki-Agent-Signature';

    public function baseString(string $method, SignatureEnvelope $envelope): string
    {
        SignatureFormat::assertMethod($method);
        return implode("\n", [
            self::DOMAIN,
            $method,
            $envelope->path,
            $envelope->bodySha256,
            $envelope->timestamp,
            $envelope->nonce,
        ]);
    }

    public function sign(#[\SensitiveParameter] string $key, string $method, SignatureEnvelope $envelope): string
    {
        SignatureFormat::assertKey($key);
        return hash_hmac('sha256', $this->baseString($method, $envelope), $key);
    }

    /** Constant-time comparison; a non-canonical signature never verifies. */
    public function verify(
        #[\SensitiveParameter] string $key,
        string $method,
        SignatureEnvelope $envelope,
        string $signature
    ): bool {
        try {
            SignatureFormat::assertSignature($signature);
        } catch (SignatureException) {
            return false;
        }
        return hash_equals($this->sign($key, $method, $envelope), $signature);
    }

    /**
     * Extracts the four security headers. Each must occur exactly once and be
     * canonical; duplicates folded into one comma-joined value fail the format.
     *
     * @return array{keyId: string, timestamp: string, nonce: string, signature: string}
     */
    public function parseHeaders(HeaderBag $headers): array
    {
        $values = [
            'keyId' => SignatureFormat::singleHeader($headers, self::HEADER_KEY),
            'timestamp' => SignatureFormat::singleHeader($headers, self::HEADER_TIMESTAMP),
            'nonce' => SignatureFormat::singleHeader($headers, self::HEADER_NONCE),
            'signature' => SignatureFormat::singleHeader($headers, self::HEADER_SIGNATURE),
        ];
        SignatureFormat::assertKeyId($values['keyId']);
        SignatureFormat::assertTimestamp($values['timestamp']);
        SignatureFormat::assertNonce($values['nonce']);
        SignatureFormat::assertSignature($values['signature']);
        return $values;
    }
}
