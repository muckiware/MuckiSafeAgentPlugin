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

use MuckiSafeAgentPlugin\Service\Action\ActionDefinitionDecoder;
use MuckiSafeAgentPlugin\Service\Action\ActionDefinitionValidator;
use PHPUnit\Framework\TestCase;

/**
 * Every examples/actions/<service>.json must be the shared fixture case
 * "<service>-pre", every <service>-post.json or <service>-finally.json the
 * case of that name, with identical canonical bytes and hash (Go checks the
 * same).
 */
final class ExampleActionsTest extends TestCase
{
    public function testExampleActionsAreTheSharedReferenceDefinitions(): void
    {
        $fixture = json_decode((string) file_get_contents(__DIR__ . '/../../../Fixtures/action-dsl-v1.json'), false, 512, JSON_THROW_ON_ERROR);
        $cases = [];
        foreach ($fixture->cases as $case) {
            $cases[$case->name] = $case;
        }
        $examples = glob(__DIR__ . '/../../../../examples/actions/*.json') ?: [];
        self::assertNotEmpty($examples);

        $decoder = new ActionDefinitionDecoder();
        $validator = new ActionDefinitionValidator();
        foreach ($examples as $path) {
            $name = self::exampleCase(basename($path, '.json'));
            self::assertArrayHasKey($name, $cases);
            $definition = $decoder->decode((string) file_get_contents($path));
            self::assertTrue($cases[$name]->valid, $name);
            self::assertSame($cases[$name]->canonical, $validator->canonicalize($definition), $name);
            self::assertSame($cases[$name]->sha256, $validator->hash($definition), $name);
        }
    }

    private static function exampleCase(string $base): string
    {
        if (str_ends_with($base, '-post') || str_ends_with($base, '-finally')) {
            return $base;
        }

        return $base . '-pre';
    }
}
