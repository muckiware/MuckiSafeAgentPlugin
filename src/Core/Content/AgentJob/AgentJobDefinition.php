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
namespace MuckiSafeAgentPlugin\Core\Content\AgentJob;

use MuckiSafeAgentPlugin\Core\Content\AgentAction\AgentActionDefinition;
use MuckiSafeAgentPlugin\Core\Content\AgentBackupRun\AgentBackupRunDefinition;
use MuckiSafeAgentPlugin\Core\Content\AgentHost\AgentHostDefinition;
use MuckiSafeAgentPlugin\Core\Framework\DataAbstractionLayer\Field\Flag\Immutable;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Field\BlobField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\CreatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\DateTimeField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;

use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\WriteProtected;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IntField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\LongTextField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\UpdatedAtField;

final class AgentJobDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'muwa_agent_job';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getEntityClass(): string
    {
        return AgentJobEntity::class;
    }

    public function getCollectionClass(): string
    {
        return AgentJobCollection::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new PrimaryKey(), new Required()),
            (new FkField('backup_run_id', 'backupRunId', AgentBackupRunDefinition::class))->addFlags(new Required(), new WriteProtected(Context::SYSTEM_SCOPE)),
            (new FkField('agent_action_id', 'agentActionId', AgentActionDefinition::class))->addFlags(new Required(), new WriteProtected(Context::SYSTEM_SCOPE)),
            (new IdField('facility_repository_id', 'facilityRepositoryId'))->addFlags(new Required(), new WriteProtected(Context::SYSTEM_SCOPE)),
            (new StringField('phase', 'phase'))->addFlags(new Required(), new Immutable(), new WriteProtected(Context::SYSTEM_SCOPE)),
            (new LongTextField('action_definition', 'actionDefinition'))->addFlags(new Required(), new Immutable(), new WriteProtected(Context::SYSTEM_SCOPE)),
            (new BlobField('action_hash', 'actionHash'))->addFlags(new Required(), new Immutable(), new WriteProtected(Context::SYSTEM_SCOPE)),
            (new LongTextField('payload', 'payload'))->addFlags(new Required(), new Immutable(), new WriteProtected(Context::SYSTEM_SCOPE)),
            (new StringField('idempotency_class', 'idempotencyClass'))->addFlags(new Required(), new Immutable(), new WriteProtected(Context::SYSTEM_SCOPE)),
            (new StringField('idempotency_key', 'idempotencyKey'))->addFlags(new Required(), new Immutable(), new WriteProtected(Context::SYSTEM_SCOPE)),
            (new StringField('status', 'status'))->addFlags(new Required(), new WriteProtected(Context::SYSTEM_SCOPE)),
            (new FkField('target_agent_id', 'targetAgentId', AgentHostDefinition::class))->addFlags(new Required(), new WriteProtected(Context::SYSTEM_SCOPE)),
            (new IntField('lease_version', 'leaseVersion'))->addFlags(new Required(), new WriteProtected(Context::SYSTEM_SCOPE)),
            (new IntField('attempt', 'attempt'))->addFlags(new Required(), new WriteProtected(Context::SYSTEM_SCOPE)),
            (new IntField('max_attempts', 'maxAttempts'))->addFlags(new Required(), new WriteProtected(Context::SYSTEM_SCOPE)),
            (new DateTimeField('available_at', 'availableAt'))->addFlags(new Required(), new WriteProtected(Context::SYSTEM_SCOPE)),
            (new DateTimeField('phase_deadline', 'phaseDeadline'))->addFlags(new Required(), new Immutable(), new WriteProtected(Context::SYSTEM_SCOPE)),
            (new DateTimeField('execution_deadline', 'executionDeadline'))->addFlags(new Required(), new Immutable(), new WriteProtected(Context::SYSTEM_SCOPE)),
            (new DateTimeField('stop_deadline', 'stopDeadline'))->addFlags(new Required(), new Immutable(), new WriteProtected(Context::SYSTEM_SCOPE)),
            (new DateTimeField('claimed_at', 'claimedAt'))->addFlags(new WriteProtected(Context::SYSTEM_SCOPE)),
            (new DateTimeField('lease_expires_at', 'leaseExpiresAt'))->addFlags(new WriteProtected(Context::SYSTEM_SCOPE)),
            (new DateTimeField('heartbeat_at', 'heartbeatAt'))->addFlags(new WriteProtected(Context::SYSTEM_SCOPE)),
            (new DateTimeField('finished_at', 'finishedAt'))->addFlags(new WriteProtected(Context::SYSTEM_SCOPE)),
            (new BlobField('completion_fingerprint', 'completionFingerprint'))->addFlags(new Immutable(), new WriteProtected(Context::SYSTEM_SCOPE)),
            (new FkField('completion_agent_id', 'completionAgentId', AgentHostDefinition::class))->addFlags(new Immutable(), new WriteProtected(Context::SYSTEM_SCOPE)),
            (new IntField('accepted_lease_version', 'acceptedLeaseVersion'))->addFlags(new Immutable(), new WriteProtected(Context::SYSTEM_SCOPE)),
            (new StringField('result_code', 'resultCode'))->addFlags(new WriteProtected(Context::SYSTEM_SCOPE)),
            (new StringField('result_summary', 'resultSummary'))->addFlags(new WriteProtected(Context::SYSTEM_SCOPE)),
            (new LongTextField('stdout_tail', 'stdoutTail'))->addFlags(new WriteProtected(Context::SYSTEM_SCOPE)),
            (new LongTextField('stderr_tail', 'stderrTail'))->addFlags(new WriteProtected(Context::SYSTEM_SCOPE)),
            new CreatedAtField(),
            new UpdatedAtField(),
        ]);
    }
}
