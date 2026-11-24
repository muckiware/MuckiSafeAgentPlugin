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
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

use MuckiSafeAgentPlugin\Core\Defaults as PluginDefaults;
use MuckiSafeAgentPlugin\Service\Secret\AgentProvisioner;
use MuckiSafeAgentPlugin\Service\Secret\AgentSecretRotator;
use MuckiSafeAgentPlugin\Service\Secret\MasterKeyReencryptor;

#[Route(defaults: ['_routeScope' => ['administration']])]
final class AgentProvisionController
{
    public function __construct(
        protected LoggerInterface $logger,
        private readonly AgentProvisioner $provisioner,
        private readonly AgentSecretRotator $rotator,
        private readonly MasterKeyReencryptor $reencryptor
    ) {
    }

    #[Route(path: '/api/_action/muwa/agent/provision', name: 'api.action.muwa.agent.provision', defaults: [PlatformRequest::ATTRIBUTE_ACL => ['muwa_agent.provision']], methods: ['POST'])]
    public function provision(Request $request): JsonResponse
    {
        try {
            $payload = $this->payload($request);
            if (!array_key_exists('name', $payload) || !is_string($payload['name'])) {
                throw new \InvalidArgumentException('Invalid request.');
            }
            return $this->noStore($this->provisioner->provision($payload['name']));
        } catch (\InvalidArgumentException $exception) {

            $this->logger->error($exception->getMessage(), PluginDefaults::DEFAULT_LOGGER_CONFIG);
            return $this->noStore(['error' => 'invalid_request'], Response::HTTP_BAD_REQUEST);
        } catch (\Throwable $exception) {

            $this->logger->error($exception->getMessage(), PluginDefaults::DEFAULT_LOGGER_CONFIG);
            return $this->noStore(['error' => 'operation_failed'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route(path: '/api/_action/muwa/agent/{id}/rotate', name: 'api.action.muwa.agent.rotate', defaults: [PlatformRequest::ATTRIBUTE_ACL => ['muwa_agent.rotate']], methods: ['POST'])]
    public function rotate(string $id): JsonResponse
    {
        try {
            return $this->noStore($this->rotator->rotate($id));
        } catch (\InvalidArgumentException $exception) {

            $this->logger->error($exception->getMessage(), PluginDefaults::DEFAULT_LOGGER_CONFIG);
            return $this->noStore(['error' => 'invalid_request'], Response::HTTP_BAD_REQUEST);
        } catch (\Throwable $exception) {

            $this->logger->error($exception->getMessage(), PluginDefaults::DEFAULT_LOGGER_CONFIG);
            return $this->noStore(['error' => 'operation_failed'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route(path: '/api/_action/muwa/agent/{id}/revoke', name: 'api.action.muwa.agent.revoke', defaults: [PlatformRequest::ATTRIBUTE_ACL => ['muwa_agent.revoke']], methods: ['POST'])]
    public function revoke(string $id): JsonResponse
    {
        try {
            $this->rotator->revoke($id);
            return $this->noStore(['revoked' => true]);
        } catch (\InvalidArgumentException $exception) {

            $this->logger->error($exception->getMessage(), PluginDefaults::DEFAULT_LOGGER_CONFIG);
            return $this->noStore(['error' => 'invalid_request'], Response::HTTP_BAD_REQUEST);
        } catch (\Throwable $exception) {

            $this->logger->error($exception->getMessage(), PluginDefaults::DEFAULT_LOGGER_CONFIG);
            return $this->noStore(['error' => 'operation_failed'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route(path: '/api/_action/muwa/agent/master-key/reencrypt', name: 'api.action.muwa.agent.master_key_reencrypt', defaults: [PlatformRequest::ATTRIBUTE_ACL => ['muwa_agent.master_key_reencrypt']], methods: ['POST'])]
    public function reencrypt(Request $request): JsonResponse
    {
        try {
            $payload = $this->payload($request);
            if (!isset($payload['fromVersion']) || !is_int($payload['fromVersion']) || $payload['fromVersion'] < 1) {
                throw new \InvalidArgumentException('Invalid request.');
            }
            $batchSize = $payload['batchSize'] ?? 100;
            if (!is_int($batchSize) || $batchSize < 1 || $batchSize > 1000) {
                throw new \InvalidArgumentException('Invalid request.');
            }
            if (array_key_exists('afterId', $payload)) {
                throw new \InvalidArgumentException('Invalid request.');
            }
            return $this->noStore($this->reencryptor->reencryptBatch(
                $payload['fromVersion'],
                $batchSize
            ));
        } catch (\InvalidArgumentException $exception) {

            $this->logger->error($exception->getMessage(), PluginDefaults::DEFAULT_LOGGER_CONFIG);
            return $this->noStore(['error' => 'invalid_request'], Response::HTTP_BAD_REQUEST);
        } catch (\Throwable $exception) {

            $this->logger->error($exception->getMessage(), PluginDefaults::DEFAULT_LOGGER_CONFIG);
            return $this->noStore(['error' => 'operation_failed'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /** @return array<string, mixed> */
    private function payload(Request $request): array
    {
        try {
            $payload = $request->toArray();
        } catch (\Throwable $exception) {

            $this->logger->error($exception->getMessage(), PluginDefaults::DEFAULT_LOGGER_CONFIG);
            throw new \InvalidArgumentException('Invalid request.', 0, $exception);
        }
        if (array_is_list($payload) && $payload !== []) {
            throw new \InvalidArgumentException('Invalid request.');
        }
        return $payload;
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
