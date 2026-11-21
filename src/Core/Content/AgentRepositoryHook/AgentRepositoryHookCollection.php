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
namespace MuckiSafeAgentPlugin\Core\Content\AgentRepositoryHook;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/** @extends EntityCollection<AgentRepositoryHookEntity> */
final class AgentRepositoryHookCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return AgentRepositoryHookEntity::class;
    }
}
