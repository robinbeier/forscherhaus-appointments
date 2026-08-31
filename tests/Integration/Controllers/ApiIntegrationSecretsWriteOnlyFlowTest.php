<?php

namespace Tests\Integration\Controllers;

use Api;
use Providers_api_v1;
use Tests\TestCase;
use Webhooks_api_v1;

require_once APPPATH . 'libraries/Api.php';
require_once APPPATH . 'controllers/api/v1/Providers_api_v1.php';
require_once APPPATH . 'controllers/api/v1/Webhooks_api_v1.php';

/**
 * Endpoint-near regression coverage for write-only integration credentials.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class ApiIntegrationSecretsWriteOnlyFlowTest extends TestCase
{
    private object $CI;

    /** @var array<int> */
    private array $providerIds = [];

    /** @var array<int> */
    private array $webhookIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->CI = &get_instance();
        $this->authenticateAsAdmin();
        $this->resetRequest();
    }

    protected function tearDown(): void
    {
        foreach ($this->webhookIds as $id) {
            $this->CI->db->delete('webhooks', ['id' => $id]);
        }

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

        $_GET = ['length' => '100', 'page' => '1'];
        $this->createProvidersController()->index();
        $collection = $this->decodeJsonOutput();
        $this->assertProviderSecretsAbsentFromList($collection);

        $this->resetRequest();
        $this->createProvidersController()->show($providerId);
        $this->assertProviderSecretsAbsent($this->decodeJsonOutput());

        $this->resetRequest();
        $_GET = ['fields' => 'settings'];
        $this->createProvidersController()->show($providerId);
        $this->assertProviderSecretsAbsent($this->decodeJsonOutput());

        $this->resetRequest();
        $_GET = ['with' => 'services'];
        $this->createProvidersController()->show($providerId);
        $this->assertProviderSecretsAbsent($this->decodeJsonOutput());

        $this->resetRequest();
        $_GET = ['q' => 'Synthetic'];
        $this->createProvidersController()->index();
        $this->assertProviderSecretsAbsentFromList($this->decodeJsonOutput());

        $this->resetRequest();
        $this->setPostPayload($this->providerPayload('rotated-google', 'rotated-caldav'));
        $this->createProvidersController()->update($providerId);
        $this->assertProviderSecretsAbsent($this->decodeJsonOutput());
    }

    public function testProviderUpdatesPreserveOmittedCredentialsAndAcceptExplicitRotation(): void
    {
        $providerId = $this->createProvider();
        $before = $this->providerSettings($providerId);

        $this->setPostPayload(['notes' => 'synthetic provider update']);
        $this->createProvidersController()->update($providerId);
        $this->assertProviderSecretsAbsent($this->decodeJsonOutput());
        $afterOmitted = $this->providerSettings($providerId);
        $this->assertTrue(($afterOmitted['google_token'] ?? null) === ($before['google_token'] ?? null));
        $this->assertTrue(($afterOmitted['caldav_password'] ?? null) === ($before['caldav_password'] ?? null));

        $this->setPostPayload($this->providerPayload('rotated-google', 'rotated-caldav'));
        $this->createProvidersController()->update($providerId);
        $this->assertProviderSecretsAbsent($this->decodeJsonOutput());
        $afterRotation = $this->providerSettings($providerId);
        $this->assertTrue(($afterRotation['google_token'] ?? null) === 'rotated-google');
        $this->assertTrue(($afterRotation['caldav_password'] ?? null) === 'rotated-caldav');

        $this->setPostPayload([
            'settings' => [
                'googleToken' => 'rotated-google',
                'caldavPassword' => 'rotated-caldav',
            ],
        ]);
        $this->createProvidersController()->update($providerId);
        $this->assertProviderSecretsAbsent($this->decodeJsonOutput());
        $afterConvergedWrite = $this->providerSettings($providerId);
        $this->assertTrue(($afterConvergedWrite['google_token'] ?? null) === 'rotated-google');
        $this->assertTrue(($afterConvergedWrite['caldav_password'] ?? null) === 'rotated-caldav');
    }

    public function testWebhookReadsAndWriteResponsesNeverExposeSecrets(): void
    {
        $webhookId = $this->createWebhook();

        $_GET = ['length' => '100', 'page' => '1'];
        $this->createWebhooksController()->index();
        $this->assertWebhookSecretsAbsentFromList($this->decodeJsonOutput());

        $this->resetRequest();
        $this->createWebhooksController()->show($webhookId);
        $this->assertWebhookSecretsAbsent($this->decodeJsonOutput());

        $this->resetRequest();
        $_GET = ['fields' => 'secretToken,secret_token'];
        $this->createWebhooksController()->show($webhookId);
        $this->assertWebhookSecretsAbsent($this->decodeJsonOutput());

        $this->resetRequest();
        $_GET = ['q' => 'Synthetic'];
        $this->createWebhooksController()->index();
        $this->assertWebhookSecretsAbsentFromList($this->decodeJsonOutput());

        $this->resetRequest();
        $this->setPostPayload([
            'name' => 'Synthetic webhook rotated',
            'url' => 'https://example.org/hook',
            'secretToken' => 'rotated-webhook-secret',
        ]);
        $this->createWebhooksController()->update($webhookId);
        $this->assertWebhookSecretsAbsent($this->decodeJsonOutput());
    }

    public function testWebhookUpdatesPreserveOmittedSecretAndAcceptExplicitRotation(): void
    {
        $webhookId = $this->createWebhook();
        $before = (string) $this->CI->db->get_where('webhooks', ['id' => $webhookId])->row()->secret_token;

        $this->setPostPayload(['name' => 'Synthetic webhook unchanged']);
        $this->createWebhooksController()->update($webhookId);
        $this->assertWebhookSecretsAbsent($this->decodeJsonOutput());
        $afterOmitted = (string) $this->CI->db->get_where('webhooks', ['id' => $webhookId])->row()->secret_token;
        $this->assertTrue($afterOmitted === $before);

        $this->setPostPayload(['secretToken' => 'rotated-webhook-secret']);
        $this->createWebhooksController()->update($webhookId);
        $this->assertWebhookSecretsAbsent($this->decodeJsonOutput());
        $afterRotation = (string) $this->CI->db->get_where('webhooks', ['id' => $webhookId])->row()->secret_token;
        $this->assertTrue($afterRotation === 'rotated-webhook-secret');

        $this->setPostPayload(['secretToken' => 'rotated-webhook-secret']);
        $this->createWebhooksController()->update($webhookId);
        $this->assertWebhookSecretsAbsent($this->decodeJsonOutput());
        $afterConvergedWrite = (string) $this->CI->db->get_where('webhooks', ['id' => $webhookId])->row()->secret_token;
        $this->assertTrue($afterConvergedWrite === 'rotated-webhook-secret');
    }

    public function testExplicitNullClearsStoredCredentialsWithoutEchoingThem(): void
    {
        $providerId = $this->createProvider();
        $this->setPostPayload([
            'settings' => [
                'googleToken' => null,
                'caldavPassword' => null,
            ],
        ]);
        $this->createProvidersController()->update($providerId);
        $this->assertProviderSecretsAbsent($this->decodeJsonOutput());
        $providerSettings = $this->providerSettings($providerId);
        $this->assertTrue(
            array_key_exists('google_token', $providerSettings) && $providerSettings['google_token'] === null,
        );
        $this->assertTrue(
            array_key_exists('caldav_password', $providerSettings) && $providerSettings['caldav_password'] === null,
        );

        $webhookId = $this->createWebhook();
        $this->setPostPayload(['secretToken' => null]);
        $this->createWebhooksController()->update($webhookId);
        $this->assertWebhookSecretsAbsent($this->decodeJsonOutput());
        $webhook = $this->CI->db->get_where('webhooks', ['id' => $webhookId])->row_array();
        $this->assertTrue(array_key_exists('secret_token', $webhook) && $webhook['secret_token'] === null);
    }

    public function testInvalidProviderCredentialTypeIsRejectedWithoutMutation(): void
    {
        $providerId = $this->createProvider();
        $beforeProvider = $this->CI->db->get_where('users', ['id' => $providerId])->row_array();
        $beforeSettings = $this->providerSettings($providerId);

        $this->setPostPayload([
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

    public function testInvalidWebhookCredentialLengthIsRejectedWithoutMutation(): void
    {
        $webhookId = $this->createWebhook();
        $before = $this->CI->db->get_where('webhooks', ['id' => $webhookId])->row_array();

        $this->setPostPayload([
            'name' => 'must not be persisted',
            'secretToken' => str_repeat('x', 513),
        ]);
        $this->createWebhooksController()->update($webhookId);
        $response = $this->decodeJsonOutput();
        $after = $this->CI->db->get_where('webhooks', ['id' => $webhookId])->row_array();

        $this->assertTrue(($response['success'] ?? null) === false);
        $this->assertTrue(($after['name'] ?? null) === ($before['name'] ?? null));
        $this->assertTrue(($after['secret_token'] ?? null) === ($before['secret_token'] ?? null));
    }

    private function createProvider(): int
    {
        $pair = $this->CI->db->select('id_services')->from('services_providers')->limit(1)->get()->row_array();
        $serviceId = (int) ($pair['id_services'] ?? 0);
        $this->setPostPayload($this->providerPayload('initial-google', 'initial-caldav', $serviceId));
        $this->createProvidersController()->store();
        $response = $this->decodeJsonOutput();
        $this->assertProviderSecretsAbsent($response);
        $id = (int) ($response['id'] ?? 0);
        $this->assertGreaterThan(0, $id);
        $this->providerIds[] = $id;

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

    private function createWebhook(): int
    {
        $this->setPostPayload([
            'name' => 'Synthetic webhook',
            'url' => 'https://example.org/hook',
            'actions' => 'synthetic_action',
            'secretToken' => 'initial-webhook-secret',
        ]);
        $this->createWebhooksController()->store();
        $response = $this->decodeJsonOutput();
        $this->assertWebhookSecretsAbsent($response);
        $id = (int) ($response['id'] ?? 0);
        $this->assertGreaterThan(0, $id);
        $this->webhookIds[] = $id;

        return $id;
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

    private function assertWebhookSecretsAbsentFromList(array $webhooks): void
    {
        foreach ($webhooks as $webhook) {
            if (is_array($webhook)) {
                $this->assertWebhookSecretsAbsent($webhook);
            }
        }
    }

    private function assertWebhookSecretsAbsent(array $webhook): void
    {
        $this->assertFalse(array_key_exists('secretToken', $webhook));
        $this->assertFalse(array_key_exists('secret_token', $webhook));
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
        $controller->webhooks_client = new class {
            public function trigger(...$args): void {}
        };
        $controller->api = $this->authenticatedApi('providers_model');

        return $controller;
    }

    private function createWebhooksController(): Webhooks_api_v1
    {
        $controller = new class extends Webhooks_api_v1 {
            public function __construct() {}
        };
        $this->CI->load->model('webhooks_model');
        $this->CI->load->library('api_request_dto_factory');
        $controller->load = $this->CI->load;
        $controller->input = $this->CI->input;
        $controller->output = $this->CI->output;
        $controller->webhooks_model = $this->CI->webhooks_model;
        $controller->api_request_dto_factory = $this->CI->api_request_dto_factory;
        $controller->api = $this->authenticatedApi('webhooks_model');

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
