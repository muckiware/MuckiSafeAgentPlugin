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

namespace MuckiSafeAgentPlugin\Service\Auth;

use Symfony\Component\HttpFoundation\Request;

interface AgentAuthenticatorInterface
{
    /**
     * Validates a request against the MUWA-HMAC-V1 wire contract and returns
     * the resolved agent context. $serverNow is injectable for deterministic
     * timestamp window tests; production calls omit it.
     *
     * @throws AgentAuthException when the request must not reach a controller
     */
    public function authenticate(Request $request, ?int $serverNow = null): AuthenticatedRequest;
}
