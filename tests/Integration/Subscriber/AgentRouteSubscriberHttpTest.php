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

namespace MuckiSafeAgentPlugin\tests\Integration\Subscriber;

require_once dirname(__DIR__, 3) . '/src/Migration/Migration1795132800CreateAgentSchema.php';
require_once dirname(__DIR__, 3) . '/src/Migration/Migration1795305600CreateSecretState.php';
foreach (glob(dirname(__DIR__, 3) . '/src/Service/Auth/*.php') ?: [] as $authServiceFile) {
    require_once $authServiceFile;
}
foreach (glob(dirname(__DIR__, 3) . '/src/Service/Secret/*.php') ?: [] as $secretServiceFile) {
    require_once $secretServiceFile;
}
foreach (glob(dirname(__DIR__, 3) . '/src/Subscriber/*.php') ?: [] as $subscriberFile) {
    require_once $subscriberFile;
}

use Doctrine\DBAL\Connection;
use MuckiSafeAgentPlugin\Controller\Agent\HostHeartbeatController;
use MuckiSafeAgentPlugin\Core\Content\AgentNonce\AgentNonceDefinition;
use MuckiSafeAgentPlugin\Migration\Migration1795132800CreateAgentSchema;
use MuckiSafeAgentPlugin\Migration\Migration1795305600CreateSecretState;
use MuckiSafeAgentPlugin\Service\Auth\AgentErrorCode;
use MuckiSafeAgentPlugin\Service\Auth\AgentProtocol;
use MuckiSafeAgentPlugin\Service\Auth\AuthenticatedRequest;
use MuckiSafeAgentPlugin\Service\Auth\RequestSignature;
use MuckiSafeAgentPlugin\Service\Auth\ResponseSignature;
use MuckiSafeAgentPlugin\Service\Auth\ResponseVerdict;
use MuckiSafeAgentPlugin\Service\Auth\SignatureEnvelope;
use MuckiSafeAgentPlugin\Service\Secret\AgentProvisioner;
use MuckiSafeAgentPlugin\Subscriber\AgentAuthenticationSubscriber;
use MuckiSafeAgentPlugin\Subscriber\AgentResponseSigningSubscriber;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Test\TestCaseBase\KernelLifecycleManager;
use Shopware\Core\Framework\Test\TestCaseBase\KernelTestBehaviour;
use Shopware\Core\Framework\Test\TestCaseHelper\TestBrowser;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Task 9 RED: kernel wiring of both subscribers. Unknown keys get the single
 * allowed unsigned empty 401; every response after key resolution — including
 * error DTOs and kernel exceptions — is signed; replays are rejected with a
 * signed REPLAY_DETECTED; both subscribers are registered with the normative
 * priorities.
 */
final class AgentRouteSubscriberHttpTest extends TestCase
{
    use KernelTestBehaviour;

    private const HEARTBEAT_PATH = '/api/_action/muwa/agent/v1/heartbeat';

    private Connection $connection;

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

        $provisioner = self::getContainer()->get(AgentProvisioner::class);
        self::assertInstanceOf(AgentProvisioner::class, $provisioner);
        $agent = $provisioner->provision('subscriber-http-host');
        self::assertIsArray($agent);
        $this->agent = $agent;
        $this->secretBytes = sodium_base642bin($this->agent['secret'], SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING, '');
        self::assertSame(32, strlen($this->secretBytes));
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

    private function browser(): TestBrowser
    {
        $browser = KernelLifecycleManager::createBrowser(self::getKernel(), false);
        $browser->followRedirects(true);

        return $browser;
    }

