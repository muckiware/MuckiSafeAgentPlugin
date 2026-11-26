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
namespace MuckiSafeAgentPlugin\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use MuckiSafeAgentPlugin\Service\Rollback\AgentRollback;
use MuckiSafeAgentPlugin\Service\Rollback\RollbackStatus;

/**
 * Operates the controlled rollback (Task 33) from a shell, see
 * docs/rollout-runbook.md. Exit codes: 0 = done and ready for hook removal
 * (stop: done), 3 = jobs are still open, also after --wait, 2 = usage error.
 */
#[AsCommand(name: 'muwa:agent:rollback', description: 'Start, stop or check the controlled rollback of the MuckiSafe agent')]
final class RollbackCommand extends Command
{
    public const EXIT_OPEN_JOBS = 3;
    private const POLL_SECONDS = 2;

    /** @param (\Closure(int): void)|null $sleep */
    public function __construct(
        private readonly AgentRollback $rollback,
        private readonly ?\Closure $sleep = null
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('action', InputArgument::REQUIRED, 'start | stop | status')
            ->addOption('wait', null, InputOption::VALUE_REQUIRED, 'start/status: wait up to SECONDS until no job is open', '0')
            ->addOption('json', null, InputOption::VALUE_NONE, 'print the status as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $action = (string) $input->getArgument('action');
        $wait = filter_var($input->getOption('wait'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if (!in_array($action, ['start', 'stop', 'status'], true) || $wait === false) {
            $output->writeln('<error>Usage: muwa:agent:rollback start|stop|status [--wait=SECONDS] [--json]</error>');

            return Command::INVALID;
        }
        $status = match ($action) {
            'start' => $this->rollback->start(),
            'stop' => $this->rollback->stop(),
            default => $this->rollback->status(),
        };
        if ($action !== 'stop') {
            $status = $this->waitUntilReady($status, $wait);
        }
        $this->print($output, $status, (bool) $input->getOption('json'));
        if ($action === 'stop') {
            return Command::SUCCESS;
        }

        return $status->readyForHookRemoval ? Command::SUCCESS : self::EXIT_OPEN_JOBS;
    }

    private function waitUntilReady(RollbackStatus $status, int $seconds): RollbackStatus
    {
        for ($waited = 0; !$status->readyForHookRemoval && $waited < $seconds; $waited += self::POLL_SECONDS) {
            $this->sleep(min(self::POLL_SECONDS, $seconds - $waited));
            $status = $this->rollback->status();
        }

        return $status;
    }

    private function sleep(int $seconds): void
    {
        if ($this->sleep !== null) {
            ($this->sleep)($seconds);

            return;
        }
        sleep($seconds);
    }

    private function print(OutputInterface $output, RollbackStatus $status, bool $json): void
    {
        if ($json) {
            $output->writeln((string) json_encode($status->toArray(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

            return;
        }
        $output->writeln('Rollback active: ' . ($status->active ? 'yes' : 'no'));
        $output->writeln('Started at: ' . ($status->startedAt ?? '-') . ', stopped at: ' . ($status->stoppedAt ?? '-'));
        foreach ($status->openJobs as $state => $count) {
            $output->writeln(sprintf('Open jobs %s: %d', $state, $count));
        }
        $output->writeln('Unknown jobs (assess manually, never repeated): ' . $status->unknownJobs);
        $output->writeln('Ready for hook removal: ' . ($status->readyForHookRemoval ? 'yes' : 'no'));
    }
}
