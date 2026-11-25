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
namespace MuckiSafeAgentPlugin\Service\Action;

/**
 * Rejected action configuration. The error code is a stable, client-facing
 * identifier; `fields` names the rejected payload fields and `detail` carries
 * the DSL validation message (a JSON path, never secret material).
 */
final class ActionConfigurationException extends \RuntimeException
{
    public const INVALID_REQUEST = 'invalid_request';
    public const INVALID_DEFINITION = 'invalid_definition';
    public const TECHNICAL_NAME_TAKEN = 'technical_name_taken';
    public const ACTION_NOT_FOUND = 'action_not_found';

    /** @param list<string> $fields */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly array $fields = [],
        public readonly ?string $detail = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    /** @param list<string> $fields */
    public static function invalidFields(array $fields): self
    {
        return new self(self::INVALID_REQUEST, 'Invalid action fields: ' . implode(', ', $fields) . '.', $fields);
    }

    public static function invalidDefinition(ActionDefinitionException $exception): self
    {
        return new self(
            self::INVALID_DEFINITION,
            'Invalid action definition: ' . $exception->getMessage(),
            ['definition'],
            $exception->getMessage(),
            $exception
        );
    }
}
