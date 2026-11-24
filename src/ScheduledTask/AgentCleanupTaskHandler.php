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
namespace MuckiSafeAgentPlugin\ScheduledTask;

use MuckiSafeAgentPlugin\Service\Auth\ReplayGuard;
use MuckiSafeAgentPlugin\Service\Job\FailureTailPruner;
use MuckiSafeAgentPlugin\Service\Job\JobReaper;
use MuckiSafeAgentPlugin\Service\Wakeup\WakeupCleaner;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;

/**
 * Reaps expired leases and deadlines first, then removes stale state in
 * bounded batches: expired replay nonces, failure tails past retention and
 * stale wakeup files. Jobs and runs themselves are never deleted.
 */
final class AgentCleanupTaskHandler extends ScheduledTaskHandler
{
    private const NONCE_BATCH_SIZE = 500;
    private const NONCE_MAX_BATCHES = 100;

    public function __construct(
        EntityRepository $scheduledTaskRepository,
        private readonly LoggerInterface $logger,
        private readonly JobReaper $reaper,
        private readonly ReplayGuard $replayGuard,
        private readonly FailureTailPruner $tailPruner,
        private readonly WakeupCleaner $wakeupCleaner
    ) {
        parent::__construct($scheduledTaskRepository, $logger);
    }

    public function run(): void
    {
        $reaped = $this->reaper->reap();
        $nonces = $this->cleanupNonces();
        $tails = $this->tailPruner->prune();
        $wakeups = $this->wakeupCleaner->clean();

        if ($reaped->total() + $nonces + $tails + $wakeups === 0) {
            return;
        }
        $this->logger->info('MuckiSafe agent cleanup finished.', [
            'requeued' => $reaped->requeued,
            'expired' => $reaped->expired,
            'unknown' => $reaped->unknown,
            'timedOut' => $reaped->timedOut,
            'cancelRequested' => $reaped->cancelRequested,
            'nonces' => $nonces,
            'tails' => $tails,
            'wakeups' => $wakeups,
        ]);
    }

    private function cleanupNonces(): int
    {
        $serverNow = time();
        $total = 0;
        for ($batch = 0; $batch < self::NONCE_MAX_BATCHES; ++$batch) {
            $deleted = $this->replayGuard->cleanupExpired($serverNow, self::NONCE_BATCH_SIZE);
            $total += $deleted;
            if ($deleted < self::NONCE_BATCH_SIZE) {
                break;
            }
        }

        return $total;
    }
}
