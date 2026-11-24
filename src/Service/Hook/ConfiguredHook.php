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

use MuckiSafeAgentPlugin\Core\Job\JobPhase;

/** One enabled repository hook as read at backup time. */
final readonly class ConfiguredHook
{
    public function __construct(
        public string $facilityRepositoryId,
        public JobPhase $phase,
        public string $agentHostId,
        public string $agentActionId,
        public int $timeoutSeconds,
        public bool $failOnError
    ) {
    }

    /**
     * The phase budget is enforced server-side again at run time: a stored
     * value outside the phase limits (e.g. a direct database change) can
     * never raise the time spent under Facility's backup lock.
     */
    public function budgetSeconds(): int
    {
        return max(JobPhase::MIN_BUDGET_SECONDS, min($this->timeoutSeconds, $this->phase->maxBudgetSeconds()));
    }
}
