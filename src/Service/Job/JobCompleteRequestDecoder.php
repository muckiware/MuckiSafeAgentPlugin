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

use MuckiSafeAgentPlugin\Api\JobCompleteRequest;
use MuckiSafeAgentPlugin\Service\Auth\AgentErrorCode;
use MuckiSafeAgentPlugin\Service\Protocol\ProtocolSchemaValidator;
use MuckiSafeAgentPlugin\Service\Protocol\StrictJsonObjectDecoder;

/**
 * Strict raw-body decoder for job completion requests: duplicate-aware
 * bounded JSON (exactly the 262144-byte route limit in front of any decode)
 * validated against the frozen v1 job-complete-request schema. Business
 * limits beyond the schema live in OutputTailNormalizer.
 */
final class JobCompleteRequestDecoder
{
    private const MAX_BYTES = 262144;

    private \stdClass $schema;

    public function __construct(
        private readonly StrictJsonObjectDecoder $jsonDecoder,
        private readonly ProtocolSchemaValidator $schemaValidator
    ) {
        $path = dirname(__DIR__, 3) . '/resources/schema/protocol/v1/job-complete-request.schema.json';
        try {
            $schema = json_decode((string) file_get_contents($path), false, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \LogicException('Job completion request schema is invalid.', 0, $exception);
        }
        if (!$schema instanceof \stdClass) {
            throw new \LogicException('Job completion request schema is not an object.');
        }
        $this->schema = $schema;
    }

    public function decode(#[\SensitiveParameter] string $raw): JobCompleteRequest
    {
        try {
            $payload = $this->jsonDecoder->decode($raw, self::MAX_BYTES);
        } catch (\InvalidArgumentException) {
            throw new JobCompletePayloadException(AgentErrorCode::PayloadInvalid);
        }
        if ($this->schemaValidator->firstViolation($this->schema, $payload) !== null
            || !isset($payload->claimToken, $payload->leaseVersion, $payload->outcome, $payload->resultCode, $payload->summary)
            || !is_string($payload->claimToken)
            || !is_int($payload->leaseVersion)
            || !is_string($payload->outcome)
            || !is_string($payload->resultCode)
            || !is_string($payload->summary)
        ) {
            throw new JobCompletePayloadException(AgentErrorCode::PayloadInvalid);
        }

        return new JobCompleteRequest(
            $payload->claimToken,
            $payload->leaseVersion,
            $payload->outcome,
            $payload->resultCode,
            $payload->summary,
            $this->optionalString($payload, 'stdoutTail'),
            $this->optionalString($payload, 'stderrTail'),
            $this->optionalString($payload, 'stdoutEncoding'),
            $this->optionalString($payload, 'stderrEncoding')
        );
    }

    private function optionalString(\stdClass $payload, string $member): ?string
    {
        $value = $payload->{$member} ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new JobCompletePayloadException(AgentErrorCode::PayloadInvalid);
        }

        return $value;
    }
}
