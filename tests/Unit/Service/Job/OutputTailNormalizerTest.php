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
use MuckiSafeAgentPlugin\Core\Job\ResultCode;
use MuckiSafeAgentPlugin\Service\Auth\AgentErrorCode;
use MuckiSafeAgentPlugin\Service\Job\JobCompletePayloadException;
use MuckiSafeAgentPlugin\Service\Job\OutputTailNormalizer;
use MuckiSafeAgentPlugin\tests\Support\ProtocolFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Task 13 RED: server-side enforcement of the normative output limits — the
 * wire schema alone cannot express them. Tails are byte-limited (65536 bytes
 * per stream, also after base64 decoding), summaries are limited to 4096
 * UTF-8 bytes and 1024 characters (VARCHAR(1024) storage contract), result
 * codes come from the versioned allowlist, succeeded and ambiguous carry no
 * tails, and every tail marking must match its content.
 */
final class OutputTailNormalizerTest extends TestCase
{
    private const TOKEN = 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';

    public function testSucceededNormalizesWithoutTailsAndStoresNoTailMembers(): void
    {
        $normalized = $this->normalizer()->normalize($this->request([
            'outcome' => 'succeeded',
            'resultCode' => 'ok',
            'summary' => 'Done.',
        ]));

        self::assertSame('succeeded', $normalized->outcome);
        self::assertSame('ok', $normalized->resultCode);
        self::assertSame('Done.', $normalized->summary);
        self::assertNull($normalized->stdoutTail);
        self::assertNull($normalized->stderrTail);
        self::assertNull($normalized->stdoutEncoding);
        self::assertNull($normalized->stderrEncoding);
    }

    #[DataProvider('terminalOutcomeMatrix')]
    public function testOutcomeMatrixAcceptsEveryAllowlistedCombination(
        string $outcome,
        string $resultCode,
        ?string $stdoutTail,
        ?string $stdoutEncoding,
        ?string $stderrTail,
        ?string $stderrEncoding
    ): void {
        $normalized = $this->normalizer()->normalize($this->request([
            'outcome' => $outcome,
            'resultCode' => $resultCode,
            'summary' => 'matrix summary',
            'stdoutTail' => $stdoutTail,
            'stdoutEncoding' => $stdoutEncoding,
            'stderrTail' => $stderrTail,
            'stderrEncoding' => $stderrEncoding,
        ]));

        self::assertSame($outcome, $normalized->outcome);
        self::assertSame($resultCode, $normalized->resultCode);
        self::assertSame($stdoutTail, $normalized->stdoutTail);
        self::assertSame($stderrTail, $normalized->stderrTail);
        self::assertSame($stdoutEncoding, $normalized->stdoutEncoding);
        self::assertSame($stderrEncoding, $normalized->stderrEncoding);
    }

    /** @return iterable<string, array{string, string, ?string, ?string, ?string, ?string}> */
    public static function terminalOutcomeMatrix(): iterable
    {
        yield 'succeeded ok' => ['succeeded', 'ok', null, null, null, null];
        yield 'failed partial_failure with both tails' => [
            'failed', 'partial_failure', "out\n", 'utf-8', 'AAEC/w==', 'base64',
        ];
        yield 'failed step_failed without tails' => ['failed', 'step_failed', null, null, null, null];
        yield 'timed_out deadline with stdout only' => ['timed_out', 'deadline', 'late', 'utf-8', null, null];
        yield 'ambiguous journal_started without tails' => ['ambiguous', 'journal_started', null, null, null, null];
    }

    #[DataProvider('forbiddenTailCombinations')]
    public function testForbiddenTailCombinationsAreRejected(array $overrides): void
    {
        $this->assertPayloadInvalid($this->request(array_merge([
            'outcome' => 'failed',
            'resultCode' => 'step_failed',
            'summary' => 'x',
        ], $overrides)));
    }

    /** @return iterable<string, array{array<string, string|null>}> */
    public static function forbiddenTailCombinations(): iterable
    {
        yield 'succeeded with stdout tail' => [['outcome' => 'succeeded', 'resultCode' => 'ok', 'stdoutTail' => 'x', 'stdoutEncoding' => 'utf-8']];
        yield 'succeeded with stderr tail' => [['outcome' => 'succeeded', 'resultCode' => 'ok', 'stderrTail' => 'x', 'stderrEncoding' => 'base64']];
        yield 'ambiguous with stdout tail' => [['outcome' => 'ambiguous', 'resultCode' => 'journal_started', 'stdoutTail' => 'x', 'stdoutEncoding' => 'utf-8']];
        yield 'ambiguous with stderr tail' => [['outcome' => 'ambiguous', 'resultCode' => 'journal_started', 'stderrTail' => 'x', 'stderrEncoding' => 'base64']];
        yield 'tail without its encoding' => [['stdoutTail' => 'x']];
        yield 'encoding without its tail' => [['stdoutEncoding' => 'utf-8']];
        yield 'stderr encoding without stderr tail' => [['stderrEncoding' => 'base64']];
    }

