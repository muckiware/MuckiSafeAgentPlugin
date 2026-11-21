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
namespace MuckiSafeAgentPlugin\Core\Content\AgentBackupRun;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Field\CreatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\DateTimeField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\WriteProtected;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\UpdatedAtField;

final class AgentBackupRunDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'muwa_agent_backup_run';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getEntityClass(): string
    {
        return AgentBackupRunEntity::class;
    }

    public function getCollectionClass(): string
    {
        return AgentBackupRunCollection::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new PrimaryKey(), new Required()),
            (new IdField('facility_repository_id', 'facilityRepositoryId'))->addFlags(new Required(), new WriteProtected(Context::SYSTEM_SCOPE)),
            (new StringField('source', 'source'))->addFlags(new Required(), new WriteProtected(Context::SYSTEM_SCOPE)),
            (new StringField('idempotency_key', 'idempotencyKey'))->addFlags(new Required(), new WriteProtected(Context::SYSTEM_SCOPE)),
            (new StringField('status', 'status'))->addFlags(new Required(), new WriteProtected(Context::SYSTEM_SCOPE)),
            (new DateTimeField('started_at', 'startedAt'))->addFlags(new Required(), new WriteProtected(Context::SYSTEM_SCOPE)),
            (new DateTimeField('finished_at', 'finishedAt'))->addFlags(new WriteProtected(Context::SYSTEM_SCOPE)),
            (new StringField('primary_error_code', 'primaryErrorCode'))->addFlags(new WriteProtected(Context::SYSTEM_SCOPE)),
            (new StringField('primary_error_summary', 'primaryErrorSummary'))->addFlags(new WriteProtected(Context::SYSTEM_SCOPE)),
            new CreatedAtField(),
            new UpdatedAtField(),
        ]);
    }
}
