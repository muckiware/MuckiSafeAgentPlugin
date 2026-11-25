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

use MuckiSafeAgentPlugin\Core\Content\AgentAction\AgentActionDefinition;
use MuckiSafeAgentPlugin\Core\Content\AgentHost\AgentHostDefinition;
use MuckiSafeAgentPlugin\Core\Content\AgentNonce\AgentNonceDefinition;
use MuckiSafeAgentPlugin\Core\Content\AgentRepositoryHook\AgentRepositoryHookDefinition;
use Shopware\Core\Framework\Api\Context\AdminApiSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Validation\PreWriteValidationEvent;
use Shopware\Core\Framework\Validation\WriteConstraintViolationException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * Rejects every generic Admin API write (create, update, delete) on entities
 * whose rows are maintained exclusively by plugin services. Field flags alone
 * cannot cover deletes, and a single forgotten flag would let a role holding
 * DAL privileges revive a revoked agent. Plugin services either write through
 * DBAL or switch into the system scope and are therefore unaffected.
 */
final class AgentEntityWriteGuardSubscriber implements EventSubscriberInterface
{
    public const VIOLATION_MESSAGE = 'This entity is managed by MuckiSafe Agent services and cannot be written through the generic API.';

    private const GUARDED_ENTITIES = [
        AgentActionDefinition::ENTITY_NAME,
        AgentHostDefinition::ENTITY_NAME,
        AgentNonceDefinition::ENTITY_NAME,
        AgentRepositoryHookDefinition::ENTITY_NAME,
    ];

    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [PreWriteValidationEvent::class => 'onPreWriteValidation'];
    }

    public function onPreWriteValidation(PreWriteValidationEvent $event): void
    {
        $context = $event->getContext();
        if (!$context->getSource() instanceof AdminApiSource || $context->getScope() === Context::SYSTEM_SCOPE) {
            return;
        }

        foreach (self::GUARDED_ENTITIES as $entityName) {
            foreach ($event->getCommandsForEntity($entityName) as $command) {
                $violations = new ConstraintViolationList([
                    new ConstraintViolation(self::VIOLATION_MESSAGE, self::VIOLATION_MESSAGE, [], null, '/', null),
                ]);
                $event->getExceptions()->add(new WriteConstraintViolationException($violations, $command->getPath()));
            }
        }
    }
}