    public function testUtf8MarkedTailMustBeValidUtf8(): void
    {
        $this->assertPayloadInvalid($this->request([
            'outcome' => 'failed',
            'resultCode' => 'step_failed',
            'summary' => 'x',
            'stdoutTail' => "\xC3\x28",
            'stdoutEncoding' => 'utf-8',
        ]));
    }

    public function testInvalidBase64TailIsRejected(): void
    {
        $this->assertPayloadInvalid($this->request([
            'outcome' => 'failed',
            'resultCode' => 'step_failed',
            'summary' => 'x',
            'stdoutTail' => 'a=b=c=',
            'stdoutEncoding' => 'base64',
        ]));
    }

    public function testBase64TailDecodedToUtf8IsStoredAsItsText(): void
    {
        $decoded = "out-ä\nnext";
        $normalized = $this->normalizer()->normalize($this->request([
            'outcome' => 'failed',
            'resultCode' => 'step_failed',
            'summary' => 'x',
            'stdoutTail' => base64_encode($decoded),
            'stdoutEncoding' => 'base64',
        ]));

        self::assertSame($decoded, $normalized->stdoutTail);
        self::assertSame('base64', $normalized->stdoutEncoding);
    }

    public function testBinaryBase64TailIsStoredAsItsBase64Text(): void
    {
        $bytes = "\x00\x01\x02\xFF\xFE";
        $encoded = base64_encode($bytes);
        $normalized = $this->normalizer()->normalize($this->request([
            'outcome' => 'failed',
            'resultCode' => 'step_failed',
            'summary' => 'x',
            'stdoutTail' => $encoded,
            'stdoutEncoding' => 'base64',
        ]));

        self::assertSame($encoded, $normalized->stdoutTail);
        self::assertSame('base64', $normalized->stdoutEncoding);
    }

    public function testTailByteLimitIsEnforcedPerStream(): void
    {
        $normalizer = $this->normalizer();
        $atLimit = $normalizer->normalize($this->request([
            'outcome' => 'failed',
            'resultCode' => 'step_failed',
            'summary' => 'x',
            'stdoutTail' => str_repeat('a', 65536),
            'stdoutEncoding' => 'utf-8',
            'stderrTail' => base64_encode(str_repeat('b', 65536)),
            'stderrEncoding' => 'base64',
        ]));
        self::assertSame(str_repeat('a', 65536), $atLimit->stdoutTail);
        self::assertSame(str_repeat('b', 65536), $atLimit->stderrTail);

        $this->assertPayloadInvalid($this->request([
            'outcome' => 'failed',
            'resultCode' => 'step_failed',
            'summary' => 'x',
            'stdoutTail' => str_repeat('a', 65537),
            'stdoutEncoding' => 'utf-8',
        ]));
    }

    public function testBase64OverheadAcceptsMaximalTailsButRejectsOversizedDecodedStreams(): void
    {
        $normalizer = $this->normalizer();
        $exactly65536Bytes = base64_encode(random_bytes(65536));
        self::assertSame(87384, strlen($exactly65536Bytes));
        $normalized = $normalizer->normalize($this->request([
            'outcome' => 'failed',
            'resultCode' => 'step_failed',
            'summary' => 'x',
            'stdoutTail' => $exactly65536Bytes,
            'stdoutEncoding' => 'base64',
        ]));
        self::assertSame($exactly65536Bytes, $normalized->stdoutTail);

        $oversizeDecoded = str_repeat('A', 87384);
        self::assertSame(87384, strlen($oversizeDecoded));
        $this->assertPayloadInvalid($this->request([
            'outcome' => 'failed',
            'resultCode' => 'step_failed',
            'summary' => 'x',
            'stdoutTail' => $oversizeDecoded,
            'stdoutEncoding' => 'base64',
        ]));
    }

    #[DataProvider('forbiddenSummaries')]
    public function testSummaryLimitsAreEnforcedInBytesAndStorageCharacters(string $summary): void
    {
        $this->assertPayloadInvalid($this->request([
            'outcome' => 'failed',
            'resultCode' => 'step_failed',
            'summary' => $summary,
        ]));
    }

