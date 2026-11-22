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
use MuckiSafeAgentPlugin\Service\Auth\RequestSignature;
use MuckiSafeAgentPlugin\Service\Auth\SignatureEnvelope;
use MuckiSafeAgentPlugin\Service\Secret\AgentProvisioner;
use MuckiSafeAgentPlugin\tests\Support\ProtocolFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\HeaderBag;

final class RequestSignatureTest extends TestCase
{
    /** @return iterable<string, array{array<string, mixed>}> */
    public static function validKeys(): iterable
    {
        foreach (ProtocolFixtures::load('hmac-v1')['keys']['valid'] as $vector) {
            yield (string) $vector['name'] => [$vector];
        }
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidKeys(): iterable
    {
        foreach (ProtocolFixtures::load('hmac-v1')['keys']['invalid'] as $vector) {
            yield (string) $vector['name'] => [$vector];
        }
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function requestVectors(): iterable
    {
        return ProtocolFixtures::named(ProtocolFixtures::load('hmac-v1'), 'requests');
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidRequests(): iterable
    {
        return ProtocolFixtures::named(ProtocolFixtures::load('hmac-v1'), 'invalidRequests');
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function headerSets(): iterable
    {
        return ProtocolFixtures::named(ProtocolFixtures::load('hmac-v1'), 'requestHeaders');
    }

    /** @param array<string, mixed> $vector */
    #[DataProvider('validKeys')]
    public function testSecretDecodesToExactlyThirtyTwoBytes(array $vector): void
    {
        self::assertSame($vector['keyHex'], bin2hex(AgentProvisioner::decodeSecret($vector['secret'])));
    }

    /** @param array<string, mixed> $vector */
    #[DataProvider('invalidKeys')]
    public function testInvalidSecretEncodingIsRejected(array $vector): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AgentProvisioner::decodeSecret($vector['secret']);
    }

    /** @param array<string, mixed> $vector */
    #[DataProvider('requestVectors')]
    public function testRequestVectorProducesExactBaseStringAndSignature(array $vector): void
    {
        $body = ProtocolFixtures::body($vector);
        $key = AgentProvisioner::decodeSecret($vector['secret']);
        $envelope = SignatureEnvelope::forBody($vector['path'], $body, $vector['timestamp'], $vector['nonce']);
        $signer = new RequestSignature();

        self::assertSame($vector['bodySha256'], $envelope->bodySha256);
        self::assertSame($vector['baseString'], $signer->baseString($vector['method'], $envelope));
        self::assertStringEndsNotWith("\n", $signer->baseString($vector['method'], $envelope));
        self::assertSame($vector['signature'], $signer->sign($key, $vector['method'], $envelope));
        self::assertTrue($signer->verify($key, $vector['method'], $envelope, $vector['signature']));
    }

    /** @param array<string, mixed> $vector */
    #[DataProvider('requestVectors')]
    public function testDisplayedSecretTextIsNeverTheHmacKey(array $vector): void
    {
        $envelope = SignatureEnvelope::forBody(
            $vector['path'],
            ProtocolFixtures::body($vector),
            $vector['timestamp'],
            $vector['nonce']
        );
        $key = AgentProvisioner::decodeSecret($vector['secret']);

        self::assertNotSame($vector['signature'], $vector['signatureWithSecretTextAsKey']);
        self::assertFalse((new RequestSignature())->verify($key, $vector['method'], $envelope, $vector['signatureWithSecretTextAsKey']));
    }

    /** @param array<string, mixed> $vector */
    #[DataProvider('requestVectors')]
    public function testEveryTamperedFieldFailsVerification(array $vector): void
    {
        $signer = new RequestSignature();
        $key = AgentProvisioner::decodeSecret($vector['secret']);
        $body = ProtocolFixtures::body($vector);
        $otherPath = str_ends_with($vector['path'], '/heartbeat') && !str_contains($vector['path'], '/jobs/')
            ? '/api/_action/muwa/agent/v1/jobs/claim'
            : '/api/_action/muwa/agent/v1/heartbeat';
        $otherNonce = strrev($vector['nonce']) === $vector['nonce'] ? $vector['nonce'] . 'x' : strrev($vector['nonce']);
        $tampered = [
            'body' => SignatureEnvelope::forBody($vector['path'], $body . ' ', $vector['timestamp'], $vector['nonce']),
            'path' => SignatureEnvelope::forBody($otherPath, $body, $vector['timestamp'], $vector['nonce']),
            'timestamp' => SignatureEnvelope::forBody($vector['path'], $body, (string) ((int) $vector['timestamp'] + 1), $vector['nonce']),
            'nonce' => SignatureEnvelope::forBody($vector['path'], $body, $vector['timestamp'], $otherNonce),
        ];
        foreach ($tampered as $field => $envelope) {
            self::assertFalse($signer->verify($key, $vector['method'], $envelope, $vector['signature']), $field);
        }

        $original = SignatureEnvelope::forBody($vector['path'], $body, $vector['timestamp'], $vector['nonce']);
        $flipped = substr($vector['signature'], 0, -1) . ($vector['signature'][63] === '0' ? '1' : '0');
        self::assertFalse($signer->verify($key, $vector['method'], $original, $flipped));
        self::assertFalse($signer->verify($key, $vector['method'], $original, strtoupper($vector['signature'])));
        self::assertFalse($signer->verify(random_bytes(32), $vector['method'], $original, $vector['signature']));
    }

    /** @param array<string, mixed> $vector */
    #[DataProvider('invalidRequests')]
    public function testInvalidRequestInputIsRejectedWithStableReason(array $vector): void
    {
        try {
            $envelope = new SignatureEnvelope($vector['path'], $vector['bodySha256'], $vector['timestamp'], $vector['nonce']);
            (new RequestSignature())->sign((string) hex2bin($vector['keyHex']), $vector['method'], $envelope);
            self::fail('Invalid input must be rejected.');
        } catch (SignatureException $exception) {
            self::assertSame($vector['error'], $exception->getReason());
        }
    }

    /** @param array<string, mixed> $vector */
    #[DataProvider('headerSets')]
    public function testSecurityHeadersMustOccurExactlyOnceAndBeCanonical(array $vector): void
    {
        $headers = new HeaderBag($vector['headers']);
        try {
            $parsed = (new RequestSignature())->parseHeaders($headers);
        } catch (SignatureException $exception) {
            self::assertArrayHasKey('error', $vector, $exception->getReason());
            self::assertSame($vector['error'], $exception->getReason());
            return;
        }
        self::assertArrayHasKey('expect', $vector, 'Header set must be rejected.');
        self::assertSame($vector['expect'], $parsed);
    }

    public function testDomainAndHeaderNamesMatchFixture(): void
    {
        $constants = ProtocolFixtures::load('hmac-v1')['constants'];

        self::assertSame($constants['requestDomain'], RequestSignature::DOMAIN);
        self::assertSame($constants['requestHeaders']['key'], RequestSignature::HEADER_KEY);
        self::assertSame($constants['requestHeaders']['timestamp'], RequestSignature::HEADER_TIMESTAMP);
        self::assertSame($constants['requestHeaders']['nonce'], RequestSignature::HEADER_NONCE);
        self::assertSame($constants['requestHeaders']['signature'], RequestSignature::HEADER_SIGNATURE);
    }
}
