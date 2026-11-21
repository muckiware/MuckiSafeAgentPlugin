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

use Shopware\Core\Framework\DataAbstractionLayer\Entity;

final class AgentBackupRunEntity extends Entity
{
    protected string $facilityRepositoryId;
    protected string $source;
    protected string $idempotencyKey;
    protected string $status;
    protected \DateTimeInterface $startedAt;
    protected ?\DateTimeInterface $finishedAt = null;
    protected ?string $primaryErrorCode = null;
    protected ?string $primaryErrorSummary = null;

    public function getFacilityRepositoryId(): string
    {
        return $this->facilityRepositoryId;
    }

    public function setFacilityRepositoryId(string $facilityRepositoryId): void
    {
        $this->facilityRepositoryId = $facilityRepositoryId;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function setSource(string $source): void
    {
        $this->source = $source;
    }

    public function getIdempotencyKey(): string
    {
        return $this->idempotencyKey;
    }

    public function setIdempotencyKey(string $idempotencyKey): void
    {
        $this->idempotencyKey = $idempotencyKey;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
    }

    public function getStartedAt(): \DateTimeInterface
    {
        return $this->startedAt;
    }

    public function setStartedAt(\DateTimeInterface $startedAt): void
    {
        $this->startedAt = $startedAt;
    }

    public function getFinishedAt(): ?\DateTimeInterface
    {
        return $this->finishedAt;
    }

    public function setFinishedAt(?\DateTimeInterface $finishedAt): void
    {
        $this->finishedAt = $finishedAt;
    }

    public function getPrimaryErrorCode(): ?string
    {
        return $this->primaryErrorCode;
    }

    public function setPrimaryErrorCode(?string $primaryErrorCode): void
    {
        $this->primaryErrorCode = $primaryErrorCode;
    }

    public function getPrimaryErrorSummary(): ?string
    {
        return $this->primaryErrorSummary;
    }

    public function setPrimaryErrorSummary(?string $primaryErrorSummary): void
    {
        $this->primaryErrorSummary = $primaryErrorSummary;
    }
}
