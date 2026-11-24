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

use MuckiSafeAgentPlugin\Api\JobHeartbeatResponse;
use MuckiSafeAgentPlugin\Api\JobLeaseRequest;

final class JobHeartbeat
{
    public function __construct(private readonly JobTransitionGuard $guard)
    {
    }

    public function heartbeat(
        string $authenticatedHostId,
        string $jobId,
        #[\SensitiveParameter] JobLeaseRequest $request
    ): JobHeartbeatResponse
    {
        return $this->guard->heartbeat(
            $authenticatedHostId,
            $jobId,
            $request->claimToken,
            $request->leaseVersion
        );
    }
}
