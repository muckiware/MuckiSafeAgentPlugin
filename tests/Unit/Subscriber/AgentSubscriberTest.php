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

namespace MuckiSafeAgentPlugin\tests\Unit\Subscriber;

use MuckiSafeAgentPlugin\Service\Auth\AgentAuthException;
use MuckiSafeAgentPlugin\Service\Auth\AgentAuthenticatorInterface;
use MuckiSafeAgentPlugin\Service\Auth\AgentErrorCode;
use MuckiSafeAgentPlugin\Service\Auth\AuthenticatedRequest;
use MuckiSafeAgentPlugin\Service\Auth\RequestSignature;
use MuckiSafeAgentPlugin\Service\Auth\ResponseSignature;
use MuckiSafeAgentPlugin\Service\Auth\ResponseVerdict;
use MuckiSafeAgentPlugin\Service\Auth\SignatureEnvelope;
use MuckiSafeAgentPlugin\Subscriber\AgentAuthenticationSubscriber;
use MuckiSafeAgentPlugin\Subscriber\AgentResponseSigningSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Controller\ControllerResolver;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernel;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Task 9 RED: subscriber control flow without a database. The authentication
 * subscriber turns authenticator outcomes into responses and the request
 * attribute; the signing subscriber signs every agent-route response after
 * key resolution (including error DTOs) and sets no-store on all of them.
 */
final class AgentSubscriberTest extends TestCase
{
    private const HEARTBEAT_PATH = '/api/_action/muwa/agent/v1/heartbeat';

    private const SECRET = '0123456789abcdef0123456789abcdef';

    private const NONCE = 'unit-test-nonce-0123456789abcdef0123456';

    private HttpKernel $kernel;

    protected function setUp(): void
    {
        $this->kernel = new HttpKernel(new EventDispatcher(), new ControllerResolver());
    }

    private function agentContext(): AuthenticatedRequest
    {
        return new AuthenticatedRequest(
            str_repeat('a', 32),
            str_repeat('b', 32),
            self::SECRET,
            'api.action.muwa.agent.v1.host_heartbeat',
            self::HEARTBEAT_PATH,
            '1790000000',
            self::NONCE
        );
    }

    private function agentRequest(string $path = self::HEARTBEAT_PATH): Request
    {
        return Request::create($path, 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}');
    }

