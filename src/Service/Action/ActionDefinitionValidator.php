<?php declare(strict_types=1);
/**
 * MuckiSafeAgentPlugin
 * @category SW6 Plugin
 * @package MuckiSafeAgent
 * @copyright Copyright (c) 2026 by Muckiware
 * @license MIT
 * @author Muckiware
 */

namespace MuckiSafeAgentPlugin\Service\Action;

final class ActionDefinitionValidator
{
    private const int MAX_STEPS = 64;
    private const string ALIAS_PATTERN = '/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/D';

    public function validate(\stdClass $definition): void
    {
        $this->exactFields($definition, ['version', 'steps'], [], '$');
        if ($definition->version !== 1) {
            throw new ActionDefinitionException('$.version must be integer 1.');
        }
        if (!is_array($definition->steps) || $definition->steps === [] || count($definition->steps) > self::MAX_STEPS) {
            throw new ActionDefinitionException('$.steps must contain between 1 and 64 steps.');
        }
        foreach ($definition->steps as $index => $step) {
            if (!$step instanceof \stdClass) {
                throw new ActionDefinitionException(sprintf('$.steps[%d] must be an object.', $index));
            }
            $this->validateStep($step, $index);
        }
        $canonical = CanonicalJson::encode($definition);
        if (strlen($canonical) > 65536) {
            throw new ActionDefinitionException('Canonical action exceeds 65536 bytes.');
        }
    }

    public function canonicalize(\stdClass $definition): string
    {
        $this->validate($definition);
        return CanonicalJson::encode($definition);
    }

    public function hash(\stdClass $definition): string
    {
        return hash('sha256', $this->canonicalize($definition));
    }

    private function validateStep(\stdClass $step, int $index): void
    {
        $primitive = $step->primitive ?? null;
        if (!is_string($primitive)) {
            throw new ActionDefinitionException(sprintf('$.steps[%d].primitive is unknown.', $index));
        }
        $path = sprintf('$.steps[%d]', $index);
        switch ($primitive) {
            case 'service.control':
                $this->exactFields($step, ['primitive', 'serviceAlias', 'operation'], [], $path);
                $this->alias($step->serviceAlias, $path . '.serviceAlias');
                if (!in_array($step->operation, ['start', 'stop', 'restart'], true)) {
                    throw new ActionDefinitionException($path . '.operation must be start, stop or restart.');
                }
                return;
            case 'service.wait-http':
                $this->exactFields($step, ['primitive', 'endpointAlias', 'expectedStatuses'], [], $path);
                $this->alias($step->endpointAlias, $path . '.endpointAlias');
                $this->statuses($step->expectedStatuses, $path . '.expectedStatuses');
                return;
            case 'docker.container.exec':
                $this->exactFields($step, ['primitive', 'containerAlias', 'commandAlias', 'arguments'], [], $path);
                $this->alias($step->containerAlias, $path . '.containerAlias');
                $this->alias($step->commandAlias, $path . '.commandAlias');
                if (!$step->arguments instanceof \stdClass || get_object_vars($step->arguments) !== []) {
                    throw new ActionDefinitionException($path . '.arguments must be an empty object in V1.');
                }
                return;
            case 'filesystem.acl.read':
                $this->exactFields($step, ['primitive', 'pathAlias', 'principalAlias'], ['relativePath'], $path);
                $this->alias($step->pathAlias, $path . '.pathAlias');
                $this->alias($step->principalAlias, $path . '.principalAlias');
                if (property_exists($step, 'relativePath')) {
                    $this->relativePath($step->relativePath, $path . '.relativePath');
                }
                return;
            case 'filesystem.assert-readable':
                $this->exactFields($step, ['primitive', 'checkAlias'], [], $path);
                $this->alias($step->checkAlias, $path . '.checkAlias');
                return;
            case 'file.write-pipeline':
                $this->exactFields($step, ['primitive', 'sourceCommandAlias', 'destinationAlias', 'transforms', 'nameFromRunId'], [], $path);
                $this->alias($step->sourceCommandAlias, $path . '.sourceCommandAlias');
                $this->alias($step->destinationAlias, $path . '.destinationAlias');
                if ($step->transforms !== ['gzip']) {
                    throw new ActionDefinitionException($path . '.transforms must be exactly ["gzip"].');
                }
                if ($step->nameFromRunId !== true) {
                    throw new ActionDefinitionException($path . '.nameFromRunId must be true.');
                }
                return;
            case 'file.retention':
                $this->exactFields($step, ['primitive', 'pathAlias', 'manifestAlias', 'keep'], [], $path);
                $this->alias($step->pathAlias, $path . '.pathAlias');
                $this->alias($step->manifestAlias, $path . '.manifestAlias');
                if (!is_int($step->keep) || $step->keep < 1 || $step->keep > 10000) {
                    throw new ActionDefinitionException($path . '.keep must be an integer from 1 to 10000.');
                }
                return;
            default:
                throw new ActionDefinitionException($path . '.primitive is unknown.');
        }
    }

    private function alias(mixed $value, string $path): void
    {
        if (!is_string($value) || strlen($value) > 64 || preg_match(self::ALIAS_PATTERN, $value) !== 1) {
            throw new ActionDefinitionException($path . ' is not a canonical policy alias.');
        }
    }

    private function relativePath(mixed $value, string $path): void
    {
        if (!is_string($value) || $value === '' || strlen($value) > 4096 || preg_match('//u', $value) !== 1
            || str_starts_with($value, '/') || str_contains($value, '\\') || preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
            throw new ActionDefinitionException($path . ' is not a safe relative path.');
        }
        if ($value === '.') {
            return;
        }
        foreach (explode('/', $value) as $component) {
            if ($component === '' || $component === '.' || $component === '..') {
                throw new ActionDefinitionException($path . ' is not a safe relative path.');
            }
        }
    }

    private function statuses(mixed $value, string $path): void
    {
        if (!is_array($value) || $value === [] || count($value) > 16) {
            throw new ActionDefinitionException($path . ' must contain 1 to 16 statuses.');
        }
        $previous = 99;
        foreach ($value as $status) {
            if (!is_int($status) || $status < 100 || $status > 599 || $status <= $previous) {
                throw new ActionDefinitionException($path . ' must contain unique ascending integers from 100 to 599.');
            }
            $previous = $status;
        }
    }

    /**
     * @param list<string> $required
     * @param list<string> $optional
     */
    private function exactFields(\stdClass $object, array $required, array $optional, string $path): void
    {
        $actual = array_keys(get_object_vars($object));
        foreach ($required as $field) {
            if (!in_array($field, $actual, true)) {
                throw new ActionDefinitionException($path . ' has missing or unknown fields.');
            }
        }
        foreach ($actual as $field) {
            if (!in_array($field, $required, true) && !in_array($field, $optional, true)) {
                throw new ActionDefinitionException($path . ' has missing or unknown fields.');
            }
        }
    }
}
