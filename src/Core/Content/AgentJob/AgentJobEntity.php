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

use Shopware\Core\Framework\DataAbstractionLayer\Entity;

final class AgentJobEntity extends Entity
{
    protected string $backupRunId;
    protected string $agentActionId;
    protected string $facilityRepositoryId;
    protected string $phase;
    protected string $actionDefinition;
    protected string $actionHash;
    protected string $payload;
    protected string $idempotencyKey;
    protected string $status;
    protected string $targetAgentId;
    protected int $leaseVersion;
    protected int $attempt;
    protected int $maxAttempts;
    protected \DateTimeInterface $availableAt;
    protected \DateTimeInterface $phaseDeadline;
    protected \DateTimeInterface $executionDeadline;
    protected \DateTimeInterface $stopDeadline;
    protected ?\DateTimeInterface $claimedAt = null;
    protected ?\DateTimeInterface $leaseExpiresAt = null;
    protected ?\DateTimeInterface $heartbeatAt = null;
    protected ?\DateTimeInterface $finishedAt = null;
    protected ?string $completionFingerprint = null;
    protected ?string $completionAgentId = null;
    protected ?int $acceptedLeaseVersion = null;
    protected ?string $resultCode = null;
    protected ?string $resultSummary = null;
    protected ?string $stdoutTail = null;
    protected ?string $stderrTail = null;

    public function getBackupRunId(): string
    {
        return $this->backupRunId;
    }

    public function setBackupRunId(string $backupRunId): void
    {
        $this->backupRunId = $backupRunId;
    }

    public function getAgentActionId(): string
    {
        return $this->agentActionId;
    }

    public function setAgentActionId(string $agentActionId): void
    {
        $this->agentActionId = $agentActionId;
    }

    public function getFacilityRepositoryId(): string
    {
        return $this->facilityRepositoryId;
    }

    public function setFacilityRepositoryId(string $facilityRepositoryId): void
    {
        $this->facilityRepositoryId = $facilityRepositoryId;
    }

    public function getPhase(): string
    {
        return $this->phase;
    }

    public function setPhase(string $phase): void
    {
        $this->phase = $phase;
    }

    public function getActionDefinition(): string
    {
        return $this->actionDefinition;
    }

    public function setActionDefinition(string $actionDefinition): void
    {
        $this->actionDefinition = $actionDefinition;
    }

    public function getActionHash(): string
    {
        return $this->actionHash;
    }

    public function setActionHash(string $actionHash): void
    {
        $this->actionHash = $actionHash;
    }

    public function getPayload(): string
    {
        return $this->payload;
    }

    public function setPayload(string $payload): void
    {
        $this->payload = $payload;
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

    public function getTargetAgentId(): string
    {
        return $this->targetAgentId;
    }

    public function setTargetAgentId(string $targetAgentId): void
    {
        $this->targetAgentId = $targetAgentId;
    }

    public function getLeaseVersion(): int
    {
        return $this->leaseVersion;
    }

    public function setLeaseVersion(int $leaseVersion): void
    {
        $this->leaseVersion = $leaseVersion;
    }

    public function getAttempt(): int
    {
        return $this->attempt;
    }

    public function setAttempt(int $attempt): void
    {
        $this->attempt = $attempt;
    }

    public function getMaxAttempts(): int
    {
        return $this->maxAttempts;
    }

    public function setMaxAttempts(int $maxAttempts): void
    {
        $this->maxAttempts = $maxAttempts;
    }

    public function getAvailableAt(): \DateTimeInterface
    {
        return $this->availableAt;
    }

    public function setAvailableAt(\DateTimeInterface $availableAt): void
    {
        $this->availableAt = $availableAt;
    }

    public function getPhaseDeadline(): \DateTimeInterface
    {
        return $this->phaseDeadline;
    }

    public function setPhaseDeadline(\DateTimeInterface $phaseDeadline): void
    {
        $this->phaseDeadline = $phaseDeadline;
    }

    public function getExecutionDeadline(): \DateTimeInterface
    {
        return $this->executionDeadline;
    }

    public function setExecutionDeadline(\DateTimeInterface $executionDeadline): void
    {
        $this->executionDeadline = $executionDeadline;
    }

    public function getStopDeadline(): \DateTimeInterface
    {
        return $this->stopDeadline;
    }

    public function setStopDeadline(\DateTimeInterface $stopDeadline): void
    {
        $this->stopDeadline = $stopDeadline;
    }

    public function getClaimedAt(): ?\DateTimeInterface
    {
        return $this->claimedAt;
    }

    public function setClaimedAt(?\DateTimeInterface $claimedAt): void
    {
        $this->claimedAt = $claimedAt;
    }

    public function getLeaseExpiresAt(): ?\DateTimeInterface
    {
        return $this->leaseExpiresAt;
    }

    public function setLeaseExpiresAt(?\DateTimeInterface $leaseExpiresAt): void
    {
        $this->leaseExpiresAt = $leaseExpiresAt;
    }

    public function getHeartbeatAt(): ?\DateTimeInterface
    {
        return $this->heartbeatAt;
    }

    public function setHeartbeatAt(?\DateTimeInterface $heartbeatAt): void
    {
        $this->heartbeatAt = $heartbeatAt;
    }

    public function getFinishedAt(): ?\DateTimeInterface
    {
        return $this->finishedAt;
    }

    public function setFinishedAt(?\DateTimeInterface $finishedAt): void
    {
        $this->finishedAt = $finishedAt;
    }

    public function getCompletionFingerprint(): ?string
    {
        return $this->completionFingerprint;
    }

    public function setCompletionFingerprint(?string $completionFingerprint): void
    {
        $this->completionFingerprint = $completionFingerprint;
    }

    public function getCompletionAgentId(): ?string
    {
        return $this->completionAgentId;
    }

    public function setCompletionAgentId(?string $completionAgentId): void
    {
        $this->completionAgentId = $completionAgentId;
    }

    public function getAcceptedLeaseVersion(): ?int
    {
        return $this->acceptedLeaseVersion;
    }

    public function setAcceptedLeaseVersion(?int $acceptedLeaseVersion): void
    {
        $this->acceptedLeaseVersion = $acceptedLeaseVersion;
    }

    public function getResultCode(): ?string
    {
        return $this->resultCode;
    }

    public function setResultCode(?string $resultCode): void
    {
        $this->resultCode = $resultCode;
    }

    public function getResultSummary(): ?string
    {
        return $this->resultSummary;
    }

    public function setResultSummary(?string $resultSummary): void
    {
        $this->resultSummary = $resultSummary;
    }

    public function getStdoutTail(): ?string
    {
        return $this->stdoutTail;
    }

    public function setStdoutTail(?string $stdoutTail): void
    {
        $this->stdoutTail = $stdoutTail;
    }

    public function getStderrTail(): ?string
    {
        return $this->stderrTail;
    }

    public function setStderrTail(?string $stderrTail): void
    {
        $this->stderrTail = $stderrTail;
    }
}
