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

use MuckiSafeAgentPlugin\Service\Auth\AgentErrorCode;
use MuckiSafeAgentPlugin\Service\Job\JobClaimPayloadException;
use MuckiSafeAgentPlugin\Service\Job\JobClaimRequestDecoder;
use MuckiSafeAgentPlugin\Service\Protocol\ProtocolSchemaValidator;
use MuckiSafeAgentPlugin\Service\Protocol\StrictJsonObjectDecoder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class JobClaimRequestDecoderTest extends TestCase
{
    public function testDecodesTheOnlySupportedRequest(): void
    {
        $request = $this->decoder()->decode('{"maxJobs":1}');

        self::assertSame(1, $request->maxJobs);
    }

    #[DataProvider('invalidPayloads')]
    public function testMalformedOrStructurallyInvalidPayloadIsRejected(string $payload): void
    {
        try {
            $this->decoder()->decode($payload);
            self::fail('Payload must be rejected.');
        } catch (JobClaimPayloadException $exception) {
            self::assertSame(AgentErrorCode::PayloadInvalid, $exception->errorCode);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function invalidPayloads(): iterable
    {
        yield 'malformed' => ['{'];
        yield 'unknown member' => ['{"maxJobs":1,"other":true}'];
        yield 'duplicate member' => ['{"maxJobs":1,"maxJobs":1}'];
        yield 'noninteger' => ['{"maxJobs":1.0}'];
        yield 'missing' => ['{}'];
    }

    public function testUnknownIntegerEnumIsProtocolUnsupported(): void
    {
        try {
            $this->decoder()->decode('{"maxJobs":2}');
            self::fail('Unsupported maxJobs must be rejected.');
        } catch (JobClaimPayloadException $exception) {
            self::assertSame(AgentErrorCode::ProtocolUnsupported, $exception->errorCode);
        }
    }

    private function decoder(): JobClaimRequestDecoder
    {
        return new JobClaimRequestDecoder(new StrictJsonObjectDecoder(), new ProtocolSchemaValidator());
    }
}
