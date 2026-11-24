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

use MuckiSafeAgentPlugin\Api\JobCompleteRequest;
use MuckiSafeAgentPlugin\Service\Auth\AgentErrorCode;
use MuckiSafeAgentPlugin\Service\Job\JobCompletePayloadException;
use MuckiSafeAgentPlugin\Service\Job\JobCompleteRequestDecoder;
use MuckiSafeAgentPlugin\Service\Protocol\ProtocolSchemaValidator;
use MuckiSafeAgentPlugin\Service\Protocol\StrictJsonObjectDecoder;
use MuckiSafeAgentPlugin\tests\Support\ProtocolFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Task 13 RED: the completion decoder pins the frozen job-complete-request
 * schema (shared PHP/Go fixtures) plus the duplicate-aware bounded raw-body
 * contract of exactly 262144 bytes.
 */
final class JobCompleteRequestDecoderTest extends TestCase
{
    public function testFrozenValidFixtureVectorsDecodeIntoTheRequestDto(): void
    {
        $vectors = self::fixtureCases(true);
        self::assertCount(4, $vectors);
        foreach ($vectors as $vector) {
            $request = $this->decoder()->decode(self::body($vector));
            $document = $vector['document'];

            self::assertSame($document['claimToken'], $request->claimToken);
            self::assertSame($document['leaseVersion'], $request->leaseVersion);
            self::assertSame($document['outcome'], $request->outcome);
            self::assertSame($document['resultCode'], $request->resultCode);
            self::assertSame($document['summary'], $request->summary);
            self::assertSame($document['stdoutTail'] ?? null, $request->stdoutTail);
            self::assertSame($document['stderrTail'] ?? null, $request->stderrTail);
            self::assertSame($document['stdoutEncoding'] ?? null, $request->stdoutEncoding);
            self::assertSame($document['stderrEncoding'] ?? null, $request->stderrEncoding);
        }
    }

    #[DataProvider('frozenInvalidVectors')]
    public function testFrozenInvalidFixtureVectorsAreRejected(array $vector): void
    {
        $this->assertPayloadInvalid(self::body($vector));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function frozenInvalidVectors(): iterable
    {
        foreach (self::fixtureCases(false) as $index => $vector) {
            yield $index . ' ' . (string) $vector['name'] => [$vector];
        }
    }

    public function testMalformedDuplicateAndBoundedBodiesAreRejected(): void
    {
        $token = str_repeat('A', 43);
        $base = ['claimToken' => $token, 'leaseVersion' => 1, 'outcome' => 'failed', 'resultCode' => 'step_failed', 'summary' => 'x'];

        $this->assertPayloadInvalid('{');
        $this->assertPayloadInvalid('[]');
        $this->assertPayloadInvalid(
            '{"claimToken":"' . $token . '","leaseVersion":1.0,"outcome":"failed","resultCode":"step_failed","summary":"x"}'
        );
        $this->assertPayloadInvalid(json_encode(array_merge($base, ['summary' => null]), JSON_THROW_ON_ERROR));
        $this->assertPayloadInvalid(json_encode(array_merge($base, ['environment' => ['PATH' => '/bin']]), JSON_THROW_ON_ERROR));
        $this->assertPayloadInvalid(json_encode(array_merge($base, ['outcome' => 'expired']), JSON_THROW_ON_ERROR));
        $duplicate = '{"claimToken":"' . $token . '","leaseVersion":1,"leaseVersion":1,'
            . '"outcome":"failed","resultCode":"step_failed","summary":"x"}';
        $this->assertPayloadInvalid($duplicate);

        $valid = json_encode($base, JSON_THROW_ON_ERROR);
        $this->assertPayloadInvalid($valid . '{}');
        $this->assertPayloadInvalid($valid . str_repeat(' ', 262145 - strlen($valid)));
    }

    public function testBodiesUpToTheExactRouteLimitDecode(): void
    {
        $token = str_repeat('A', 43);
        $base = json_encode([
            'claimToken' => $token,
            'leaseVersion' => 1,
            'outcome' => 'succeeded',
            'resultCode' => 'ok',
            'summary' => 'Done.',
        ], JSON_THROW_ON_ERROR);
        $atLimit = $base . str_repeat(' ', 262144 - strlen($base));
        self::assertSame(262144, strlen($atLimit));

        $request = $this->decoder()->decode($atLimit);
        self::assertSame($token, $request->claimToken);
    }

    private function assertPayloadInvalid(string $payload): void
    {
        try {
            $this->decoder()->decode($payload);
            self::fail('The completion payload must be rejected.');
        } catch (JobCompletePayloadException $exception) {
            self::assertSame(AgentErrorCode::PayloadInvalid, $exception->errorCode);
        }
    }

    /** @return list<array<string, mixed>> */
    private static function fixtureCases(bool $valid): array
    {
        $fixture = ProtocolFixtures::load('dtos-v1');
        $cases = [];
        foreach ($fixture['cases'] as $case) {
            if (($case['schema'] ?? null) !== 'job-complete-request') {
                continue;
            }
            if ((bool) $case['valid'] === $valid) {
                $cases[] = $case;
            }
        }

        return $cases;
    }

    /** @param array<string, mixed> $vector */
    private static function body(array $vector): string
    {
        return json_encode($vector['document'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private function decoder(): JobCompleteRequestDecoder
    {
        return new JobCompleteRequestDecoder(new StrictJsonObjectDecoder(), new ProtocolSchemaValidator());
    }
}
