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
use MuckiSafeAgentPlugin\Service\Action\ActionConfigurationException;
use MuckiSafeAgentPlugin\Service\Action\ActionConfigurator;
use MuckiSafeAgentPlugin\Service\Action\ActionDraft;

/**
 * Thin admin API over ActionConfigurator. Reading actions stays on the
 * generic DAL API; writes go exclusively through these routes.
 */
#[Route(defaults: ['_routeScope' => ['administration']])]
final class ActionController
{
    private const STATUS = [
        ActionConfigurationException::INVALID_REQUEST => Response::HTTP_BAD_REQUEST,
        ActionConfigurationException::INVALID_DEFINITION => Response::HTTP_UNPROCESSABLE_ENTITY,
        ActionConfigurationException::TECHNICAL_NAME_TAKEN => Response::HTTP_CONFLICT,
        ActionConfigurationException::ACTION_NOT_FOUND => Response::HTTP_NOT_FOUND,
    ];

    public function __construct(
        protected LoggerInterface $logger,
        private readonly ActionConfigurator $configurator
    )
    {}

    #[Route(path: '/api/_action/muwa/agent/action', name: 'api.action.muwa.agent.action.save', defaults: [PlatformRequest::ATTRIBUTE_ACL => ['muwa_agent.editor']], methods: ['POST'])]
    public function save(Request $request): JsonResponse
    {
        return $this->handle(fn (): array => [
            'id' => $this->configurator->save(ActionDraft::fromPayload($this->payload($request))),
        ]);
    }

    #[Route(path: '/api/_action/muwa/agent/action/validate', name: 'api.action.muwa.agent.action.validate', defaults: [PlatformRequest::ATTRIBUTE_ACL => ['muwa_agent.editor']], methods: ['POST'])]
    public function validate(Request $request): JsonResponse
    {
        return $this->handle(function () use ($request): array {
            $payload = $this->payload($request);
            if (array_keys($payload) !== ['definition'] || !is_string($payload['definition'])) {
                throw ActionConfigurationException::invalidFields(['definition']);
            }

            return $this->configurator->validate($payload['definition']);
        });
    }

    /** @param \Closure(): array<string, mixed> $operation */
    private function handle(\Closure $operation): JsonResponse
    {
        try {
            return $this->noStore($operation());
        } catch (ActionConfigurationException $exception) {

            $this->logger->error($exception->getMessage(), PluginDefaults::DEFAULT_LOGGER_CONFIG);
            $body = ['error' => $exception->errorCode];
            if ($exception->fields !== []) {
                $body['fields'] = $exception->fields;
            }
            if ($exception->detail !== null) {
                $body['detail'] = $exception->detail;
            }

            return $this->noStore($body, self::STATUS[$exception->errorCode] ?? Response::HTTP_BAD_REQUEST);
        } catch (\Throwable $exception) {

            $this->logger->error($exception->getMessage(), PluginDefaults::DEFAULT_LOGGER_CONFIG);
            return $this->noStore(['error' => 'operation_failed'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /** @return array<mixed> */
    private function payload(Request $request): array
    {
        try {
            $payload = $request->toArray();
        } catch (\Throwable $exception) {
            throw new ActionConfigurationException(ActionConfigurationException::INVALID_REQUEST, 'Invalid request.', [], null, $exception);
        }
        if (array_is_list($payload) && $payload !== []) {
            throw new ActionConfigurationException(ActionConfigurationException::INVALID_REQUEST, 'Invalid request.');
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
