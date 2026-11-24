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
namespace MuckiSafeAgentPlugin\Service\Wakeup;

/**
 * Removes stale wakeup files from the global flat wakeup directory. Wakeups
 * are mere hints and polling stays authoritative, so only regular files
 * named `<job-uuid>.ready` (or their `.tmp` staging file) older than 24 hours
 * are removed. The Go watcher never deletes; foreign files, symlinks and
 * directories are never touched and a missing directory is not an error.
 */
final class WakeupCleaner
{
    private const MAX_AGE_SECONDS = 86_400;
    private const NAME_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.ready(?:\.tmp)?$/D';

    /** @param (\Closure(): int)|null $clock unix time provider */
    public function __construct(
        private readonly string $directory,
        private readonly ?\Closure $clock = null,
        private readonly int $maxFilesPerRun = 1000
    ) {
        if ($maxFilesPerRun < 1) {
            throw new \InvalidArgumentException('The per-run file limit must be positive.');
        }
    }

    public function clean(): int
    {
        if (!is_dir($this->directory)) {
            return 0;
        }
        $entries = scandir($this->directory);
        if ($entries === false) {
            return 0;
        }
        $cutoff = ($this->clock === null ? time() : ($this->clock)()) - self::MAX_AGE_SECONDS;

        $removed = 0;
        foreach ($entries as $entry) {
            if ($removed >= $this->maxFilesPerRun) {
                break;
            }
            if (preg_match(self::NAME_PATTERN, $entry) !== 1) {
                continue;
            }
            $path = $this->directory . '/' . $entry;
            $stat = lstat($path);
            if ($stat === false || ($stat['mode'] & 0170000) !== 0100000 || $stat['mtime'] >= $cutoff) {
                continue;
            }
            // A concurrent writer or second cleaner may have replaced or
            // removed the file meanwhile; unlink() never follows a symlink.
            if (unlink($path)) {
                ++$removed;
            }
        }

        return $removed;
    }
}
