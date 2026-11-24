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
namespace MuckiSafeAgentPlugin\Service\Hook;

use MuckiFacilityPlugin\Core\Content\BackupRepository\BackupRepositoryEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;

/**
 * Per repository either Facility legacy hooks or agent hooks run, never
 * both. The Facility repository is read through Facility's own DAL
 * repository (no cross-plugin foreign key) and only the exact value `none`
 * counts as inactive, so unknown command types fail closed. Facility values
 * are only read, never changed.
 */
final class LegacyHookConflictGuard
{
    /** @param EntityRepository<\MuckiFacilityPlugin\Core\Content\BackupRepository\BackupRepositoryCollection>|null $facilityRepositories */
    public function __construct(private readonly ?EntityRepository $facilityRepositories)
    {
    }

    /**
     * @throws HookConfigurationException facility_unavailable, facility_repository_not_found or legacy_hook_conflict
     */
    public function assertNoConflict(string $facilityRepositoryId, Context $context): void
    {
        if ($this->facilityRepositories === null) {
            throw new HookConfigurationException(
                HookConfigurationException::FACILITY_UNAVAILABLE,
                'The Facility backup repository service is unavailable.'
            );
        }
        $repository = $this->facilityRepositories->search(new Criteria([$facilityRepositoryId]), $context)->first();
        if (!$repository instanceof BackupRepositoryEntity) {
            throw new HookConfigurationException(
                HookConfigurationException::FACILITY_REPOSITORY_NOT_FOUND,
                'The Facility backup repository does not exist.'
            );
        }
        $fields = $this->conflictingFields($repository);
        if ($fields !== []) {
            throw HookConfigurationException::legacyConflict($fields);
        }
    }

    /** @return list<string> DAL names of the active legacy hook fields */
    public function conflictingFields(BackupRepositoryEntity $repository): array
    {
        $fields = [];
        if ($repository->getPreBackupCommandType() !== 'none') {
            $fields[] = 'preBackupCommandType';
        }
        if ($repository->getPostBackupCommandType() !== 'none') {
            $fields[] = 'postBackupCommandType';
        }

        return $fields;
    }
}
