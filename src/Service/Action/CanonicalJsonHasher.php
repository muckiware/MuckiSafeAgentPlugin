<?php declare(strict_types=1);
/**
 * MuckiSafeAgentPlugin
 * @category SW6 Plugin
 * @package MuckiSafeAgent
 * @copyright Copyright (c) 2026 by Muckiware
 * @license MIT
 * @author Muckiware
 */

namespace MuckiSafeAgentPlugin\Service\Action;

final class CanonicalJsonHasher
{
    public function canonicalize(mixed $value): string
    {
        return CanonicalJson::encode($value);
    }

    public function hash(mixed $value): string
    {
        return CanonicalJson::hash($value);
    }

    public function verify(string $canonical, string $expectedHash): bool
    {
        return preg_match('/^[0-9a-f]{64}$/D', $expectedHash) === 1
            && hash_equals($expectedHash, hash('sha256', $canonical));
    }
}
