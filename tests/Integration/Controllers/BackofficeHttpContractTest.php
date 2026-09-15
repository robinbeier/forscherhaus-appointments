<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ReleaseGate\GateHttpClient;
use Tests\Integration\Support\DefenseCycleFixtures;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__) . '/Support/DefenseCycleFixtures.php';
require_once dirname(__DIR__) . '/Support/DefenseCycleHttpServer.php';

/** Ordinary method, CSRF, and role contracts for authenticated backoffice routes. */
final class BackofficeHttpContractTest extends TestCase
{
    private ?DefenseCycleFixtures $fixture = null;
    private ?DefenseCycleHttpServer $server = null;
    private array $credentials = [];

    protected function setUp(): void
    {
        if (getenv('FH_DEFENSE_ISOLATED') !== '1') {
            self::markTestSkipped('Run with the fresh isolated synthetic stack.');
        }
        try {
            $this->fixture = new DefenseCycleFixtures();
            $this->fixture->create();
            $this->credentials = $this->fixture->enableProviderHttpAuth();
            $this->server = new DefenseCycleHttpServer();
        } catch (Throwable $error) {
            $this->fixture?->cleanup();
            throw $error;
        }
    }

    protected function tearDown(): void
    {
        try {
            $this->server?->close();
        } finally {
            $this->fixture?->cleanup();
        }
    }

    public function testPermittedAdminPostOnlyRoutesRejectGetAndHeadWithoutMutation(): void
    {
        $client = $this->login($this->credentials['admin_username'], $this->credentials['password']);
        $before = $this->dbSnapshot();
        foreach (['customers', 'blocked_periods', 'admins', 'secretaries'] as $controller) {
            foreach (['store', 'update', 'destroy'] as $action) {
                $path = $controller . '/' . $action;
                foreach (['GET', 'HEAD'] as $method) {
                    $response = $client->requestApp($method, $path);
                    self::assertSame(405, $response->statusCode, $method . ' ' . $path . ' must be rejected.');
                    self::assertSame('POST', $response->header('allow'));
                    self::assertSame($before, $this->dbSnapshot());
                }
            }
        }
        foreach (['general_settings/save'] as $path) {
            foreach (['GET', 'HEAD'] as $method) {
                $response = $client->requestApp($method, $path);
                self::assertSame(405, $response->statusCode);
                self::assertSame('POST', $response->header('allow'));
                self::assertSame($before, $this->dbSnapshot());
            }
        }
    }

    public function testPermittedAdminPostWithoutCsrfIsRejectedWithoutMutation(): void
    {
        $client = $this->login($this->credentials['admin_username'], $this->credentials['password']);
        $ownedSetting = $this->fixture->ownedSetting('csrf', 'before');
        $ownedSetting['value'] = 'after';
        $before = $this->dbSnapshot();
        $payloads = [
            ['customers/store', ['customer' => $this->fixture->customerWritePayload('csrf')]],
            ['blocked_periods/store', ['blocked_period' => $this->fixture->blockedPeriodWritePayload('csrf')]],
            ['admins/store', ['admin' => $this->staffPayload('admin', 'csrf')]],
            ['secretaries/store', ['secretary' => $this->staffPayload('secretary', 'csrf')]],
            ['general_settings/save', ['general_settings' => [$ownedSetting]]],
            ['update', []],
        ];
        foreach ($payloads as [$path, $payload]) {
            $response = $client->requestApp('POST', $path, $payload);
            self::assertSame(403, $response->statusCode, 'Missing CSRF must be rejected for ' . $path . '.');
            self::assertSame($before, $this->dbSnapshot());
        }
    }

    public function testProviderSessionIsRejectedForAdminOnlyStaffWrites(): void
    {
        $client = $this->login($this->credentials['provider_username'], $this->credentials['password']);
        $ownedSetting = $this->fixture->ownedSetting('provider', 'before');
        $ownedSetting['value'] = 'after';
        $before = $this->dbSnapshot();
        $payloads = [
            ['admins/store', ['admin' => $this->staffPayload('admin', 'provider')]],
            ['secretaries/store', ['secretary' => $this->staffPayload('secretary', 'provider')]],
            ['general_settings/save', ['general_settings' => [$ownedSetting]]],
        ];
        foreach ($payloads as [$path, $payload]) {
            $response = $client->post($path, $payload, withCsrfToken: true);
            if ($path === 'general_settings/save') {
                // The existing SettingsPostGuardTest contract serializes permission exceptions as 500.
                self::assertSame(500, $response->statusCode);
                self::assertSame(
                    ['success' => false, 'message' => 'You do not have the required permissions for this task.'],
                    json_decode($response->body, true, 512, JSON_THROW_ON_ERROR),
                );
            } else {
                self::assertSame(403, $response->statusCode, 'Provider must be denied for ' . $path . '.');
            }
            self::assertSame($before, $this->dbSnapshot());
        }
    }

    public function testUpdatePageKeepsItsOrdinaryReadMethodContract(): void
    {
        $client = $this->login($this->credentials['admin_username'], $this->credentials['password']);
        $before = $this->dbSnapshot();
        self::assertSame(200, $client->get('update')->statusCode);
        self::assertSame($before, $this->dbSnapshot());
        self::assertSame(200, $client->requestApp('HEAD', 'update')->statusCode);
        self::assertSame($before, $this->dbSnapshot());
    }

    public function testProviderCannotReadUpdatePage(): void
    {
        $client = $this->login($this->credentials['provider_username'], $this->credentials['password']);
        $before = $this->dbSnapshot();
        self::assertSame(403, $client->get('update')->statusCode);
        self::assertSame($before, $this->dbSnapshot());
    }

    private function staffPayload(string $role, string $case): array
    {
        $api =
            $role === 'admin' ? $this->fixture->adminWritePayload($case) : $this->fixture->secretaryWritePayload($case);
        $payload = [
            'first_name' => $api['firstName'],
            'last_name' => $api['lastName'],
            'email' => $api['email'],
            'settings' => $api['settings'],
        ];
        if ($role === 'secretary') {
            $payload['providers'] = [];
        }
        return $payload;
    }

    private function dbSnapshot(): array
    {
        $snapshot = [];
        foreach (
            ['users', 'user_settings', 'secretaries_providers', 'blocked_periods', 'settings', 'migrations']
            as $table
        ) {
            $rows = get_instance()->db->get($table)->result_array();
            usort($rows, static fn(array $left, array $right): int => strcmp(json_encode($left), json_encode($right)));
            $snapshot[$table] = hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
        }
        return $snapshot;
    }

    private function login(string $username, string $password): GateHttpClient
    {
        $client = $this->server->client();
        self::assertSame(200, $client->get('login')->statusCode);
        $response = $client->post('login/validate', ['username' => $username, 'password' => $password]);
        self::assertSame(200, $response->statusCode);
        self::assertTrue((bool) (json_decode($response->body, true, 512, JSON_THROW_ON_ERROR)['success'] ?? false));
        return $client;
    }
}
