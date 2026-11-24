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
use MuckiSafeAgentPlugin\Service\Job\ClaimConflict;
use MuckiSafeAgentPlugin\Service\Job\JobCompletePayloadException;
use MuckiSafeAgentPlugin\Service\Job\JobCompleteRequestDecoder;
use MuckiSafeAgentPlugin\Service\Job\JobCompletion;
use MuckiSafeAgentPlugin\Subscriber\AgentAuthenticationSubscriber;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: ['_routeScope' => ['api'], 'auth_required' => false])]
final class JobCompleteController
{
    public function __construct(
        private readonly JobCompleteRequestDecoder $decoder,
        private readonly JobCompletion $completion
    ) {
    }

    #[Route(
        path: '/api/_action/muwa/agent/v1/jobs/{jobId}/complete',
        name: AgentProtocol::ROUTE_JOB_COMPLETE,
        methods: ['POST'],
        requirements: ['jobId' => AgentProtocol::UUID_PATTERN]
    )]
    public function complete(Request $request, string $jobId): JsonResponse
    {
        $authenticated = $request->attributes->get(AgentAuthenticationSubscriber::AUTH_ATTRIBUTE);
        if (!$authenticated instanceof AuthenticatedRequest
            || $authenticated->routeName !== AgentProtocol::ROUTE_JOB_COMPLETE
        ) {
            return AgentErrorResponses::forCode(AgentErrorCode::RequestInvalid);
        }
        try {
            $payload = $this->decoder->decode($authenticated->rawBody);
            return new JsonResponse($this->completion->complete(
                $authenticated->hostId,
                $jobId,
                $payload
            )->toArray());
        } catch (JobCompletePayloadException $exception) {
            return AgentErrorResponses::forCode($exception->errorCode);
        } catch (ClaimConflict) {
            return AgentErrorResponses::forCode(AgentErrorCode::ClaimConflict);
        }
    }
}
