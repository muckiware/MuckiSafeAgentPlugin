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
namespace MuckiSafeAgentPlugin\tests\Integration\Controller\Agent;

use Doctrine\DBAL\Connection;
use MuckiSafeAgentPlugin\Migration\Migration1795132800CreateAgentSchema;
use MuckiSafeAgentPlugin\Migration\Migration1795305600CreateSecretState;
use MuckiSafeAgentPlugin\Service\Auth\AgentErrorCode;
use MuckiSafeAgentPlugin\Service\Auth\RequestSignature;
use MuckiSafeAgentPlugin\Service\Auth\ResponseSignature;
use MuckiSafeAgentPlugin\Service\Auth\ResponseVerdict;
use MuckiSafeAgentPlugin\Service\Auth\SignatureEnvelope;
use MuckiSafeAgentPlugin\Service\Protocol\ProtocolSchemaValidator;
use MuckiSafeAgentPlugin\Service\Secret\AgentProvisioner;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Test\TestCaseBase\KernelLifecycleManager;
use Shopware\Core\Framework\Test\TestCaseBase\KernelTestBehaviour;
use Shopware\Core\Framework\Test\TestCaseHelper\TestBrowser;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouterInterface;

final class JobLeaseControllerHttpTest extends TestCase
{
    use KernelTestBehaviour;

    private const TOKEN = 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';
    private const VERSION = 4;

    private Connection $connection;
    /** @var array{id: string, keyId: string, secret: string} */
    private array $agent;
    private string $secretBytes;
    private string $jobId;

    protected function setUp(): void
    {
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->dropSchema();
        (new Migration1795132800CreateAgentSchema())->update($connection);
        (new Migration1795305600CreateSecretState())->update($connection);
        $provisioner = self::getContainer()->get(AgentProvisioner::class);
        self::assertInstanceOf(AgentProvisioner::class, $provisioner);
        $this->agent = $provisioner->provision('job-lease-http-host');
        $this->secretBytes = sodium_base642bin($this->agent['secret'], SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING, '');
        $this->jobId = $this->insertClaimedJob();
    }

    protected function tearDown(): void
    {
        $this->dropSchema();
    }

    public function testSignedStartAndHeartbeatReturnSchemaBackedResponsesAndMutateOnlyTheClaimedJob(): void
    {
        $body = $this->validBody();
        $start = $this->request('start', $body);
        self::assertSame(Response::HTTP_OK, $start->getResponse()->getStatusCode(), (string) $start->getResponse()->getContent());
        $startPayload = $this->json($start->getResponse());
        self::assertSame(self::VERSION, $startPayload['leaseVersion']);
        self::assertSame(
            ['leaseVersion', 'leaseExpiresAt', 'phaseDeadline', 'executionDeadline', 'stopDeadline'],
            array_keys($startPayload)
        );
        $this->assertSchema('job-start-response', $start->getResponse());
        $this->assertSigned($start, 'start');

        $heartbeat = $this->request('heartbeat', $body);
        self::assertSame(Response::HTTP_OK, $heartbeat->getResponse()->getStatusCode());
        $heartbeatPayload = $this->json($heartbeat->getResponse());
        self::assertSame(self::VERSION, $heartbeatPayload['leaseVersion']);
        self::assertFalse($heartbeatPayload['stopRequested']);
        self::assertArrayNotHasKey('stopDeadline', $heartbeatPayload);
        $this->assertSchema('job-heartbeat-response', $heartbeat->getResponse());
        $this->assertSigned($heartbeat, 'heartbeat');

        $row = $this->connection->fetchAssociative(
            'SELECT `status`, `heartbeat_at` FROM `muwa_agent_job` WHERE `id` = UNHEX(:id)',
            ['id' => $this->jobId]
        );
        self::assertIsArray($row);
        self::assertSame('running', $row['status']);
        self::assertNotNull($row['heartbeat_at']);
    }

    public function testExactBodyLimitIsAcceptedForBothRoutesAndLimitPlusOneIsSigned413(): void
    {
        $base = $this->validBody();
        $atLimit = $base . str_repeat(' ', 4096 - strlen($base));
        self::assertSame(4096, strlen($atLimit));

        foreach (['start', 'heartbeat'] as $action) {
            $accepted = $this->request($action, $atLimit);
            self::assertSame(Response::HTTP_OK, $accepted->getResponse()->getStatusCode());
            self::assertStringContainsString('no-store', (string) $accepted->getResponse()->headers->get('Cache-Control'));
            $this->assertSigned($accepted, $action);
        }

        foreach (['start', 'heartbeat'] as $action) {
            $tooLarge = $this->request($action, $atLimit . ' ');
            self::assertSame(Response::HTTP_REQUEST_ENTITY_TOO_LARGE, $tooLarge->getResponse()->getStatusCode());
            self::assertSame(AgentErrorCode::BodyTooLarge->value, $this->json($tooLarge->getResponse())['code']);
            self::assertStringContainsString('no-store', (string) $tooLarge->getResponse()->headers->get('Cache-Control'));
            $this->assertSigned($tooLarge, $action);
        }
    }

