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

use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

final class AgentActionEntity extends Entity
{
    use EntityIdTrait;

    protected string $technicalName;
    protected string $label;
    protected bool $enabled;
    protected string $definition;
    protected string $definitionHash;
    protected ?string $parameterSchema = null;
    /** @var list<string> */
    protected array $requiredCapabilities;
    protected int $defaultTimeoutSeconds;
    protected int $maxTimeoutSeconds;
    protected string $retryPolicy;
    protected string $idempotencyClass;

    public function getTechnicalName(): string
    {
        return $this->technicalName;
    }

    public function setTechnicalName(string $technicalName): void
    {
        $this->technicalName = $technicalName;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): void
    {
        $this->label = $label;
    }

    public function getEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }

    public function getDefinition(): string
    {
        return $this->definition;
    }

    public function setDefinition(string $definition): void
    {
        $this->definition = $definition;
    }

    public function getDefinitionHash(): string
    {
        return $this->definitionHash;
    }

    public function setDefinitionHash(string $definitionHash): void
    {
        $this->definitionHash = $definitionHash;
    }

    public function getParameterSchema(): ?string
    {
        return $this->parameterSchema;
    }

    public function setParameterSchema(?string $parameterSchema): void
    {
        $this->parameterSchema = $parameterSchema;
    }

    /** @return list<string> */
    public function getRequiredCapabilities(): array
    {
        return $this->requiredCapabilities;
    }

    /** @param list<string> $requiredCapabilities */
    public function setRequiredCapabilities(array $requiredCapabilities): void
    {
        $this->requiredCapabilities = $requiredCapabilities;
    }

    public function getDefaultTimeoutSeconds(): int
    {
        return $this->defaultTimeoutSeconds;
    }

    public function setDefaultTimeoutSeconds(int $defaultTimeoutSeconds): void
    {
        $this->defaultTimeoutSeconds = $defaultTimeoutSeconds;
    }

    public function getMaxTimeoutSeconds(): int
    {
        return $this->maxTimeoutSeconds;
    }

    public function setMaxTimeoutSeconds(int $maxTimeoutSeconds): void
    {
        $this->maxTimeoutSeconds = $maxTimeoutSeconds;
    }

    public function getRetryPolicy(): string
    {
        return $this->retryPolicy;
    }

    public function setRetryPolicy(string $retryPolicy): void
    {
        $this->retryPolicy = $retryPolicy;
    }

    public function getIdempotencyClass(): string
    {
        return $this->idempotencyClass;
    }

    public function setIdempotencyClass(string $idempotencyClass): void
    {
        $this->idempotencyClass = $idempotencyClass;
    }

    /**
     * The plain JSON API encodes the whole struct before removing fields that
     * are not ApiAware; raw hash bytes would abort that encoding.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        $data = parent::jsonSerialize();
        unset($data['definitionHash']);

        return $data;
    }
}
