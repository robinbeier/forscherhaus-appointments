<?php

declare(strict_types=1);

namespace ReleaseGate;

use RuntimeException;
use Throwable;

/** Direct ROB-551 read/update/delete denial checks against owned synthetic staff rows. */
final class CustomerRoleBoundaryProbe
{
    /** @var callable(?string):void */
    private $rememberSession;

    public function __construct(
        private readonly GateHttpClient $client,
        private readonly object $db,
        ?callable $rememberSession = null,
    ) {
        $this->rememberSession = $rememberSession ?? static function (?string $session): void {};
    }

    /**
     * @param array{user_id:int,username:string,password:string,email:string,marker:string} $actor
     * @param array{profile:string,marker:string,search_marker:string,provider_target_id:int,admin_target_id:int,customer_update_id:int,customer_delete_id:int} $fixture
     * @return array{status:string,coverage:string,search_status:int,denial_statuses:array<string,array<string,int>>,positive_statuses:array<string,int>,observed:string}
     */
    public function run(array $actor, array $fixture, ?callable $observe = null): array
    {
        $this->assertContext($actor, $fixture);
        $observe ??= static function (string $phase, string $outcome): void {};
        $loggedIn = false;
        $result = null;
        $probeError = null;
        $logoutError = null;

        try {
            $observe('boundary_login', 'started');
            $this->login($actor);
            $loggedIn = true;
            $observe('boundary_login', 'passed');

            $staffBaselines = [
                'provider' => $this->staffSnapshot($fixture['provider_target_id'], $fixture['marker']),
                'admin' => $this->staffSnapshot($fixture['admin_target_id'], $fixture['marker']),
            ];

            $observe('boundary_search', 'started');
            $search = $this->client->post('customers/search', [
                'keyword' => $fixture['search_marker'],
                'limit' => 20,
                'offset' => 0,
                'order_by' => '',
            ]);
            $this->remember();
            $this->expectStatus($search, 200, 'customer role-boundary search');
            $searchRows = $this->decodeArray($search);
            $returnedIds = array_map(static fn(array $row): int => (int) ($row['id'] ?? 0), $searchRows);
            sort($returnedIds, SORT_NUMERIC);
            $expectedCustomerIds = [$fixture['customer_update_id'], $fixture['customer_delete_id']];
            sort($expectedCustomerIds, SORT_NUMERIC);
            if ($returnedIds !== $expectedCustomerIds) {
                throw new RuntimeException('Customer search did not return exactly the owned customer-role fixtures.');
            }
            foreach ($staffBaselines as $role => $staff) {
                $this->assertResponseExcludesStaff(
                    $search,
                    array_merge($staff['user'], ['username' => $staff['settings']['username']]),
                    (int) $fixture[$role . '_target_id'],
                    checkMarker: false,
                );
            }
            $observe('boundary_search', 'passed');

            $denials = [];
            foreach (
                ['provider' => $fixture['provider_target_id'], 'admin' => $fixture['admin_target_id']]
                as $role => $targetId
            ) {
                $before = $staffBaselines[$role];
                $denials[$role] = [];

                foreach (['find', 'update', 'destroy'] as $operation) {
                    $phase = 'boundary_' . $role . '_' . $operation;
                    $observe($phase, 'started');
                    $response = match ($operation) {
                        'find' => $this->client->post('customers/find', ['customer_id' => $targetId]),
                        'update' => $this->client->post('customers/update', [
                            'customer' => $this->customerPayload($before['user'], 'Must Not Change'),
                        ]),
                        'destroy' => $this->client->post('customers/destroy', ['customer_id' => $targetId]),
                    };
                    $this->remember();
                    $this->expectStatus($response, 403, $phase);
                    $this->assertResponseExcludesStaff(
                        $response,
                        array_merge($before['user'], ['username' => $before['settings']['username']]),
                        $targetId,
                    );
                    if ($this->staffSnapshot($targetId, $fixture['marker']) !== $before) {
                        throw new RuntimeException($phase . ' changed an owned synthetic staff row.');
                    }
                    $denials[$role][$operation] = $response->statusCode;
                    $observe($phase, 'passed');
                }
            }

            $observe('boundary_customer_find', 'started');
            $find = $this->client->post('customers/find', ['customer_id' => $fixture['customer_update_id']]);
            $this->remember();
            $this->expectStatus($find, 200, 'owned customer find positive control');
            $found = $this->decodeObject($find);
            if ((int) ($found['id'] ?? 0) !== $fixture['customer_update_id']) {
                throw new RuntimeException('Owned customer find positive control returned another row.');
            }
            $observe('boundary_customer_find', 'passed');

            $observe('boundary_customer_update', 'started');
            $beforeCustomer = $this->customerSnapshot($fixture['customer_update_id'], $fixture['marker']);
            $update = $this->client->post('customers/update', [
                'customer' => $this->customerPayload($beforeCustomer, 'Boundary Verified'),
            ]);
            $this->remember();
            $this->expectSuccessfulMutation($update, 'owned customer update positive control');
            $afterCustomer = $this->customerSnapshot($fixture['customer_update_id'], $fixture['marker']);
            if (($afterCustomer['last_name'] ?? null) !== 'Boundary Verified') {
                throw new RuntimeException('Owned customer update positive control was not persisted.');
            }
            $beforeCustomer['last_name'] = $afterCustomer['last_name'];
            $beforeCustomer['update_datetime'] = $afterCustomer['update_datetime'] ?? null;
            if ($beforeCustomer !== $afterCustomer) {
                throw new RuntimeException('Owned customer update changed fields outside its positive control.');
            }
            $observe('boundary_customer_update', 'passed');

            $observe('boundary_customer_destroy', 'started');
            $deleteBefore = $this->customerSnapshot($fixture['customer_delete_id'], $fixture['marker']);
            if ($deleteBefore === []) {
                throw new RuntimeException('Owned customer delete positive control is unavailable.');
            }
            $destroy = $this->client->post('customers/destroy', ['customer_id' => $fixture['customer_delete_id']]);
            $this->remember();
            $this->expectSuccessfulMutation($destroy, 'owned customer destroy positive control');
            if ($this->db->get_where('users', ['id' => $fixture['customer_delete_id']])->num_rows() !== 0) {
                throw new RuntimeException('Owned customer destroy positive control was not persisted.');
            }
            $observe('boundary_customer_destroy', 'passed');

            $result = [
                'status' => 'verified',
                'coverage' => 'complete',
                'search_status' => $search->statusCode,
                'denial_statuses' => $denials,
                'positive_statuses' => [
                    'find' => $find->statusCode,
                    'update' => $update->statusCode,
                    'destroy' => $destroy->statusCode,
                ],
                'observed' =>
                    'Owned synthetic provider and administrator identifiers were absent from customer responses; user rows, login settings, appointments, service assignments, and secretary links stayed unchanged across denied read, update, and delete requests; owned customer controls succeeded.',
            ];
        } catch (Throwable $error) {
            $probeError = $error;
        } finally {
            if ($loggedIn) {
                try {
                    $observe('boundary_logout', 'started');
                    $logout = $this->client->get('logout');
                    $this->remember();
                    $this->expectStatus($logout, 200, 'customer role-boundary logout');
                    $afterLogout = $this->client->get('customers');
                    $this->remember();
                    if ($afterLogout->statusCode !== 307) {
                        throw new RuntimeException(
                            'Customer role-boundary session remained authenticated after logout.',
                        );
                    }
                    $observe('boundary_logout', 'passed');
                } catch (Throwable $error) {
                    $observe('boundary_logout', 'failed');
                    $logoutError = $error;
                }
            }
        }

        if ($probeError !== null) {
            throw $probeError;
        }
        if ($logoutError !== null) {
            throw $logoutError;
        }
        if (!is_array($result)) {
            throw new RuntimeException('Customer role-boundary probe produced no result.');
        }

        return $result;
    }

