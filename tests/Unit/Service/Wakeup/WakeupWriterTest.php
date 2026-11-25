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
use MuckiSafeAgentPlugin\Service\Wakeup\WakeupWriter;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Task 17 RED: the writer signals a committed job by atomically renaming
 * `<job-uuid>.ready.tmp` to `<job-uuid>.ready` in the flat wakeup directory.
 * Wakeups are hints only, so every write failure is logged and reported as
 * `false`, never thrown into the caller that already committed the job.
 */
final class WakeupWriterTest extends TestCase
{
    private const JOB_HEX = '0190b0a87e2c7d4e9a513c6f2b1d8e47';
    private const JOB = '0190b0a8-7e2c-7d4e-9a51-3c6f2b1d8e47';

    private string $directory;
    /** @var AbstractLogger&object{records: list<string>} */
    private AbstractLogger $logger;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/muwa-wakeup-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0700);
        $this->logger = new class () extends AbstractLogger {
            /** @var list<string> */
            public array $records = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->records[] = $level . ': ' . $message;
            }
        };
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

    public function testWakeupIsNamedByTheHyphenatedJobUuidAndCarriesOnlyIdAndProtocolVersion(): void
    {
        self::assertTrue($this->writer()->signal(self::JOB_HEX));

        self::assertSame([self::JOB . '.ready'], $this->entries());
        self::assertSame(
            ['jobId' => self::JOB, 'protocolVersion' => 1],
            json_decode((string) file_get_contents($this->directory . '/' . self::JOB . '.ready'), true)
        );
        self::assertSame([], $this->logger->records);
    }

    public function testHyphenatedJobIdsAreAcceptedAsWell(): void
    {
        self::assertTrue($this->writer()->signal(self::JOB));
        self::assertSame([self::JOB . '.ready'], $this->entries());
    }

    public function testInvalidJobIdsAreRejectedBeforeTouchingTheDirectory(): void
    {
        foreach (['', '../etc/passwd', strtoupper(self::JOB_HEX), self::JOB . '.ready', 'not-a-uuid'] as $jobId) {
            try {
                $this->writer()->signal($jobId);
                self::fail('Expected rejection of ' . $jobId);
            } catch (\InvalidArgumentException) {
            }
        }
        self::assertSame([], $this->entries());
    }

    public function testDuplicateSignalsAreHarmlessAndLeaveOneWakeup(): void
    {
        self::assertTrue($this->writer()->signal(self::JOB_HEX));
        self::assertTrue($this->writer()->signal(self::JOB_HEX));

        self::assertSame([self::JOB . '.ready'], $this->entries());
    }

    public function testStaleTemporaryFileOfACrashedWriterIsReplaced(): void
    {
        file_put_contents($this->directory . '/' . self::JOB . '.ready.tmp', 'garbage');

        self::assertTrue($this->writer()->signal(self::JOB_HEX));

        self::assertSame([self::JOB . '.ready'], $this->entries());
        self::assertStringContainsString(self::JOB, (string) file_get_contents($this->directory . '/' . self::JOB . '.ready'));
    }

    public function testTemporarySymlinkIsNeverFollowed(): void
    {
        $target = $this->directory . '/foreign-target';
        file_put_contents($target, 'untouched');
        symlink($target, $this->directory . '/' . self::JOB . '.ready.tmp');

        self::assertTrue($this->writer()->signal(self::JOB_HEX));

        self::assertSame('untouched', file_get_contents($target));
        self::assertFalse(is_link($this->directory . '/' . self::JOB . '.ready'));
        self::assertSame([self::JOB . '.ready', 'foreign-target'], $this->entries());
    }

    public function testForeignDirectoryInTheWayIsLoggedAndLeavesNoTemporaryFile(): void
    {
        mkdir($this->directory . '/' . self::JOB . '.ready');
        file_put_contents($this->directory . '/notes.txt', 'foreign');

        self::assertFalse($this->writer()->signal(self::JOB_HEX));

        self::assertSame([self::JOB . '.ready', 'notes.txt'], $this->entries());
        self::assertDirectoryExists($this->directory . '/' . self::JOB . '.ready');
        self::assertCount(1, $this->logger->records);
    }

    public function testMissingDirectoryIsLoggedInsteadOfThrown(): void
    {
        $writer = new WakeupWriter($this->directory . '/absent', $this->logger);

        self::assertFalse($writer->signal(self::JOB_HEX));
        self::assertCount(1, $this->logger->records);
        self::assertStringContainsString(self::JOB, $this->logger->records[0]);
    }

    /**
     * Found by the Task-32 stack smoke test: the first log call autoloads the
     * logger class, whose PHP 8.4 deprecation must not become an exception
     * that aborts the backup, because the write failure is only a hint.
     */
    public function testANoticeRaisedWhileLoggingTheFailureIsNotThrown(): void
    {
        $logger = new class () extends AbstractLogger {
            public int $calls = 0;

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->calls++;
                trigger_error('Implicitly marking parameter $throwable as nullable is deprecated', E_USER_DEPRECATED);
            }
        };
        $writer = new WakeupWriter($this->directory . '/absent', $logger);

        self::assertFalse(@$writer->signal(self::JOB_HEX));
        self::assertSame(1, $logger->calls);
    }

    public function testWrittenWakeupsAreRecognisedByTheStaleCleanup(): void
    {
        $this->writer()->signal(self::JOB_HEX);
        touch($this->directory . '/' . self::JOB . '.ready', 1_900_000_000 - 90_000);

        self::assertSame(1, (new WakeupCleaner($this->directory, static fn (): int => 1_900_000_000))->clean());
    }

    private function writer(): WakeupWriter
    {
        return new WakeupWriter($this->directory, $this->logger);
    }

    /** @return list<string> */
    private function entries(): array
    {
        $entries = array_values(array_diff(scandir($this->directory) ?: [], ['.', '..']));
        sort($entries);

        return $entries;
    }
}
