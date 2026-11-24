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
use MuckiSafeAgentPlugin\Service\Plugin\FacilityDependencyGuard;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Single write path for repository hooks. Every hook field is write-protected
 * outside the system scope, so the generic admin API cannot bypass these
 * checks: Facility available, Facility repository present without active
 * legacy hooks, target agent enabled/not revoked/capable, action enabled and
 * the budget within the action maximum. There is exactly one hook per
 * repository and phase; saving a phase again replaces that hook. The runtime
 * re-check before any side effect follows in the Facility subscribers.
 */
final class RepositoryHookConfigurator
{
    /** @param EntityRepository<\MuckiSafeAgentPlugin\Core\Content\AgentRepositoryHook\AgentRepositoryHookCollection> $hookRepository */
    public function __construct(
        private readonly Connection $connection,
        private readonly EntityRepository $hookRepository,
        private readonly LegacyHookConflictGuard $legacyGuard,
        private readonly FacilityDependencyGuard $facilityGuard
    ) {
    }

    /** @return string the hook id */
    public function save(RepositoryHookDraft $draft, Context $context): string
    {
        if (!$this->facilityGuard->isFacilityAvailable()) {
            throw new HookConfigurationException(
                HookConfigurationException::FACILITY_UNAVAILABLE,
                'MuckiFacilityPlugin is not active.'
            );
        }
        $this->legacyGuard->assertNoConflict($draft->facilityRepositoryId, $context);
        $this->assertTargetIsUsable($draft);

        $existingId = $this->connection->fetchOne(
            'SELECT LOWER(HEX(`id`)) FROM `muwa_agent_repository_hook` '
            . 'WHERE `facility_repository_id` = UNHEX(:repositoryId) AND `phase` = :phase',
            ['repositoryId' => $draft->facilityRepositoryId, 'phase' => $draft->phase->value]
        );
        $payload = [
            'agentHostId' => $draft->agentHostId,
            'agentActionId' => $draft->agentActionId,
            'parameters' => '{}',
            'timeoutSeconds' => $draft->timeoutSeconds,
            'failOnError' => $draft->failOnError,
            'enabled' => $draft->enabled,
        ];
        if (is_string($existingId)) {
            $payload['id'] = $existingId;
            $context->scope(Context::SYSTEM_SCOPE, fn (Context $system) => $this->hookRepository->update([$payload], $system));

            return $existingId;
        }

        $payload['id'] = Uuid::randomHex();
        $payload['facilityRepositoryId'] = $draft->facilityRepositoryId;
        $payload['phase'] = $draft->phase->value;
        $context->scope(Context::SYSTEM_SCOPE, fn (Context $system) => $this->hookRepository->create([$payload], $system));

        return $payload['id'];
    }

    public function delete(string $hookId, Context $context): void
    {
        if (preg_match('/^[0-9a-f]{32}$/D', $hookId) !== 1 || $this->connection->fetchOne(
            'SELECT 1 FROM `muwa_agent_repository_hook` WHERE `id` = UNHEX(:id)',
            ['id' => $hookId]
        ) === false) {
            throw new HookConfigurationException(HookConfigurationException::HOOK_NOT_FOUND, 'The hook does not exist.');
        }
        $context->scope(
            Context::SYSTEM_SCOPE,
            fn (Context $system) => $this->hookRepository->delete([['id' => $hookId]], $system)
        );
    }

    private function assertTargetIsUsable(RepositoryHookDraft $draft): void
    {
        $host = $this->connection->fetchAssociative(
            'SELECT `capabilities` FROM `muwa_agent_host` WHERE `id` = UNHEX(:id) AND `enabled` = 1 AND `revoked_at` IS NULL',
            ['id' => $draft->agentHostId]
        );
        if ($host === false) {
            throw new HookConfigurationException(HookConfigurationException::AGENT_UNAVAILABLE, 'The target agent is unavailable.');
        }
        $action = $this->connection->fetchAssociative(
            'SELECT `required_capabilities`, `max_timeout_seconds` FROM `muwa_agent_action` WHERE `id` = UNHEX(:id) AND `enabled` = 1',
            ['id' => $draft->agentActionId]
        );
        if ($action === false) {
            throw new HookConfigurationException(HookConfigurationException::ACTION_UNAVAILABLE, 'The action is unavailable.');
        }
        if (array_diff($this->stringList($action['required_capabilities']), $this->stringList($host['capabilities'])) !== []) {
            throw new HookConfigurationException(
                HookConfigurationException::CAPABILITY_MISSING,
                'The target agent lacks a capability the action requires.'
            );
        }
        if ($draft->timeoutSeconds > (int) $action['max_timeout_seconds']) {
            throw new HookConfigurationException(
                HookConfigurationException::TIMEOUT_OUT_OF_RANGE,
                'The hook budget exceeds the action maximum.'
            );
        }
    }

    /**
     * Stored capability lists that are not a JSON list of strings fail
     * closed: as host capabilities they grant nothing, as requirements they
     * can never be satisfied.
     *
     * @return list<string>
     */
    private function stringList(mixed $json): array
    {
        try {
            $values = json_decode((string) $json, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new HookConfigurationException(
                HookConfigurationException::CAPABILITY_MISSING,
                'Stored capabilities are invalid.',
                [],
                $exception
            );
        }
        if (!is_array($values) || !array_is_list($values) || array_filter($values, 'is_string') !== $values) {
            throw new HookConfigurationException(HookConfigurationException::CAPABILITY_MISSING, 'Stored capabilities are invalid.');
        }

        return $values;
    }
}
