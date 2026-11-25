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

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Shopware\Core\PlatformRequest;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

use MuckiSafeAgentPlugin\Core\Defaults as PluginDefaults;

/**
 * Failure tails (stdout/stderr, at most 64 KiB each, already redacted by the
 * agent) are hidden from the generic DAL API and readable only with the
 * `muwa_agent.executor` privilege. Successful jobs never store tails, so the
 * values are null for them.
 */
#[Route(defaults: ['_routeScope' => ['administration']])]
final class JobOutputController
{
    public function __construct(
        protected LoggerInterface $logger,
        private readonly Connection $connection
    )
    {}

    #[Route(path: '/api/_action/muwa/agent/job/{id}/output', name: 'api.action.muwa.agent.job.output', defaults: [PlatformRequest::ATTRIBUTE_ACL => ['muwa_agent.executor']], methods: ['GET'])]
    public function output(string $id): JsonResponse
    {
        try {
            $row = preg_match('/^[0-9a-f]{32}$/D', $id) === 1 ? $this->connection->fetchAssociative(
                'SELECT `stdout_tail`, `stderr_tail` FROM `muwa_agent_job` WHERE `id` = UNHEX(:id)',
                ['id' => $id]
            ) : false;
            if ($row === false) {
                return $this->noStore(['error' => 'job_not_found'], Response::HTTP_NOT_FOUND);
            }

            return $this->noStore(['stdoutTail' => $row['stdout_tail'], 'stderrTail' => $row['stderr_tail']]);
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
