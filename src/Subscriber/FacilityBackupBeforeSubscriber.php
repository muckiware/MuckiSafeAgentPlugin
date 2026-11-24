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
namespace MuckiSafeAgentPlugin\Subscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

use MuckiFacilityPlugin\Events\BeforeBackupCreatedEvent;
use MuckiSafeAgentPlugin\Service\Hook\BackupRunCoordinator;

/**
 * Runs ahead of Facility's legacy BackupCommandSubscriber (priority 0): the
 * legacy conflict check and the pre hook happen before any legacy command
 * or backup side effect. A thrown AgentHookFailure aborts the backup.
 */
final class FacilityBackupBeforeSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly BackupRunCoordinator $coordinator)
    {
    }

    /** @return array<string, string|array{0: string, 1: int}> */
    public static function getSubscribedEvents(): array
    {
        return [BeforeBackupCreatedEvent::NAME => ['onBefore', 1000]];
    }

    public function onBefore(BeforeBackupCreatedEvent $event): void
    {
        $this->coordinator->before(FacilityRepositoryId::fromSettings($event->getBackupSettings()));
    }
}