    public function testCancellationHeartbeatReturnsSignedStopRequestWithoutExtendingLease(): void
    {
        $oldLease = (new \DateTimeImmutable('-1 minute', new \DateTimeZone('UTC')))
            ->format(Defaults::STORAGE_DATE_TIME_FORMAT);
        $this->connection->update('muwa_agent_job', [
            'status' => 'cancel_requested',
            'lease_expires_at' => $oldLease,
        ], ['id' => Uuid::fromHexToBytes($this->jobId)]);

        $browser = $this->request('heartbeat', $this->validBody());
        self::assertSame(Response::HTTP_OK, $browser->getResponse()->getStatusCode());
        $payload = $this->json($browser->getResponse());
        self::assertTrue($payload['stopRequested']);
        self::assertArrayHasKey('stopDeadline', $payload);
        self::assertStringContainsString('no-store', (string) $browser->getResponse()->headers->get('Cache-Control'));
        $this->assertSchema('job-heartbeat-response', $browser->getResponse());
        $this->assertSigned($browser, 'heartbeat');
        self::assertSame($oldLease, $this->connection->fetchOne(
            'SELECT `lease_expires_at` FROM `muwa_agent_job` WHERE `id` = UNHEX(:id)',
            ['id' => $this->jobId]
        ));
    }

