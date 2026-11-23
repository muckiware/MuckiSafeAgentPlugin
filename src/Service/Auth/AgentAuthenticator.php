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

use Doctrine\DBAL\Connection;
use MuckiSafeAgentPlugin\Exception\SignatureException;
use MuckiSafeAgentPlugin\Service\Secret\SecretCipher;
use Symfony\Component\HttpFoundation\Request;

/**
 * Server side of the MUWA-HMAC-V1 wire contract for all agent routes.
 *
 * Check order (each failure is answered with its AgentErrorCode, signed with
 * the resolved secret; only the key resolution failure stays unsigned):
 *  1. key resolution — unknown, missing, malformed key or undecryptable
 *     secret yields the terminal unsignable 401 signal
 *  2. revoked or disabled host
 *  3. transport framing — Content-Encoding, duplicate Content-Length,
 *     Content-Length combined with Transfer-Encoding
 *  4. query parameters (never allowed on agent routes)
 *  5. exact canonical route path (no normalization, no trailing slash)
 *  6. POST method
 *  7. strict signature header formats (each exactly once, canonical)
 *  8. timestamp window ±60s
 *  9. streamed raw body hash and constant-time HMAC verification
 * 10. atomic nonce insert after a valid signature (UNIQUE decides replay)
 * 11. route body limit (the oversize body was hashed and authenticated)
 */
final class AgentAuthenticator implements AgentAuthenticatorInterface
{
    private const READ_CHUNK_BYTES = 65536;

    public function __construct(
        private readonly Connection $connection,
        private readonly SecretCipher $cipher,
        private readonly ReplayGuard $replayGuard,
        private readonly RequestSignature $requestSignature
    ) {
    }

    public function authenticate(Request $request, ?int $serverNow = null): AuthenticatedRequest
    {
        $now = $serverNow ?? time();
        $path = $request->getPathInfo();

        $keyId = $this->resolveKeyId($request);
        $host = $this->fetchHost($keyId);
        if ($host === null) {
            throw AgentAuthException::unknownKey();
        }
        $hostId = bin2hex((string) $host['id']);
        $secret = $this->resolveSecret($host);

        $headers = $this->tryParseHeaders($request);
        $context = static fn (): AuthenticatedRequest => new AuthenticatedRequest(
            $hostId,
            $keyId,
            $secret,
            '',
            $path,
            $headers['timestamp'] ?? '',
            $headers['nonce'] ?? ''
        );

        if ((int) $host['enabled'] !== 1 || $host['revoked_at'] !== null) {
            throw new AgentAuthException(AgentErrorCode::Revoked, 'Agent key is revoked or disabled.', $context());
        }

        if ($request->headers->has('Content-Encoding')) {
            throw new AgentAuthException(AgentErrorCode::RequestInvalid, 'Content-Encoding is not allowed on agent routes.', $context());
        }
        $contentType = $request->headers->get('Content-Type');
        if (!is_string($contentType) || strtolower(trim($contentType)) !== 'application/json') {
            throw new AgentAuthException(AgentErrorCode::UnsupportedMediaType, 'Content-Type must be application/json.', $context());
        }
        $contentLengths = $request->headers->all('content-length');
        if (count($contentLengths) > 1) {
            throw new AgentAuthException(AgentErrorCode::RequestInvalid, 'Ambiguous Content-Length.', $context());
        }
        // SAPIs comma-fold duplicated request headers into one value, so a
        // folded or otherwise non-numeric Content-Length is ambiguous too
        foreach ($contentLengths as $contentLength) {
            if (!ctype_digit((string) $contentLength)) {
                throw new AgentAuthException(AgentErrorCode::RequestInvalid, 'Ambiguous Content-Length.', $context());
            }
        }
        if ($contentLengths !== [] && $request->headers->has('Transfer-Encoding')) {
            throw new AgentAuthException(AgentErrorCode::RequestInvalid, 'Content-Length and Transfer-Encoding must not be combined.', $context());
        }

        if ($request->query->count() > 0) {
            throw new AgentAuthException(AgentErrorCode::RequestInvalid, 'Query parameters are not allowed on agent routes.', $context());
        }

        try {
            $routeName = AgentProtocol::matchRoute($path);
        } catch (SignatureException) {
            throw new AgentAuthException(AgentErrorCode::RequestInvalid, 'Route path is not canonical.', $context());
        }

        if ($request->getMethod() !== 'POST') {
            throw new AgentAuthException(AgentErrorCode::MethodNotAllowed, 'Only POST is allowed on agent routes.', $context());
        }

        if ($headers === null) {
            throw new AgentAuthException(AgentErrorCode::RequestInvalid, 'Signature headers are missing or malformed.', $context());
        }

        $requestTime = (int) $headers['timestamp'];
        if (
            $requestTime < $now - AgentProtocol::TIMESTAMP_WINDOW_SECONDS
            || $requestTime > $now + AgentProtocol::TIMESTAMP_WINDOW_SECONDS
        ) {
            throw new AgentAuthException(AgentErrorCode::TimestampOutOfWindow, 'Request timestamp is outside the allowed window.', $context());
        }

        $body = $this->hashBody($request);
        $envelope = new SignatureEnvelope($path, $body['sha256'], $headers['timestamp'], $headers['nonce']);
        if (!$this->requestSignature->verify($secret, 'POST', $envelope, $headers['signature'])) {
            throw new AgentAuthException(AgentErrorCode::SignatureInvalid, 'Request signature is invalid.', $context());
        }

        try {
            $this->replayGuard->remember($hostId, $headers['nonce'], $requestTime);
        } catch (AgentAuthException $exception) {
            throw new AgentAuthException(AgentErrorCode::ReplayDetected, $exception->getMessage(), $context());
        }

        if ($body['length'] > AgentProtocol::bodyLimit($routeName)) {
            throw new AgentAuthException(AgentErrorCode::BodyTooLarge, 'Request body exceeds the route limit.', $context());
        }

        return new AuthenticatedRequest(
            $hostId,
            $keyId,
            $secret,
            $routeName,
            $path,
            $headers['timestamp'],
            $headers['nonce'],
            $body['raw']
        );
    }

