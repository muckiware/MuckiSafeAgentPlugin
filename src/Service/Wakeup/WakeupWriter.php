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

use Psr\Log\LoggerInterface;

use MuckiSafeAgentPlugin\Core\Defaults as PluginDefaults;
use MuckiSafeAgentPlugin\Service\Auth\AgentProtocol;

/**
 * Signals an already committed job to the host agent through the global flat
 * wakeup directory: `<job-uuid>.ready.tmp` is written exclusively, synced and
 * atomically renamed to `<job-uuid>.ready`. Wakeups are hints only and
 * polling stays authoritative, so a failed write is logged and reported as
 * `false` instead of being thrown into a caller whose job is already
 * committed. The file carries nothing but the job id and protocol version.
 */
final class WakeupWriter
{
    private const PROTOCOL_VERSION = 1;

    public function __construct(
        private readonly string $directory,
        protected LoggerInterface $logger
    ) {
    }

    public function signal(string $jobId): bool
    {
        $uuid = $this->wireUuid($jobId);
        $final = $this->directory . '/' . $uuid . '.ready';
        $temporary = $final . '.tmp';

        $failure = $this->writeAtomically($temporary, $final, $uuid);
        if ($failure === null) {
            return true;
        }
        // Logged after the error handler is restored: a notice raised while
        // logging (e.g. a deprecation when the logger class is autoloaded)
        // must never turn this hint into an exception that aborts the backup.
        $this->logger->warning(
            sprintf('Wakeup for job %s not written, polling will pick it up: %s', $uuid, $failure->getMessage()),
            PluginDefaults::DEFAULT_LOGGER_CONFIG
        );

        return false;
    }

    /**
     * Writes and renames the wakeup; returns the failure instead of throwing.
     * Only warnings and notices of the file functions become exceptions,
     * deprecations of unrelated code stay untouched.
     */
    private function writeAtomically(string $temporary, string $final, string $uuid): ?\Throwable
    {
        set_error_handler(static function (int $severity, string $message): never {
            throw new \ErrorException($message, 0, $severity);
        }, E_WARNING | E_NOTICE);
        try {
            $this->writeTemporary($temporary, $uuid);
            rename($temporary, $final);

            return null;
        } catch (\ErrorException | \RuntimeException $exception) {
            try {
                $this->removeTemporary($temporary);
            } catch (\ErrorException) {
                // The leftover is harmless: the next write replaces it.
            }

            return $exception;
        } finally {
            restore_error_handler();
        }
    }

    private function writeTemporary(string $temporary, string $uuid): void
    {
        // A leftover of a crashed writer (or a planted symlink) is removed;
        // unlink() never follows a symlink and mode `x` (O_EXCL) refuses one.
        if (is_link($temporary) || is_file($temporary)) {
            unlink($temporary);
        }
        $handle = fopen($temporary, 'x');
        if ($handle === false) {
            throw new \RuntimeException('Temporary wakeup file could not be created.');
        }
        try {
            $content = json_encode(['jobId' => $uuid, 'protocolVersion' => self::PROTOCOL_VERSION], JSON_THROW_ON_ERROR) . "\n";
            if (fwrite($handle, $content) !== strlen($content) || !fflush($handle) || !fsync($handle)) {
                throw new \RuntimeException('Short write to the temporary wakeup file.');
            }
        } finally {
            fclose($handle);
        }
    }

    private function removeTemporary(string $temporary): void
    {
        if (is_file($temporary) && !is_link($temporary)) {
            unlink($temporary);
        }
    }

    private function wireUuid(string $jobId): string
    {
        if (preg_match('/^[0-9a-f]{32}$/D', $jobId) === 1) {
            $jobId = substr($jobId, 0, 8) . '-' . substr($jobId, 8, 4) . '-' . substr($jobId, 12, 4)
                . '-' . substr($jobId, 16, 4) . '-' . substr($jobId, 20, 12);
        }
        if (preg_match('/^' . AgentProtocol::UUID_PATTERN . '$/D', $jobId) !== 1) {
            throw new \InvalidArgumentException('Wakeup job id must be a lowercase UUID.');
        }

        return $jobId;
    }
}