    /**
     * @param array<string, string> $overrides keyId, timestamp, nonce, signature, contentType
     */
    private function signedAgentRequest(
        TestBrowser $browser,
        string $path,
        string $body,
        array $overrides = []
    ): void {
        $keyId = $overrides['keyId'] ?? $this->agent['keyId'];
        $timestamp = $overrides['timestamp'] ?? (string) (time() - 30);
        $nonce = $overrides['nonce'] ?? ('http-nonce-' . bin2hex(random_bytes(8)) . 'paddingpad');
        $envelope = SignatureEnvelope::forBody($path, $body, $timestamp, $nonce);
        $signature = $overrides['signature'] ?? hash_hmac('sha256', (new RequestSignature())->baseString('POST', $envelope), $this->secretBytes);

        $browser->request('POST', $path, [], [], [
            'CONTENT_TYPE' => $overrides['contentType'] ?? 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_MUCKI_AGENT_KEY' => $keyId,
            'HTTP_X_MUCKI_AGENT_TIMESTAMP' => $timestamp,
            'HTTP_X_MUCKI_AGENT_NONCE' => $nonce,
            'HTTP_X_MUCKI_AGENT_SIGNATURE' => $signature,
        ], $body);
    }

    /** @return array<string, mixed> */
    private function decodeJson(Response $response): array
    {
        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        return $payload;
    }

