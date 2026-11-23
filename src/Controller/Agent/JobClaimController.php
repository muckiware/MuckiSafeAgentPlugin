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
use MuckiSafeAgentPlugin\Service\Job\JobClaimer;
use MuckiSafeAgentPlugin\Service\Job\JobClaimPayloadException;
use MuckiSafeAgentPlugin\Service\Job\JobClaimRejected;
use MuckiSafeAgentPlugin\Service\Job\JobClaimRequestDecoder;
use MuckiSafeAgentPlugin\Subscriber\AgentAuthenticationSubscriber;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: ['_routeScope' => ['api'], 'auth_required' => false])]
final class JobClaimController
{
    public function __construct(
        private readonly JobClaimRequestDecoder $decoder,
        private readonly JobClaimer $claimer
    ) {
    }

    #[Route(
        path: '/api/_action/muwa/agent/v1/jobs/claim',
        name: AgentProtocol::ROUTE_JOB_CLAIM,
        methods: ['POST']
    )]
    public function claim(Request $request): JsonResponse
    {
        $authenticated = $request->attributes->get(AgentAuthenticationSubscriber::AUTH_ATTRIBUTE);
        if (!$authenticated instanceof AuthenticatedRequest
            || $authenticated->routeName !== AgentProtocol::ROUTE_JOB_CLAIM
        ) {
            return AgentErrorResponses::forCode(AgentErrorCode::RequestInvalid);
        }

        try {
            $this->decoder->decode($authenticated->rawBody);
            $response = new JsonResponse($this->claimer->claim($authenticated->hostId)->toArray());
            $response->headers->set('Cache-Control', 'no-store');

            return $response;
        } catch (JobClaimPayloadException $exception) {
            return AgentErrorResponses::forCode($exception->errorCode);
        } catch (JobClaimRejected) {
            return AgentErrorResponses::forCode(AgentErrorCode::Revoked);
        }
    }
}
