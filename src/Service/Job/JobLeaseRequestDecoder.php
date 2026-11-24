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

use MuckiSafeAgentPlugin\Api\JobLeaseRequest;
use MuckiSafeAgentPlugin\Service\Auth\AgentErrorCode;
use MuckiSafeAgentPlugin\Service\Protocol\ProtocolSchemaValidator;
use MuckiSafeAgentPlugin\Service\Protocol\StrictJsonObjectDecoder;

/** Shared strict raw-body decoder for start and lease-heartbeat requests. */
final class JobLeaseRequestDecoder
{
    private const MAX_BYTES = 4096;

    /** @var array<string, \stdClass> */
    private array $schemas = [];

    public function __construct(
        private readonly StrictJsonObjectDecoder $jsonDecoder,
        private readonly ProtocolSchemaValidator $schemaValidator
    ) {
        foreach (['start' => 'job-start-request', 'heartbeat' => 'job-heartbeat-request'] as $operation => $name) {
            $path = dirname(__DIR__, 3) . '/resources/schema/protocol/v1/' . $name . '.schema.json';
            try {
                $schema = json_decode((string) file_get_contents($path), false, 32, JSON_THROW_ON_ERROR);
            } catch (\JsonException $exception) {
                throw new \LogicException('Job lease request schema is invalid.', 0, $exception);
            }
            if (!$schema instanceof \stdClass) {
                throw new \LogicException('Job lease request schema is not an object.');
            }
            $this->schemas[$operation] = $schema;
        }
    }

    public function decodeStart(#[\SensitiveParameter] string $raw): JobLeaseRequest
    {
        return $this->decode($raw, 'start');
    }

    public function decodeHeartbeat(#[\SensitiveParameter] string $raw): JobLeaseRequest
    {
        return $this->decode($raw, 'heartbeat');
    }

    private function decode(#[\SensitiveParameter] string $raw, string $operation): JobLeaseRequest
    {
        try {
            $payload = $this->jsonDecoder->decode($raw, self::MAX_BYTES);
        } catch (\InvalidArgumentException) {
            throw new JobLeasePayloadException(AgentErrorCode::PayloadInvalid);
        }
        if ($this->schemaValidator->firstViolation($this->schemas[$operation], $payload) !== null
            || !isset($payload->claimToken, $payload->leaseVersion)
            || !is_string($payload->claimToken)
            || !is_int($payload->leaseVersion)
        ) {
            throw new JobLeasePayloadException(AgentErrorCode::PayloadInvalid);
        }
        return new JobLeaseRequest($payload->claimToken, $payload->leaseVersion);
    }
}
