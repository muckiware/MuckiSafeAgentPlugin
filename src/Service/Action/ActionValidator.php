<?php declare(strict_types=1);
/**
 * MuckiSafeAgentPlugin
 * @category SW6 Plugin
 * @package MuckiSafeAgent
 * @copyright Copyright (c) 2026 by Muckiware
 * @license MIT
 * @author Muckiware
 */

namespace MuckiSafeAgentPlugin\Service\Action;

/** Stable Task-10B façade for strict V1 definition validation. */
final class ActionValidator
{
    public function __construct(private readonly ActionDefinitionValidator $definitions = new ActionDefinitionValidator())
    {
    }

    public function validate(\stdClass $definition): void
    {
        $this->definitions->validate($definition);
    }

    public function canonicalize(\stdClass $definition): string
    {
        return $this->definitions->canonicalize($definition);
    }
}