    /** @param array<string,mixed> $actor @param array<string,mixed> $fixture */
    private function assertContext(array $actor, array $fixture): void
    {
        foreach (['user_id', 'username', 'password', 'email', 'marker'] as $key) {
            if (!isset($actor[$key]) || $actor[$key] === '' || ($key === 'user_id' && (int) $actor[$key] < 1)) {
                throw new RuntimeException('Customer role-boundary actor context is incomplete.');
            }
        }
        if (($fixture['profile'] ?? null) !== 'customer_boundary') {
            throw new RuntimeException('Customer role-boundary fixture profile is invalid.');
        }
        foreach (
            [
                'marker',
                'search_marker',
                'provider_target_id',
                'admin_target_id',
                'customer_update_id',
                'customer_delete_id',
            ]
            as $key
        ) {
            if (
                !isset($fixture[$key]) ||
                $fixture[$key] === '' ||
                (str_ends_with($key, '_id') && (int) $fixture[$key] < 1)
            ) {
                throw new RuntimeException('Customer role-boundary fixture context is incomplete.');
            }
        }
        $role = $this->db
            ->select('roles.slug')
            ->from('users')
            ->join('roles', 'roles.id = users.id_roles', 'inner')
            ->where('users.id', (int) $actor['user_id'])
            ->where('users.email', (string) $actor['email'])
            ->where('users.notes', (string) $actor['marker'])
            ->get()
            ->row_array();
        if (($role['slug'] ?? null) !== 'admin') {
            throw new RuntimeException('Customer role-boundary actor is not the owned synthetic administrator.');
        }
    }

    /** @param array<string,mixed> $actor */
    private function login(array $actor): void
    {
        $loginPage = $this->client->get('login');
        $this->remember();
        $this->expectStatus($loginPage, 200, 'customer role-boundary login page');
        $login = $this->client->post('login/validate', [
            'username' => $actor['username'],
            'password' => $actor['password'],
        ]);
        $this->remember();
        $this->expectStatus($login, 200, 'customer role-boundary login');
        $data = $this->decodeObject($login);
        if (($data['success'] ?? false) !== true) {
            throw new RuntimeException('Customer role-boundary login failed.');
        }
    }

