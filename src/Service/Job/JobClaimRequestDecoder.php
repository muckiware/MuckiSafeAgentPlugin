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
namespace MuckiSafeAgentPlugin\Service\Job;

use MuckiSafeAgentPlugin\Api\JobClaimRequest;
use MuckiSafeAgentPlugin\Service\Auth\AgentErrorCode;
use MuckiSafeAgentPlugin\Service\Protocol\ProtocolSchemaValidator;
use MuckiSafeAgentPlugin\Service\Protocol\StrictJsonObjectDecoder;

final class JobClaimRequestDecoder
{
    private const MAX_BYTES = 1024;
    private readonly \stdClass $schema;

    public function __construct(
        private readonly StrictJsonObjectDecoder $jsonDecoder,
        private readonly ProtocolSchemaValidator $schemaValidator
    ) {
        try {
            $schema = json_decode((string) file_get_contents(
                dirname(__DIR__, 3) . '/resources/schema/protocol/v1/claim-request.schema.json'
            ), false, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \LogicException('Claim request schema is invalid.', 0, $exception);
        }
        if (!$schema instanceof \stdClass) {
            throw new \LogicException('Claim request schema is not an object.');
        }
        $this->schema = $schema;
    }

    public function decode(string $raw): JobClaimRequest
    {
        try {
            $payload = $this->jsonDecoder->decode($raw, self::MAX_BYTES);
        } catch (\InvalidArgumentException) {
            throw new JobClaimPayloadException(AgentErrorCode::PayloadInvalid);
        }

        if (property_exists($payload, 'maxJobs') && is_int($payload->maxJobs) && $payload->maxJobs !== 1) {
            throw new JobClaimPayloadException(AgentErrorCode::ProtocolUnsupported);
        }
        if ($this->schemaValidator->firstViolation($this->schema, $payload) !== null
            || !property_exists($payload, 'maxJobs')
            || !is_int($payload->maxJobs)
        ) {
            throw new JobClaimPayloadException(AgentErrorCode::PayloadInvalid);
        }

        return new JobClaimRequest($payload->maxJobs);
    }
}
