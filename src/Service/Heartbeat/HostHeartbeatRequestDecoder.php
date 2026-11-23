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
namespace MuckiSafeAgentPlugin\Service\Heartbeat;

use MuckiSafeAgentPlugin\Service\Auth\AgentErrorCode;
use MuckiSafeAgentPlugin\Service\Protocol\ProtocolSchemaValidator;
use MuckiSafeAgentPlugin\Service\Protocol\StrictJsonObjectDecoder;

/** Strict decoder backed by the frozen protocol-v1 heartbeat schema. */
final class HostHeartbeatRequestDecoder
{
    private const MAX_BYTES = 16384;

    private readonly \stdClass $schema;

    public function __construct(
        private readonly StrictJsonObjectDecoder $jsonDecoder,
        private readonly ProtocolSchemaValidator $schemaValidator
    ) {
        $path = dirname(__DIR__, 3) . '/resources/schema/protocol/v1/heartbeat-request.schema.json';
        try {
            $schema = json_decode((string) file_get_contents($path), false, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \LogicException('Heartbeat schema is invalid.', 0, $exception);
        }
        if (!$schema instanceof \stdClass) {
            throw new \LogicException('Heartbeat schema is not an object.');
        }
        $this->schema = $schema;
    }

    public function decode(string $raw): HostHeartbeatRequest
    {
        try {
            $payload = $this->jsonDecoder->decode($raw, self::MAX_BYTES);
        } catch (\InvalidArgumentException) {
            throw new HostHeartbeatPayloadException(AgentErrorCode::PayloadInvalid);
        }

        if ($this->isUnsupportedEnum($payload, 'platform') || $this->isUnsupportedEnum($payload, 'arch')) {
            throw new HostHeartbeatPayloadException(AgentErrorCode::ProtocolUnsupported);
        }
        if ($this->schemaValidator->firstViolation($this->schema, $payload) !== null) {
            throw new HostHeartbeatPayloadException(AgentErrorCode::PayloadInvalid);
        }

        if (!is_string($payload->agentVersion)
            || !is_string($payload->platform)
            || !is_string($payload->arch)
            || !is_array($payload->capabilities)
            || !is_string($payload->agentTime)
        ) {
            throw new HostHeartbeatPayloadException(AgentErrorCode::PayloadInvalid);
        }
        $capabilities = [];
        foreach ($payload->capabilities as $capability) {
            if (!is_string($capability)) {
                throw new HostHeartbeatPayloadException(AgentErrorCode::PayloadInvalid);
            }
            $capabilities[] = $capability;
        }

        return new HostHeartbeatRequest(
            $payload->agentVersion,
            $payload->platform,
            $payload->arch,
            $capabilities,
            $payload->agentTime
        );
    }

    private function isUnsupportedEnum(\stdClass $payload, string $field): bool
    {
        if (!property_exists($payload, $field) || !is_string($payload->{$field})) {
            return false;
        }
        $properties = $this->schema->properties ?? null;
        if (!$properties instanceof \stdClass) {
            throw new \LogicException('Heartbeat schema properties are missing.');
        }
        $fieldSchema = $properties->{$field} ?? null;
        if (!$fieldSchema instanceof \stdClass || !isset($fieldSchema->enum) || !is_array($fieldSchema->enum)) {
            throw new \LogicException('Heartbeat enum schema is missing.');
        }

        return !in_array($payload->{$field}, $fieldSchema->enum, true);
    }
}
