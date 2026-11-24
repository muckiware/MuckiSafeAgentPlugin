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

use MuckiFacilityPlugin\Events\BackupFinishedEvent;
use MuckiSafeAgentPlugin\Service\Hook\BackupRunCoordinator;

/**
 * Runs the finally hook at most once on every outcome and closes the run.
 * Facility keeps a recorded primary error ahead of a failure thrown here.
 */
final class FacilityBackupFinishedSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly BackupRunCoordinator $coordinator)
    {
    }

    /** @return array<string, string|array{0: string, 1: int}> */
    public static function getSubscribedEvents(): array
    {
        return [BackupFinishedEvent::NAME => 'onFinished'];
    }

    public function onFinished(BackupFinishedEvent $event): void
    {
        $this->coordinator->finished(
            FacilityRepositoryId::fromSettings($event->getBackupSettings()),
            $event->getPrimaryError()
        );
    }
}