    private function nonceCount(): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM `' . AgentNonceDefinition::ENTITY_NAME . '`');
    }

    public function testUnknownKeyReceivesEmptyUnsigned401WithNoStore(): void
    {
        $browser = $this->browser();
        $this->signedAgentRequest($browser, self::HEARTBEAT_PATH, '{}', [
            'keyId' => bin2hex(random_bytes(16)),
        ]);

        $response = $browser->getResponse();
        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
        self::assertSame('', (string) $response->getContent());
        self::assertNull($response->headers->get('X-Mucki-Agent-Signature'));
        self::assertNull($response->headers->get('X-Mucki-Agent-Request-Nonce'));
        self::assertNull($response->headers->get('X-Mucki-Agent-Timestamp'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control', ''));
        self::assertSame(0, $this->nonceCount());
    }

    public function testInvalidSignatureReceivesSignedErrorDto(): void
    {
        $browser = $this->browser();
        $this->signedAgentRequest($browser, self::HEARTBEAT_PATH, '{"tampered":true}', [
            'signature' => str_repeat('a', 64),
        ]);

        $response = $browser->getResponse();
        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
        $payload = $this->decodeJson($response);
        self::assertSame(AgentErrorCode::SignatureInvalid->value, $payload['code']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', (string) $payload['traceId']);
        self::assertArrayHasKey('message', $payload);

        self::assertNotNull($response->headers->get('X-Mucki-Agent-Signature'));
        $requestNonce = $browser->getRequest()->headers->get('X-Mucki-Agent-Nonce');
        self::assertNotNull($requestNonce);
        self::assertSame($requestNonce, $response->headers->get('X-Mucki-Agent-Request-Nonce'));

        $verdict = (new ResponseSignature())->classify($this->secretBytes, (string) $requestNonce, self::HEARTBEAT_PATH, $response);
        self::assertSame(ResponseVerdict::Accept, $verdict);
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control', ''));
        self::assertSame(0, $this->nonceCount());
    }

    public function testReplayedRequestIsRejectedWithSignedReplayError(): void
    {
        $browser = $this->browser();
        $nonce = 'replay-http-' . bin2hex(random_bytes(8)) . 'paddingpad';
        $timestamp = (string) (time() - 30);

        $this->signedAgentRequest($browser, self::HEARTBEAT_PATH, '{}', [
            'nonce' => $nonce,
            'timestamp' => $timestamp,
        ]);
        self::assertSame(1, $this->nonceCount());

        $this->signedAgentRequest($browser, self::HEARTBEAT_PATH, '{}', [
            'nonce' => $nonce,
            'timestamp' => $timestamp,
        ]);
        $response = $browser->getResponse();
        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
        $payload = $this->decodeJson($response);
        self::assertSame(AgentErrorCode::ReplayDetected->value, $payload['code']);

        $requestNonce = $browser->getRequest()->headers->get('X-Mucki-Agent-Nonce');
        self::assertNotNull($requestNonce);
        self::assertSame($requestNonce, $response->headers->get('X-Mucki-Agent-Request-Nonce'));
        $verdict = (new ResponseSignature())->classify($this->secretBytes, (string) $requestNonce, self::HEARTBEAT_PATH, $response);
        self::assertSame(ResponseVerdict::Accept, $verdict);
        self::assertSame(1, $this->nonceCount());
    }

    public function testValidHeartbeatUpdatesOnlyAuthenticatedHostAndReturnsSignedResponse(): void
    {
        $other = self::getContainer()->get(AgentProvisioner::class)->provision('other-host');
        $otherBefore = $this->connection->fetchAssociative(
            'SELECT `version`, `capabilities`, `last_seen_at` FROM `muwa_agent_host` WHERE `id` = UNHEX(:id)',
            ['id' => $other['id']]
        );
        $browser = $this->browser();
        $body = json_encode([
            'agentVersion' => '1.2.3',
            'platform' => 'linux',
            'arch' => 'amd64',
            'capabilities' => ['docker.exec', 'service.control'],
            'agentTime' => gmdate('Y-m-d\\TH:i:s\\Z'),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $this->signedAgentRequest($browser, self::HEARTBEAT_PATH, $body);

        $response = $browser->getResponse();
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
        $payload = $this->decodeJson($response);
        self::assertSame(30, $payload['pollAfterSeconds']);
        self::assertMatchesRegularExpression('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$/', (string) $payload['serverTime']);

        $row = $this->connection->fetchAssociative(
            'SELECT `version`, `capabilities`, `last_seen_at` FROM `muwa_agent_host` WHERE `id` = UNHEX(:id)',
            ['id' => $this->agent['id']]
        );
        self::assertIsArray($row);
        self::assertSame('1.2.3', $row['version']);
        self::assertSame(['docker.exec', 'service.control'], json_decode((string) $row['capabilities'], true, 8, JSON_THROW_ON_ERROR));
        self::assertNotNull($row['last_seen_at']);
        self::assertSame($otherBefore, $this->connection->fetchAssociative(
            'SELECT `version`, `capabilities`, `last_seen_at` FROM `muwa_agent_host` WHERE `id` = UNHEX(:id)',
            ['id' => $other['id']]
        ));

        $nonce = $browser->getRequest()->headers->get('X-Mucki-Agent-Nonce');
        self::assertIsString($nonce);
        self::assertSame(ResponseVerdict::Accept, (new ResponseSignature())->classify($this->secretBytes, $nonce, self::HEARTBEAT_PATH, $response));

        // A semantically identical heartbeat with a fresh nonce remains a
        // successful idempotent update rather than being confused with a
        // zero-row update or replay.
        $this->signedAgentRequest($browser, self::HEARTBEAT_PATH, $body);
        $retryResponse = $browser->getResponse();
        self::assertSame(Response::HTTP_OK, $retryResponse->getStatusCode(), (string) $retryResponse->getContent());
        $retryNonce = $browser->getRequest()->headers->get('X-Mucki-Agent-Nonce');
        self::assertIsString($retryNonce);
        self::assertSame(ResponseVerdict::Accept, (new ResponseSignature())->classify(
            $this->secretBytes,
            $retryNonce,
            self::HEARTBEAT_PATH,
            $retryResponse
        ));
    }

    public function testHeartbeatBodyAtExactLimitReachesControllerAndReturnsSignedPayloadError(): void
    {
        $browser = $this->browser();
        $limit = AgentProtocol::bodyLimit(AgentProtocol::ROUTE_HOST_HEARTBEAT);
        $body = '{' . str_repeat(' ', $limit - 2) . '}';
        self::assertSame($limit, strlen($body));

        $this->signedAgentRequest($browser, self::HEARTBEAT_PATH, $body);

        $response = $browser->getResponse();
        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertSame(AgentErrorCode::PayloadInvalid->value, $this->decodeJson($response)['code']);
        $nonce = $browser->getRequest()->headers->get('X-Mucki-Agent-Nonce');
        self::assertIsString($nonce);
        self::assertSame(ResponseVerdict::Accept, (new ResponseSignature())->classify(
            $this->secretBytes,
            $nonce,
            self::HEARTBEAT_PATH,
            $response
        ));
    }

    public function testHeartbeatBodyAboveLimitReturnsSignedBodyTooLargeError(): void
    {
        $browser = $this->browser();
        $limit = AgentProtocol::bodyLimit(AgentProtocol::ROUTE_HOST_HEARTBEAT);
        $body = '{' . str_repeat(' ', $limit - 1) . '}';
        self::assertSame($limit + 1, strlen($body));

        $this->signedAgentRequest($browser, self::HEARTBEAT_PATH, $body);

        $response = $browser->getResponse();
        self::assertSame(Response::HTTP_REQUEST_ENTITY_TOO_LARGE, $response->getStatusCode());
        self::assertSame(AgentErrorCode::BodyTooLarge->value, $this->decodeJson($response)['code']);
        $nonce = $browser->getRequest()->headers->get('X-Mucki-Agent-Nonce');
        self::assertIsString($nonce);
        self::assertSame(ResponseVerdict::Accept, (new ResponseSignature())->classify(
            $this->secretBytes,
            $nonce,
            self::HEARTBEAT_PATH,
            $response
        ));
    }

    public function testHostDisabledAfterAuthenticationIsRejectedByAtomicUpdateWithSignedError(): void
    {
        $this->assertPostAuthenticationStateChangeIsRejected('`enabled` = 0');
    }

    public function testHostRevokedAfterAuthenticationIsRejectedByAtomicUpdateWithSignedError(): void
    {
        $this->assertPostAuthenticationStateChangeIsRejected('`revoked_at` = NOW(3)');
    }

    private function assertPostAuthenticationStateChangeIsRejected(string $stateChange): void
    {
        $body = json_encode([
            'agentVersion' => '9.9.9',
            'platform' => 'linux',
            'arch' => 'amd64',
            'capabilities' => ['service.control'],
            'agentTime' => gmdate('Y-m-d\\TH:i:s\\Z'),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $nonce = 'race-nonce-' . bin2hex(random_bytes(8)) . 'paddingpad';
        $request = Request::create(self::HEARTBEAT_PATH, 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $body);
        $request->attributes->set(AgentAuthenticationSubscriber::AUTH_ATTRIBUTE, new AuthenticatedRequest(
            $this->agent['id'],
            $this->agent['keyId'],
            $this->secretBytes,
            AgentProtocol::ROUTE_HOST_HEARTBEAT,
            self::HEARTBEAT_PATH,
            (string) time(),
            $nonce,
            $body
        ));
        $before = $this->connection->fetchAssociative(
            'SELECT `version`, `capabilities`, `last_seen_at` FROM `muwa_agent_host` WHERE `id` = UNHEX(:id)',
            ['id' => $this->agent['id']]
        );

        // This state change deliberately occurs after the authenticated host
        // context exists but before the service performs its conditional UPDATE.
        $this->connection->executeStatement(
            'UPDATE `muwa_agent_host` SET ' . $stateChange . ' WHERE `id` = UNHEX(:id)',
            ['id' => $this->agent['id']]
        );
        $controller = self::getContainer()->get(HostHeartbeatController::class);
        self::assertInstanceOf(HostHeartbeatController::class, $controller);
        $response = $controller->heartbeat($request);
        $signer = self::getContainer()->get(AgentResponseSigningSubscriber::class);
        self::assertInstanceOf(AgentResponseSigningSubscriber::class, $signer);
        $signer->onKernelResponse(new ResponseEvent(
            self::getKernel(),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $response
        ));

        self::assertSame(AgentErrorCode::Revoked->value, $this->decodeJson($response)['code']);
        self::assertSame(ResponseVerdict::Accept, (new ResponseSignature())->classify(
            $this->secretBytes,
            $nonce,
            self::HEARTBEAT_PATH,
            $response
        ));
        self::assertSame($before, $this->connection->fetchAssociative(
            'SELECT `version`, `capabilities`, `last_seen_at` FROM `muwa_agent_host` WHERE `id` = UNHEX(:id)',
            ['id' => $this->agent['id']]
        ));
    }

    public function testMalformedHeartbeatGetsSignedPayloadErrorWithoutHostMutation(): void
    {
        $before = $this->connection->fetchAssociative(
            'SELECT `version`, `capabilities`, `last_seen_at` FROM `muwa_agent_host` WHERE `id` = UNHEX(:id)',
            ['id' => $this->agent['id']]
        );
        $browser = $this->browser();
        $this->signedAgentRequest($browser, self::HEARTBEAT_PATH, '{"agentVersion":"broken"}');

        $response = $browser->getResponse();
        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertSame(AgentErrorCode::PayloadInvalid->value, $this->decodeJson($response)['code']);
        $nonce = $browser->getRequest()->headers->get('X-Mucki-Agent-Nonce');
        self::assertIsString($nonce);
        self::assertSame(ResponseVerdict::Accept, (new ResponseSignature())->classify($this->secretBytes, $nonce, self::HEARTBEAT_PATH, $response));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertSame($before, $this->connection->fetchAssociative(
            'SELECT `version`, `capabilities`, `last_seen_at` FROM `muwa_agent_host` WHERE `id` = UNHEX(:id)',
            ['id' => $this->agent['id']]
        ));
    }

    public function testUnsupportedPlatformGetsSignedProtocolError(): void
    {
        $browser = $this->browser();
        $body = '{"agentVersion":"1.0.0","platform":"darwin","arch":"amd64","capabilities":[],"agentTime":"2026-11-23T10:15:30Z"}';
        $this->signedAgentRequest($browser, self::HEARTBEAT_PATH, $body);

        $response = $browser->getResponse();
        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertSame(AgentErrorCode::ProtocolUnsupported->value, $this->decodeJson($response)['code']);
        $nonce = $browser->getRequest()->headers->get('X-Mucki-Agent-Nonce');
        self::assertIsString($nonce);
        self::assertSame(ResponseVerdict::Accept, (new ResponseSignature())->classify($this->secretBytes, $nonce, self::HEARTBEAT_PATH, $response));
    }

    public function testUnsupportedMediaTypeGetsSigned415(): void
    {
        $browser = $this->browser();
        $this->signedAgentRequest($browser, self::HEARTBEAT_PATH, '{}', ['contentType' => 'text/plain']);

        $response = $browser->getResponse();
        self::assertSame(Response::HTTP_UNSUPPORTED_MEDIA_TYPE, $response->getStatusCode());
        self::assertSame(AgentErrorCode::UnsupportedMediaType->value, $this->decodeJson($response)['code']);
        self::assertNotNull($response->headers->get('X-Mucki-Agent-Signature'));
    }

    public function testBothSubscribersAreRegisteredWithNormativePriorities(): void
    {
        $authentication = self::getContainer()->get(\MuckiSafeAgentPlugin\Subscriber\AgentAuthenticationSubscriber::class);
        self::assertInstanceOf(\MuckiSafeAgentPlugin\Subscriber\AgentAuthenticationSubscriber::class, $authentication);
        $signing = self::getContainer()->get(\MuckiSafeAgentPlugin\Subscriber\AgentResponseSigningSubscriber::class);
        self::assertInstanceOf(\MuckiSafeAgentPlugin\Subscriber\AgentResponseSigningSubscriber::class, $signing);

        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        self::assertSame(256, $dispatcher->getListenerPriority('kernel.request', [$authentication, 'onKernelRequest']));
        self::assertSame(-256, $dispatcher->getListenerPriority('kernel.response', [$signing, 'onKernelResponse']));
        self::assertNotNull($dispatcher->getListenerPriority('kernel.exception', [$signing, 'onKernelException']));

        $requestListeners = $dispatcher->getListeners('kernel.request');
        self::assertContains([$authentication, 'onKernelRequest'], $requestListeners, 'authentication subscriber missing from kernel.request');
    }

    public function testUnrelatedRouteIsNotTouchedByTheSubscribers(): void
    {
        $browser = $this->browser();
        // outside the agent route prefix: neither subscriber may interfere,
        // the plain 404 of a missing route is neither signed nor no-store
        $browser->request('POST', '/api/_action/muwa/agentxyz', [], [], ['CONTENT_TYPE' => 'application/json'], '{}');

        $response = $browser->getResponse();
        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        self::assertNull($response->headers->get('X-Mucki-Agent-Signature'));
        self::assertStringNotContainsString('no-store', (string) $response->headers->get('Cache-Control', ''));
        self::assertStringNotContainsString('MUWA_AGENT_', (string) $response->getContent());
    }
}
