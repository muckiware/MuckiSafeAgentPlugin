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
namespace MuckiSafeAgentPlugin\Core\Content\AgentAction;

use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Field\BlobField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\BoolField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\CreatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IntField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\JsonField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\LongTextField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\UpdatedAtField;

final class AgentActionDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'muwa_agent_action';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getEntityClass(): string
    {
        return AgentActionEntity::class;
    }

    public function getCollectionClass(): string
    {
        return AgentActionCollection::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new PrimaryKey(), new Required()),
            (new StringField('technical_name', 'technicalName'))->addFlags(new Required()),
            (new StringField('label', 'label'))->addFlags(new Required()),
            (new BoolField('enabled', 'enabled'))->addFlags(new Required()),
            (new LongTextField('definition', 'definition'))->addFlags(new Required()),
            (new BlobField('definition_hash', 'definitionHash'))->addFlags(new Required()),
            (new LongTextField('parameter_schema', 'parameterSchema')),
            (new JsonField('required_capabilities', 'requiredCapabilities'))->addFlags(new Required()),
            (new IntField('default_timeout_seconds', 'defaultTimeoutSeconds'))->addFlags(new Required()),
            (new IntField('max_timeout_seconds', 'maxTimeoutSeconds'))->addFlags(new Required()),
            (new StringField('retry_policy', 'retryPolicy'))->addFlags(new Required()),
            (new StringField('idempotency_class', 'idempotencyClass'))->addFlags(new Required()),
            new CreatedAtField(),
            new UpdatedAtField(),
        ]);
    }
}
