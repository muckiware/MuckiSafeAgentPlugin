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

/**
 * Task-10A requires no caller-supplied values. V1 therefore accepts only an
 * empty parameter object; adding a parameter requires a later typed schema.
 */
final class ParameterValidator
{
    public function validate(\stdClass $parameters): void
    {
        if (get_object_vars($parameters) !== []) {
            throw new ActionDefinitionException('V1 action parameters must be an empty object.');
        }
    }
}
