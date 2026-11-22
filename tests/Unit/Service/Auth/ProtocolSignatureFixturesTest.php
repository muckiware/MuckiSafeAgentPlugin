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
namespace MuckiSafeAgentPlugin\tests\Unit\Service\Auth;

use MuckiSafeAgentPlugin\Exception\SignatureException;
use MuckiSafeAgentPlugin\Service\Auth\AgentErrorCode;
use MuckiSafeAgentPlugin\Service\Auth\AgentProtocol;
use MuckiSafeAgentPlugin\tests\Support\ProtocolFixtures;
use MuckiSafeAgentPlugin\tests\Support\ProtocolSchemaValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Routes, body limits, error codes and DTO schemas of the shared protocol
 * fixtures (the signature vectors themselves live in Request-/ResponseSignatureTest).
 */
final class ProtocolSignatureFixturesTest extends TestCase
{
    /** @return iterable<string, array{array<string, mixed>}> */
    public static function routes(): iterable
    {
        return ProtocolFixtures::named(ProtocolFixtures::load('routes-v1'), 'routes');
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function pathCases(): iterable
    {
        foreach (ProtocolFixtures::load('routes-v1')['pathCases'] as $index => $case) {
            yield $index . ' ' . json_encode($case['path'], JSON_THROW_ON_ERROR) => [$case['path'], $case['route']];
        }
    }

    /** @return iterable<string, array{string, \stdClass}> */
    public static function dtoCases(): iterable
    {
        foreach (ProtocolFixtures::loadObjects('dtos-v1')->cases as $case) {
            yield $case->schema . ': ' . $case->name => [$case->schema, $case];
        }
    }

    /** @param array<string, mixed> $route */
    #[DataProvider('routes')]
    public function testRouteTableMatchesFixture(array $route): void
    {
        $routes = AgentProtocol::routes();

        self::assertArrayHasKey($route['name'], $routes);
        self::assertSame(
            ['method' => $route['method'], 'path' => $route['path'], 'bodyLimitBytes' => $route['bodyLimitBytes']],
            $routes[$route['name']]
        );
        self::assertFileExists(ProtocolFixtures::schemaDir() . '/' . $route['requestSchema'] . '.schema.json');
        self::assertFileExists(ProtocolFixtures::schemaDir() . '/' . $route['responseSchema'] . '.schema.json');
    }

    public function testExactlyTheFixtureRoutesExist(): void
    {
        $fixture = ProtocolFixtures::load('routes-v1');

        self::assertSame(array_column($fixture['routes'], 'name'), array_keys(AgentProtocol::routes()));
        self::assertSame($fixture['prefix'], AgentProtocol::ROUTE_PREFIX);
        self::assertSame($fixture['transportHardCapBytes'], AgentProtocol::TRANSPORT_HARD_CAP_BYTES);
    }

    #[DataProvider('pathCases')]
    public function testPathMatchingIsExactAndCanonical(string $path, ?string $expectedRoute): void
    {
        try {
            $route = AgentProtocol::matchRoute($path);
        } catch (SignatureException $exception) {
            self::assertNull($expectedRoute, 'Canonical path rejected: ' . $exception->getReason());
            self::assertSame(SignatureException::INVALID_PATH, $exception->getReason());
            return;
        }
        self::assertSame($expectedRoute, $route);
    }

    /** @param array<string, mixed> $route */
    #[DataProvider('routes')]
    public function testBodyLimitAcceptsLimitAndRejectsLimitPlusOne(array $route): void
    {
        $limit = $route['bodyLimitBytes'];
        AgentProtocol::assertBodyWithinLimit($route['name'], 0);
        AgentProtocol::assertBodyWithinLimit($route['name'], $limit);
        self::assertSame($limit, AgentProtocol::bodyLimit($route['name']));
        self::assertLessThan(AgentProtocol::TRANSPORT_HARD_CAP_BYTES, $limit);

        try {
            AgentProtocol::assertBodyWithinLimit($route['name'], $limit + 1);
            self::fail('limit+1 must be rejected.');
        } catch (SignatureException $exception) {
            self::assertSame(SignatureException::BODY_TOO_LARGE, $exception->getReason());
        }
    }

    public function testErrorCodesMatchFixture(): void
    {
        $fixture = ProtocolFixtures::load('errors-v1');
        $expected = [];
        foreach ($fixture['codes'] as $code) {
            self::assertTrue($code['signed'], $code['code']);
            $expected[$code['code']] = $code['httpStatus'];
        }
        $actual = [];
        foreach (AgentErrorCode::cases() as $case) {
            $actual[$case->value] = $case->httpStatus();
        }

        self::assertSame($expected, $actual);
        self::assertSame(401, $fixture['unknownKey']['httpStatus']);
        self::assertSame('', $fixture['unknownKey']['body']);
        self::assertFalse($fixture['unknownKey']['signed']);
    }

    public function testSignedErrorBodiesInSignatureVectorsMatchErrorSchema(): void
    {
        $validator = $this->validator();
        $schema = ProtocolFixtures::schema('error-response');
        $checked = 0;
        foreach (ProtocolFixtures::load('hmac-v1')['responses'] as $vector) {
            if ($vector['status'] < 400 || $vector['body'] === '') {
                continue;
            }
            $body = json_decode($vector['body'], false, 512, JSON_THROW_ON_ERROR);
            self::assertNull($validator->firstViolation($schema, $body), $vector['name']);
            self::assertNotNull(AgentErrorCode::tryFrom($body->code), $vector['name']);
            self::assertSame(AgentErrorCode::from($body->code)->httpStatus(), $vector['status'], $vector['name']);
            ++$checked;
        }
        self::assertGreaterThan(0, $checked);
    }

    #[DataProvider('dtoCases')]
    public function testDtoDocumentsMatchSchemaVerdict(string $schemaName, \stdClass $case): void
    {
        $violation = $this->validator()->firstViolation(ProtocolFixtures::schema($schemaName), $case->document);

        self::assertSame($case->valid ? null : $case->violation, $violation);
    }

    public function testEverySchemaHasValidAndInvalidDocuments(): void
    {
        $coverage = [];
        foreach (ProtocolFixtures::loadObjects('dtos-v1')->cases as $case) {
            $coverage[$case->schema][$case->valid ? 'valid' : 'invalid'] = true;
        }
        $schemas = glob(ProtocolFixtures::schemaDir() . '/*.schema.json') ?: [];

        self::assertCount(11, $schemas);
        foreach ($schemas as $file) {
            $name = basename($file, '.schema.json');
            self::assertTrue($coverage[$name]['valid'] ?? false, $name . ' has no valid document');
            self::assertTrue($coverage[$name]['invalid'] ?? false, $name . ' has no invalid document');
        }
    }

    private function validator(): ProtocolSchemaValidator
    {
        /** @var list<string> $keywords */
        $keywords = ProtocolFixtures::loadObjects('dtos-v1')->supportedKeywords;
        return new ProtocolSchemaValidator($keywords);
    }
}
