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
namespace MuckiSafeAgentPlugin\tests\Unit\Service\Job;

use MuckiSafeAgentPlugin\Service\Job\JobClaimer;
use PHPUnit\Framework\TestCase;

final class JobClaimerGuardTest extends TestCase
{
    public function testOnlyExactActiveAgentDuplicateKeyIsNormalized(): void
    {
        $claimer = (new \ReflectionClass(JobClaimer::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(JobClaimer::class, 'isActiveAgentGuardViolation');

        self::assertTrue($method->invoke(
            $claimer,
            "An exception occurred while executing a query: SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'abc' for key 'uniq_active_agent'"
        ));
        self::assertTrue($method->invoke(
            $claimer,
            "An exception occurred while executing a query: SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'binary'value' for key 'muwa_agent_job.uniq_active_agent'"
        ));
        self::assertFalse($method->invoke(
            $claimer,
            "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'uniq_active_agent' for key 'uniq_agent_job_idempotency'"
        ));
        self::assertFalse($method->invoke(
            $claimer,
            "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'abc' for key 'uniq_active_agent_suffix'"
        ));
        self::assertFalse($method->invoke(
            $claimer,
            "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'abc' for key 'uniq_active_agent' while uniq_active_agent appeared elsewhere"
        ));
    }
}
