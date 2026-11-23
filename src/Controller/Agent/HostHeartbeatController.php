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
namespace MuckiSafeAgentPlugin\Controller\Agent;

use MuckiSafeAgentPlugin\Service\Auth\AgentErrorCode;
use MuckiSafeAgentPlugin\Service\Auth\AgentErrorResponses;
use MuckiSafeAgentPlugin\Service\Auth\AgentProtocol;
use MuckiSafeAgentPlugin\Service\Auth\AuthenticatedRequest;
use MuckiSafeAgentPlugin\Service\Heartbeat\HostHeartbeat;
use MuckiSafeAgentPlugin\Service\Heartbeat\HostHeartbeatPayloadException;
use MuckiSafeAgentPlugin\Service\Heartbeat\HostHeartbeatRejected;
use MuckiSafeAgentPlugin\Service\Heartbeat\HostHeartbeatRequestDecoder;
use MuckiSafeAgentPlugin\Subscriber\AgentAuthenticationSubscriber;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: ['_routeScope' => ['api'], 'auth_required' => false])]
final class HostHeartbeatController
{
    public function __construct(
        private readonly HostHeartbeatRequestDecoder $decoder,
        private readonly HostHeartbeat $heartbeat
    ) {
    }

    #[Route(
        path: '/api/_action/muwa/agent/v1/heartbeat',
        name: AgentProtocol::ROUTE_HOST_HEARTBEAT,
        methods: ['POST']
    )]
    public function heartbeat(Request $request): JsonResponse
    {
        $authenticated = $request->attributes->get(AgentAuthenticationSubscriber::AUTH_ATTRIBUTE);
        if (!$authenticated instanceof AuthenticatedRequest
            || $authenticated->routeName !== AgentProtocol::ROUTE_HOST_HEARTBEAT
        ) {
            return AgentErrorResponses::forCode(AgentErrorCode::RequestInvalid);
        }

        try {
            $payload = $this->decoder->decode($authenticated->rawBody);
            $response = new JsonResponse($this->heartbeat->record($authenticated->hostId, $payload));
            $response->headers->set('Cache-Control', 'no-store');

            return $response;
        } catch (HostHeartbeatPayloadException $exception) {
            return AgentErrorResponses::forCode($exception->errorCode);
        } catch (HostHeartbeatRejected) {
            return AgentErrorResponses::forCode(AgentErrorCode::Revoked);
        }
    }
}
