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

use MuckiSafeAgentPlugin\Core\Content\AgentAction\AgentActionDefinition;
use MuckiSafeAgentPlugin\Core\Content\AgentHost\AgentHostDefinition;
use MuckiSafeAgentPlugin\Core\Framework\DataAbstractionLayer\Field\Flag\Immutable;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Field\BoolField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\CreatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;

use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\WriteProtected;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IntField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\LongTextField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\UpdatedAtField;

final class AgentRepositoryHookDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'muwa_agent_repository_hook';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getEntityClass(): string
    {
        return AgentRepositoryHookEntity::class;
    }

    public function getCollectionClass(): string
    {
        return AgentRepositoryHookCollection::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new PrimaryKey(), new Required()),
            // Hooks are written exclusively by RepositoryHookConfigurator in the
            // system scope, so the generic admin API cannot bypass its checks.
            (new IdField('facility_repository_id', 'facilityRepositoryId'))->addFlags(new Required(), new WriteProtected(Context::SYSTEM_SCOPE)),
            (new StringField('phase', 'phase'))->addFlags(new Required(), new Immutable(), new WriteProtected(Context::SYSTEM_SCOPE)),
            (new FkField('agent_host_id', 'agentHostId', AgentHostDefinition::class))->addFlags(new Required(), new WriteProtected(Context::SYSTEM_SCOPE)),
            (new FkField('agent_action_id', 'agentActionId', AgentActionDefinition::class))->addFlags(new Required(), new WriteProtected(Context::SYSTEM_SCOPE)),
            (new LongTextField('parameters', 'parameters'))->addFlags(new Required(), new WriteProtected(Context::SYSTEM_SCOPE)),
            (new IntField('timeout_seconds', 'timeoutSeconds'))->addFlags(new Required(), new WriteProtected(Context::SYSTEM_SCOPE)),
            (new BoolField('fail_on_error', 'failOnError'))->addFlags(new Required(), new WriteProtected(Context::SYSTEM_SCOPE)),
            (new BoolField('enabled', 'enabled'))->addFlags(new Required(), new WriteProtected(Context::SYSTEM_SCOPE)),
            new ManyToOneAssociationField('agentHost', 'agent_host_id', AgentHostDefinition::class, 'id', false),
            new CreatedAtField(),
            new UpdatedAtField(),
        ]);
    }
}
