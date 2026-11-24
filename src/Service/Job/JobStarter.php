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
namespace MuckiSafeAgentPlugin\Service\Job;

use MuckiSafeAgentPlugin\Api\JobLeaseRequest;
use MuckiSafeAgentPlugin\Api\JobStartResponse;

final class JobStarter
{
    public function __construct(private readonly JobTransitionGuard $guard)
    {
    }

    public function start(
        string $authenticatedHostId,
        string $jobId,
        #[\SensitiveParameter] JobLeaseRequest $request
    ): JobStartResponse
    {
        return $this->guard->start(
            $authenticatedHostId,
            $jobId,
            $request->claimToken,
            $request->leaseVersion
        );
    }
}