    /** @return iterable<string, array{string}> */
    public static function forbiddenSummaries(): iterable
    {
        yield 'summary over the 4096 byte limit at 4096 code points' => [str_repeat('ä', 4096)];
        yield 'summary over the 1024 character storage limit' => [str_repeat('a', 1025)];
        yield 'summary over both limits' => [str_repeat('ä', 2048)];
        yield 'summary is not valid UTF-8' => ["\xC3\x28"];
    }

    public function testSummaryBoundaryAt4096BytesAnd1024CharactersIsAccepted(): void
    {
        $normalizer = $this->normalizer();

        $fourByteChars = str_repeat("\u{1F680}", 1024);
        self::assertSame(4096, strlen($fourByteChars));
        $normalized = $normalizer->normalize($this->request([
            'outcome' => 'failed',
            'resultCode' => 'step_failed',
            'summary' => $fourByteChars,
        ]));
        self::assertSame($fourByteChars, $normalized->summary);

        $empty = $normalizer->normalize($this->request([
            'outcome' => 'ambiguous',
            'resultCode' => 'journal_started',
            'summary' => '',
        ]));
        self::assertSame('', $empty->summary);
    }

    #[DataProvider('resultCodesOutsideTheAllowlist')]
    public function testResultCodesOutsideTheVersionedAllowlistAreRejected(string $resultCode): void
    {
        $this->assertPayloadInvalid($this->request([
            'outcome' => 'succeeded',
            'resultCode' => $resultCode,
            'summary' => 'x',
        ]));
    }

    /** @return iterable<string, array{string}> */
    public static function resultCodesOutsideTheAllowlist(): iterable
    {
        yield 'success' => ['success'];
        yield 'uppercase OK' => ['OK'];
        yield 'partial_failures' => ['partial_failures'];
        yield 'empty code' => [''];
    }

    public function testResultCodeAllowlistCoversExactlyTheSharedProtocolFixtureCodes(): void
    {
        $codes = [];
        $dtos = ProtocolFixtures::load('dtos-v1');
        foreach ($dtos['cases'] as $case) {
            if (($case['schema'] ?? null) === 'job-complete-request'
                && (bool) ($case['valid'] ?? false) === true
                && is_array($case['document'] ?? null)
                && isset($case['document']['resultCode'])
                && is_string($case['document']['resultCode'])
            ) {
                $codes[] = (string) $case['document']['resultCode'];
            }
        }
        $hmac = ProtocolFixtures::load('hmac-v1');
        foreach ($hmac['requests'] ?? [] as $request) {
            $body = json_decode((string) ($request['body'] ?? ''), true, 16);
            if (is_array($body) && isset($body['resultCode']) && is_string($body['resultCode'])) {
                $codes[] = (string) $body['resultCode'];
            }
        }
        $expected = array_column(ResultCode::cases(), 'value');
        sort($expected);
        $codes = array_values(array_unique($codes));
        sort($codes);

        self::assertSame($expected, $codes);
    }

    private function assertPayloadInvalid(JobCompleteRequest $request): void
    {
        try {
            $this->normalizer()->normalize($request);
            self::fail('The completion content must be rejected.');
        } catch (JobCompletePayloadException $exception) {
            self::assertSame(AgentErrorCode::PayloadInvalid, $exception->errorCode);
        }
    }

    /**
     * @param array<string, string|null> $overrides
     */
    private function request(array $overrides): JobCompleteRequest
    {
        $values = array_merge([
            'claimToken' => self::TOKEN,
            'leaseVersion' => 1,
            'outcome' => 'succeeded',
            'resultCode' => 'ok',
            'summary' => 'Done.',
            'stdoutTail' => null,
            'stderrTail' => null,
            'stdoutEncoding' => null,
            'stderrEncoding' => null,
        ], $overrides);

        return new JobCompleteRequest(
            self::TOKEN,
            (int) $values['leaseVersion'],
            (string) $values['outcome'],
            (string) $values['resultCode'],
            (string) $values['summary'],
            $values['stdoutTail'] === null ? null : (string) $values['stdoutTail'],
            $values['stderrTail'] === null ? null : (string) $values['stderrTail'],
            $values['stdoutEncoding'] === null ? null : (string) $values['stdoutEncoding'],
            $values['stderrEncoding'] === null ? null : (string) $values['stderrEncoding']
        );
    }

    private function normalizer(): OutputTailNormalizer
    {
        return new OutputTailNormalizer();
    }
}