    public function testRoutesExposeTheExactControllerContract(): void
    {
        $router = self::getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);
        $expected = [
            'api.action.muwa.agent.v1.job_start' => 'MuckiSafeAgentPlugin\\Controller\\Agent\\JobStartController::start',
            'api.action.muwa.agent.v1.job_heartbeat' => 'MuckiSafeAgentPlugin\\Controller\\Agent\\JobHeartbeatController::heartbeat',
        ];
        foreach ($expected as $name => $controller) {
            $route = $router->getRouteCollection()->get($name);
            self::assertNotNull($route);
            self::assertSame(['POST'], $route->getMethods());
            self::assertSame(['api'], $route->getDefault('_routeScope'));
            self::assertFalse($route->getDefault('auth_required'));
            self::assertSame($controller, $route->getDefault('_controller'));
        }
    }

    public function testMalformedSignedBodyReturnsStableSignedPayloadErrorWithoutMutation(): void
    {
        $browser = $this->request('start', '{"claimToken":"x","leaseVersion":4}');
        $response = $browser->getResponse();

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertSame(AgentErrorCode::PayloadInvalid->value, $this->json($response)['code']);
        $this->assertSchema('error-response', $response);
        $this->assertSigned($browser, 'start');
        self::assertSame('claimed', $this->jobStatus());
    }

    public function testStaleLeaseVersionReturnsStableSignedClaimConflict(): void
    {
        $body = json_encode(['claimToken' => self::TOKEN, 'leaseVersion' => self::VERSION - 1], JSON_THROW_ON_ERROR);
        $browser = $this->request('start', $body);
        $response = $browser->getResponse();

        self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
        self::assertSame(AgentErrorCode::ClaimConflict->value, $this->json($response)['code']);
        $this->assertSchema('error-response', $response);
        $this->assertSigned($browser, 'start');
        self::assertSame('claimed', $this->jobStatus());
    }

    private function request(string $action, string $body): TestBrowser
    {
        $path = $this->path($action);
        $timestamp = (string) (time() - 1);
        $nonce = 'job-lease-http-' . bin2hex(random_bytes(10));
        $envelope = SignatureEnvelope::forBody($path, $body, $timestamp, $nonce);
        $signature = hash_hmac('sha256', (new RequestSignature())->baseString('POST', $envelope), $this->secretBytes);
        $browser = KernelLifecycleManager::createBrowser(self::getKernel(), false);
        $browser->request('POST', $path, [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_MUCKI_AGENT_KEY' => $this->agent['keyId'],
            'HTTP_X_MUCKI_AGENT_TIMESTAMP' => $timestamp,
            'HTTP_X_MUCKI_AGENT_NONCE' => $nonce,
            'HTTP_X_MUCKI_AGENT_SIGNATURE' => $signature,
        ], $body);
        return $browser;
    }

    private function assertSigned(TestBrowser $browser, string $action): void
    {
        $nonce = $browser->getRequest()->headers->get('X-Mucki-Agent-Nonce');
        self::assertIsString($nonce);
        self::assertSame(
            ResponseVerdict::Accept,
            (new ResponseSignature())->classify($this->secretBytes, $nonce, $this->path($action), $browser->getResponse())
        );
    }

    private function assertSchema(string $name, Response $response): void
    {
        $schema = json_decode(
            (string) file_get_contents(dirname(__DIR__, 4) . '/resources/schema/protocol/v1/' . $name . '.schema.json'),
            false,
            32,
            JSON_THROW_ON_ERROR
        );
        $payload = json_decode((string) $response->getContent(), false, 32, JSON_THROW_ON_ERROR);
        self::assertInstanceOf(\stdClass::class, $schema);
        self::assertInstanceOf(\stdClass::class, $payload);
        self::assertNull((new ProtocolSchemaValidator())->firstViolation($schema, $payload));
    }

    private function validBody(): string
    {
        return json_encode(['claimToken' => self::TOKEN, 'leaseVersion' => self::VERSION], JSON_THROW_ON_ERROR);
    }

    private function path(string $action): string
    {
        return '/api/_action/muwa/agent/v1/jobs/' . $this->wireUuid($this->jobId) . '/' . $action;
    }

    private function jobStatus(): string
    {
        return (string) $this->connection->fetchOne(
            'SELECT `status` FROM `muwa_agent_job` WHERE `id` = UNHEX(:id)',
            ['id' => $this->jobId]
        );
    }

    /** @return array<string, mixed> */
    private function json(Response $response): array
    {
        $decoded = json_decode((string) $response->getContent(), true, 16, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        return $decoded;
    }

    private function insertClaimedJob(): string
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $runId = Uuid::randomHex();
        $actionId = Uuid::randomHex();
        $jobId = Uuid::randomHex();
        $timestamp = $now->format(Defaults::STORAGE_DATE_TIME_FORMAT);
        $this->connection->insert('muwa_agent_backup_run', [
            'id' => Uuid::fromHexToBytes($runId),
            'facility_repository_id' => Uuid::randomBytes(),
            'source' => 'facility',
            'idempotency_key' => Uuid::randomHex(),
            'status' => 'running',
            'started_at' => $timestamp,
            'created_at' => $timestamp,
        ]);
        $definition = '{"version":1,"steps":[]}';
        $this->connection->insert('muwa_agent_action', [
            'id' => Uuid::fromHexToBytes($actionId),
            'technical_name' => Uuid::randomHex(),
            'label' => 'HTTP lease action',
            'enabled' => 1,
            'definition' => $definition,
            'definition_hash' => hash('sha256', $definition, true),
            'required_capabilities' => '[]',
            'default_timeout_seconds' => 60,
            'max_timeout_seconds' => 600,
            'retry_policy' => 'none',
            'idempotency_class' => 'safe',
            'created_at' => $timestamp,
        ]);
        $this->connection->insert('muwa_agent_job', [
            'id' => Uuid::fromHexToBytes($jobId),
            'backup_run_id' => Uuid::fromHexToBytes($runId),
            'agent_action_id' => Uuid::fromHexToBytes($actionId),
            'facility_repository_id' => Uuid::randomBytes(),
            'phase' => 'pre',
            'action_definition' => $definition,
            'action_hash' => hash('sha256', $definition, true),
            'payload' => '{}',
            'idempotency_class' => 'safe',
            'idempotency_key' => Uuid::randomHex(),
            'status' => 'claimed',
            'target_agent_id' => Uuid::fromHexToBytes($this->agent['id']),
            'claim_token_hash' => hash('sha256', self::TOKEN, true),
            'lease_version' => self::VERSION,
            'attempt' => 1,
            'max_attempts' => 3,
            'available_at' => $now->modify('-1 minute')->format(Defaults::STORAGE_DATE_TIME_FORMAT),
            'phase_deadline' => $now->modify('+10 minutes')->format(Defaults::STORAGE_DATE_TIME_FORMAT),
            'execution_deadline' => $now->modify('+9 minutes 45 seconds')->format(Defaults::STORAGE_DATE_TIME_FORMAT),
            'stop_deadline' => $now->modify('+9 minutes 50 seconds')->format(Defaults::STORAGE_DATE_TIME_FORMAT),
            'claimed_at' => $timestamp,
            'lease_expires_at' => $now->modify('+1 minute')->format(Defaults::STORAGE_DATE_TIME_FORMAT),
            'created_at' => $timestamp,
        ]);
        return $jobId;
    }

    private function wireUuid(string $hex): string
    {
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
    }

    private function dropSchema(): void
    {
        $this->connection->executeStatement(
            'DROP TABLE IF EXISTS `muwa_agent_nonce`, `muwa_agent_job`, `muwa_agent_backup_run`, '
            . '`muwa_agent_repository_hook`, `muwa_agent_action`, `muwa_agent_host`, `muwa_agent_secret_state`'
        );
    }
}
