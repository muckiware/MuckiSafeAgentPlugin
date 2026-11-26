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
use MuckiSafeAgentPlugin\Controller\Agent\JobClaimController;
use MuckiSafeAgentPlugin\Migration\Migration1795132800CreateAgentSchema;
use MuckiSafeAgentPlugin\Migration\Migration1795478400CreateRollbackState;
use MuckiSafeAgentPlugin\Migration\Migration1795305600CreateSecretState;
use MuckiSafeAgentPlugin\Service\Action\ActionDefinitionDecoder;
use MuckiSafeAgentPlugin\Service\Action\ActionValidator;
use MuckiSafeAgentPlugin\Service\Auth\AgentErrorCode;
use MuckiSafeAgentPlugin\Service\Auth\AgentProtocol;
use MuckiSafeAgentPlugin\Service\Auth\AuthenticatedRequest;
use MuckiSafeAgentPlugin\Service\Auth\RequestSignature;
use MuckiSafeAgentPlugin\Service\Auth\ResponseSignature;
use MuckiSafeAgentPlugin\Service\Auth\ResponseVerdict;
use MuckiSafeAgentPlugin\Service\Auth\SignatureEnvelope;
use MuckiSafeAgentPlugin\Service\Job\JobCreator;
use MuckiSafeAgentPlugin\Service\Protocol\ProtocolSchemaValidator;
use MuckiSafeAgentPlugin\Service\Secret\AgentProvisioner;
use MuckiSafeAgentPlugin\Subscriber\AgentAuthenticationSubscriber;
use MuckiSafeAgentPlugin\Subscriber\AgentResponseSigningSubscriber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Test\TestCaseBase\KernelLifecycleManager;
use Shopware\Core\Framework\Test\TestCaseBase\KernelTestBehaviour;
use Shopware\Core\Framework\Test\TestCaseHelper\TestBrowser;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class JobClaimControllerHttpTest extends TestCase
{
    use KernelTestBehaviour;

    private const PATH = '/api/_action/muwa/agent/v1/jobs/claim';

    private Connection $connection;
    /** @var array{id: string, keyId: string, secret: string} */
    private array $agent;
    private string $secretBytes;

    protected function setUp(): void
    {
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->dropSchema();
        (new Migration1795132800CreateAgentSchema())->update($connection);
        (new Migration1795478400CreateRollbackState())->update($connection);
        (new Migration1795305600CreateSecretState())->update($connection);
        $provisioner = self::getContainer()->get(AgentProvisioner::class);
        self::assertInstanceOf(AgentProvisioner::class, $provisioner);
        $this->agent = $provisioner->provision('claim-http-host');
        $this->secretBytes = sodium_base642bin($this->agent['secret'], SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING, '');
    }

    protected function tearDown(): void
    {
        $this->dropSchema();
    }

    public function testValidSignedClaimReturnsExactEmptyResponseShapeAndValidSignature(): void
    {
        $browser = $this->request('{"maxJobs":1}');
        $response = $browser->getResponse();

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['job' => null], $this->json($response));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSigned($browser);
    }

    public function testValidSignedClaimReturnsExistingJobWithExactSchemaShape(): void
    {
        $jobId = $this->createClaimableJob();
        $browser = $this->request('{"maxJobs":1}');
        $response = $browser->getResponse();

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $payload = $this->json($response);
        self::assertIsArray($payload['job']);
        self::assertSame($this->wireUuid($jobId), $payload['job']['id']);
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D',
            $payload['job']['id']
        );
        self::assertSame([
            'id', 'claimToken', 'leaseVersion', 'leaseExpiresAt', 'phaseDeadline',
            'executionDeadline', 'stopDeadline', 'phase', 'idempotencyClass', 'action', 'parameters',
        ], array_keys($payload['job']));
        self::assertSame(['definitionCanonical', 'actionHash'], array_keys($payload['job']['action']));
        self::assertSame([], $payload['job']['parameters']);
        self::assertSame(1, preg_match('/^[A-Za-z0-9_-]{43}$/D', $payload['job']['claimToken']));
        $decoded = json_decode((string) $response->getContent(), false, 32, JSON_THROW_ON_ERROR);
        self::assertInstanceOf(\stdClass::class, $decoded);
        $schema = json_decode(
            (string) file_get_contents(dirname(__DIR__, 4) . '/resources/schema/protocol/v1/claim-response.schema.json'),
            false,
            32,
            JSON_THROW_ON_ERROR
        );
        self::assertInstanceOf(\stdClass::class, $schema);
        self::assertNull((new ProtocolSchemaValidator())->firstViolation($schema, $decoded));
        $this->assertSigned($browser);
    }

    #[DataProvider('invalidBodies')]
    public function testPayloadValidationIsStableAndSigned(string $body, AgentErrorCode $code): void
    {
        $browser = $this->request($body);
        $response = $browser->getResponse();

        self::assertSame($code->httpStatus(), $response->getStatusCode(), (string) $response->getContent());
        self::assertSame($code->value, $this->json($response)['code']);
        $this->assertSigned($browser);
    }

    /** @return iterable<string, array{string, AgentErrorCode}> */
    public static function invalidBodies(): iterable
    {
        yield 'malformed' => ['{', AgentErrorCode::PayloadInvalid];
        yield 'unknown' => ['{"maxJobs":1,"hostId":"00000000000000000000000000000000"}', AgentErrorCode::PayloadInvalid];
        yield 'duplicate' => ['{"maxJobs":1,"maxJobs":1}', AgentErrorCode::PayloadInvalid];
        yield 'noninteger' => ['{"maxJobs":1.0}', AgentErrorCode::PayloadInvalid];
        yield 'unsupported integer' => ['{"maxJobs":2}', AgentErrorCode::ProtocolUnsupported];
    }

    public function testExact1024ByteBodyIsAcceptedByAuthenticationAndDecoded(): void
    {
        $browser = $this->request(str_pad('{"maxJobs":1}', 1024, ' '));
        self::assertSame(Response::HTTP_OK, $browser->getResponse()->getStatusCode());
        self::assertSame(['job' => null], $this->json($browser->getResponse()));
        $this->assertSigned($browser);
    }

    public function test1025ByteBodyGetsSignedBodyTooLarge(): void
    {
        $browser = $this->request(str_pad('{"maxJobs":1}', 1025, ' '));
        $response = $browser->getResponse();
        self::assertSame(Response::HTTP_REQUEST_ENTITY_TOO_LARGE, $response->getStatusCode());
        self::assertSame(AgentErrorCode::BodyTooLarge->value, $this->json($response)['code']);
        $this->assertSigned($browser);
    }

    public function testBodyCannotOverrideAuthenticatedHostBinding(): void
    {
        $other = self::getContainer()->get(AgentProvisioner::class)->provision('other-claim-host');
        $body = json_encode(['maxJobs' => 1, 'hostId' => $other['id']], JSON_THROW_ON_ERROR);
        $browser = $this->request($body);

        self::assertSame(Response::HTTP_BAD_REQUEST, $browser->getResponse()->getStatusCode());
        self::assertSame(AgentErrorCode::PayloadInvalid->value, $this->json($browser->getResponse())['code']);
        $this->assertSigned($browser);
    }

    public function testRevocationAfterAuthenticationReturnsStableSignedRevokedError(): void
    {
        $nonce = 'claim-race-' . bin2hex(random_bytes(12));
        $request = Request::create(self::PATH, 'POST');
        $request->attributes->set(AgentAuthenticationSubscriber::AUTH_ATTRIBUTE, new AuthenticatedRequest(
            $this->agent['id'],
            $this->agent['keyId'],
            $this->secretBytes,
            AgentProtocol::ROUTE_JOB_CLAIM,
            self::PATH,
            (string) time(),
            $nonce,
            '{"maxJobs":1}'
        ));
        $this->connection->executeStatement(
            'UPDATE `muwa_agent_host` SET `revoked_at` = UTC_TIMESTAMP(3) WHERE `id` = UNHEX(:id)',
            ['id' => $this->agent['id']]
        );

        $controller = self::getContainer()->get(JobClaimController::class);
        self::assertInstanceOf(JobClaimController::class, $controller);
        $response = $controller->claim($request);
        $signer = self::getContainer()->get(AgentResponseSigningSubscriber::class);
        self::assertInstanceOf(AgentResponseSigningSubscriber::class, $signer);
        $signer->onKernelResponse(new ResponseEvent(
            self::getKernel(),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $response
        ));

        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
        self::assertSame(AgentErrorCode::Revoked->value, $this->json($response)['code']);
        self::assertSame(
            ResponseVerdict::Accept,
            (new ResponseSignature())->classify($this->secretBytes, $nonce, self::PATH, $response)
        );
    }

    private function createClaimableJob(): string
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $runId = Uuid::randomHex();
        $actionId = Uuid::randomHex();
        $definition = (new ActionDefinitionDecoder())->decode(
            '{"version":1,"steps":[{"primitive":"service.control","serviceAlias":"backup","operation":"stop"}]}'
        );
        $canonical = (new ActionValidator())->canonicalize($definition);
        $this->connection->update(
            'muwa_agent_host',
            ['capabilities' => '["service.control"]'],
            ['id' => Uuid::fromHexToBytes($this->agent['id'])]
        );
        $this->connection->insert('muwa_agent_backup_run', [
            'id' => Uuid::fromHexToBytes($runId),
            'facility_repository_id' => Uuid::randomBytes(),
            'source' => 'facility',
            'idempotency_key' => Uuid::randomHex(),
            'status' => 'running',
            'started_at' => $now->format(Defaults::STORAGE_DATE_TIME_FORMAT),
            'created_at' => $now->format(Defaults::STORAGE_DATE_TIME_FORMAT),
        ]);
        $this->connection->insert('muwa_agent_action', [
            'id' => Uuid::fromHexToBytes($actionId),
            'technical_name' => Uuid::randomHex(),
            'label' => 'HTTP action',
            'enabled' => 1,
            'definition' => $canonical,
            'definition_hash' => hash('sha256', $canonical, true),
            'required_capabilities' => '["service.control"]',
            'default_timeout_seconds' => 60,
            'max_timeout_seconds' => 600,
            'retry_policy' => 'none',
            'idempotency_class' => 'safe',
            'created_at' => $now->format(Defaults::STORAGE_DATE_TIME_FORMAT),
        ]);
        $creator = self::getContainer()->get(JobCreator::class);
        self::assertInstanceOf(JobCreator::class, $creator);

        return $creator->create(
            $runId,
            $actionId,
            Uuid::randomHex(),
            $this->agent['id'],
            'pre',
            $now->modify('-1 second'),
            $now->modify('+10 minutes'),
            2,
            Uuid::randomHex()
        );
    }

    private function request(string $body): TestBrowser
    {
        $browser = KernelLifecycleManager::createBrowser(self::getKernel(), false);
        $timestamp = (string) (time() - 1);
        $nonce = 'claim-http-' . bin2hex(random_bytes(12));
        $envelope = SignatureEnvelope::forBody(self::PATH, $body, $timestamp, $nonce);
        $signature = hash_hmac('sha256', (new RequestSignature())->baseString('POST', $envelope), $this->secretBytes);
        $browser->request('POST', self::PATH, [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_MUCKI_AGENT_KEY' => $this->agent['keyId'],
            'HTTP_X_MUCKI_AGENT_TIMESTAMP' => $timestamp,
            'HTTP_X_MUCKI_AGENT_NONCE' => $nonce,
            'HTTP_X_MUCKI_AGENT_SIGNATURE' => $signature,
        ], $body);

        return $browser;
    }

    /** @return array<string, mixed> */
    private function json(Response $response): array
    {
        $decoded = json_decode((string) $response->getContent(), true, 16, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        return $decoded;
    }

    private function assertSigned(TestBrowser $browser): void
    {
        $nonce = $browser->getRequest()->headers->get('X-Mucki-Agent-Nonce');
        self::assertIsString($nonce);
        self::assertSame(
            ResponseVerdict::Accept,
            (new ResponseSignature())->classify($this->secretBytes, $nonce, self::PATH, $browser->getResponse())
        );
    }

    private function wireUuid(string $hex): string
    {
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
    }

    private function dropSchema(): void
    {
        $this->connection->executeStatement(
            'DROP TABLE IF EXISTS `muwa_agent_rollback`, `muwa_agent_nonce`, `muwa_agent_job`, `muwa_agent_backup_run`, '
            . '`muwa_agent_repository_hook`, `muwa_agent_action`, `muwa_agent_host`, `muwa_agent_secret_state`'
        );
    }
}
