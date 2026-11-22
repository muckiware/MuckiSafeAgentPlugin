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
namespace MuckiSafeAgentPlugin\tests\Unit\Core\Content;

use MuckiSafeAgentPlugin\Core\Content\AgentAction\AgentActionEntity;
use MuckiSafeAgentPlugin\Core\Content\AgentBackupRun\AgentBackupRunEntity;
use MuckiSafeAgentPlugin\Core\Content\AgentHost\AgentHostEntity;
use MuckiSafeAgentPlugin\Core\Content\AgentJob\AgentJobEntity;
use MuckiSafeAgentPlugin\Core\Content\AgentNonce\AgentNonceEntity;
use MuckiSafeAgentPlugin\Core\Content\AgentRepositoryHook\AgentRepositoryHookEntity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;

final class EntityIdTest extends TestCase
{
    /** @return iterable<string, array{class-string<Entity>}> */
    public static function entities(): iterable
    {
        yield 'agent action' => [AgentActionEntity::class];
        yield 'agent backup run' => [AgentBackupRunEntity::class];
        yield 'agent host' => [AgentHostEntity::class];
        yield 'agent job' => [AgentJobEntity::class];
        yield 'agent nonce' => [AgentNonceEntity::class];
        yield 'agent repository hook' => [AgentRepositoryHookEntity::class];
    }

    /** @param class-string<Entity> $class */
    #[DataProvider('entities')]
    public function testIdIsADeclaredPropertyAndNotDynamic(string $class): void
    {
        self::assertTrue(property_exists($class, 'id'), $class . ' must declare $id');

        $entity = new $class();
        $entity->assign(['id' => 'a4b1c2d3e4f5a6b7c8d9e0f1a2b3c4d5']);

        self::assertSame('a4b1c2d3e4f5a6b7c8d9e0f1a2b3c4d5', $entity->getUniqueIdentifier());
        self::assertSame('a4b1c2d3e4f5a6b7c8d9e0f1a2b3c4d5', $entity->get('id'));
        self::assertTrue(method_exists($entity, 'getId'), $class . ' must expose getId()');
    }
}