    private function requestEvent(Request $request): RequestEvent
    {
        return new RequestEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST);
    }

    /**
     * @param callable(Request, ?int): AuthenticatedRequest $authenticate
     */
    private function authenticationSubscriber(callable $authenticate): AgentAuthenticationSubscriber
    {
        $authenticator = new class ($authenticate) implements AgentAuthenticatorInterface {
            public function __construct(private readonly mixed $authenticate)
            {
            }

            public function authenticate(Request $request, ?int $serverNow = null): AuthenticatedRequest
            {
                return ($this->authenticate)($request, $serverNow);
            }
        };

        return new AgentAuthenticationSubscriber($authenticator);
    }

    public function testSubscribedPrioritiesMatchThePlan(): void
    {
        self::assertSame(['kernel.request' => ['onKernelRequest', 256]], AgentAuthenticationSubscriber::getSubscribedEvents());
        self::assertSame([
            'kernel.response' => ['onKernelResponse', -256],
            'kernel.exception' => ['onKernelException'],
        ], AgentResponseSigningSubscriber::getSubscribedEvents());
    }

    public function testValidRequestIsPassedThroughWithAttribute(): void
    {
        $agent = $this->agentContext();
        $subscriber = $this->authenticationSubscriber(static function () use ($agent): AuthenticatedRequest {
            return $agent;
        });
        $request = $this->agentRequest();
        $event = $this->requestEvent($request);

        $subscriber->onKernelRequest($event);

        self::assertFalse($event->hasResponse());
        self::assertSame($agent, $request->attributes->get(AgentAuthenticationSubscriber::AUTH_ATTRIBUTE));
    }

    public function testUnknownKeyReceivesEmptyUnsigned401AndNoAttribute(): void
    {
        $subscriber = $this->authenticationSubscriber(static function (): AuthenticatedRequest {
            throw AgentAuthException::unknownKey();
        });
        $request = $this->agentRequest();
        $event = $this->requestEvent($request);

        $subscriber->onKernelRequest($event);

        self::assertTrue($event->hasResponse());
        $response = $event->getResponse();
        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
        self::assertSame('', (string) $response->getContent());
        self::assertNull($request->attributes->get(AgentAuthenticationSubscriber::AUTH_ATTRIBUTE));
    }

    public function testSignedFailureAfterKeyResolutionSetsAttributeAndErrorDto(): void
    {
        $agent = $this->agentContext();
        $subscriber = $this->authenticationSubscriber(static function () use ($agent): AuthenticatedRequest {
            throw new AgentAuthException(AgentErrorCode::ReplayDetected, 'replayed', $agent);
        });
        $request = $this->agentRequest();
        $event = $this->requestEvent($request);

        $subscriber->onKernelRequest($event);

        self::assertTrue($event->hasResponse());
        $response = $event->getResponse();
        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertSame(AgentErrorCode::ReplayDetected->value, $payload['code']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', (string) $payload['traceId']);
        self::assertSame($agent, $request->attributes->get(AgentAuthenticationSubscriber::AUTH_ATTRIBUTE));
    }

    public function testNonAgentPathsAreIgnored(): void
    {
        $subscriber = $this->authenticationSubscriber(static function (): AuthenticatedRequest {
            throw new \LogicException('must not be called');
        });
        $request = $this->agentRequest('/api/_action/muwa/agent/provision');
        $event = $this->requestEvent($request);

        $subscriber->onKernelRequest($event);

        self::assertFalse($event->hasResponse());
        self::assertFalse($request->attributes->has(AgentAuthenticationSubscriber::AUTH_ATTRIBUTE));
    }

    public function testResponseSigningSignsControllerResponsesAndSetsNoStore(): void
    {
        $agent = $this->agentContext();
        $request = $this->agentRequest();
        $request->attributes->set(AgentAuthenticationSubscriber::AUTH_ATTRIBUTE, $agent);
        $response = new Response('{"serverTime":"now"}', 200, ['CONTENT_TYPE' => 'application/json']);
        $event = new ResponseEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);

        (new AgentResponseSigningSubscriber(new ResponseSignature()))->onKernelResponse($event);

        self::assertSame(self::NONCE, $response->headers->get('X-Mucki-Agent-Request-Nonce'));
        self::assertMatchesRegularExpression('/^[0-9]{1,10}$/', (string) $response->headers->get('X-Mucki-Agent-Timestamp'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control', ''));
        $verdict = (new ResponseSignature())->classify(
            self::SECRET,
            self::NONCE,
            self::HEARTBEAT_PATH,
            $response
        );
        self::assertSame(ResponseVerdict::Accept, $verdict);
    }

    public function testResponseSigningLeavesUnsigned401Unsigned(): void
    {
        $request = $this->agentRequest();
        $response = new Response('', 401);
        $event = new ResponseEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);

        (new AgentResponseSigningSubscriber(new ResponseSignature()))->onKernelResponse($event);

        self::assertNull($response->headers->get('X-Mucki-Agent-Signature'));
        self::assertNull($response->headers->get('X-Mucki-Agent-Request-Nonce'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control', ''));
    }

    public function testExceptionSubscriberAnswersSignedStableInternalErrorDto(): void
    {
        $agent = $this->agentContext();
        $request = $this->agentRequest();
        $request->attributes->set(AgentAuthenticationSubscriber::AUTH_ATTRIBUTE, $agent);
        $signing = new AgentResponseSigningSubscriber(new ResponseSignature());

        $event = new ExceptionEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST, new \RuntimeException('boom'));
        $signing->onKernelException($event);

        self::assertTrue($event->hasResponse());
        $response = $event->getResponse();
        self::assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertSame(AgentErrorCode::InternalError->value, $payload['code']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', (string) $payload['traceId']);

        // the kernel passes exception responses through kernel.response,
        // where the same subscriber signs them — emulate that pass here
        $responseEvent = new ResponseEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);
        $signing->onKernelResponse($responseEvent);

        $verdict = (new ResponseSignature())->classify(self::SECRET, self::NONCE, self::HEARTBEAT_PATH, $response);
        self::assertSame(ResponseVerdict::Accept, $verdict);
        self::assertSame(self::NONCE, $response->headers->get('X-Mucki-Agent-Request-Nonce'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control', ''));
    }

    public function testExceptionSubscriberWithoutKeyResolutionLeavesResponseUntouched(): void
    {
        $request = $this->agentRequest();
        $event = new ExceptionEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST, new \RuntimeException('boom'));

        (new AgentResponseSigningSubscriber(new ResponseSignature()))->onKernelException($event);

        self::assertFalse($event->hasResponse());
    }

    public function testRequestSignatureHeadersRemainUnchangedBySubscribers(): void
    {
        $agent = $this->agentContext();
        $subscriber = $this->authenticationSubscriber(static function () use ($agent): AuthenticatedRequest {
            return $agent;
        });
        $request = Request::create(self::HEARTBEAT_PATH, 'POST', [], [], [], [
            'HTTP_X_MUCKI_AGENT_KEY' => str_repeat('b', 32),
            'HTTP_X_MUCKI_AGENT_TIMESTAMP' => '1790000000',
            'HTTP_X_MUCKI_AGENT_NONCE' => self::NONCE,
            'HTTP_X_MUCKI_AGENT_SIGNATURE' => str_repeat('c', 64),
        ], '{}');

        $subscriber->onKernelRequest($this->requestEvent($request));

        self::assertSame(str_repeat('b', 32), $request->headers->get('X-Mucki-Agent-Key'));
        self::assertSame(str_repeat('c', 64), $request->headers->get('X-Mucki-Agent-Signature'));
    }

    /**
     * The Go agent verifies that a signature over an empty 401 body is absent,
     * so the subscribers must never attach signature headers without context.
     */
    public function testSignedErrorDtoSignatureVerifiesWithClientLogic(): void
    {
        $agent = $this->agentContext();
        $subscriber = $this->authenticationSubscriber(static function () use ($agent): AuthenticatedRequest {
            throw new AgentAuthException(AgentErrorCode::Revoked, 'revoked', $agent);
        });
        $request = $this->agentRequest();
        $event = $this->requestEvent($request);
        $subscriber->onKernelRequest($event);
        self::assertTrue($event->hasResponse());
        $errorResponse = $event->getResponse();

        // emulate the kernel.response pass
        $responseEvent = new ResponseEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST, $errorResponse);
        (new AgentResponseSigningSubscriber(new ResponseSignature()))->onKernelResponse($responseEvent);

        $verdict = (new ResponseSignature())->classify(self::SECRET, self::NONCE, self::HEARTBEAT_PATH, $errorResponse);
        self::assertSame(ResponseVerdict::Accept, $verdict);
        self::assertSame(Response::HTTP_UNAUTHORIZED, $errorResponse->getStatusCode());

        $payload = json_decode((string) $errorResponse->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertSame(AgentErrorCode::Revoked->value, $payload['code']);
        // secret bytes are never part of any response payload
        self::assertStringNotContainsString(self::SECRET, (string) $errorResponse->getContent());
    }
}
