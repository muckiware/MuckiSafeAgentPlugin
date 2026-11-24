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
namespace MuckiSafeAgentPlugin\Service\Hook;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Log\LoggerInterface;

use MuckiSafeAgentPlugin\Core\Defaults as PluginDefaults;
use MuckiSafeAgentPlugin\Service\Action\ActionDefinitionException;
use MuckiSafeAgentPlugin\Service\Job\JobCreator;
use MuckiSafeAgentPlugin\Service\Job\JobWaiter;

/**
 * Materializes one hook as a targeted job of a backup run and waits for its
 * terminal status. The phase deadline is computed from the server clock and
 * the server-side clamped budget; the job idempotency key `<run>:<phase>`
 * guarantees at most one job per run and phase. A job that cannot be
 * created (agent revoked, capability or action gone meanwhile) is a failed
 * hook, not a crash.
 */
final class HostAgentHookExecutor
{
    private const SAFE_MAX_ATTEMPTS = 3;
    private const NON_RETRYABLE_MAX_ATTEMPTS = 1;

    /** @param (\Closure(): \DateTimeImmutable)|null $clock */
    public function __construct(
        protected LoggerInterface $logger,
        private readonly Connection $connection,
        private readonly JobCreator $jobCreator,
        private readonly JobWaiter $waiter,
        private readonly ?\Closure $clock = null
    ) {
    }

    public function execute(ConfiguredHook $hook, string $runId): HookOutcome
    {
        $idempotencyKey = $runId . ':' . $hook->phase->value;
        try {
            $jobId = $this->createJob($hook, $runId, $idempotencyKey);
        } catch (UniqueConstraintViolationException $exception) {
            $jobId = $this->connection->fetchOne(
                'SELECT LOWER(HEX(`id`)) FROM `muwa_agent_job` WHERE `idempotency_key` = :key',
                ['key' => $idempotencyKey]
            );
            if (!is_string($jobId)) {
                throw $exception;
            }
        } catch (\InvalidArgumentException | ActionDefinitionException $exception) {

            $this->logger->error($exception->getMessage(), PluginDefaults::DEFAULT_LOGGER_CONFIG);
            return new HookOutcome('not_created', null);
        }

        return new HookOutcome($this->waiter->wait($jobId), $jobId);
    }

    private function createJob(ConfiguredHook $hook, string $runId, string $idempotencyKey): string
    {
        $now = $this->now();
        $idempotencyClass = $this->connection->fetchOne(
            'SELECT `idempotency_class` FROM `muwa_agent_action` WHERE `id` = UNHEX(:id)',
            ['id' => $hook->agentActionId]
        );

        return $this->jobCreator->create(
            $runId,
            $hook->agentActionId,
            $hook->facilityRepositoryId,
            $hook->agentHostId,
            $hook->phase->value,
            $now,
            $now->modify('+' . $hook->budgetSeconds() . ' seconds'),
            $idempotencyClass === 'safe' ? self::SAFE_MAX_ATTEMPTS : self::NON_RETRYABLE_MAX_ATTEMPTS,
            $idempotencyKey
        );
    }

    private function now(): \DateTimeImmutable
    {
        if ($this->clock !== null) {
            return ($this->clock)();
        }

        return new \DateTimeImmutable((string) $this->connection->fetchOne('SELECT UTC_TIMESTAMP(3)'), new \DateTimeZone('UTC'));
    }
}
