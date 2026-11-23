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
namespace MuckiSafeAgentPlugin\tests\Unit\Service\Heartbeat;

use MuckiSafeAgentPlugin\Service\Heartbeat\HostHeartbeatRequestDecoder;
use MuckiSafeAgentPlugin\Service\Heartbeat\HostHeartbeatPayloadException;
use MuckiSafeAgentPlugin\Service\Auth\AgentErrorCode;
use MuckiSafeAgentPlugin\Service\Protocol\ProtocolSchemaValidator;
use MuckiSafeAgentPlugin\Service\Protocol\StrictJsonObjectDecoder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HostHeartbeatRequestDecoderTest extends TestCase
{
    public function testDecodesSchemaValidPayloadIndependentOfPropertyOrder(): void
    {
        $decoded = $this->decoder()->decode(
            '{"platform":"linux","capabilities":["docker.exec","service.control"],"agentTime":"2026-11-23T10:15:30Z","arch":"amd64","agentVersion":"1.2.3-rc.1"}'
        );

        self::assertSame('1.2.3-rc.1', $decoded->agentVersion);
        self::assertSame(['docker.exec', 'service.control'], $decoded->capabilities);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidPayloads(): iterable
    {
        yield 'malformed JSON' => ['{'];
        yield 'array root' => ['[]'];
        yield 'missing field' => ['{"agentVersion":"1.0.0","platform":"linux","arch":"amd64","capabilities":[]}'];
        yield 'unknown field' => ['{"agentVersion":"1.0.0","platform":"linux","arch":"amd64","capabilities":[],"agentTime":"2026-11-23T10:15:30Z","extra":true}'];
        yield 'bad version' => ['{"agentVersion":"v1","platform":"linux","arch":"amd64","capabilities":[],"agentTime":"2026-11-23T10:15:30Z"}'];
        yield 'duplicate capability' => ['{"agentVersion":"1.0.0","platform":"linux","arch":"amd64","capabilities":["docker.exec","docker.exec"],"agentTime":"2026-11-23T10:15:30Z"}'];
        yield 'duplicate object member' => ['{"agentVersion":"1.0.0","agentVersion":"2.0.0","platform":"linux","arch":"amd64","capabilities":[],"agentTime":"2026-11-23T10:15:30Z"}'];
    }

    #[DataProvider('invalidPayloads')]
    public function testRejectsPayloadOutsideHeartbeatSchema(string $payload): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->decoder()->decode($payload);
    }

    public function testUnsupportedEnumHasNormativeErrorCode(): void
    {
        try {
            $this->decoder()->decode('{"agentVersion":"1.0.0","platform":"darwin","arch":"amd64","capabilities":[],"agentTime":"2026-11-23T10:15:30Z"}');
            self::fail('Unsupported platform was accepted.');
        } catch (HostHeartbeatPayloadException $exception) {
            self::assertSame(AgentErrorCode::ProtocolUnsupported, $exception->errorCode);
        }
    }

    private function decoder(): HostHeartbeatRequestDecoder
    {
        return new HostHeartbeatRequestDecoder(new StrictJsonObjectDecoder(), new ProtocolSchemaValidator());
    }
}
