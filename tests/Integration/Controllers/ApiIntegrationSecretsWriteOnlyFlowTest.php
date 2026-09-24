<?php

namespace Tests\Integration\Controllers;

use Api;
use Providers_api_v1;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\TestCase;

require_once APPPATH . 'libraries/Api.php';
require_once APPPATH . 'controllers/api/v1/Providers_api_v1.php';

/**
 * Endpoint-near regression coverage for write-only integration credentials.
 *
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ApiIntegrationSecretsWriteOnlyFlowTest extends TestCase
{
    private object $CI;

    /** @var array<int> */
    private array $providerIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->CI = &get_instance();
        $this->authenticateAsAdmin();
        $this->resetRequest();
    }

    protected function tearDown(): void
    {
        foreach ($this->providerIds as $id) {
            $this->CI->db->delete('services_providers', ['id_users' => $id]);
            $this->CI->db->delete('user_settings', ['id_users' => $id]);
            $this->CI->db->delete('users', ['id' => $id]);
        }

        $this->resetRequest();
        unset(
            $_SERVER['PHP_AUTH_USER'],
            $_SERVER['PHP_AUTH_PW'],
            $_SERVER['Authorization'],
            $_SERVER['HTTP_AUTHORIZATION'],
        );

        parent::tearDown();
    }

    public function testProviderReadsAndWriteResponsesNeverExposeIntegrationCredentials(): void
    {
        $providerId = $this->createProvider();
        $identity = $this->providerIdentity($providerId);
        $serviceId = $this->providerServiceId($providerId);
        $username = $this->providerSettings($providerId)['username'];

        $_GET = ['length' => '1', 'page' => '1', 'sort' => '-id'];
        $this->createProvidersController()->index();
        $collection = $this->decodeJsonOutput();
        $this->assertCount(1, $collection);
        $this->assertProviderInCollection($collection, $providerId, $identity);
        $this->assertProviderSecretsAbsentFromList($collection);

        $this->resetRequest();
        $this->createProvidersController()->show($providerId);
        $response = $this->decodeJsonOutput();
        $this->assertSuccessfulProviderResponse($response, $providerId, $identity, $serviceId);
        $this->assertProviderSecretsAbsent($response);

        $this->resetRequest();
        $_GET = ['fields' => 'settings'];
        $this->createProvidersController()->show($providerId);
        $projection = $this->decodeJsonOutput();
        $this->assertSame(['settings'], array_keys($projection));
        $this->assertIsArray($projection['settings'] ?? null);
        $this->assertSame($username, $projection['settings']['username'] ?? null);
        $this->assertProviderSecretsAbsent($projection);

        $this->resetRequest();
        $_GET = ['with' => 'services'];
        $this->createProvidersController()->show($providerId);
        $expanded = $this->decodeJsonOutput();
        $this->assertSuccessfulProviderResponse($expanded, $providerId, $identity);
        $this->assertArrayHasKey('services', $expanded);
        $this->assertIsArray($expanded['services']);
        $this->assertNotEmpty($expanded['services']);
        $this->assertContains(
            $serviceId,
            array_map(static fn(array $service): int => (int) $service['id'], $expanded['services']),
        );
        $this->assertProviderSecretsAbsent($expanded);

        $this->resetRequest();
        $_GET = ['q' => $identity['email']];
        $this->createProvidersController()->index();
        $search = $this->decodeJsonOutput();
        $this->assertCount(1, $search);
        $this->assertProviderInCollection($search, $providerId, $identity);
        $this->assertProviderSecretsAbsentFromList($search);

        $this->resetRequest();
        $payload = $this->providerPayload('rotated-google', 'rotated-caldav');
        $identity = $this->providerIdentityFromPayload($payload);
        $this->setPutPayload($payload);
        $this->createProvidersController()->update($providerId);
        $response = $this->decodeJsonOutput();
        $this->assertSuccessfulProviderResponse($response, $providerId, $identity, $serviceId);
        $this->assertSame($identity, $this->providerIdentity($providerId));
        $this->assertSame('rotated-google', $this->providerSettings($providerId)['google_token']);
        $this->assertSame('rotated-caldav', $this->providerSettings($providerId)['caldav_password']);
        $this->assertProviderSecretsAbsent($response);
    }

    public function testProviderUpdatesPreserveOmittedCredentialsAndAcceptExplicitRotation(): void
    {
        $providerId = $this->createProvider();
        $before = $this->providerSettings($providerId);
        $identity = $this->providerIdentity($providerId);

        $this->setPutPayload(['notes' => 'synthetic provider update']);
        $this->createProvidersController()->update($providerId);
        $response = $this->decodeJsonOutput();
        $this->assertSuccessfulProviderResponse($response, $providerId, $identity);
        $this->assertSame($identity, $this->providerIdentity($providerId));
        $this->assertProviderSecretsAbsent($response);
        $this->assertSame(
            'synthetic provider update',
            $this->CI->db->get_where('users', ['id' => $providerId])->row_array()['notes'],
        );
        $afterOmitted = $this->providerSettings($providerId);
        $this->assertTrue(($afterOmitted['google_token'] ?? null) === ($before['google_token'] ?? null));
        $this->assertTrue(($afterOmitted['caldav_password'] ?? null) === ($before['caldav_password'] ?? null));

        $payload = $this->providerPayload('rotated-google', 'rotated-caldav');
        $identity = $this->providerIdentityFromPayload($payload);
        $this->setPutPayload($payload);
        $this->createProvidersController()->update($providerId);
        $response = $this->decodeJsonOutput();
        $this->assertSuccessfulProviderResponse($response, $providerId, $identity);
        $this->assertSame($identity, $this->providerIdentity($providerId));
        $this->assertProviderSecretsAbsent($response);
        $afterRotation = $this->providerSettings($providerId);
        $this->assertTrue(($afterRotation['google_token'] ?? null) === 'rotated-google');
        $this->assertTrue(($afterRotation['caldav_password'] ?? null) === 'rotated-caldav');

        $this->setPutPayload(['settings' => ['googleToken' => 'single-google']]);
        $this->createProvidersController()->update($providerId);
        $response = $this->decodeJsonOutput();
        $this->assertSuccessfulProviderResponse($response, $providerId, $identity);
        $this->assertSame($identity, $this->providerIdentity($providerId));
        $this->assertProviderSecretsAbsent($response);
        $afterGoogleOnly = $this->providerSettings($providerId);
        $this->assertSame('single-google', $afterGoogleOnly['google_token'] ?? null);
        $this->assertSame('rotated-caldav', $afterGoogleOnly['caldav_password'] ?? null);

        $this->setPutPayload(['settings' => ['caldavPassword' => 'single-caldav']]);
        $this->createProvidersController()->update($providerId);
        $response = $this->decodeJsonOutput();
        $this->assertSuccessfulProviderResponse($response, $providerId, $identity);
        $this->assertSame($identity, $this->providerIdentity($providerId));
        $this->assertProviderSecretsAbsent($response);
        $afterCaldavOnly = $this->providerSettings($providerId);
        $this->assertSame('single-google', $afterCaldavOnly['google_token'] ?? null);
        $this->assertSame('single-caldav', $afterCaldavOnly['caldav_password'] ?? null);

        $this->setPutPayload([
            'settings' => [
                'googleToken' => 'single-google',
                'caldavPassword' => 'single-caldav',
            ],
        ]);
        $this->createProvidersController()->update($providerId);
        $response = $this->decodeJsonOutput();
        $this->assertSuccessfulProviderResponse($response, $providerId, $identity);
        $this->assertSame($identity, $this->providerIdentity($providerId));
        $this->assertProviderSecretsAbsent($response);
        $afterConvergedWrite = $this->providerSettings($providerId);
        $this->assertTrue(($afterConvergedWrite['google_token'] ?? null) === 'single-google');
        $this->assertTrue(($afterConvergedWrite['caldav_password'] ?? null) === 'single-caldav');
    }

    public function testExplicitNullClearsStoredCredentialsWithoutEchoingThem(): void
    {
        $providerId = $this->createProvider();
        $identity = $this->providerIdentity($providerId);
        $this->setPutPayload([
            'settings' => [
                'googleToken' => null,
                'caldavPassword' => null,
            ],
        ]);
        $this->createProvidersController()->update($providerId);
        $response = $this->decodeJsonOutput();
        $this->assertSuccessfulProviderResponse($response, $providerId, $identity);
        $this->assertSame($identity, $this->providerIdentity($providerId));
        $this->assertProviderSecretsAbsent($response);
        $providerSettings = $this->providerSettings($providerId);
        $this->assertTrue(
            array_key_exists('google_token', $providerSettings) && $providerSettings['google_token'] === null,
        );
        $this->assertTrue(
            array_key_exists('caldav_password', $providerSettings) && $providerSettings['caldav_password'] === null,
        );
    }

    public function testInvalidProviderCredentialTypeIsRejectedWithoutMutation(): void
    {
        $providerId = $this->createProvider();
        $beforeProvider = $this->CI->db->get_where('users', ['id' => $providerId])->row_array();
        $beforeSettings = $this->providerSettings($providerId);

        $this->setPutPayload([
            'notes' => 'must not be persisted',
            'settings' => [
                'googleToken' => ['synthetic-sensitive-value'],
            ],
        ]);
        $this->createProvidersController()->update($providerId);
        $response = $this->decodeJsonOutput();
        $afterProvider = $this->CI->db->get_where('users', ['id' => $providerId])->row_array();
        $afterSettings = $this->providerSettings($providerId);

        $this->assertTrue(($response['success'] ?? null) === false);
        $this->assertFalse(str_contains((string) ($response['message'] ?? ''), 'synthetic-sensitive-value'));
        $this->assertTrue(($afterProvider['notes'] ?? null) === ($beforeProvider['notes'] ?? null));
        $this->assertTrue(($afterSettings['google_token'] ?? null) === ($beforeSettings['google_token'] ?? null));
        $this->assertTrue(($afterSettings['caldav_password'] ?? null) === ($beforeSettings['caldav_password'] ?? null));
    }

    private function createProvider(): int
    {
        $pair = $this->CI->db->select('id_services')->from('services_providers')->limit(1)->get()->row_array();
        $serviceId = (int) ($pair['id_services'] ?? 0);
        $payload = $this->providerPayload('initial-google', 'initial-caldav', $serviceId);
        $identity = $this->providerIdentityFromPayload($payload);
        $this->setPostPayload($payload);
        $this->createProvidersController()->store();
        $response = $this->decodeJsonOutput();
        $id = (int) ($response['id'] ?? 0);
        if ($id > 0) {
            $this->providerIds[] = $id;
        }
        $this->assertSuccessfulProviderResponse($response, $id, $identity, $serviceId);
        $this->assertSame($identity, $this->providerIdentity($id));
        $this->assertSame($serviceId, $this->providerServiceId($id));
        $settings = $this->providerSettings($id);
        $this->assertSame($payload['settings']['username'], $settings['username'] ?? null);
        $this->assertSame('initial-google', $settings['google_token'] ?? null);
        $this->assertSame('initial-caldav', $settings['caldav_password'] ?? null);
        $this->assertProviderSecretsAbsent($response);

        return $id;
    }

    private function providerPayload(string $googleToken, string $caldavPassword, ?int $serviceId = null): array
    {
        return [
            'firstName' => 'Synthetic',
            'lastName' => 'Secrets ' . bin2hex(random_bytes(4)),
            'email' => 'synthetic-' . bin2hex(random_bytes(5)) . '@example.org',
            'services' => [
                $serviceId ??
                (int) $this->CI->db->select('id_services')->from('services_providers')->limit(1)->get()->row()
                    ->id_services,
            ],
            'settings' => [
                'username' => 'synthetic-' . bin2hex(random_bytes(4)),
                'password' => 'SyntheticPassword123!',
                'googleToken' => $googleToken,
                'caldavPassword' => $caldavPassword,
            ],
        ];
    }

    private function providerSettings(int $id): array
    {
        return $this->CI->db->get_where('user_settings', ['id_users' => $id])->row_array();
    }

    private function providerIdentityFromPayload(array $payload): array
    {
        return [
            'firstName' => $payload['firstName'],
            'lastName' => $payload['lastName'],
            'email' => $payload['email'],
        ];
    }

    private function providerIdentity(int $id): array
    {
        $provider = $this->CI->db
            ->select('first_name, last_name, email')
            ->get_where('users', ['id' => $id])
            ->row_array();

        return [
            'firstName' => (string) ($provider['first_name'] ?? ''),
            'lastName' => (string) ($provider['last_name'] ?? ''),
            'email' => (string) ($provider['email'] ?? ''),
        ];
    }

    private function providerServiceId(int $id): int
    {
        return (int) $this->CI->db
            ->select('id_services')
            ->get_where('services_providers', ['id_users' => $id])
            ->row()->id_services;
    }

    private function assertSuccessfulProviderResponse(
        array $provider,
        int $id,
        array $identity,
        ?int $serviceId = null,
    ): void {
        $this->assertGreaterThan(0, $id);
        $this->assertArrayHasKey('id', $provider);
        $this->assertSame($id, (int) $provider['id']);
        $this->assertSame($identity['firstName'], $provider['firstName'] ?? null);
        $this->assertSame($identity['lastName'], $provider['lastName'] ?? null);
        $this->assertSame($identity['email'], $provider['email'] ?? null);
        $this->assertIsArray($provider['settings'] ?? null);

        if ($serviceId !== null) {
            $this->assertArrayHasKey('services', $provider);
            $this->assertContains($serviceId, array_map('intval', $provider['services']));
        }
    }

    private function assertProviderInCollection(array $providers, int $id, array $identity): void
    {
        $this->assertNotEmpty($providers);
        $matches = array_values(
            array_filter(
                $providers,
                static fn(mixed $provider): bool => is_array($provider) && (int) ($provider['id'] ?? 0) === $id,
            ),
        );
        $this->assertCount(1, $matches);
        $this->assertSuccessfulProviderResponse($matches[0], $id, $identity);
    }

    private function assertProviderSecretsAbsentFromList(array $providers): void
    {
        foreach ($providers as $provider) {
            if (is_array($provider)) {
                $this->assertProviderSecretsAbsent($provider);
            }
        }
    }

    private function assertProviderSecretsAbsent(array $provider): void
    {
        $settings = $provider['settings'] ?? [];
        $this->assertFalse(is_array($settings) && array_key_exists('googleToken', $settings));
        $this->assertFalse(is_array($settings) && array_key_exists('caldavPassword', $settings));
        $this->assertFalse(is_array($settings) && array_key_exists('google_token', $settings));
        $this->assertFalse(is_array($settings) && array_key_exists('caldav_password', $settings));
    }

    private function createProvidersController(): Providers_api_v1
    {
        $controller = new class extends Providers_api_v1 {
            public function __construct() {}
        };
        $this->CI->load->model('providers_model');
        $this->CI->load->library('api_request_dto_factory');
        $controller->load = $this->CI->load;
        $controller->input = $this->CI->input;
        $controller->output = $this->CI->output;
        $controller->providers_model = $this->CI->providers_model;
        $controller->api_request_dto_factory = $this->CI->api_request_dto_factory;
        $controller->api = $this->authenticatedApi('providers_model');

        return $controller;
    }

    private function authenticatedApi(string $model): Api
    {
        $api = new class extends Api {
            public function request_authentication(): void {}
        };
        $api->model($model);
        $api->auth();

        return $api;
    }

    private function authenticateAsAdmin(): void
    {
        $_SERVER['PHP_AUTH_USER'] = 'administrator';
        $_SERVER['PHP_AUTH_PW'] = 'administrator';
    }

    private function setPostPayload(array $payload): void
    {
        $_POST = $payload;
        $_GET = [];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $this->CI->output->set_output('');
    }

    private function setPutPayload(array $payload): void
    {
        $this->setPostPayload($payload);
        $_SERVER['REQUEST_METHOD'] = 'PUT';
    }

    private function resetRequest(): void
    {
        $_POST = [];
        $_GET = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $this->CI->output->set_output('');
    }

    private function decodeJsonOutput(): array
    {
        $decoded = json_decode($this->CI->output->get_output(), true);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}
