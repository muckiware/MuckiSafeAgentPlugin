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

namespace MuckiSafeAgentPlugin\tests\Integration\Service\Auth;

require_once dirname(__DIR__, 4) . '/src/Migration/Migration1795132800CreateAgentSchema.php';
require_once dirname(__DIR__, 4) . '/src/Migration/Migration1795305600CreateSecretState.php';
foreach (glob(dirname(__DIR__, 4) . '/src/Service/Auth/*.php') ?: [] as $authServiceFile) {
    require_once $authServiceFile;
}
foreach (glob(dirname(__DIR__, 4) . '/src/Service/Secret/*.php') ?: [] as $secretServiceFile) {
    require_once $secretServiceFile;
}

use Doctrine\DBAL\Connection;
use MuckiSafeAgentPlugin\Core\Content\AgentNonce\AgentNonceDefinition;
use MuckiSafeAgentPlugin\Migration\Migration1795132800CreateAgentSchema;
use MuckiSafeAgentPlugin\Migration\Migration1795305600CreateSecretState;
use MuckiSafeAgentPlugin\Service\Auth\AgentAuthException;
use MuckiSafeAgentPlugin\Service\Auth\AgentAuthenticator;
use MuckiSafeAgentPlugin\Service\Auth\AgentErrorCode;
use MuckiSafeAgentPlugin\Service\Auth\AgentProtocol;
use MuckiSafeAgentPlugin\Service\Auth\AuthenticatedRequest;
use MuckiSafeAgentPlugin\Service\Auth\RequestSignature;
use MuckiSafeAgentPlugin\Service\Auth\ReplayGuard;
use MuckiSafeAgentPlugin\Service\Auth\SignatureEnvelope;
use MuckiSafeAgentPlugin\Service\Secret\AgentProvisioner;
use MuckiSafeAgentPlugin\Service\Secret\EncryptionKeyProvider;
use MuckiSafeAgentPlugin\Service\Secret\SecretCipher;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Test\TestCaseBase\KernelTestBehaviour;
use Symfony\Component\HttpFoundation\HeaderBag;
use Symfony\Component\HttpFoundation\Request;

/**
 * Task 9 RED: the authenticator enforces the MUWA-HMAC-V1 wire contract for
 * every agent route: key resolution first (unknown keys yield the terminal
 * unsignable 401 signal), then revoked/disabled, transport framing, query
 * ban, canonical paths, method, header formats, timestamp window, HMAC
 * verification, replay insertion and route body limits.
 */
final class AgentAuthenticatorTest extends TestCase
{
    use KernelTestBehaviour;

    private const HEARTBEAT_PATH = '/api/_action/muwa/agent/v1/heartbeat';
    private const FIXED_NOW = 1790000000;

    private Connection $connection;

    private AgentAuthenticator $authenticator;

    /** @var array{id: string, keyId: string, secret: string} */
    private array $agent;

    private string $secretBytes;

    protected function setUp(): void
    {
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->dropSchema();
        (new Migration1795132800CreateAgentSchema())->update($this->connection);
        (new Migration1795305600CreateSecretState())->update($this->connection);

        $keyProvider = self::getContainer()->get(EncryptionKeyProvider::class);
        self::assertInstanceOf(EncryptionKeyProvider::class, $keyProvider);
        $cipher = self::getContainer()->get(SecretCipher::class);
        self::assertInstanceOf(SecretCipher::class, $cipher);
        $this->authenticator = new AgentAuthenticator(
            $this->connection,
            $cipher,
            new ReplayGuard($this->connection),
            new RequestSignature()
        );

        $provisioner = self::getContainer()->get(AgentProvisioner::class);
        self::assertInstanceOf(AgentProvisioner::class, $provisioner);
        $agent = $provisioner->provision('authenticator-host');
        self::assertIsArray($agent);
        $this->agent = $agent;
        $this->secretBytes = $this->decodeSecret($this->agent['secret']);
    }

    protected function tearDown(): void
    {
        $this->dropSchema();
    }