    /**
     * The only header needed before a response can be signed. Missing or
     * duplicated key headers are treated like an unknown key: without a
     * resolvable secret no error response could be signed.
     */
    private function resolveKeyId(Request $request): string
    {
        try {
            $keyId = SignatureFormat::singleHeader($request->headers, RequestSignature::HEADER_KEY);
            SignatureFormat::assertKeyId($keyId);
        } catch (SignatureException) {
            throw AgentAuthException::unknownKey();
        }

        return $keyId;
    }

    /** @return array<string, mixed>|null */
    private function fetchHost(string $keyId): ?array
    {
        $host = $this->connection->fetchAssociative(
            'SELECT `id`, `key_id`, `enabled`, `revoked_at`, `secret_ciphertext`, `secret_nonce`, `secret_key_version` '
            . 'FROM `muwa_agent_host` WHERE `key_id` = :keyId',
            ['keyId' => $keyId]
        );

        return $host === false ? null : $host;
    }

    /**
     * Fail closed: any decryption problem (unknown key version, corrupted
     * ciphertext) means no secret can be resolved and the request gets the
     * unsigned terminal 401.
     *
     * @param array<string, mixed> $host
     */
    private function resolveSecret(array $host): string
    {
        try {
            $secret = $this->cipher->decrypt(
                (string) $host['secret_ciphertext'],
                (string) $host['secret_nonce'],
                (int) $host['secret_key_version']
            );
            SignatureFormat::assertKey($secret);
        } catch (\RuntimeException | \InvalidArgumentException | \UnexpectedValueException) {
            throw AgentAuthException::unknownKey();
        }

        return $secret;
    }

    /**
     * @return array{keyId: string, timestamp: string, nonce: string, signature: string}|null
     */
    private function tryParseHeaders(Request $request): ?array
    {
        try {
            $parsed = $this->requestSignature->parseHeaders($request->headers);
        } catch (SignatureException) {
            return null;
        }

        return $parsed;
    }

    /**
     * Hashes the raw body exactly once in bounded memory. Reading stops at
     * the transport hard cap; bodies beyond it cannot authenticate anyway
     * and the upstream webserver already rejects them.
     *
     * @return array{length: int, sha256: string, raw: string}
     */
    private function hashBody(Request $request): array
    {
        $hash = hash_init('sha256');
        $length = 0;
        $raw = '';
        $stream = $request->getContent(true);
        if (is_resource($stream)) {
            while ($length < AgentProtocol::TRANSPORT_HARD_CAP_BYTES) {
                $chunk = fread($stream, self::READ_CHUNK_BYTES);
                if (!is_string($chunk) || $chunk === '') {
                    break;
                }
                hash_update($hash, $chunk);
                $length += strlen($chunk);
                $raw .= $chunk;
            }
            fclose($stream);
        }

        return ['length' => $length, 'sha256' => hash_final($hash), 'raw' => $raw];
    }
}
