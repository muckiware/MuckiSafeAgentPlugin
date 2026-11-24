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

use MuckiSafeAgentPlugin\Service\Hook\HookConfigurationException;
use MuckiSafeAgentPlugin\Service\Hook\RepositoryHookConfigurator;
use MuckiSafeAgentPlugin\Service\Hook\RepositoryHookDraft;
use Shopware\Core\Framework\Context;
use Shopware\Core\PlatformRequest;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Thin admin API over RepositoryHookConfigurator. Reading hooks stays on the
 * generic DAL API; writes go exclusively through these routes.
 */
#[Route(defaults: ['_routeScope' => ['administration']])]
final class RepositoryHookController
{
    private const STATUS = [
        HookConfigurationException::INVALID_REQUEST => Response::HTTP_BAD_REQUEST,
        HookConfigurationException::FACILITY_REPOSITORY_NOT_FOUND => Response::HTTP_NOT_FOUND,
        HookConfigurationException::HOOK_NOT_FOUND => Response::HTTP_NOT_FOUND,
        HookConfigurationException::LEGACY_HOOK_CONFLICT => Response::HTTP_CONFLICT,
        HookConfigurationException::FACILITY_UNAVAILABLE => Response::HTTP_SERVICE_UNAVAILABLE,
    ];

    public function __construct(private readonly RepositoryHookConfigurator $configurator)
    {
    }

    #[Route(path: '/api/_action/muwa/agent/repository-hook', name: 'api.action.muwa.agent.repository_hook.save', defaults: [PlatformRequest::ATTRIBUTE_ACL => ['muwa_agent.editor']], methods: ['POST'])]
    public function save(Request $request, Context $context): JsonResponse
    {
        return $this->handle(fn (): array => [
            'id' => $this->configurator->save(RepositoryHookDraft::fromPayload($this->payload($request)), $context),
        ]);
    }

    #[Route(path: '/api/_action/muwa/agent/repository-hook/{id}', name: 'api.action.muwa.agent.repository_hook.delete', defaults: [PlatformRequest::ATTRIBUTE_ACL => ['muwa_agent.editor']], methods: ['DELETE'])]
    public function delete(string $id, Context $context): JsonResponse
    {
        return $this->handle(function () use ($id, $context): array {
            $this->configurator->delete($id, $context);

            return ['deleted' => true];
        });
    }

    /** @param \Closure(): array<string, mixed> $operation */
    private function handle(\Closure $operation): JsonResponse
    {
        try {
            return $this->noStore($operation());
        } catch (HookConfigurationException $exception) {
            $body = ['error' => $exception->errorCode];
            if ($exception->fields !== []) {
                $body['fields'] = $exception->fields;
            }

            return $this->noStore($body, self::STATUS[$exception->errorCode] ?? Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Throwable) {
            return $this->noStore(['error' => 'operation_failed'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /** @return array<mixed> */
    private function payload(Request $request): array
    {
        try {
            $payload = $request->toArray();
        } catch (\Throwable $exception) {
            throw new HookConfigurationException(HookConfigurationException::INVALID_REQUEST, 'Invalid request.', [], $exception);
        }
        if (array_is_list($payload) && $payload !== []) {
            throw new HookConfigurationException(HookConfigurationException::INVALID_REQUEST, 'Invalid request.');
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
