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
namespace MuckiSafeAgentPlugin\Core\Content\AgentNonce;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/** @extends EntityCollection<AgentNonceEntity> */
final class AgentNonceCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return AgentNonceEntity::class;
    }
}
