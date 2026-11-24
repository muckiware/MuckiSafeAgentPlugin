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
namespace MuckiSafeAgentPlugin\tests\Unit\Service\Hook;

use MuckiSafeAgentPlugin\Core\Job\JobPhase;
use MuckiSafeAgentPlugin\Service\Hook\HookConfigurationException;
use MuckiSafeAgentPlugin\Service\Hook\RepositoryHookDraft;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Task 15 RED: strict decoding of a hook configuration request. Phase
 * budgets follow implementation plan 2.11 (pre/post default 600 s, maximum
 * 1800 s; finally default 180 s, maximum 600 s); V1 parameters must be an
 * empty object; unknown fields are rejected.
 */
final class RepositoryHookDraftTest extends TestCase
{
    private const REPOSITORY = '0190b0a87e2c7d4e9a513c6f2b1d8e47';
    private const HOST = '1190b0a87e2c7d4e9a513c6f2b1d8e47';
    private const ACTION = '2190b0a87e2c7d4e9a513c6f2b1d8e47';

    public function testCompletePayloadIsDecoded(): void
    {
        $draft = RepositoryHookDraft::fromPayload($this->payload([
            'timeoutSeconds' => 900,
            'failOnError' => false,
            'enabled' => false,
        ]));

        self::assertSame(self::REPOSITORY, $draft->facilityRepositoryId);
        self::assertSame(JobPhase::Post, $draft->phase);
        self::assertSame(self::HOST, $draft->agentHostId);
        self::assertSame(self::ACTION, $draft->agentActionId);
        self::assertSame(900, $draft->timeoutSeconds);
        self::assertFalse($draft->failOnError);
        self::assertFalse($draft->enabled);
    }

    /** @return iterable<string, array{string, int}> */
    public static function phaseDefaults(): iterable
    {
        yield 'pre' => ['pre', 600];
        yield 'post' => ['post', 600];
        yield 'finally' => ['finally', 180];
    }

    #[DataProvider('phaseDefaults')]
    public function testOmittedOptionalFieldsUsePhaseDefaults(string $phase, int $budget): void
    {
        $payload = $this->payload(['phase' => $phase]);
        unset($payload['timeoutSeconds'], $payload['failOnError'], $payload['enabled'], $payload['parameters']);

        $draft = RepositoryHookDraft::fromPayload($payload);

        self::assertSame($budget, $draft->timeoutSeconds);
        self::assertTrue($draft->failOnError);
        self::assertTrue($draft->enabled);
    }

    /** @return iterable<string, array{string, int}> */
    public static function acceptedBudgetBoundaries(): iterable
    {
        yield 'pre minimum' => ['pre', 45];
        yield 'pre maximum' => ['pre', 1800];
        yield 'post maximum' => ['post', 1800];
        yield 'finally maximum' => ['finally', 600];
    }

    #[DataProvider('acceptedBudgetBoundaries')]
    public function testBudgetBoundariesAreInclusive(string $phase, int $budget): void
    {
        self::assertSame(
            $budget,
            RepositoryHookDraft::fromPayload($this->payload(['phase' => $phase, 'timeoutSeconds' => $budget]))->timeoutSeconds
        );
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidPayloads(): iterable
    {
        yield 'below minimum budget' => [['timeoutSeconds' => 44], 'timeout_out_of_range'];
        yield 'above pre maximum' => [['phase' => 'pre', 'timeoutSeconds' => 1801], 'timeout_out_of_range'];
        yield 'above finally maximum' => [['phase' => 'finally', 'timeoutSeconds' => 601], 'timeout_out_of_range'];
        yield 'string budget' => [['timeoutSeconds' => '600'], 'invalid_request'];
        yield 'unknown phase' => [['phase' => 'during'], 'invalid_request'];
        yield 'uppercase phase' => [['phase' => 'PRE'], 'invalid_request'];
        yield 'uppercase repository id' => [['facilityRepositoryId' => strtoupper(self::REPOSITORY)], 'invalid_request'];
        yield 'dashed host id' => [['agentHostId' => '1190b0a8-7e2c-7d4e-9a51-3c6f2b1d8e47'], 'invalid_request'];
        yield 'missing action' => [['agentActionId' => null], 'invalid_request'];
        yield 'string boolean' => [['enabled' => 'true'], 'invalid_request'];
        yield 'non-empty parameters' => [['parameters' => ['path' => '/etc']], 'invalid_request'];
        yield 'list parameters' => [['parameters' => [1]], 'invalid_request'];
        yield 'unknown field' => [['id' => self::REPOSITORY], 'invalid_request'];
    }

    /** @param array<string, mixed> $overrides */
    #[DataProvider('invalidPayloads')]
    public function testInvalidPayloadIsRejected(array $overrides, string $errorCode): void
    {
        $payload = $this->payload($overrides);
        foreach ($overrides as $key => $value) {
            if ($value === null) {
                unset($payload[$key]);
            }
        }

        try {
            RepositoryHookDraft::fromPayload($payload);
            self::fail('Invalid payload must be rejected.');
        } catch (HookConfigurationException $exception) {
            self::assertSame($errorCode, $exception->errorCode);
        }
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'facilityRepositoryId' => self::REPOSITORY,
            'phase' => 'post',
            'agentHostId' => self::HOST,
            'agentActionId' => self::ACTION,
            'timeoutSeconds' => 600,
            'failOnError' => true,
            'enabled' => true,
            'parameters' => [],
        ], $overrides);
    }
}
