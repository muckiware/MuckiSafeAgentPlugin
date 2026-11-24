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

/**
 * Server-normalized completion content: the stored representation of a
 * validated completion, ready for persistence and canonical fingerprinting.
 * Tail values are the storage forms (utf-8 text, decoded base64 text when
 * the decoded bytes are valid UTF-8, otherwise the base64 text itself).
 */
final readonly class NormalizedCompletion
{
    public function __construct(
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
