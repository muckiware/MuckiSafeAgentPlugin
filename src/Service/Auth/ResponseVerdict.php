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

/** Client-side verdict over an agent API response; values are fixture strings. */
enum ResponseVerdict: string
{
    /** Signature valid and bound to the request nonce: the body may be used. */
    case Accept = 'accept';

    /** Unsigned, empty 401 for an unknown key: terminal failure, no semantics. */
    case TerminalUnauthenticated = 'terminal_unauthenticated';

    /** Anything else: never trusted, never used as data source. */
    case Reject = 'reject';
}
