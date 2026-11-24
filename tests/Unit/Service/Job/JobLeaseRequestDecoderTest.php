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
namespace MuckiSafeAgentPlugin\tests\Unit\Service\Job;

use MuckiSafeAgentPlugin\Api\JobHeartbeatResponse;
use MuckiSafeAgentPlugin\Service\Auth\AgentErrorCode;
use MuckiSafeAgentPlugin\Service\Job\JobLeasePayloadException;
use MuckiSafeAgentPlugin\Service\Job\JobLeaseRequestDecoder;
use MuckiSafeAgentPlugin\Service\Protocol\ProtocolSchemaValidator;
use MuckiSafeAgentPlugin\Service\Protocol\StrictJsonObjectDecoder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class JobLeaseRequestDecoderTest extends TestCase
{
    public function testStartAndHeartbeatUseTheirFrozenSchemas(): void
    {
        $raw = '{"claimToken":"' . str_repeat('A', 43) . '","leaseVersion":7}';
        $decoder = $this->decoder();

        $start = $decoder->decodeStart($raw);
        $heartbeat = $decoder->decodeHeartbeat($raw);

        self::assertSame(str_repeat('A', 43), $start->claimToken);
        self::assertSame(7, $start->leaseVersion);
        self::assertSame($start->claimToken, $heartbeat->claimToken);
        self::assertSame($start->leaseVersion, $heartbeat->leaseVersion);
    }

    #[DataProvider('invalidPayloads')]
    public function testMalformedDuplicateAndSchemaInvalidPayloadsAreRejected(string $payload): void
    {
        foreach (['decodeStart', 'decodeHeartbeat'] as $method) {
            try {
                $this->decoder()->{$method}($payload);
                self::fail($method . ' must reject the payload.');
            } catch (JobLeasePayloadException $exception) {
                self::assertSame(AgentErrorCode::PayloadInvalid, $exception->errorCode);
            }
        }
    }

    /** @return iterable<string, array{string}> */
    public static function invalidPayloads(): iterable
    {
        $token = str_repeat('A', 43);
        yield 'malformed' => ['{'];
        yield 'duplicate member' => ['{"claimToken":"' . $token . '","leaseVersion":7,"leaseVersion":7}'];
        yield 'unknown member' => ['{"claimToken":"' . $token . '","leaseVersion":7,"hostId":"x"}'];
        yield 'short token' => ['{"claimToken":"short","leaseVersion":7}'];
        yield 'float version' => ['{"claimToken":"' . $token . '","leaseVersion":7.0}'];
        yield 'zero version' => ['{"claimToken":"' . $token . '","leaseVersion":0}'];
        yield 'missing version' => ['{"claimToken":"' . $token . '"}'];
    }

    public function testHeartbeatResponseRequiresDeadlineExactlyWhenStopIsRequested(): void
    {
        foreach ([[true, null], [false, '2030-01-02T03:04:05Z']] as [$stopRequested, $stopDeadline]) {
            try {
                new JobHeartbeatResponse(1, '2030-01-02T03:04:00Z', $stopRequested, $stopDeadline);
                self::fail('Inconsistent heartbeat response must be rejected.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }

        $schema = json_decode(
            (string) file_get_contents(dirname(__DIR__, 4) . '/resources/schema/protocol/v1/job-heartbeat-response.schema.json'),
            false,
            32,
            JSON_THROW_ON_ERROR
        );
        self::assertInstanceOf(\stdClass::class, $schema);
        $validator = new ProtocolSchemaValidator();
        foreach ([
            (object) ['leaseVersion' => 1, 'leaseExpiresAt' => '2030-01-02T03:04:00Z', 'stopRequested' => true],
            (object) [
                'leaseVersion' => 1,
                'leaseExpiresAt' => '2030-01-02T03:04:00Z',
                'stopRequested' => false,
                'stopDeadline' => '2030-01-02T03:04:05Z',
            ],
        ] as $payload) {
            self::assertSame('oneOf', $validator->firstViolation($schema, $payload));
        }
    }

    private function decoder(): JobLeaseRequestDecoder
    {
        return new JobLeaseRequestDecoder(new StrictJsonObjectDecoder(), new ProtocolSchemaValidator());
    }
}
