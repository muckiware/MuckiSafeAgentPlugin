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

use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Stable error DTO of the agent routes:
 * {"code":"MUWA_AGENT_*","message":"...","traceId":"<uuid>"}. Used for every
 * authenticated rejection and for kernel exceptions; the response signer
 * signs these responses like any other.
 */
final class AgentErrorResponses
{
    private const MESSAGES = [
        AgentErrorCode::RequestInvalid->value => 'Request does not match the MUWA-HMAC-V1 wire contract.',
        AgentErrorCode::PayloadInvalid->value => 'Request payload is invalid.',
        AgentErrorCode::ProtocolUnsupported->value => 'Protocol value is not supported.',
        AgentErrorCode::SignatureInvalid->value => 'Request signature is invalid.',
        AgentErrorCode::TimestampOutOfWindow->value => 'Request timestamp is outside the allowed window.',
        AgentErrorCode::ReplayDetected->value => 'Request nonce was already used.',
        AgentErrorCode::Revoked->value => 'Agent key is revoked or disabled.',
        AgentErrorCode::MethodNotAllowed->value => 'Only POST is allowed on agent routes.',
        AgentErrorCode::ClaimConflict->value => 'Claim conflict.',
        AgentErrorCode::BodyTooLarge->value => 'Request body exceeds the route limit.',
        AgentErrorCode::UnsupportedMediaType->value => 'Unsupported media type.',
        AgentErrorCode::InternalError->value => 'Internal server error.',
        AgentErrorCode::Unavailable->value => 'Service unavailable.',
    ];

    public static function forCode(AgentErrorCode $code): JsonResponse
    {
        return new JsonResponse([
            'code' => $code->value,
            'message' => self::MESSAGES[$code->value],
            'traceId' => self::wireUuid(Uuid::randomHex()),
        ], $code->httpStatus());
    }

    private static function wireUuid(string $hex): string
    {
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
    }

    /**
     * Non-instantiable: the DTO factory is intentionally static and the
     * message map is the single normative source.
     */
    private function __construct()
    {
    }
}