    private function dropSchema(): void
    {
        $this->connection->executeStatement(
            'DROP TABLE IF EXISTS `muwa_agent_nonce`, `muwa_agent_job`, `muwa_agent_backup_run`, '
            . '`muwa_agent_repository_hook`, `muwa_agent_action`, `muwa_agent_host`, `muwa_agent_secret_state`'
        );
    }

    private function decodeSecret(string $base64url): string
    {
        $bytes = sodium_base642bin($base64url, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING, '');
        self::assertSame(32, strlen($bytes));

        return $bytes;
    }

    /**
     * @param array<string, mixed> $overrides supported keys: keyId, timestamp,
     *     nonce, method, signature, query, server
     */
    private function signedRequest(string $path, string $body, array $overrides = []): Request
    {
        $keyId = (string) ($overrides['keyId'] ?? $this->agent['keyId']);
        $timestamp = (string) ($overrides['timestamp'] ?? (string) self::FIXED_NOW);
        $nonce = (string) ($overrides['nonce'] ?? ('nonce-' . bin2hex(random_bytes(8)) . 'paddingpad'));
        $method = (string) ($overrides['method'] ?? 'POST');
        $query = (string) ($overrides['query'] ?? '');

        try {
            $envelope = SignatureEnvelope::forBody($path, $body, $timestamp, $nonce);
            $signature = (string) ($overrides['signature'] ?? hash_hmac('sha256', (new RequestSignature())->baseString($method, $envelope), $this->secretBytes));
        } catch (\MuckiSafeAgentPlugin\Exception\SignatureException) {
            // non-canonical paths and non-POST methods cannot build a valid
            // basis string at all; the authenticator rejects them before the
            // signature check, so a syntactically valid dummy is fine
            $signature = str_repeat('0', 64);
        }

        $server = (array) ($overrides['server'] ?? []);
        $server = array_merge($server, [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_MUCKI_AGENT_KEY' => $keyId,
            'HTTP_X_MUCKI_AGENT_TIMESTAMP' => $timestamp,
            'HTTP_X_MUCKI_AGENT_NONCE' => $nonce,
            'HTTP_X_MUCKI_AGENT_SIGNATURE' => $signature,
        ]);

        return Request::create($path . $query, $method, [], [], [], $server, $body);
    }

    private function authenticate(Request $request, ?int $serverNow = self::FIXED_NOW): AuthenticatedRequest
    {
        return $this->authenticator->authenticate($request, $serverNow);
    }

    private function expectError(Request $request, AgentErrorCode $expected, ?int $serverNow = self::FIXED_NOW): AgentAuthException
    {
        try {
            $this->authenticator->authenticate($request, $serverNow);
            self::fail('expected AgentAuthException ' . $expected->value);
        } catch (AgentAuthException $exception) {
            self::assertSame($expected, $exception->errorCode, $exception->getMessage());
        }

        return $exception;
    }

