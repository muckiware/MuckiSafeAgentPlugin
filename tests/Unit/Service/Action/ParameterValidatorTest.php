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
use MuckiSafeAgentPlugin\Service\Action\ParameterValidator;
use PHPUnit\Framework\TestCase;

final class ParameterValidatorTest extends TestCase
{
    public function testV1RejectsEveryUntypedParameter(): void
    {
        $fixture = json_decode((string) file_get_contents(__DIR__ . '/../../../Fixtures/action-parameters-v1.json'), false, 512, JSON_THROW_ON_ERROR);
        $validator = new ParameterValidator();
        foreach ($fixture->cases as $case) {
            try {
                if (!$case->parameters instanceof \stdClass) {
                    throw new ActionDefinitionException('Parameters must be an object.');
                }
                $validator->validate($case->parameters);
                self::assertTrue($case->valid, $case->name);
            } catch (ActionDefinitionException $exception) {
                self::assertFalse($case->valid, $case->name . ': ' . $exception->getMessage());
            }
        }
    }
}
