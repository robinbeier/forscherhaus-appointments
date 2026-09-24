<?php

declare(strict_types=1);

namespace ReleaseGate;

use RuntimeException;
use Throwable;

/** Bounded localhost proof for Staff API v1 URL-targeted PUT writes. */
final class StaffApiPutProbe
{
    public function __construct(
        private readonly GateHttpClient $client,
        private readonly object $db,
        private readonly DefenseVerificationFixture $fixture,
    ) {}

    public static function forApp(
        string $baseUrl,
        string $username,
        string $password,
        object $db,
        DefenseVerificationFixture $fixture,
        string $indexPage = 'index.php',
    ): self {
        if ($username === '' || $password === '') {
            throw new RuntimeException('Staff API probe credentials are unavailable.');
        }

        return new self(
            new GateHttpClient(
                $baseUrl,
                indexPage: $indexPage,
                additionalHeaders: [
                    'X-FH-Ordinary-Probe' => '1',
                    'Authorization' => 'Basic ' . base64_encode($username . ':' . $password),
                ],
            ),
            $db,
            $fixture,
        );
    }

    public function run(?callable $observe = null): array
    {
        $observe ??= static function (string $phase, string $outcome): void {};
        $state = $this->fixture->read();
        if (($state['profile'] ?? null) !== 'customer_boundary') {
            throw new RuntimeException('Staff API verification requires the owned staff graph.');
        }
        $providerId = (int) ($state['provider_target_id'] ?? 0);
        $adminId = (int) ($state['admin_target_id'] ?? 0);
        $marker = (string) ($state['marker'] ?? '');
        if ($providerId < 1 || $adminId < 1 || $providerId === $adminId || $marker === '') {
            throw new RuntimeException('Staff API owned targets are unavailable.');
        }

        $provider = $this->snapshot($providerId, $marker);
        $admin = $this->snapshot($adminId, $marker);
        $this->phase($observe, 'staff_api_provider_conflict', function () use (
            $providerId,
            $adminId,
            $marker,
            $provider,
            $admin,
        ): void {
            $response = $this->client->requestJsonApp('PUT', 'api/v1/providers/' . $providerId, [
                'id' => $adminId,
                'firstName' => 'Must Not Persist',
                'email' => $admin['user']['email'],
                'settings' => ['username' => $admin['settings']['username']],
            ]);
            $this->expectStatus($response, 400, 'provider conflict');
            $this->assertPairUnchanged($providerId, $adminId, $marker, $provider, $admin, 'provider conflict');
        });
        $this->phase($observe, 'staff_api_admin_conflict', function () use (
            $providerId,
            $adminId,
            $marker,
            $provider,
            $admin,
        ): void {
            $response = $this->client->requestJsonApp('PUT', 'api/v1/admins/' . $adminId, [
                'id' => $providerId,
                'firstName' => 'Must Not Persist',
                'email' => $provider['user']['email'],
                'settings' => ['username' => $provider['settings']['username']],
            ]);
            $this->expectStatus($response, 400, 'admin conflict');
            $this->assertPairUnchanged($providerId, $adminId, $marker, $provider, $admin, 'admin conflict');
        });
        $this->phase($observe, 'staff_api_alias_get', function () use (
            $providerId,
            $adminId,
            $marker,
            $provider,
            $admin,
        ): void {
            $response = $this->client->requestApp('GET', 'api/v1/providers_api_v1/update/' . $providerId);
            $this->expectStatus($response, 405, 'direct GET update alias');
            if ($response->header('Allow') !== 'PUT') {
                throw new RuntimeException('Staff API update alias did not advertise PUT.');
            }
            $this->assertPairUnchanged($providerId, $adminId, $marker, $provider, $admin, 'direct GET update alias');
        });
        $this->phase($observe, 'staff_api_provider_matching', function () use (
            $providerId,
            $adminId,
            $marker,
            $provider,
            $admin,
        ): void {
            $firstName = 'Staff API Verified';
            $response = $this->client->requestJsonApp('PUT', 'api/v1/providers/' . $providerId, [
                'id' => $providerId,
                'firstName' => $firstName,
            ]);
            $this->expectStatus($response, 200, 'matching provider PUT');
            $body = $this->decodeObject($response->body);
            if ((int) ($body['id'] ?? 0) !== $providerId || ($body['firstName'] ?? null) !== $firstName) {
                throw new RuntimeException('Staff API matching PUT returned another target or value.');
            }
            $after = $this->snapshot($providerId, $marker);
            $expected = $provider;
            $expected['user']['first_name'] = $firstName;
            $expected['user']['update_datetime'] = $after['user']['update_datetime'];
            if ($after !== $expected || $this->snapshot($adminId, $marker) !== $admin) {
                throw new RuntimeException('Staff API matching PUT changed unexpected owned state.');
            }
        });

        return [
            'status' => 'verified',
            'coverage' => 'owned_provider_admin_target_binding_and_alias_method',
            'provider_conflict_status' => 400,
            'admin_conflict_status' => 400,
            'wrong_verb_status' => 405,
            'matching_status' => 200,
            'observed' =>
                'Cross-role body IDs were rejected before mutation; both full owned staff snapshots stayed unchanged. The direct GET update alias returned 405. A matching provider PUT changed only the owned provider first name.',
        ];
    }

