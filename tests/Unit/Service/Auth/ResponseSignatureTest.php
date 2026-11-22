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
use MuckiSafeAgentPlugin\Service\Auth\ResponseSignature;
use MuckiSafeAgentPlugin\Service\Auth\ResponseVerdict;
use MuckiSafeAgentPlugin\Service\Auth\SignatureEnvelope;
use MuckiSafeAgentPlugin\Service\Secret\AgentProvisioner;
use MuckiSafeAgentPlugin\tests\Support\ProtocolFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

final class ResponseSignatureTest extends TestCase
{
    /** @return iterable<string, array{array<string, mixed>}> */
    public static function responseVectors(): iterable
    {
        return ProtocolFixtures::named(ProtocolFixtures::load('hmac-v1'), 'responses');
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidResponses(): iterable
    {
        return ProtocolFixtures::named(ProtocolFixtures::load('hmac-v1'), 'invalidResponses');
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function verificationVectors(): iterable
    {
        return ProtocolFixtures::named(ProtocolFixtures::load('hmac-v1'), 'responseVerification');
    }

    /** @param array<string, mixed> $vector */
    #[DataProvider('responseVectors')]
    public function testResponseVectorProducesExactBaseStringSignatureAndHeaders(array $vector): void
    {
        $key = AgentProvisioner::decodeSecret($vector['secret']);
        $envelope = SignatureEnvelope::forBody($vector['path'], $vector['body'], $vector['timestamp'], $vector['requestNonce']);
        $signer = new ResponseSignature();

        self::assertSame($vector['bodySha256'], $envelope->bodySha256);
        self::assertSame($vector['baseString'], $signer->baseString($vector['status'], $envelope));
        self::assertSame($vector['signature'], $signer->sign($key, $vector['status'], $envelope));
        self::assertSame(
            [
                ResponseSignature::HEADER_TIMESTAMP => $vector['timestamp'],
                ResponseSignature::HEADER_REQUEST_NONCE => $vector['requestNonce'],
                ResponseSignature::HEADER_SIGNATURE => $vector['signature'],
            ],
            $signer->headers($key, $vector['status'], $envelope)
        );
    }

    /** @param array<string, mixed> $vector */
    #[DataProvider('responseVectors')]
    public function testSignedResponseVectorIsAcceptedByVerifier(array $vector): void
    {
        $response = new Response($vector['body'], $vector['status'], [
            ResponseSignature::HEADER_TIMESTAMP => $vector['timestamp'],
            ResponseSignature::HEADER_REQUEST_NONCE => $vector['requestNonce'],
            ResponseSignature::HEADER_SIGNATURE => $vector['signature'],
        ]);

        self::assertSame(ResponseVerdict::Accept, (new ResponseSignature())->classify(
            AgentProvisioner::decodeSecret($vector['secret']),
            $vector['requestNonce'],
            $vector['path'],
            $response
        ));
    }

    /** @param array<string, mixed> $vector */
    #[DataProvider('invalidResponses')]
    public function testInvalidResponseInputIsRejectedWithStableReason(array $vector): void
    {
        try {
            $envelope = new SignatureEnvelope($vector['path'], $vector['bodySha256'], $vector['timestamp'], $vector['requestNonce']);
            (new ResponseSignature())->sign((string) hex2bin($vector['keyHex']), $vector['status'], $envelope);
            self::fail('Invalid input must be rejected.');
        } catch (SignatureException $exception) {
            self::assertSame($vector['error'], $exception->getReason());
        }
    }

    /** @param array<string, mixed> $vector */
    #[DataProvider('verificationVectors')]
    public function testClientSideClassificationMatchesFixture(array $vector): void
    {
        $response = new Response($vector['body'], $vector['status']);
        foreach ($vector['headers'] as $name => $values) {
            $response->headers->set($name, $values);
        }

        $verdict = (new ResponseSignature())->classify(
            AgentProvisioner::decodeSecret($vector['secret']),
            $vector['requestNonce'],
            $vector['path'],
            $response
        );

        self::assertSame($vector['verdict'], $verdict->value);
    }

    public function testDomainAndHeaderNamesMatchFixture(): void
    {
        $constants = ProtocolFixtures::load('hmac-v1')['constants'];

        self::assertSame($constants['responseDomain'], ResponseSignature::DOMAIN);
        self::assertSame($constants['responseHeaders']['timestamp'], ResponseSignature::HEADER_TIMESTAMP);
        self::assertSame($constants['responseHeaders']['requestNonce'], ResponseSignature::HEADER_REQUEST_NONCE);
        self::assertSame($constants['responseHeaders']['signature'], ResponseSignature::HEADER_SIGNATURE);
    }
}
