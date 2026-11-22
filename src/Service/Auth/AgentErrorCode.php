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
namespace MuckiSafeAgentPlugin\Service\Auth;

/**
 * Stable error codes of the signed agent error DTO
 * {"code":"MUWA_AGENT_*","message":"...","traceId":"<uuid>"}; pinned by
 * tests/Fixtures/protocol/errors-v1.json and mirrored in agent/internal/api/errors.go.
 */
enum AgentErrorCode: string
{
    case RequestInvalid = 'MUWA_AGENT_REQUEST_INVALID';
    case PayloadInvalid = 'MUWA_AGENT_PAYLOAD_INVALID';
    case ProtocolUnsupported = 'MUWA_AGENT_PROTOCOL_UNSUPPORTED';
    case SignatureInvalid = 'MUWA_AGENT_SIGNATURE_INVALID';
    case TimestampOutOfWindow = 'MUWA_AGENT_TIMESTAMP_OUT_OF_WINDOW';
    case ReplayDetected = 'MUWA_AGENT_REPLAY_DETECTED';
    case Revoked = 'MUWA_AGENT_REVOKED';
    case MethodNotAllowed = 'MUWA_AGENT_METHOD_NOT_ALLOWED';
    case ClaimConflict = 'MUWA_AGENT_CLAIM_CONFLICT';
    case BodyTooLarge = 'MUWA_AGENT_BODY_TOO_LARGE';
    case UnsupportedMediaType = 'MUWA_AGENT_UNSUPPORTED_MEDIA_TYPE';
    case InternalError = 'MUWA_AGENT_INTERNAL_ERROR';
    case Unavailable = 'MUWA_AGENT_UNAVAILABLE';

    public function httpStatus(): int
    {
        return match ($this) {
            self::RequestInvalid, self::PayloadInvalid, self::ProtocolUnsupported => 400,
            self::SignatureInvalid, self::TimestampOutOfWindow, self::ReplayDetected, self::Revoked => 401,
            self::MethodNotAllowed => 405,
            self::ClaimConflict => 409,
            self::BodyTooLarge => 413,
            self::UnsupportedMediaType => 415,
            self::InternalError => 500,
            self::Unavailable => 503,
        };
    }
}