    private function phase(callable $observe, string $phase, callable $operation): void
    {
        $observe($phase, 'started');
        try {
            $operation();
            $observe($phase, 'passed');
        } catch (Throwable $error) {
            $observe($phase, 'failed');
            throw $error;
        }
    }

    private function assertPairUnchanged(
        int $providerId,
        int $adminId,
        string $marker,
        array $provider,
        array $admin,
        string $context,
    ): void {
        if ($this->snapshot($providerId, $marker) !== $provider || $this->snapshot($adminId, $marker) !== $admin) {
            throw new RuntimeException('Staff API ' . $context . ' changed an owned staff row.');
        }
    }

    /** @return array<string,mixed> */
    private function snapshot(int $id, string $marker): array
    {
        $users = $this->db->get_where('users', ['id' => $id])->result_array();
        $settings = $this->db->get_where('user_settings', ['id_users' => $id])->result_array();
        if (count($users) !== 1 || ($users[0]['notes'] ?? null) !== $marker || count($settings) !== 1) {
            throw new RuntimeException('Staff API owned snapshot is missing or drifted.');
        }
        return [
            'user' => $users[0],
            'settings' => $settings[0],
            'provider_appointments' => $this->db
                ->order_by('id', 'ASC')
                ->get_where('appointments', ['id_users_provider' => $id])
                ->result_array(),
            'customer_appointments' => $this->db
                ->order_by('id', 'ASC')
                ->get_where('appointments', ['id_users_customer' => $id])
                ->result_array(),
            'services' => $this->db
                ->order_by('id_services', 'ASC')
                ->get_where('services_providers', ['id_users' => $id])
                ->result_array(),
            'provider_secretary_links' => $this->db
                ->order_by('id_users_secretary', 'ASC')
                ->get_where('secretaries_providers', ['id_users_provider' => $id])
                ->result_array(),
            'secretary_provider_links' => $this->db
                ->order_by('id_users_provider', 'ASC')
                ->get_where('secretaries_providers', ['id_users_secretary' => $id])
                ->result_array(),
        ];
    }

    private function expectStatus(GateHttpResponse $response, int $expected, string $context): void
    {
        if ($response->statusCode !== $expected) {
            throw new RuntimeException('Staff API ' . $context . ' returned an unexpected status.');
        }
    }

    /** @return array<string,mixed> */
    private function decodeObject(string $body): array
    {
        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new RuntimeException('Staff API matching PUT returned invalid JSON.');
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new RuntimeException('Staff API matching PUT returned no staff object.');
        }
        return $decoded;
    }
}
