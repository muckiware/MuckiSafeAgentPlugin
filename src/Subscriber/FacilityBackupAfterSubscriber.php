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

use MuckiFacilityPlugin\Events\AfterBackupCreatedEvent;
use MuckiSafeAgentPlugin\Service\Hook\BackupRunCoordinator;

/** Runs the post hook on the active run once the backup core succeeded. */
final class FacilityBackupAfterSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly BackupRunCoordinator $coordinator)
    {
    }

    /** @return array<string, string|array{0: string, 1: int}> */
    public static function getSubscribedEvents(): array
    {
        return [AfterBackupCreatedEvent::NAME => 'onAfter'];
    }

    public function onAfter(AfterBackupCreatedEvent $event): void
    {
        $this->coordinator->after(FacilityRepositoryId::fromSettings($event->getBackupSettings()));
    }
}