    private function nonceCount(): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM `' . AgentNonceDefinition::ENTITY_NAME . '`');
    }

    public function testValidRequestAuthenticatesAndExposesContext(): void
    {
        $body = '{"agentVersion":"1.0","platform":"linux","arch":"arm64","capabilities":[],"agentTime":"2026-01-01T00:00:00Z"}';
        $request = $this->signedRequest(self::HEARTBEAT_PATH, $body);

        $authenticated = $this->authenticate($request);

        self::assertSame($this->agent['id'], $authenticated->hostId);
        self::assertSame($this->agent['keyId'], $authenticated->keyId);
        self::assertSame($this->secretBytes, $authenticated->secret);
        self::assertSame(self::HEARTBEAT_PATH, $authenticated->path);
        self::assertSame(AgentProtocol::ROUTE_HOST_HEARTBEAT, $authenticated->routeName);
        self::assertSame((string) self::FIXED_NOW, $authenticated->requestTimestamp);
        self::assertSame($request->headers->get('X-Mucki-Agent-Nonce'), $authenticated->nonce);
        self::assertSame($body, $authenticated->rawBody);
        self::assertSame(1, $this->nonceCount());
    }

    public function testJobRouteFamilyResolvesAndAuthenticates(): void
    {
        $uuid = '0a1b2c3d-4e5f-4a6b-8c9d-0e1f2a3b4c5d';
        $path = '/api/_action/muwa/agent/v1/jobs/' . $uuid . '/complete';
        $request = $this->signedRequest($path, '{"claimToken":"x","leaseVersion":1,"outcome":"succeeded"}');

        $authenticated = $this->authenticate($request);

        self::assertSame(AgentProtocol::ROUTE_JOB_COMPLETE, $authenticated->routeName);
        self::assertSame($path, $authenticated->path);
    }

    public function testUnknownKeyYieldsUnsignable401Signal(): void
    {
        $request = $this->signedRequest(self::HEARTBEAT_PATH, '{}', ['keyId' => bin2hex(random_bytes(16))]);

        try {
            $this->authenticate($request);
            self::fail('expected AgentAuthException for unknown key');
        } catch (AgentAuthException $exception) {
            self::assertNull($exception->errorCode);
            self::assertNull($exception->agent);
        }
        self::assertSame(0, $this->nonceCount());
    }

    public function testMissingKeyHeaderYieldsUnsignable401Signal(): void
    {
        $request = $this->signedRequest(self::HEARTBEAT_PATH, '{}');
        $request->headers->remove('X-Mucki-Agent-Key');

        try {
            $this->authenticate($request);
            self::fail('expected AgentAuthException');
        } catch (AgentAuthException $exception) {
            self::assertNull($exception->errorCode);
            self::assertNull($exception->agent);
        }
        self::assertSame(0, $this->nonceCount());
    }

    public function testDuplicateKeyHeaderYieldsUnsignable401Signal(): void
    {
        $request = $this->signedRequest(self::HEARTBEAT_PATH, '{}');
        $headers = $request->headers->all();
        $headers['x-mucki-agent-key'] = [$this->agent['keyId'], $this->agent['keyId']];
        $request->headers = new HeaderBag($headers);

        try {
            $this->authenticate($request);
            self::fail('expected AgentAuthException');
        } catch (AgentAuthException $exception) {
            self::assertNull($exception->errorCode);
        }
    }

    public function testRevokedHostIsRejectedWithSignedRevokedError(): void
    {
        $this->connection->executeStatement(
            'UPDATE `muwa_agent_host` SET `revoked_at` = :revokedAt WHERE `id` = UNHEX(:id)',
            ['revokedAt' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.v'), 'id' => $this->agent['id']]
        );
        $request = $this->signedRequest(self::HEARTBEAT_PATH, '{}');

        $exception = $this->expectError($request, AgentErrorCode::Revoked);

        self::assertNotNull($exception->agent);
        self::assertSame($this->secretBytes, $exception->agent->secret);
        self::assertSame(0, $this->nonceCount());
    }

    public function testDisabledHostIsRejectedLikeRevoked(): void
    {
        $this->connection->executeStatement(
            'UPDATE `muwa_agent_host` SET `enabled` = 0 WHERE `id` = UNHEX(:id)',
            ['id' => $this->agent['id']]
        );
        $request = $this->signedRequest(self::HEARTBEAT_PATH, '{}');

        $this->expectError($request, AgentErrorCode::Revoked);
    }

    public function testTimestampWindowAcceptsBothEdgesAndRejectsBeyond(): void
    {
        $olderEdge = $this->signedRequest(self::HEARTBEAT_PATH, '{}', ['timestamp' => (string) (self::FIXED_NOW - 60)]);
        $authenticated = $this->authenticate($olderEdge);
        self::assertSame($this->agent['id'], $authenticated->hostId);

        $futureEdge = $this->signedRequest(self::HEARTBEAT_PATH, '{}', ['timestamp' => (string) (self::FIXED_NOW + 60)]);
        $authenticated = $this->authenticate($futureEdge);
        self::assertSame($this->agent['id'], $authenticated->hostId);

        $tooOld = $this->signedRequest(self::HEARTBEAT_PATH, '{}', ['timestamp' => (string) (self::FIXED_NOW - 61)]);
        $this->expectError($tooOld, AgentErrorCode::TimestampOutOfWindow);

        $tooNew = $this->signedRequest(self::HEARTBEAT_PATH, '{}', ['timestamp' => (string) (self::FIXED_NOW + 61)]);
        $this->expectError($tooNew, AgentErrorCode::TimestampOutOfWindow);
    }

    public function testPositiveEdgeRequestStaysReplayProtectedUntilRequestTimestampPlus65(): void
    {
        $edgeTimestamp = self::FIXED_NOW - 60;
        $first = $this->signedRequest(self::HEARTBEAT_PATH, '{}', ['timestamp' => (string) $edgeTimestamp]);
        $this->authenticate($first);
        $firstNonce = (string) $first->headers->get('X-Mucki-Agent-Nonce');

        $expectedExpiry = (new \DateTimeImmutable('@' . ($edgeTimestamp + 65)))->format('Y-m-d H:i:s.v');
        $row = $this->connection->fetchAssociative('SELECT `expires_at` FROM `' . AgentNonceDefinition::ENTITY_NAME . '`');
        self::assertIsArray($row);
        self::assertSame($expectedExpiry, (string) $row['expires_at']);

        $replayGuard = new ReplayGuard($this->connection);
        self::assertSame(0, $replayGuard->cleanupExpired(self::FIXED_NOW, 100));
        self::assertSame(1, $this->nonceCount());

        $second = $this->signedRequest(self::HEARTBEAT_PATH, '{}', [
            'timestamp' => (string) $edgeTimestamp,
            'nonce' => $firstNonce,
        ]);
        $this->expectError($second, AgentErrorCode::ReplayDetected);
    }

    public function testWrongBodySignatureIsRejected(): void
    {
        $signedForOtherBody = $this->signedRequest(self::HEARTBEAT_PATH, '{"other":true}');
        $body = '{"agentVersion":"1.0"}';
        $tampered = $this->signedRequest(self::HEARTBEAT_PATH, $body, [
            'signature' => (string) $signedForOtherBody->headers->get('X-Mucki-Agent-Signature'),
        ]);

        $this->expectError($tampered, AgentErrorCode::SignatureInvalid);
        self::assertSame(0, $this->nonceCount());
    }

    public function testTamperedNonceIsRejected(): void
    {
        $request = $this->signedRequest(self::HEARTBEAT_PATH, '{}', ['nonce' => str_repeat('x', 43)]);
        $request->headers->set('X-Mucki-Agent-Nonce', str_repeat('y', 43));

        $this->expectError($request, AgentErrorCode::SignatureInvalid);
    }

    public function testMalformedNonceFormatIsRejectedBeforeSignatureCheck(): void
    {
        $request = $this->signedRequest(self::HEARTBEAT_PATH, '{}');
        $request->headers->set('X-Mucki-Agent-Nonce', 'short');

        $this->expectError($request, AgentErrorCode::RequestInvalid);
        self::assertSame(0, $this->nonceCount());
    }

    public function testQueryParametersAreRejected(): void
    {
        $request = $this->signedRequest(self::HEARTBEAT_PATH, '{}', ['query' => '?poll=1']);

        $exception = $this->expectError($request, AgentErrorCode::RequestInvalid);
        self::assertNotNull($exception->agent);
        self::assertSame(0, $this->nonceCount());
    }

    public function testNonCanonicalPathsAreRejected(): void
    {
        $cases = [
            self::HEARTBEAT_PATH . '/',
            '/api/_action/muwa/agent/v1/heartbeat/',
            '//api/_action/muwa/agent/v1/heartbeat',
            '/api/_action/muwa/agent/v1/jobs/0a1b2c3d-4e5f-4a6b-8c9d-0e1f2a3b4c5d/start/extra',
            '/api/_action/muwa/agent/v1/jobs/NOT-A-UUID/start',
            '/api/_action/muwa/agent/v1/jobs/0A1B2C3D-4E5F-4A6B-8C9D-0E1F2A3B4C5D/start',
            '/api/_action/muwa/agent/v1/heartbeat%20',
        ];
        foreach ($cases as $case) {
            $request = $this->signedRequest($case, '{}');
            try {
                $this->authenticate($request);
                self::fail('non-canonical path accepted: ' . $case);
            } catch (AgentAuthException $exception) {
                self::assertSame(AgentErrorCode::RequestInvalid, $exception->errorCode, 'path: ' . $case);
            }
        }
        self::assertSame(0, $this->nonceCount());
    }

    public function testNonPostMethodIsRejected(): void
    {
        $request = $this->signedRequest(self::HEARTBEAT_PATH, '', ['method' => 'GET']);

        $this->expectError($request, AgentErrorCode::MethodNotAllowed);
    }

    public function testContentEncodingIsRejected(): void
    {
        $request = $this->signedRequest(self::HEARTBEAT_PATH, '{}');
        $request->headers->set('Content-Encoding', 'gzip');

        $this->expectError($request, AgentErrorCode::RequestInvalid);
    }

    public function testDuplicateContentLengthIsRejected(): void
    {
        $request = $this->signedRequest(self::HEARTBEAT_PATH, '{}');
        $headers = $request->headers->all();
        $headers['content-length'] = ['2', '3'];
        $request->headers = new HeaderBag($headers);

        $this->expectError($request, AgentErrorCode::RequestInvalid);
    }

    public function testCommaFoldedContentLengthIsRejected(): void
    {
        // php-fpm/apache fold duplicated request headers into one comma-joined
        // value; that folded form is ambiguous framing as well
        $request = $this->signedRequest(self::HEARTBEAT_PATH, '{}');
        $request->headers->set('Content-Length', '2, 3');

        $this->expectError($request, AgentErrorCode::RequestInvalid);
    }

    public function testContentLengthWithTransferEncodingIsRejected(): void
    {
        $request = $this->signedRequest(self::HEARTBEAT_PATH, '{}');
        $request->headers->set('Content-Length', '2');
        $request->headers->set('Transfer-Encoding', 'chunked');

        $this->expectError($request, AgentErrorCode::RequestInvalid);
    }

    public function testBodyAtLimitAuthenticates(): void
    {
        $body = str_repeat('a', AgentProtocol::bodyLimit(AgentProtocol::ROUTE_HOST_HEARTBEAT));
        $request = $this->signedRequest(self::HEARTBEAT_PATH, $body);

        $authenticated = $this->authenticate($request);

        self::assertSame($this->agent['id'], $authenticated->hostId);
    }

    public function testOversizeBodyIsHashedAuthenticatedAndRejectedAsTooLarge(): void
    {
        $limit = AgentProtocol::bodyLimit(AgentProtocol::ROUTE_HOST_HEARTBEAT);
        $body = str_repeat('b', $limit + 1);
        $request = $this->signedRequest(self::HEARTBEAT_PATH, $body);

        $exception = $this->expectError($request, AgentErrorCode::BodyTooLarge);

        // the signature over the oversize raw body was valid, so the nonce is
        // already spent and the response stays signable
        self::assertNotNull($exception->agent);
        self::assertSame(1, $this->nonceCount());
    }

    public function testNonceIsOnlyStoredAfterAValidSignature(): void
    {
        $unsigned = Request::create(self::HEARTBEAT_PATH, 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_MUCKI_AGENT_KEY' => $this->agent['keyId'],
            'HTTP_X_MUCKI_AGENT_TIMESTAMP' => (string) self::FIXED_NOW,
            'HTTP_X_MUCKI_AGENT_NONCE' => str_repeat('z', 43),
        ], '{}');

        $this->expectError($unsigned, AgentErrorCode::RequestInvalid);
        self::assertSame(0, $this->nonceCount());
    }
}
