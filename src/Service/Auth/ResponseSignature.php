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
use Symfony\Component\HttpFoundation\Response;

/**
 * MUWA-HMAC-V1 response signature, bound to the nonce of the answered request:
 *
 *   MUWA-HMAC-V1-RESPONSE\nSTATUS\nPATH\nSHA256_HEX(BODY)\nREQUEST_NONCE\nTIMESTAMP
 *
 * Every response after successful key resolution, including 4xx/5xx, is
 * signed. Mirrored by agent/internal/api/signing.go.
 */
final class ResponseSignature
{
    public const DOMAIN = 'MUWA-HMAC-V1-RESPONSE';

    public const HEADER_TIMESTAMP = 'X-Mucki-Agent-Timestamp';
    public const HEADER_REQUEST_NONCE = 'X-Mucki-Agent-Request-Nonce';
    public const HEADER_SIGNATURE = 'X-Mucki-Agent-Signature';

    private const SIGNATURE_HEADERS = [self::HEADER_TIMESTAMP, self::HEADER_REQUEST_NONCE, self::HEADER_SIGNATURE];

    /** The envelope nonce is the request nonce. */
    public function baseString(int $status, SignatureEnvelope $envelope): string
    {
        SignatureFormat::assertStatus($status);
        return implode("\n", [
            self::DOMAIN,
            (string) $status,
            $envelope->path,
            $envelope->bodySha256,
            $envelope->nonce,
            $envelope->timestamp,
        ]);
    }

    public function sign(#[\SensitiveParameter] string $key, int $status, SignatureEnvelope $envelope): string
    {
        SignatureFormat::assertKey($key);
        return hash_hmac('sha256', $this->baseString($status, $envelope), $key);
    }

    /** @return array<string, string> */
    public function headers(#[\SensitiveParameter] string $key, int $status, SignatureEnvelope $envelope): array
    {
        return [
            self::HEADER_TIMESTAMP => $envelope->timestamp,
            self::HEADER_REQUEST_NONCE => $envelope->nonce,
            self::HEADER_SIGNATURE => $this->sign($key, $status, $envelope),
        ];
    }

    /**
     * Client-side verification as performed by the Go agent. The only
     * unsigned response that is not rejected is the empty 401 of the unknown
     * key case, and it is terminal.
     */
    public function classify(
        #[\SensitiveParameter] string $key,
        string $requestNonce,
        string $path,
        Response $response
    ): ResponseVerdict {
        $body = (string) $response->getContent();
        if (!$this->hasAnySignatureHeader($response)) {
            return $response->getStatusCode() === 401 && $body === ''
                ? ResponseVerdict::TerminalUnauthenticated
                : ResponseVerdict::Reject;
        }
        try {
            $timestamp = SignatureFormat::singleHeader($response->headers, self::HEADER_TIMESTAMP);
            $nonce = SignatureFormat::singleHeader($response->headers, self::HEADER_REQUEST_NONCE);
            $signature = SignatureFormat::singleHeader($response->headers, self::HEADER_SIGNATURE);
            SignatureFormat::assertSignature($signature);
            if (!hash_equals($requestNonce, $nonce)) {
                return ResponseVerdict::Reject;
            }
            $expected = $this->sign($key, $response->getStatusCode(), SignatureEnvelope::forBody($path, $body, $timestamp, $nonce));
        } catch (SignatureException) {
            return ResponseVerdict::Reject;
        }
        return hash_equals($expected, $signature) ? ResponseVerdict::Accept : ResponseVerdict::Reject;
    }

    private function hasAnySignatureHeader(Response $response): bool
    {
        foreach (self::SIGNATURE_HEADERS as $name) {
            if ($response->headers->all($name) !== []) {
                return true;
            }
        }
        return false;
    }
}
