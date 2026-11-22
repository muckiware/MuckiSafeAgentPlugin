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

use MuckiSafeAgentPlugin\Exception\SignatureException;

/**
 * Versioned V1 wire contract of the agent routes: exact canonical paths and
 * raw body limits. Mirrored by agent/internal/api/protocol.go and pinned by
 * tests/Fixtures/protocol/routes-v1.json.
 */
final class AgentProtocol
{
    public const ROUTE_PREFIX = '/api/_action/muwa/agent/v1';

    public const ROUTE_HOST_HEARTBEAT = 'api.action.muwa.agent.v1.host_heartbeat';
    public const ROUTE_JOB_CLAIM = 'api.action.muwa.agent.v1.job_claim';
    public const ROUTE_JOB_START = 'api.action.muwa.agent.v1.job_start';
    public const ROUTE_JOB_HEARTBEAT = 'api.action.muwa.agent.v1.job_heartbeat';
    public const ROUTE_JOB_COMPLETE = 'api.action.muwa.agent.v1.job_complete';

    /** Absolute transport cap of the upstream web server, above every route limit. */
    public const TRANSPORT_HARD_CAP_BYTES = 270336;

    public const TIMESTAMP_WINDOW_SECONDS = 60;
    public const REPLAY_CLEANUP_TOLERANCE_SECONDS = 5;

    /** Lowercase RFC 4122 UUID (versions 1-8, RFC variant). */
    public const UUID_PATTERN = '[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}';

    private const ROUTES = [
        self::ROUTE_HOST_HEARTBEAT => ['method' => 'POST', 'path' => self::ROUTE_PREFIX . '/heartbeat', 'bodyLimitBytes' => 16384],
        self::ROUTE_JOB_CLAIM => ['method' => 'POST', 'path' => self::ROUTE_PREFIX . '/jobs/claim', 'bodyLimitBytes' => 1024],
        self::ROUTE_JOB_START => ['method' => 'POST', 'path' => self::ROUTE_PREFIX . '/jobs/{jobId}/start', 'bodyLimitBytes' => 4096],
        self::ROUTE_JOB_HEARTBEAT => ['method' => 'POST', 'path' => self::ROUTE_PREFIX . '/jobs/{jobId}/heartbeat', 'bodyLimitBytes' => 4096],
        self::ROUTE_JOB_COMPLETE => ['method' => 'POST', 'path' => self::ROUTE_PREFIX . '/jobs/{jobId}/complete', 'bodyLimitBytes' => 262144],
    ];

    private const JOB_ACTION_ROUTES = [
        'start' => self::ROUTE_JOB_START,
        'heartbeat' => self::ROUTE_JOB_HEARTBEAT,
        'complete' => self::ROUTE_JOB_COMPLETE,
    ];

    /** @return array<string, array{method: string, path: string, bodyLimitBytes: int}> */
    public static function routes(): array
    {
        return self::ROUTES;
    }

    /**
     * Resolves the route name of an exact, already canonical request path.
     * Query, fragment, percent encoding, backslash, dot segments, double or
     * trailing slashes and any other spelling are rejected, never normalized.
     */
    public static function matchRoute(string $path): string
    {
        if ($path === self::ROUTES[self::ROUTE_HOST_HEARTBEAT]['path']) {
            return self::ROUTE_HOST_HEARTBEAT;
        }
        if ($path === self::ROUTES[self::ROUTE_JOB_CLAIM]['path']) {
            return self::ROUTE_JOB_CLAIM;
        }
        $pattern = '#^' . preg_quote(self::ROUTE_PREFIX, '#') . '/jobs/' . self::UUID_PATTERN . '/(start|heartbeat|complete)$#D';
        if (preg_match($pattern, $path, $matches) === 1) {
            return self::JOB_ACTION_ROUTES[$matches[1]];
        }
        throw new SignatureException(SignatureException::INVALID_PATH);
    }

    public static function bodyLimit(string $routeName): int
    {
        if (!isset(self::ROUTES[$routeName])) {
            throw new SignatureException(SignatureException::INVALID_PATH);
        }
        return self::ROUTES[$routeName]['bodyLimitBytes'];
    }

    public static function assertBodyWithinLimit(string $routeName, int $length): void
    {
        if ($length > self::bodyLimit($routeName)) {
            throw new SignatureException(SignatureException::BODY_TOO_LARGE);
        }
    }
}
