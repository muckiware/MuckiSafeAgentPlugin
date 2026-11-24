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
namespace MuckiSafeAgentPlugin\tests\Unit\Service\Hook;

use MuckiFacilityPlugin\Core\Content\BackupRepository\BackupRepositoryEntity;
use MuckiSafeAgentPlugin\Service\Hook\LegacyHookConflictGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Task 15 RED: the guard reads exactly getPreBackupCommandType() and
 * getPostBackupCommandType() of the Facility entity. Only the exact value
 * `none` counts as inactive; every other value — including unknown ones —
 * is a legacy hook and fails closed.
 */
final class LegacyHookConflictGuardTest extends TestCase
{
    /** @return iterable<string, array{string, string, list<string>}> */
    public static function commandTypes(): iterable
    {
        yield 'both inactive' => ['none', 'none', []];
        yield 'pre batch' => ['batch', 'none', ['preBackupCommandType']];
        yield 'post php' => ['none', 'php', ['postBackupCommandType']];
        yield 'both active' => ['php', 'batch', ['preBackupCommandType', 'postBackupCommandType']];
        yield 'uppercase none is not none' => ['NONE', 'none', ['preBackupCommandType']];
        yield 'empty value is not none' => ['none', '', ['postBackupCommandType']];
        yield 'padded none is not none' => [' none', 'none', ['preBackupCommandType']];
        yield 'unknown type' => ['shell', 'none', ['preBackupCommandType']];
    }

    /** @param list<string> $expected */
    #[DataProvider('commandTypes')]
    public function testOnlyExactNoneIsInactive(string $pre, string $post, array $expected): void
    {
        $repository = new BackupRepositoryEntity();
        $repository->setPreBackupCommandType($pre);
        $repository->setPostBackupCommandType($post);

        self::assertSame($expected, (new LegacyHookConflictGuard(null))->conflictingFields($repository));
    }

    public function testGuardDoesNotModifyTheFacilityEntity(): void
    {
        $repository = new BackupRepositoryEntity();
        $repository->setPreBackupCommandType('batch');
        $repository->setPostBackupCommandType('php');

        (new LegacyHookConflictGuard(null))->conflictingFields($repository);

        self::assertSame('batch', $repository->getPreBackupCommandType());
        self::assertSame('php', $repository->getPostBackupCommandType());
    }
}
