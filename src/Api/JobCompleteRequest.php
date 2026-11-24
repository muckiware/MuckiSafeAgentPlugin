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

/**
 * Decoded job completion request. The claim token is the agent-side secret
 * of the current lease fence and is marked sensitive so native trace
 * argument redaction covers it.
 */
final readonly class JobCompleteRequest
{
    public function __construct(
        #[\SensitiveParameter] public string $claimToken,
        public int $leaseVersion,
        public string $outcome,
        public string $resultCode,
        public string $summary,
        public ?string $stdoutTail,
        public ?string $stderrTail,
        public ?string $stdoutEncoding,
        public ?string $stderrEncoding
    ) {
    }
}
