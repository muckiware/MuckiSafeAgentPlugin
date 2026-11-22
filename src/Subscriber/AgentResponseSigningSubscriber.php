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

use MuckiSafeAgentPlugin\Exception\SignatureException;
use MuckiSafeAgentPlugin\Service\Auth\AgentAuthException;
use MuckiSafeAgentPlugin\Service\Auth\AgentErrorCode;
use MuckiSafeAgentPlugin\Service\Auth\AgentErrorResponses;
use MuckiSafeAgentPlugin\Service\Auth\AuthenticatedRequest;
use MuckiSafeAgentPlugin\Service\Auth\ResponseSignature;
use MuckiSafeAgentPlugin\Service\Auth\SignatureEnvelope;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

/**
 * Signs every agent route response after successful key resolution —
 * including error DTOs and kernel exceptions (kernel.response priority
 * -256, kernel.exception converts throwables into the stable signed error
 * DTO first). All agent route responses carry Cache-Control: no-store. If
 * no canonical request nonce exists, signing is impossible and the response
 * stays unsigned; the Go agent rejects such responses fail-closed.
 */
final class AgentResponseSigningSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly ResponseSignature $responseSignature)
    {
    }

    /**
     * @return array<string, array<int, string|int>>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'kernel.response' => ['onKernelResponse', -256],
            'kernel.exception' => ['onKernelException'],
        ];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || !AgentAuthenticationSubscriber::isAgentRoute($event->getRequest()->getPathInfo())) {
            return;
        }
        $response = $event->getResponse();
        $response->headers->set('Cache-Control', 'no-store');

        $agent = $event->getRequest()->attributes->get(AgentAuthenticationSubscriber::AUTH_ATTRIBUTE);
        if (!$agent instanceof AuthenticatedRequest) {
            return;
        }

        $this->signResponse($event->getRequest()->getPathInfo(), $response, $agent);
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        if (!$event->isMainRequest() || !AgentAuthenticationSubscriber::isAgentRoute($event->getRequest()->getPathInfo())) {
            return;
        }
        $agent = $event->getRequest()->attributes->get(AgentAuthenticationSubscriber::AUTH_ATTRIBUTE);
        if (!$agent instanceof AuthenticatedRequest) {
            return;
        }

        $throwable = $event->getThrowable();
        $code = $throwable instanceof AgentAuthException && $throwable->errorCode !== null
            ? $throwable->errorCode
            : AgentErrorCode::InternalError;
        $event->setResponse(AgentErrorResponses::forCode($code));
    }

    private function signResponse(string $path, Response $response, #[\SensitiveParameter] AuthenticatedRequest $agent): void
    {
        try {
            $envelope = new SignatureEnvelope(
                $path,
                hash('sha256', (string) $response->getContent()),
                (string) time(),
                $agent->nonce
            );
            foreach ($this->responseSignature->headers($agent->secret, $response->getStatusCode(), $envelope) as $name => $value) {
                $response->headers->set($name, $value);
            }
        } catch (SignatureException) {
            // no canonical request nonce or path: a verifiable signature is
            // impossible — leave the response unsigned and let the agent
            // reject it fail-closed
        }
    }
}
