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
namespace MuckiSafeAgentPlugin\tests\Integration\Command;

use Doctrine\DBAL\Connection;
use MuckiSafeAgentPlugin\Command\RollbackCommand;
use MuckiSafeAgentPlugin\Migration\Migration1795132800CreateAgentSchema;
use MuckiSafeAgentPlugin\Migration\Migration1795478400CreateRollbackState;
use MuckiSafeAgentPlugin\Service\Job\JobDrainer;
use MuckiSafeAgentPlugin\Service\Rollback\AgentRollback;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Test\TestCaseBase\KernelTestBehaviour;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Task 33: `muwa:agent:rollback start|stop|status` for the runbook over SSH.
 * Exit codes are scriptable: 0 = done / ready for hook removal, 3 = jobs are
 * still open (also after --wait), 2 = invalid usage.
 */
final class RollbackCommandTest extends TestCase
{
    use KernelTestBehaviour;

    private Connection $connection;
    /** @var list<int> */
    private array $sleeps = [];

    protected function setUp(): void
    {
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->dropSchema();
        (new Migration1795132800CreateAgentSchema())->update($connection);
        (new Migration1795478400CreateRollbackState())->update($connection);
    }

    protected function tearDown(): void
    {
        $this->dropSchema();
    }

    public function testCommandIsRegistered(): void
    {
        self::assertInstanceOf(RollbackCommand::class, self::getContainer()->get(RollbackCommand::class));
    }

    public function testStatusOfAnInactiveRollbackIsReadyAsJson(): void
    {
        $tester = $this->tester();

        self::assertSame(0, $tester->execute(['action' => 'status', '--json' => true]));

        $status = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($status['active']);
        self::assertTrue($status['readyForHookRemoval']);
    }

    public function testStartActivatesAndReportsReadiness(): void
    {
        $tester = $this->tester();

        self::assertSame(0, $tester->execute(['action' => 'start']));

        self::assertStringContainsString('Rollback active: yes', $tester->getDisplay());
        self::assertStringContainsString('Ready for hook removal: yes', $tester->getDisplay());
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT `active` FROM `muwa_agent_rollback`'));
    }

    public function testOpenJobsExitWithThreeAndWaitPollsUntilTheDeadline(): void
    {
        $this->connection->executeStatement('UPDATE `muwa_agent_rollback` SET `active` = 1');
        $tester = $this->tester(openJobsFor: PHP_INT_MAX);

        self::assertSame(3, $tester->execute(['action' => 'status', '--wait' => '10']));

        self::assertStringContainsString('Ready for hook removal: no', $tester->getDisplay());
        self::assertSame(10, array_sum($this->sleeps));
    }

    public function testWaitEndsAsSoonAsNoJobIsOpen(): void
    {
        $tester = $this->tester(openJobsFor: 2);

        self::assertSame(0, $tester->execute(['action' => 'status', '--wait' => '60']));

        self::assertSame(4, array_sum($this->sleeps));
    }

    public function testStopDeactivates(): void
    {
        $this->connection->executeStatement('UPDATE `muwa_agent_rollback` SET `active` = 1');
        $tester = $this->tester();

        self::assertSame(0, $tester->execute(['action' => 'stop']));

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT `active` FROM `muwa_agent_rollback`'));
    }

    public function testUnknownActionIsAUsageError(): void
    {
        self::assertSame(2, $this->tester()->execute(['action' => 'restart']));
    }

    /**
     * The simulated agent keeps a running job open for the first N status
     * reads; the injected sleep records the waiting instead of sleeping.
     */
    private function tester(int $openJobsFor = 0): CommandTester
    {
        $rollback = new AgentRollback($this->connection, new JobDrainer($this->connection));
        $reads = 0;
        $command = new RollbackCommand(
            $rollback,
            function (int $seconds) use (&$reads, $openJobsFor): void {
                $this->sleeps[] = $seconds;
                ++$reads;
                if ($openJobsFor > 0) {
                    $this->setRunningJob($reads < $openJobsFor);
                }
            }
        );
        if ($openJobsFor > 0) {
            $this->setRunningJob(true);
        }

        return new CommandTester($command);
    }

    /** One fake open job row is enough for the counter; the schema is test-local. */
    private function setRunningJob(bool $open): void
    {
        $this->connection->executeStatement('DROP TABLE IF EXISTS `muwa_agent_job`');
        $this->connection->executeStatement(
            'CREATE TABLE `muwa_agent_job` (`id` INT NOT NULL PRIMARY KEY, `status` VARCHAR(32) NOT NULL)'
        );
        if ($open) {
            $this->connection->executeStatement('INSERT INTO `muwa_agent_job` VALUES (1, \'cancel_requested\')');
        }
    }

    private function dropSchema(): void
    {
        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS=0');
        foreach (['muwa_agent_rollback', 'muwa_agent_nonce', 'muwa_agent_job', 'muwa_agent_backup_run', 'muwa_agent_repository_hook', 'muwa_agent_action', 'muwa_agent_host'] as $table) {
            $this->connection->executeStatement('DROP TABLE IF EXISTS `' . $table . '`');
        }
        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS=1');
    }
}
