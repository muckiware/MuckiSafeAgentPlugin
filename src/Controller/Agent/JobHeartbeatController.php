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

use MuckiSafeAgentPlugin\Core\Defaults as PluginDefaults;
use MuckiSafeAgentPlugin\Service\Auth\AgentErrorCode;
use MuckiSafeAgentPlugin\Service\Auth\AgentErrorResponses;
use MuckiSafeAgentPlugin\Service\Auth\AgentProtocol;
use MuckiSafeAgentPlugin\Service\Auth\AuthenticatedRequest;
use MuckiSafeAgentPlugin\Service\Job\ClaimConflict;
use MuckiSafeAgentPlugin\Service\Job\JobHeartbeat;
use MuckiSafeAgentPlugin\Service\Job\JobLeasePayloadException;
use MuckiSafeAgentPlugin\Service\Job\JobLeaseRequestDecoder;
use MuckiSafeAgentPlugin\Subscriber\AgentAuthenticationSubscriber;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: ['_routeScope' => ['api'], 'auth_required' => false])]
final class JobHeartbeatController
{
    public function __construct(
        protected LoggerInterface $logger,
        private readonly JobLeaseRequestDecoder $decoder,
        private readonly JobHeartbeat $heartbeat
    ) {
    }

    #[Route(
        path: '/api/_action/muwa/agent/v1/jobs/{jobId}/heartbeat',
        name: AgentProtocol::ROUTE_JOB_HEARTBEAT,
        methods: ['POST'],
        requirements: ['jobId' => AgentProtocol::UUID_PATTERN]
    )]
    public function heartbeat(Request $request, string $jobId): JsonResponse
    {
        $authenticated = $request->attributes->get(AgentAuthenticationSubscriber::AUTH_ATTRIBUTE);
        if (!$authenticated instanceof AuthenticatedRequest
            || $authenticated->routeName !== AgentProtocol::ROUTE_JOB_HEARTBEAT
        ) {
            return AgentErrorResponses::forCode(AgentErrorCode::RequestInvalid);
        }
        try {
            $payload = $this->decoder->decodeHeartbeat($authenticated->rawBody);
            return new JsonResponse($this->heartbeat->heartbeat(
                $authenticated->hostId,
                $jobId,
                $payload
            )->toArray());
        } catch (JobLeasePayloadException $exception) {

            $this->logger->error($exception->getMessage(), PluginDefaults::DEFAULT_LOGGER_CONFIG);
            return AgentErrorResponses::forCode($exception->errorCode);
        } catch (ClaimConflict $exception) {

            $this->logger->error($exception->getMessage(), PluginDefaults::DEFAULT_LOGGER_CONFIG);
            return AgentErrorResponses::forCode(AgentErrorCode::ClaimConflict);
        }
    }
}
