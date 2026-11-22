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
namespace MuckiSafeAgentPlugin\tests\Support;

/**
 * Loads the shared PHP/Go protocol fixtures from tests/Fixtures/protocol and
 * the V1 DTO schemas from resources/schema/protocol/v1. The Go agent reads
 * the very same files in agent/internal/api.
 */
final class ProtocolFixtures
{
    /** @return array<string, mixed> */
    public static function load(string $name): array
    {
        $decoded = json_decode(self::read(self::fixtureDir() . '/' . $name . '.json'), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Fixture ' . $name . ' is not a JSON object.');
        }
        return $decoded;
    }

    /**
     * Objects stay \stdClass so that `{}` and `[]` as well as `1` and `1.0`
     * remain distinguishable for schema validation.
     */
    public static function loadObjects(string $name): \stdClass
    {
        return self::decodeObject(self::read(self::fixtureDir() . '/' . $name . '.json'));
    }

    public static function schema(string $name): \stdClass
    {
        return self::decodeObject(self::read(self::schemaDir() . '/' . $name . '.schema.json'));
    }

    public static function schemaDir(): string
    {
        return dirname(__DIR__, 2) . '/resources/schema/protocol/v1';
    }

    /** @param array<string, mixed> $vector */
    public static function body(array $vector): string
    {
        if (isset($vector['bodyRepeat']) && is_array($vector['bodyRepeat'])) {
            return str_repeat((string) $vector['bodyRepeat']['char'], (int) $vector['bodyRepeat']['count']);
        }
        return (string) $vector['body'];
    }

    /**
     * @param array<string, mixed> $fixture
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function named(array $fixture, string $section): iterable
    {
        foreach ($fixture[$section] as $vector) {
            yield (string) $vector['name'] => [$vector];
        }
    }

    private static function fixtureDir(): string
    {
        return dirname(__DIR__) . '/Fixtures/protocol';
    }

    private static function decodeObject(string $json): \stdClass
    {
        $decoded = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        if (!$decoded instanceof \stdClass) {
            throw new \RuntimeException('Expected a JSON object.');
        }
        return $decoded;
    }

    private static function read(string $path): string
    {
        $content = file_get_contents($path);
        if (!is_string($content)) {
            throw new \RuntimeException('Cannot read ' . $path);
        }
        return $content;
    }
}
