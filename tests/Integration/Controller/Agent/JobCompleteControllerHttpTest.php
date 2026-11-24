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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Test\TestCaseBase\KernelLifecycleManager;
use Shopware\Core\Framework\Test\TestCaseBase\KernelTestBehaviour;
use Shopware\Core\Framework\Test\TestCaseHelper\TestBrowser;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouterInterface;

/**
 * Task 13 RED: signed HTTP surface of the completion route — the exact
 * 262144-byte body limit in front of JSON decoding, signed stable errors for
 * payload violations, idempotent identical retries, contradicting 409s and
 * the cancellation outcome restrictions.
 */
final class JobCompleteControllerHttpTest extends TestCase
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
        $this->agent = $provisioner->provision('job-complete-http-host');
        $this->secretBytes = sodium_base642bin($this->agent['secret'], SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING, '');
        $this->jobId = $this->insertRunningJob();
    }

    protected function tearDown(): void
    {
        $this->dropSchema();
    }

    public function testSignedCompletionStoresTerminalStateAndReturnsSignedSchemaBackedResponse(): void
    {
        $body = json_encode([
            'claimToken' => self::TOKEN,
            'leaseVersion' => self::VERSION,
            'outcome' => 'succeeded',
            'resultCode' => 'ok',
            'summary' => 'Done.',
        ], JSON_THROW_ON_ERROR);
        $browser = $this->request($body);
        $response = $browser->getResponse();

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['accepted' => true, 'terminalStatus' => 'succeeded'], $this->json($response));
        $this->assertSchema('job-complete-response', $response);
        $this->assertSigned($browser);
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $row = $this->row();
        self::assertSame('succeeded', $row['status']);
        self::assertNull($row['stdout_tail']);
        self::assertNull($row['stderr_tail']);
        self::assertNull($row['claim_token_hash']);
        self::assertSame(self::VERSION, (int) $row['accepted_lease_version']);
        self::assertSame(self::VERSION + 1, (int) $row['lease_version']);
        self::assertSame(hash('sha256', self::TOKEN, true), $row['previous_claim_token_hash']);
        self::assertSame(Uuid::fromHexToBytes($this->agent['id']), $row['completion_agent_id']);
        self::assertSame(32, strlen((string) $row['completion_fingerprint']));
        self::assertNotNull($row['finished_at']);
    }

    public function testFailedCompletionWithBoundedTailsStoresDiagnostics(): void
    {
        $body = json_encode([
            'claimToken' => self::TOKEN,
            'leaseVersion' => self::VERSION,
            'outcome' => 'failed',
            'resultCode' => 'step_failed',
            'summary' => 'Step 2 failed.',
            'stdoutTail' => "out\n",
            'stdoutEncoding' => 'utf-8',
            'stderrTail' => base64_encode('err-ä'),
            'stderrEncoding' => 'base64',
        ], JSON_THROW_ON_ERROR);
        $browser = $this->request($body);

        self::assertSame(Response::HTTP_OK, $browser->getResponse()->getStatusCode(), (string) $browser->getResponse()->getContent());
        self::assertSame('failed', $this->json($browser->getResponse())['terminalStatus']);
        $this->assertSchema('job-complete-response', $browser->getResponse());
        $this->assertSigned($browser);

        $row = $this->row();
        self::assertSame('failed', $row['status']);
        self::assertSame('step_failed', $row['result_code']);
        self::assertSame('Step 2 failed.', $row['result_summary']);
        self::assertSame("out\n", $row['stdout_tail']);
        self::assertSame('err-ä', $row['stderr_tail']);
    }

    public function testExactBodyLimitIsAcceptedAndLimitPlusOneIsSigned413WithoutMutation(): void
    {
        $base = json_encode([
            'claimToken' => self::TOKEN,
            'leaseVersion' => self::VERSION,
            'outcome' => 'succeeded',
            'resultCode' => 'ok',
            'summary' => 'Done.',
        ], JSON_THROW_ON_ERROR);

        $tooLarge = $this->request($base . str_repeat(' ', 262145 - strlen($base)));
        self::assertSame(Response::HTTP_REQUEST_ENTITY_TOO_LARGE, $tooLarge->getResponse()->getStatusCode());
        self::assertSame(AgentErrorCode::BodyTooLarge->value, $this->json($tooLarge->getResponse())['code']);
        self::assertStringContainsString('no-store', (string) $tooLarge->getResponse()->headers->get('Cache-Control'));
        $this->assertSigned($tooLarge);
        self::assertSame('running', $this->jobStatus());

        $atLimit = $base . str_repeat(' ', 262144 - strlen($base));
        self::assertSame(262144, strlen($atLimit));
        $accepted = $this->request($atLimit);
        self::assertSame(Response::HTTP_OK, $accepted->getResponse()->getStatusCode());
        self::assertStringContainsString('no-store', (string) $accepted->getResponse()->headers->get('Cache-Control'));
        $this->assertSigned($accepted);
        self::assertSame('succeeded', $this->jobStatus());
    }

    public function testMalformedSignedBodyReturnsStableSignedPayloadErrorWithoutMutation(): void
    {
        $browser = $this->request('{"claimToken":"x","leaseVersion":4}');
        $response = $browser->getResponse();

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertSame(AgentErrorCode::PayloadInvalid->value, $this->json($response)['code']);
        $this->assertSchema('error-response', $response);
        $this->assertSigned($browser);
        self::assertSame('running', $this->jobStatus());
    }

    #[DataProvider('payloadViolations')]
    public function testContentViolationsReturnSignedPayloadErrorsWithoutMutation(string $body): void
    {
        $browser = $this->request($body);
        $response = $browser->getResponse();

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertSame(AgentErrorCode::PayloadInvalid->value, $this->json($response)['code']);
        $this->assertSchema('error-response', $response);
        $this->assertSigned($browser);
        self::assertSame('running', $this->jobStatus());
        self::assertNull($this->row()['completion_fingerprint']);
    }

    /** @return iterable<string, array{string}> */
    public static function payloadViolations(): iterable
    {
        $base = ['claimToken' => self::TOKEN, 'leaseVersion' => self::VERSION];

        yield 'succeeded with tail' => [json_encode(array_merge($base, [
            'outcome' => 'succeeded',
            'resultCode' => 'ok',
            'summary' => 'x',
            'stdoutTail' => 'out',
            'stdoutEncoding' => 'utf-8',
        ]), JSON_THROW_ON_ERROR)];
        yield 'ambiguous with tail' => [json_encode(array_merge($base, [
            'outcome' => 'ambiguous',
            'resultCode' => 'journal_started',
            'summary' => 'x',
            'stderrTail' => base64_encode('err'),
            'stderrEncoding' => 'base64',
        ]), JSON_THROW_ON_ERROR)];
        yield 'tail without its encoding' => [json_encode(array_merge($base, [
            'outcome' => 'failed',
            'resultCode' => 'step_failed',
            'summary' => 'x',
            'stdoutTail' => 'out',
        ]), JSON_THROW_ON_ERROR)];
        yield 'result code outside the allowlist' => [json_encode(array_merge($base, [
            'outcome' => 'succeeded',
            'resultCode' => 'success',
            'summary' => 'x',
        ]), JSON_THROW_ON_ERROR)];
        yield 'summary over the 4096 byte service limit' => [json_encode(array_merge($base, [
            'outcome' => 'succeeded',
            'resultCode' => 'ok',
            'summary' => str_repeat('ä', 4096),
        ]), JSON_THROW_ON_ERROR)];
        yield 'utf-8 tail over 65536 bytes' => [json_encode(array_merge($base, [
            'outcome' => 'failed',
            'resultCode' => 'step_failed',
            'summary' => 'x',
            'stdoutTail' => str_repeat('a', 65537),
            'stdoutEncoding' => 'utf-8',
        ]), JSON_THROW_ON_ERROR)];
        yield 'base64 tail decoding over 65536 bytes' => [json_encode(array_merge($base, [
            'outcome' => 'failed',
            'resultCode' => 'step_failed',
            'summary' => 'x',
            'stdoutTail' => str_repeat('A', 87384),
            'stdoutEncoding' => 'base64',
        ]), JSON_THROW_ON_ERROR)];
    }

    public function testIdenticalSignedRetryReturnsTheStoredStatusAndContradictionsConflict(): void
    {
        $first = json_encode([
            'claimToken' => self::TOKEN,
            'leaseVersion' => self::VERSION,
            'outcome' => 'failed',
            'resultCode' => 'step_failed',
            'summary' => 'Step 2 failed.',
        ], JSON_THROW_ON_ERROR);
        $browser = $this->request($first);
        self::assertSame(Response::HTTP_OK, $browser->getResponse()->getStatusCode());
        $rowAfterFirst = $this->row();

        $retry = $this->request($first);
        self::assertSame(Response::HTTP_OK, $retry->getResponse()->getStatusCode());
        self::assertSame('failed', $this->json($retry->getResponse())['terminalStatus']);
        $this->assertSigned($retry);
        self::assertSame($rowAfterFirst, $this->row());

        $contradiction = json_encode([
            'claimToken' => self::TOKEN,
            'leaseVersion' => self::VERSION,
            'outcome' => 'failed',
            'resultCode' => 'step_failed',
            'summary' => 'A different story.',
        ], JSON_THROW_ON_ERROR);
        $conflict = $this->request($contradiction);
        self::assertSame(Response::HTTP_CONFLICT, $conflict->getResponse()->getStatusCode());
        self::assertSame(AgentErrorCode::ClaimConflict->value, $this->json($conflict->getResponse())['code']);
        $this->assertSchema('error-response', $conflict->getResponse());
        $this->assertSigned($conflict);
        self::assertSame($rowAfterFirst, $this->row());
    }

    public function testStaleLeaseVersionOrWrongTokenReturnsSignedClaimConflict(): void
    {
        foreach ([
            ['claimToken' => str_repeat('B', 43), 'leaseVersion' => self::VERSION],
            ['claimToken' => self::TOKEN, 'leaseVersion' => self::VERSION - 1],
        ] as $fence) {
            $body = json_encode(array_merge($fence, [
                'outcome' => 'succeeded',
                'resultCode' => 'ok',
                'summary' => 'Done.',
            ]), JSON_THROW_ON_ERROR);
            $browser = $this->request($body);

            self::assertSame(Response::HTTP_CONFLICT, $browser->getResponse()->getStatusCode());
            self::assertSame(AgentErrorCode::ClaimConflict->value, $this->json($browser->getResponse())['code']);
            $this->assertSchema('error-response', $browser->getResponse());
            $this->assertSigned($browser);
            self::assertSame('running', $this->jobStatus());
        }
    }

    public function testCancellationAcceptsOnlyTimedOutCompletionOverHttp(): void
    {
        $this->connection->update('muwa_agent_job', [
            'status' => 'cancel_requested',
            'lease_expires_at' => (new \DateTimeImmutable('-1 minute', new \DateTimeZone('UTC')))
                ->format(Defaults::STORAGE_DATE_TIME_FORMAT),
        ], ['id' => Uuid::fromHexToBytes($this->jobId)]);

        $forbidden = json_encode([
            'claimToken' => self::TOKEN,
            'leaseVersion' => self::VERSION,
            'outcome' => 'succeeded',
            'resultCode' => 'ok',
            'summary' => 'Too late.',
        ], JSON_THROW_ON_ERROR);
        $conflict = $this->request($forbidden);
        self::assertSame(Response::HTTP_CONFLICT, $conflict->getResponse()->getStatusCode());
        $this->assertSigned($conflict);
        self::assertSame('cancel_requested', $this->jobStatus());

        $timedOut = json_encode([
            'claimToken' => self::TOKEN,
            'leaseVersion' => self::VERSION,
            'outcome' => 'timed_out',
            'resultCode' => 'deadline',
            'summary' => 'Killed after cancellation.',
        ], JSON_THROW_ON_ERROR);
        $accepted = $this->request($timedOut);
        self::assertSame(Response::HTTP_OK, $accepted->getResponse()->getStatusCode());
        self::assertSame('timed_out', $this->json($accepted->getResponse())['terminalStatus']);
        $this->assertSigned($accepted);
        self::assertSame('timed_out', $this->jobStatus());
    }

    public function testCompletionForAnotherHostsJobReturnsSignedClaimConflict(): void
    {
        $otherHostId = $this->insertHost('other-http-host');
        $this->connection->update('muwa_agent_job', [
            'target_agent_id' => Uuid::fromHexToBytes($otherHostId),
        ], ['id' => Uuid::fromHexToBytes($this->jobId)]);

        $body = json_encode([
            'claimToken' => self::TOKEN,
            'leaseVersion' => self::VERSION,
            'outcome' => 'succeeded',
            'resultCode' => 'ok',
            'summary' => 'Done.',
        ], JSON_THROW_ON_ERROR);
        $browser = $this->request($body);

        self::assertSame(Response::HTTP_CONFLICT, $browser->getResponse()->getStatusCode());
        self::assertSame(AgentErrorCode::ClaimConflict->value, $this->json($browser->getResponse())['code']);
        $this->assertSigned($browser);
        self::assertSame('running', $this->jobStatus());
    }

    public function testRoutesExposeTheExactControllerContract(): void
    {
        $router = self::getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);
        $route = $router->getRouteCollection()->get('api.action.muwa.agent.v1.job_complete');
        self::assertNotNull($route);
        self::assertSame(['POST'], $route->getMethods());
        self::assertSame(['api'], $route->getDefault('_routeScope'));
        self::assertFalse($route->getDefault('auth_required'));
        self::assertSame('MuckiSafeAgentPlugin\\Controller\\Agent\\JobCompleteController::complete', $route->getDefault('_controller'));
        self::assertSame('/api/_action/muwa/agent/v1/jobs/{jobId}/complete', $route->getPath());
    }

    private function request(string $body): TestBrowser
    {
        $path = $this->path();
        $timestamp = (string) (time() - 1);
        $nonce = 'job-complete-http-' . bin2hex(random_bytes(10));
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

    private function assertSigned(TestBrowser $browser): void
    {
        $nonce = $browser->getRequest()->headers->get('X-Mucki-Agent-Nonce');
        self::assertIsString($nonce);
        self::assertSame(
            ResponseVerdict::Accept,
            (new ResponseSignature())->classify($this->secretBytes, $nonce, $this->path(), $browser->getResponse())
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

    private function path(): string
    {
        return '/api/_action/muwa/agent/v1/jobs/' . $this->wireUuid($this->jobId) . '/complete';
    }

    private function jobStatus(): string
    {
        return (string) $this->connection->fetchOne(
            'SELECT `status` FROM `muwa_agent_job` WHERE `id` = UNHEX(:id)',
            ['id' => $this->jobId]
        );
    }

    /** @return array<string, mixed> */
    private function row(): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM `muwa_agent_job` WHERE `id` = UNHEX(:id)',
            ['id' => $this->jobId]
        );
        self::assertIsArray($row);

        return $row;
    }

    /** @return array<string, mixed> */
    private function json(Response $response): array
    {
        $decoded = json_decode((string) $response->getContent(), true, 16, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function insertRunningJob(): string
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $runId = Uuid::randomHex();
        $actionId = Uuid::randomHex();
        $jobId = Uuid::randomHex();
        $timestamp = $now->format(Defaults::STORAGE_DATE_TIME_FORMAT);
        $definition = '{"version":1,"steps":[]}';
        $this->connection->insert('muwa_agent_backup_run', [
            'id' => Uuid::fromHexToBytes($runId),
            'facility_repository_id' => Uuid::randomBytes(),
            'source' => 'facility',
            'idempotency_key' => Uuid::randomHex(),
            'status' => 'running',
            'started_at' => $timestamp,
            'created_at' => $timestamp,
        ]);
        $this->connection->insert('muwa_agent_action', [
            'id' => Uuid::fromHexToBytes($actionId),
            'technical_name' => Uuid::randomHex(),
            'label' => 'HTTP completion action',
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
            'status' => 'running',
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
            'heartbeat_at' => $timestamp,
            'created_at' => $timestamp,
        ]);

        return $jobId;
    }

    private function insertHost(string $name): string
    {
        $id = Uuid::randomHex();
        $this->connection->insert('muwa_agent_host', [
            'id' => Uuid::fromHexToBytes($id),
            'name' => $name,
            'enabled' => 1,
            'key_id' => 'complete-http-' . $id,
            'secret_ciphertext' => random_bytes(32),
            'secret_nonce' => random_bytes(24),
            'secret_key_version' => 1,
            'capabilities' => '[]',
            'created_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(Defaults::STORAGE_DATE_TIME_FORMAT),
        ]);

        return $id;
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
