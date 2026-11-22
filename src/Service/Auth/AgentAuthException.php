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
 * Rejection of an agent request. errorCode === null marks the only case the
 * wire contract allows to answer unsigned: no secret could be resolved from
 * X-Mucki-Agent-Key, so a stable error DTO could not be signed either. Every
 * other failure carries the resolved agent context (secret, path, nonce) so
 * the subscribers can sign the error response.
 */
final class AgentAuthException extends \RuntimeException
{
    public function __construct(
        public readonly ?AgentErrorCode $errorCode,
        string $message,
        #[\SensitiveParameter] public readonly ?AuthenticatedRequest $agent = null
    ) {
        parent::__construct($message);
    }

    /**
     * Unknown, missing, malformed or unresolvable key: the terminal
     * unauthenticated case with no signable context.
     */
    public static function unknownKey(): self
    {
        return new self(null, 'No agent secret can be resolved for this key.');
    }
}
