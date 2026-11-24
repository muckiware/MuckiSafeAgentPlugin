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

use MuckiSafeAgentPlugin\Core\Content\AgentHost\AgentHostEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

final class AgentRepositoryHookEntity extends Entity
{
    use EntityIdTrait;

    protected string $facilityRepositoryId;
    protected string $phase;
    protected string $agentHostId;
    protected string $agentActionId;
    protected string $parameters;
    protected int $timeoutSeconds;
    protected bool $failOnError;
    protected bool $enabled;
    protected ?AgentHostEntity $agentHost = null;

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

    public function getAgentHostId(): string
    {
        return $this->agentHostId;
    }

    public function setAgentHostId(string $agentHostId): void
    {
        $this->agentHostId = $agentHostId;
    }

    public function getAgentActionId(): string
    {
        return $this->agentActionId;
    }

    public function setAgentActionId(string $agentActionId): void
    {
        $this->agentActionId = $agentActionId;
    }

    public function getParameters(): string
    {
        return $this->parameters;
    }

    public function setParameters(string $parameters): void
    {
        $this->parameters = $parameters;
    }

    public function getTimeoutSeconds(): int
    {
        return $this->timeoutSeconds;
    }

    public function setTimeoutSeconds(int $timeoutSeconds): void
    {
        $this->timeoutSeconds = $timeoutSeconds;
    }

    public function getFailOnError(): bool
    {
        return $this->failOnError;
    }

    public function setFailOnError(bool $failOnError): void
    {
        $this->failOnError = $failOnError;
    }

    public function getEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }

    public function getAgentHost(): ?AgentHostEntity
    {
        return $this->agentHost;
    }

    public function setAgentHost(?AgentHostEntity $agentHost): void
    {
        $this->agentHost = $agentHost;
    }
}
