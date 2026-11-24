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
namespace MuckiSafeAgentPlugin\tests\Unit\Controller\Admin;

use MuckiSafeAgentPlugin\Controller\Admin\AgentProvisionController;
use MuckiSafeAgentPlugin\Service\Secret\AgentProvisioner;
use MuckiSafeAgentPlugin\Service\Secret\AgentSecretRotator;
use MuckiSafeAgentPlugin\Service\Secret\MasterKeyReencryptor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shopware\Core\PlatformRequest;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class AgentProvisionControllerTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function actionMethods(): iterable
    {
        yield 'provision' => ['provision', 'muwa_agent.provision'];
        yield 'rotate' => ['rotate', 'muwa_agent.rotate'];
        yield 'revoke' => ['revoke', 'muwa_agent.revoke'];
        yield 'reencrypt' => ['reencrypt', 'muwa_agent.master_key_reencrypt'];
    }

    #[DataProvider('actionMethods')]
    public function testEveryActionRequiresItsDedicatedAcl(string $method, string $acl): void
    {
        $attributes = (new \ReflectionMethod(AgentProvisionController::class, $method))->getAttributes(Route::class);
        self::assertCount(1, $attributes);
        $defaults = $attributes[0]->newInstance()->getDefaults();
        self::assertSame([$acl], $defaults[PlatformRequest::ATTRIBUTE_ACL] ?? null);
    }

    public function testSuccessfulProvisionResponseContainsSecretAndNoStore(): void
    {
        $provisioner = $this->createMock(AgentProvisioner::class);
        $provisioner->method('provision')->willReturn([
            'id' => str_repeat('a', 32),
            'keyId' => str_repeat('b', 32),
            'secret' => str_repeat('c', 43),
        ]);
        $controller = new AgentProvisionController(
            new NullLogger(),
            $provisioner,
            $this->createMock(AgentSecretRotator::class),
            $this->createMock(MasterKeyReencryptor::class)
        );

        $response = $controller->provision(new Request(content: '{"name":"host-a"}'));
        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertSame(str_repeat('c', 43), $payload['secret']);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidProvisionPayloads(): iterable
    {
        yield 'malformed json' => ['{"name"'];
        yield 'array name' => ['{"name":[]}'];
        yield 'boolean name' => ['{"name":true}'];
        yield 'float name' => ['{"name":1.5}'];
        yield 'missing name' => ['{}'];
    }

    #[DataProvider('invalidProvisionPayloads')]
    public function testProvisionRejectsMalformedOrNonStringNameWithoutCallingService(string $content): void
    {
        $provisioner = $this->createMock(AgentProvisioner::class);
        $provisioner->expects(self::never())->method('provision');
        $controller = new AgentProvisionController(
            new NullLogger(),
            $provisioner,
            $this->createMock(AgentSecretRotator::class),
            $this->createMock(MasterKeyReencryptor::class)
        );

        $response = $controller->provision(new Request(content: $content));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('{"error":"invalid_request"}', $response->getContent());
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidReencryptPayloads(): iterable
    {
        yield 'version numeric string' => ['{"fromVersion":"1"}'];
        yield 'version float' => ['{"fromVersion":1.5}'];
        yield 'version bool' => ['{"fromVersion":true}'];
        yield 'batch numeric string' => ['{"fromVersion":1,"batchSize":"10"}'];
        yield 'batch float' => ['{"fromVersion":1,"batchSize":2.5}'];
        yield 'batch bool' => ['{"fromVersion":1,"batchSize":false}'];
        yield 'after id integer' => ['{"fromVersion":1,"afterId":42}'];
        yield 'client cursor string' => ['{"fromVersion":1,"afterId":"00000000000000000000000000000000"}'];
        yield 'malformed json' => ['{"fromVersion"'];
    }

    #[DataProvider('invalidReencryptPayloads')]
    public function testReencryptRejectsNonExactTypesWithoutCallingService(string $content): void
    {
        $reencryptor = $this->createMock(MasterKeyReencryptor::class);
        $reencryptor->expects(self::never())->method('reencryptBatch');
        $controller = new AgentProvisionController(
            new NullLogger(),
            $this->createMock(AgentProvisioner::class),
            $this->createMock(AgentSecretRotator::class),
            $reencryptor
        );

        $response = $controller->reencrypt(new Request(content: $content));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('{"error":"invalid_request"}', $response->getContent());
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function testFailureResponseDoesNotLeakSecretOrKeyring(): void
    {
        $provisioner = $this->createMock(AgentProvisioner::class);
        $provisioner->method('provision')->willThrowException(new \RuntimeException('secret=LEAK key=LEAK'));
        $controller = new AgentProvisionController(
            new NullLogger(),
            $provisioner,
            $this->createMock(AgentSecretRotator::class),
            $this->createMock(MasterKeyReencryptor::class)
        );

        $response = $controller->provision(new Request(content: '{"name":"host-a"}'));

        self::assertSame(500, $response->getStatusCode());
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertSame('{"error":"operation_failed"}', $response->getContent());
        self::assertStringNotContainsString('LEAK', (string) $response->getContent());
    }
}
