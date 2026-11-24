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
namespace MuckiSafeAgentPlugin\Core\Job;

/**
 * Versioned V1 result-code allowlist of job completions. The wire schema only
 * pins the ^[a-z][a-z0-9_]{0,63}$ shape; the server accepts exactly these
 * codes. The shared protocol fixtures (tests/Fixtures/protocol/dtos-v1.json
 * and hmac-v1.json) use these and only these values, so adding or renaming a
 * case is a protocol-version decision pinned by the shared fixtures, not a
 * free server-side change.
 */
enum ResultCode: string
{
    case Ok = 'ok';
    case PartialFailure = 'partial_failure';
    case StepFailed = 'step_failed';
    case Deadline = 'deadline';
    case JournalStarted = 'journal_started';
}
