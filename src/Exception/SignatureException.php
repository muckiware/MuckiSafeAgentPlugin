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
namespace MuckiSafeAgentPlugin\Exception;

/**
 * Thrown when an input of the MUWA-HMAC-V1 signature protocol is not
 * canonical. The reason codes are part of the shared PHP/Go fixtures
 * (tests/Fixtures/protocol/hmac-v1.json) and are identical in the Go agent.
 */
final class SignatureException extends \RuntimeException
{
    public const INVALID_KEY = 'invalid_key';
    public const INVALID_KEY_ID = 'invalid_key_id';
    public const INVALID_METHOD = 'invalid_method';
    public const INVALID_PATH = 'invalid_path';
    public const INVALID_BODY_HASH = 'invalid_body_hash';
    public const INVALID_TIMESTAMP = 'invalid_timestamp';
    public const INVALID_NONCE = 'invalid_nonce';
    public const INVALID_STATUS = 'invalid_status';
    public const INVALID_SIGNATURE_FORMAT = 'invalid_signature_format';
    public const MISSING_HEADER = 'missing_header';
    public const DUPLICATE_HEADER = 'duplicate_header';
    public const BODY_TOO_LARGE = 'body_too_large';

    public function __construct(private readonly string $reason)
    {
        parent::__construct('Agent signature input rejected: ' . $reason);
    }

    public function getReason(): string
    {
        return $this->reason;
    }
}
