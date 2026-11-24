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
namespace MuckiSafeAgentPlugin\tests\Unit\Service\Wakeup;

use MuckiSafeAgentPlugin\Service\Wakeup\WakeupCleaner;
use PHPUnit\Framework\TestCase;

/**
 * Task 14 RED: the PHP cleanup removes only stale (older than 24 hours)
 * regular wakeup files from the flat directory. Young wakeups, foreign
 * files, symlinks and directories are never touched; a missing directory
 * is not an error because polling stays authoritative.
 */
final class WakeupCleanerTest extends TestCase
{
    private const NOW = 1_900_000_000;
    private const JOB = '0190b0a8-7e2c-7d4e-9a51-3c6f2b1d8e47';

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/muwa-wakeup-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0700);
    }

    protected function tearDown(): void
    {
        foreach (scandir($this->directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $this->directory . '/' . $entry;
            is_dir($path) && !is_link($path) ? rmdir($path) : unlink($path);
        }
        rmdir($this->directory);
    }

    public function testStaleReadyAndTemporaryWakeupsAreRemoved(): void
    {
        $this->file(self::JOB . '.ready', self::NOW - 86_401);
        $this->file(self::JOB . '.ready.tmp', self::NOW - 90_000);

        self::assertSame(2, $this->cleaner()->clean());
        self::assertFileDoesNotExist($this->directory . '/' . self::JOB . '.ready');
        self::assertFileDoesNotExist($this->directory . '/' . self::JOB . '.ready.tmp');
    }

    public function testWakeupsUpToTwentyFourHoursOldAreKept(): void
    {
        $this->file(self::JOB . '.ready', self::NOW - 86_400);
        $this->file('1190b0a8-7e2c-7d4e-9a51-3c6f2b1d8e47.ready', self::NOW - 10);

        self::assertSame(0, $this->cleaner()->clean());
        self::assertFileExists($this->directory . '/' . self::JOB . '.ready');
        self::assertFileExists($this->directory . '/1190b0a8-7e2c-7d4e-9a51-3c6f2b1d8e47.ready');
    }

    public function testForeignFilesSymlinksAndDirectoriesAreNeverTouched(): void
    {
        $this->file('notes.txt', self::NOW - 999_999);
        $this->file('0190B0A8-7E2C-7D4E-9A51-3C6F2B1D8E47.ready', self::NOW - 999_999);
        $target = $this->file('target.txt', self::NOW - 999_999);
        symlink($target, $this->directory . '/2190b0a8-7e2c-7d4e-9a51-3c6f2b1d8e47.ready');
        mkdir($this->directory . '/3190b0a8-7e2c-7d4e-9a51-3c6f2b1d8e47.ready');
        touch($this->directory . '/3190b0a8-7e2c-7d4e-9a51-3c6f2b1d8e47.ready', self::NOW - 999_999);

        self::assertSame(0, $this->cleaner()->clean());
        self::assertFileExists($this->directory . '/notes.txt');
        self::assertFileExists($this->directory . '/0190B0A8-7E2C-7D4E-9A51-3C6F2B1D8E47.ready');
        self::assertTrue(is_link($this->directory . '/2190b0a8-7e2c-7d4e-9a51-3c6f2b1d8e47.ready'));
        self::assertDirectoryExists($this->directory . '/3190b0a8-7e2c-7d4e-9a51-3c6f2b1d8e47.ready');
        self::assertFileExists($target);
    }

    public function testCleanupIsBoundedPerRun(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            $this->file(sprintf('%08x-7e2c-7d4e-9a51-3c6f2b1d8e47.ready', $i), self::NOW - 100_000);
        }

        self::assertSame(3, (new WakeupCleaner($this->directory, static fn (): int => self::NOW, 3))->clean());
        self::assertSame(2, (new WakeupCleaner($this->directory, static fn (): int => self::NOW, 3))->clean());
    }

    public function testMissingDirectoryIsANoop(): void
    {
        self::assertSame(0, (new WakeupCleaner($this->directory . '/absent', static fn (): int => self::NOW))->clean());
    }

    public function testInvalidLimitIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new WakeupCleaner($this->directory, null, 0);
    }

    private function cleaner(): WakeupCleaner
    {
        return new WakeupCleaner($this->directory, static fn (): int => self::NOW);
    }

    private function file(string $name, int $mtime): string
    {
        $path = $this->directory . '/' . $name;
        file_put_contents($path, '');
        touch($path, $mtime);

        return $path;
    }
}