    /**
     * @return array{
     *   user:array<string,mixed>,
     *   settings:array<string,mixed>,
     *   provider_appointments:list<array<string,mixed>>,
     *   customer_appointments:list<array<string,mixed>>,
     *   services:list<array<string,mixed>>,
     *   provider_secretary_links:list<array<string,mixed>>,
     *   secretary_provider_links:list<array<string,mixed>>
     * }
     */
    private function staffSnapshot(int $id, string $marker): array
    {
        $user = $this->db->get_where('users', ['id' => $id, 'notes' => $marker])->row_array();
        $settings = $this->db->get_where('user_settings', ['id_users' => $id])->row_array();
        if (!is_array($user) || $user === [] || !is_array($settings) || $settings === []) {
            throw new RuntimeException('Owned synthetic staff snapshot is unavailable.');
        }

        return [
            'user' => $user,
            'settings' => $settings,
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

    /** @param array<string,mixed> $staff */
    private function assertResponseExcludesStaff(
        GateHttpResponse $response,
        array $staff,
        int $targetId,
        bool $checkMarker = true,
    ): void {
        $fields = $checkMarker
            ? [
                'first_name',
                'last_name',
                'email',
                'phone_number',
                'mobile_number',
                'address',
                'city',
                'state',
                'zip_code',
                'custom_field_1',
                'custom_field_2',
                'custom_field_3',
                'custom_field_4',
                'custom_field_5',
                'ldap_dn',
                'username',
            ]
            : ['last_name', 'email', 'username'];
        foreach ($fields as $field) {
            $value = (string) ($staff[$field] ?? '');
            if ($value !== '' && str_contains($response->body, $value)) {
                throw new RuntimeException('Customer path response exposed an owned synthetic staff identifier.');
            }
        }
        $marker = (string) ($staff['notes'] ?? '');
        if ($checkMarker && $marker !== '' && str_contains($response->body, $marker)) {
            throw new RuntimeException('Customer path response exposed an owned synthetic staff marker.');
        }

        $decoded = json_decode($response->body, true);
        if (json_last_error() === JSON_ERROR_NONE && $this->containsTargetId($decoded, $targetId)) {
            throw new RuntimeException('Customer path response exposed an owned synthetic staff ID.');
        }
    }

    private function containsTargetId(mixed $value, int $targetId, ?string $key = null): bool
    {
        if (is_array($value)) {
            foreach ($value as $childKey => $child) {
                if ($this->containsTargetId($child, $targetId, is_string($childKey) ? $childKey : null)) {
                    return true;
                }
            }
            return false;
        }
        return in_array(
            $key,
            [
                'id',
                'user_id',
                'customer_id',
                'provider_id',
                'admin_id',
                'staff_id',
                'id_users',
                'id_users_provider',
                'id_users_customer',
            ],
            true,
        ) && (int) $value === $targetId;
    }

    /** @return array<string,mixed> */
    private function customerSnapshot(int $id, string $marker): array
    {
        $row = $this->db->get_where('users', ['id' => $id, 'notes' => $marker])->row_array();
        if (!is_array($row) || $row === []) {
            throw new RuntimeException('Owned synthetic customer snapshot is unavailable.');
        }

        return $row;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function customerPayload(array $row, string $lastName): array
    {
        $allowed = [
            'id',
            'first_name',
            'last_name',
            'email',
            'phone_number',
            'mobile_number',
            'address',
            'city',
            'state',
            'zip_code',
            'notes',
            'timezone',
            'language',
            'custom_field_1',
            'custom_field_2',
            'custom_field_3',
            'custom_field_4',
            'custom_field_5',
            'ldap_dn',
        ];
        $payload = array_intersect_key($row, array_flip($allowed));
        $payload['last_name'] = $lastName;

        return $payload;
    }

    /** @return array<string,mixed> */
    private function decodeObject(GateHttpResponse $response): array
    {
        $data = json_decode($response->body, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($data) || array_is_list($data)) {
            throw new RuntimeException('Customer role-boundary response object is invalid.');
        }

        return $data;
    }

    /** @return list<array<string,mixed>> */
    private function decodeArray(GateHttpResponse $response): array
    {
        $data = json_decode($response->body, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($data) || !array_is_list($data)) {
            throw new RuntimeException('Customer role-boundary response list is invalid.');
        }
        foreach ($data as $row) {
            if (!is_array($row)) {
                throw new RuntimeException('Customer role-boundary response contains an invalid row.');
            }
        }

        return $data;
    }

    private function expectSuccessfulMutation(GateHttpResponse $response, string $operation): void
    {
        $this->expectStatus($response, 200, $operation);
        $data = $this->decodeObject($response);
        if (($data['success'] ?? false) !== true) {
            throw new RuntimeException($operation . ' did not report success.');
        }
    }

    private function remember(): void
    {
        ($this->rememberSession)($this->client->getCookie('ea_session'));
    }

    private function expectStatus(GateHttpResponse $response, int $expected, string $operation): void
    {
        if ($response->statusCode !== $expected) {
            throw new RuntimeException($operation . ' returned an unexpected HTTP status.');
        }
    }
}
