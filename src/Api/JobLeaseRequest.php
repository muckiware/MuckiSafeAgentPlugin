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
namespace MuckiSafeAgentPlugin\Api;

final readonly class JobLeaseRequest
{
    public function __construct(
        #[\SensitiveParameter] public string $claimToken,
        public int $leaseVersion
    ) {
    }
}
