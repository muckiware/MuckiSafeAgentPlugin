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

enum JobPhase: string
{
    case Pre = 'pre';
    case Post = 'post';
    case Finally = 'finally';

    /** Minimum phase budget: one full 30 second lease plus the 15 second stop/completion reserve. */
    public const MIN_BUDGET_SECONDS = 45;

    /** Server-side default phase budget (implementation plan 2.11). */
    public function defaultBudgetSeconds(): int
    {
        return $this === self::Finally ? 180 : 600;
    }

    /** Server-side maximum phase budget; client values can never raise it (implementation plan 2.11). */
    public function maxBudgetSeconds(): int
    {
        return $this === self::Finally ? 600 : 1800;
    }
}
