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

use MuckiSafeAgentPlugin\Api\JobCompleteRequest;
use MuckiSafeAgentPlugin\Core\Job\ResultCode;
use MuckiSafeAgentPlugin\Service\Auth\AgentErrorCode;

/**
 * Enforces the normative completion content rules the wire schema cannot
 * express. The agent redacts and tail-limits before sending; the server
 * independently re-validates the outcome-specific field matrix, the tail
 * marking against its content, the byte limits per decoded stream, the
 * summary storage limits and the versioned result-code allowlist before
 * anything is persisted. Violations fail closed as signed payload errors.
 */
final class OutputTailNormalizer
{
    public const TAIL_LIMIT_BYTES = 65536;
    public const SUMMARY_LIMIT_BYTES = 4096;
    public const SUMMARY_LIMIT_CHARS = 1024;

    public function normalize(#[\SensitiveParameter] JobCompleteRequest $request): NormalizedCompletion
    {
        $resultCode = ResultCode::tryFrom($request->resultCode);
        if ($resultCode === null) {
            throw new JobCompletePayloadException(AgentErrorCode::PayloadInvalid);
        }
        $summary = $this->normalizeSummary($request->summary);

        [$stdoutTail, $stdoutEncoding] = $this->normalizeTail($request->outcome, $request->stdoutTail, $request->stdoutEncoding);
        [$stderrTail, $stderrEncoding] = $this->normalizeTail($request->outcome, $request->stderrTail, $request->stderrEncoding);

        return new NormalizedCompletion(
            $request->outcome,
            $resultCode->value,
            $summary,
            $stdoutTail,
            $stderrTail,
            $stdoutEncoding,
            $stderrEncoding
        );
    }

    private function normalizeSummary(string $summary): string
    {
        if (preg_match('//u', $summary) !== 1
            || strlen($summary) > self::SUMMARY_LIMIT_BYTES
            || mb_strlen($summary, 'UTF-8') > self::SUMMARY_LIMIT_CHARS
        ) {
            throw new JobCompletePayloadException(AgentErrorCode::PayloadInvalid);
        }

        return $summary;
    }

    /** @return array{0: ?string, 1: ?string} */
    private function normalizeTail(string $outcome, ?string $tail, ?string $encoding): array
    {
        if ($outcome === 'succeeded' || $outcome === 'ambiguous') {
            if ($tail !== null || $encoding !== null) {
                throw new JobCompletePayloadException(AgentErrorCode::PayloadInvalid);
            }

            return [null, null];
        }
        if ($tail === null && $encoding !== null) {
            throw new JobCompletePayloadException(AgentErrorCode::PayloadInvalid);
        }
        if ($tail === null) {
            return [null, null];
        }
        if ($encoding === null) {
            throw new JobCompletePayloadException(AgentErrorCode::PayloadInvalid);
        }
        if ($encoding === 'utf-8') {
            if (preg_match('//u', $tail) !== 1 || strlen($tail) > self::TAIL_LIMIT_BYTES) {
                throw new JobCompletePayloadException(AgentErrorCode::PayloadInvalid);
            }

            return [$tail, 'utf-8'];
        }

        $decoded = base64_decode($tail, true);
        if ($decoded === false || strlen($decoded) > self::TAIL_LIMIT_BYTES) {
            throw new JobCompletePayloadException(AgentErrorCode::PayloadInvalid);
        }

        return [preg_match('//u', $decoded) === 1 ? $decoded : $tail, 'base64'];
    }
}
