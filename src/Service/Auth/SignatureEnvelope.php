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
namespace MuckiSafeAgentPlugin\Service\Auth;

/**
 * Fields shared by request and response base strings. For a response the
 * nonce is the nonce of the request being answered. Construction validates
 * every field, so a SignatureEnvelope is always canonical.
 */
final readonly class SignatureEnvelope
{
    public string $routeName;

    public function __construct(
        public string $path,
        public string $bodySha256,
        public string $timestamp,
        public string $nonce
    ) {
        $this->routeName = AgentProtocol::matchRoute($path);
        SignatureFormat::assertBodySha256($bodySha256);
        SignatureFormat::assertTimestamp($timestamp);
        SignatureFormat::assertNonce($nonce);
    }

    public static function forBody(string $path, string $rawBody, string $timestamp, string $nonce): self
    {
        return new self($path, hash('sha256', $rawBody), $timestamp, $nonce);
    }
}
