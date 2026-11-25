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
namespace MuckiSafeAgentPlugin\Service\Secret;

/**
 * MUWA_AGENT_ENCRYPTION_KEYRING is missing or invalid. Only operations that
 * need an agent secret key fail; messages never contain key material.
 */
final class EncryptionKeyringException extends \RuntimeException
{
    public static function notConfigured(): self
    {
        return new self('MUWA_AGENT_ENCRYPTION_KEYRING is not configured.');
    }

    public static function invalid(?\Throwable $previous = null): self
    {
        return new self('Invalid encryption keyring.', 0, $previous);
    }
}
