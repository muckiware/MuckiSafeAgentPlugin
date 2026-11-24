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
namespace MuckiSafeAgentPlugin\Service\Job;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Shopware\Core\Defaults;

/**
 * Output retention: failure tails are nulled once more than 30 days have
 * passed since `finished_at`. Result code, redacted summary and the
 * completion audit stay; only successful jobs never had tails to begin with.
 */
final class FailureTailPruner
{
    private const RETENTION = '-30 days';

    /** @param (\Closure(): (\DateTimeInterface|string))|null $timestamp */
    public function __construct(
        private readonly Connection $connection,
        private readonly ?\Closure $timestamp = null,
        private readonly int $batchSize = 500,
        private readonly int $maxBatches = 100
    ) {
        if ($batchSize < 1 || $maxBatches < 1) {
            throw new \InvalidArgumentException('Batch size and batch count must be positive.');
        }
    }

    public function prune(): int
    {
        $now = $this->serverNow();
        $cutoff = $now->modify(self::RETENTION)->format(Defaults::STORAGE_DATE_TIME_FORMAT);

        $total = 0;
        for ($batch = 0; $batch < $this->maxBatches; ++$batch) {
            $affected = (int) $this->connection->executeStatement(
                'UPDATE `muwa_agent_job` SET `stdout_tail` = NULL, `stderr_tail` = NULL, `updated_at` = :now '
                . 'WHERE `finished_at` < :cutoff AND (`stdout_tail` IS NOT NULL OR `stderr_tail` IS NOT NULL) '
                . 'ORDER BY `id` LIMIT :batchSize',
                [
                    'now' => $now->format(Defaults::STORAGE_DATE_TIME_FORMAT),
                    'cutoff' => $cutoff,
                    'batchSize' => $this->batchSize,
                ],
                ['now' => ParameterType::STRING, 'cutoff' => ParameterType::STRING, 'batchSize' => ParameterType::INTEGER]
            );
            $total += $affected;
            if ($affected < $this->batchSize) {
                break;
            }
        }

        return $total;
    }

    private function serverNow(): \DateTimeImmutable
    {
        $value = $this->timestamp === null
            ? (string) $this->connection->fetchOne('SELECT UTC_TIMESTAMP(3)')
            : ($this->timestamp)();
        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value)->setTimezone(new \DateTimeZone('UTC'));
        }
        if ($value === '') {
            throw new \LogicException('Timestamp provider must return a timestamp.');
        }

        return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
    }
}
