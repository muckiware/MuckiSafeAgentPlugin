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

/**
 * Result of a successful MUWA-HMAC-V1 authentication. Attached to the request
 * as AgentAuthenticationSubscriber::AUTH_ATTRIBUTE so controllers receive the
 * resolved host and the response signer can reuse the secret without a second
 * decryption. Fields may be empty strings for failure contexts built before
 * the full wire contract was validated.
 */
final readonly class AuthenticatedRequest
{
    public function __construct(
        public string $hostId,
        public string $keyId,
        #[\SensitiveParameter] public string $secret,
        public string $routeName,
        public string $path,
        public string $requestTimestamp,
        public string $nonce,
        #[\SensitiveParameter] public string $rawBody = ''
    ) {
    }
}
