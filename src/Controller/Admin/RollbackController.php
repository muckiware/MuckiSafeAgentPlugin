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
namespace MuckiSafeAgentPlugin\Controller\Admin;

use Psr\Log\LoggerInterface;
use Shopware\Core\PlatformRequest;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

use MuckiSafeAgentPlugin\Core\Defaults as PluginDefaults;
use MuckiSafeAgentPlugin\Service\Rollback\AgentRollback;
use MuckiSafeAgentPlugin\Service\Rollback\RollbackStatus;

/**
 * Controlled rollback (Task 33). The status is the runbook gate and readable
 * with the viewer role; starting (switch on + drain, repeatable) and stopping
 * need the separate `muwa_agent.rollback` privilege.
 */
#[Route(defaults: ['_routeScope' => ['administration']])]
final class RollbackController
{
    public function __construct(
        protected LoggerInterface $logger,
        private readonly AgentRollback $rollback
    )
    {}

    #[Route(path: '/api/_action/muwa/agent/rollback', name: 'api.action.muwa.agent.rollback.status', defaults: [PlatformRequest::ATTRIBUTE_ACL => ['muwa_agent.viewer']], methods: ['GET'])]
    public function status(): JsonResponse
    {
        return $this->handle(fn (): RollbackStatus => $this->rollback->status());
    }

    #[Route(path: '/api/_action/muwa/agent/rollback/start', name: 'api.action.muwa.agent.rollback.start', defaults: [PlatformRequest::ATTRIBUTE_ACL => ['muwa_agent.rollback']], methods: ['POST'])]
    public function start(): JsonResponse
    {
        return $this->handle(function (): RollbackStatus {
            $status = $this->rollback->start();
            $this->logger->warning('Agent rollback started.', PluginDefaults::DEFAULT_LOGGER_CONFIG);

            return $status;
        });
    }

    #[Route(path: '/api/_action/muwa/agent/rollback/stop', name: 'api.action.muwa.agent.rollback.stop', defaults: [PlatformRequest::ATTRIBUTE_ACL => ['muwa_agent.rollback']], methods: ['POST'])]
    public function stop(): JsonResponse
    {
        return $this->handle(function (): RollbackStatus {
            $status = $this->rollback->stop();
            $this->logger->warning('Agent rollback stopped.', PluginDefaults::DEFAULT_LOGGER_CONFIG);

            return $status;
        });
    }

    /** @param \Closure(): RollbackStatus $operation */
    private function handle(\Closure $operation): JsonResponse
    {
        try {
            return $this->noStore($operation()->toArray());
        } catch (\Throwable $exception) {

            $this->logger->error($exception->getMessage(), PluginDefaults::DEFAULT_LOGGER_CONFIG);
            return $this->noStore(['error' => 'operation_failed'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /** @param array<string, mixed> $payload */
    private function noStore(array $payload, int $status = Response::HTTP_OK): JsonResponse
    {
        $response = new JsonResponse($payload, $status);
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->removeCacheControlDirective('private');

        return $response;
    }
}
