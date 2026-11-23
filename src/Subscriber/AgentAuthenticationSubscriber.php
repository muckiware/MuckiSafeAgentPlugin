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

namespace MuckiSafeAgentPlugin\Subscriber;

use MuckiSafeAgentPlugin\Service\Auth\AgentAuthException;
use MuckiSafeAgentPlugin\Service\Auth\AgentAuthenticatorInterface;
use MuckiSafeAgentPlugin\Service\Auth\AgentErrorCode;
use MuckiSafeAgentPlugin\Service\Auth\AgentErrorResponses;
use MuckiSafeAgentPlugin\Service\Auth\AgentProtocol;
use MuckiSafeAgentPlugin\Service\Auth\AuthenticatedRequest;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * Protects every agent route before any controller runs (kernel.request,
 * priority 256, ahead of the router). Successful authentication attaches
 * the AuthenticatedRequest context to the request; rejections are answered
 * with the stable error DTO, which the response signing subscriber signs.
 * Only the unresolved key case yields the unsignable empty 401.
 */
final class AgentAuthenticationSubscriber implements EventSubscriberInterface
{
    public const AUTH_ATTRIBUTE='muwa_agent_authenticated';

    public function __construct(private readonly AgentAuthenticatorInterface $authenticator)
    {
    }

    /**
     * @return array<string, array<int, string|int>>
     */
    public static function getSubscribedEvents(): array
    {
        return ['kernel.request' => ['onKernelRequest', 256]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        if (!self::isAgentRoute($request->getPathInfo())) {
            return;
        }

        try {
            $authenticated = $this->authenticator->authenticate($request);
        } catch (AgentAuthException $exception) {
            $agent = $exception->agent;
            if ($agent === null) {
                $event->setResponse(new Response('', Response::HTTP_UNAUTHORIZED, ['Cache-Control' => 'no-store']));

                return;
            }
            $request->attributes->set(self::AUTH_ATTRIBUTE, $agent);
            $event->setResponse(AgentErrorResponses::forCode($exception->errorCode ?? AgentErrorCode::InternalError));

            return;
        }

        $request->attributes->set(self::AUTH_ATTRIBUTE, $authenticated);
        // The authenticated bytes are authoritative and decoded by the endpoint's
        // duplicate-aware decoder. Prevent Shopware's later generic JSON request
        // transformer from pre-empting that decoder for malformed JSON.
        $request->headers->set('Content-Type', 'application/octet-stream');
    }

    /** Exact route prefix of the v1 agent routes, no trailing suffix. */
    public static function isAgentRoute(string $path): bool
    {
        return str_starts_with($path, AgentProtocol::ROUTE_PREFIX . '/');
    }
}
