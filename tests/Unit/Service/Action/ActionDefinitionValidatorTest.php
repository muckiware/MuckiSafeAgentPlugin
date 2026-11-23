<?php declare(strict_types=1);
/**
 * MuckiSafeAgentPlugin
 * @category SW6 Plugin
 * @package MuckiSafeAgent
 * @copyright Copyright (c) 2026 by Muckiware
 * @license MIT
 * @author Muckiware
 */

namespace MuckiSafeAgentPlugin\tests\Unit\Service\Action;

use MuckiSafeAgentPlugin\Service\Action\ActionDefinitionException;
use MuckiSafeAgentPlugin\Service\Action\ActionDefinitionDecoder;
use MuckiSafeAgentPlugin\Service\Action\ActionDefinitionValidator;
use PHPUnit\Framework\TestCase;

final class ActionDefinitionValidatorTest extends TestCase
{
    public function testSharedFixturesHaveExpectedVerdictCanonicalJsonAndHash(): void
    {
        $fixture = json_decode((string) file_get_contents(__DIR__ . '/../../../Fixtures/action-dsl-v1.json'), false, 512, JSON_THROW_ON_ERROR);
        $validator = new ActionDefinitionValidator();
        foreach ($fixture->cases as $case) {
            try {
                $canonical = $validator->canonicalize($case->definition);
                self::assertTrue($case->valid, $case->name);
                self::assertSame($case->canonical, $canonical, $case->name);
                self::assertSame($case->sha256, $validator->hash($case->definition), $case->name);
            } catch (ActionDefinitionException $exception) {
                self::assertFalse($case->valid, $case->name . ': ' . $exception->getMessage());
            }
        }
    }

    public function testRawFixturesAreStrictlyDecodedBeforeObjectConversion(): void
    {
        $fixture = json_decode((string) file_get_contents(__DIR__ . '/../../../Fixtures/action-dsl-v1.json'), false, 512, JSON_THROW_ON_ERROR);
        $decoder = new ActionDefinitionDecoder();
        foreach ($fixture->rawCases as $case) {
            try {
                $input = str_repeat(' ', $case->paddingBytes ?? 0) . $case->input;
                $decoder->decode($input);
                self::assertTrue($case->valid, $case->name);
            } catch (ActionDefinitionException $exception) {
                self::assertFalse($case->valid, $case->name . ': ' . $exception->getMessage());
            }
        }
    }
}
