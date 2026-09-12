<?php defined('BASEPATH') or exit('No direct script access allowed');

/** Root-only lifecycle for the synthetic Zero Surprise release canary. */
final class Zero_surprise_canary_fixture
{
    public const DEFAULT_STATE_FILE = '/var/lib/fh-zero-surprise-canary/active.json';
    public const ACTOR_USERNAME = '__ea_zero_surprise_canary_v1';
    public const PROVIDER_USERNAME = '__ea_zero_surprise_provider_v1';
    private const LOCK_NAME = 'fh-zero-surprise-canary-v1';
    private const SCHEMA = 'zero_surprise_canary.v1';

    private EA_Controller|CI_Controller $CI;

    public function __construct()
    {
        $this->CI = &get_instance();
    }

    /** Run activate, verify, or deactivate and return a non-sensitive status. */
    public function run(string $action, string $stateFile = self::DEFAULT_STATE_FILE): string
    {
        if (!in_array($action, ['activate', 'verify', 'deactivate'], true)) {
            throw new InvalidArgumentException('Unsupported Zero Surprise canary action.');
        }
        $this->assertRootCli();
        if ($stateFile !== self::DEFAULT_STATE_FILE) {
            throw new InvalidArgumentException('The canary state path is fixed.');
        }
        $this->assertSafePath($stateFile);
        if ($action === 'verify') {
            return $this->verify($stateFile);
        }
        $this->lock();
        try {
            if (!$this->CI->db->trans_begin()) {
                throw new RuntimeException('Canary transaction could not start.');
            }
            $remove = false;
            try {
                $result = match ($action) {
                    'activate' => $this->activate($stateFile),
                    'verify' => $this->verify($stateFile, $remove),
                    'deactivate' => $this->deactivate($stateFile, $remove),
                };
                if ($this->CI->db->trans_status() === false || !$this->CI->db->trans_commit()) {
                    throw new RuntimeException('Canary transaction could not commit.');
                }
            } catch (Throwable $e) {
                $this->CI->db->trans_rollback();
                throw $e;
            }
            if ($remove && (!is_file($stateFile) || !unlink($stateFile))) {
                throw new RuntimeException('Canary state could not be removed after commit.');
            }
            return $result;
        } finally {
            $this->unlock();
        }
    }

    private function activate(string $stateFile): string
    {
        if (is_link($stateFile)) {
            throw new RuntimeException('A symlink cannot be used as canary state.');
        }
        if (file_exists($stateFile)) {
            $state = $this->readState($stateFile);
            throw new RuntimeException(
                ($state['expires_at'] ?? 0) > time()
                    ? 'A canary lease is already active.'
                    : 'An expired canary lease requires explicit cleanup.',
            );
        }
        $this->assertNoReservedRows();
        $runId = 'zs-canary-' . bin2hex(random_bytes(16));
        $token = bin2hex(random_bytes(32));
        $actorPassword = bin2hex(random_bytes(32));
        $now = time();
        $expires = $now + 600;
        $adminRole = $this->roleId('admin');
        $providerRole = $this->roleId('provider');
        $actorId = $this->insertUser(self::ACTOR_USERNAME, $adminRole, $runId, $now, true);
        $providerId = $this->insertUser(self::PROVIDER_USERNAME, $providerRole, $runId, $now, false);
        $this->insertSettings($actorId, self::ACTOR_USERNAME, $actorPassword, '{}');
        $plan = json_encode(
            array_fill_keys(
                ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'],
                ['start' => '08:00', 'end' => '18:00', 'breaks' => []],
            ),
            JSON_THROW_ON_ERROR,
        );
        $this->insertSettings($providerId, self::PROVIDER_USERNAME, '', $plan);
        $serviceMarker = $this->marker($runId, $actorId, $providerId, 0, $token, $expires);
        $this->insert('services', [
            'name' => 'Zero Surprise synthetic canary ' . $runId,
            'duration' => 30,
            'price' => 0,
            'currency' => 'EUR',
            'description' => $serviceMarker,
            'location' => 'Synthetic',
            'is_private' => 1,
            'attendants_number' => 1,
            'buffer_before' => 0,
            'buffer_after' => 0,
        ]);
        $serviceId = (int) $this->CI->db->insert_id();
        $serviceMarker = $this->marker($runId, $actorId, $providerId, $serviceId, $token, $expires);
        $this->updateMarker($actorId, $providerId, $serviceId, $serviceMarker);
        $state = [
            'schema' => self::SCHEMA,
            'run_id' => $runId,
            'actor_id' => $actorId,
            'actor_username' => self::ACTOR_USERNAME,
            'actor_password' => $actorPassword,
            'provider_id' => $providerId,
            'service_id' => $serviceId,
            'token' => $token,
            'expires_at' => $expires,
            'created_at' => $now,
        ];
        $this->insert('services_providers', ['id_users' => $providerId, 'id_services' => $serviceId]);
        $this->writeState($stateFile, $state);
        return 'active';
    }

    private function verify(string $stateFile, bool &$remove = false): string
    {
        if (!file_exists($stateFile) || is_link($stateFile)) {
            $this->assertNoReservedRows();
            return 'clean';
        }
        $state = $this->readState($stateFile);
        if ($this->stateHasNoRows($state)) {
            return 'cleanup_pending';
        }
        $this->assertOwnedState($state);
        return ($state['expires_at'] ?? 0) > time() ? 'active' : 'expired';
    }

    private function deactivate(string $stateFile, bool &$remove): string
    {
        if (!file_exists($stateFile) || is_link($stateFile)) {
            $this->assertNoReservedRows();
            return 'clean';
        }
        $state = $this->readState($stateFile);
        if (!$this->stateHasNoRows($state)) {
            $this->cleanupState($state);
        }
        $remove = true;
        return 'clean';
    }

    private function cleanupState(array $s): void
    {
        $this->validateState($s);
        $customerRows = $this->CI->db->get_where('users', ['notes' => 'run:' . $s['run_id']])->result_array();
        $ids = array_unique(
            array_merge([$s['actor_id'], $s['provider_id']], array_map('intval', array_column($customerRows, 'id'))),
        );
        sort($ids, SORT_NUMERIC);
        $usersTable = $this->CI->db->escape_identifiers($this->CI->db->dbprefix('users'));
        $servicesTable = $this->CI->db->escape_identifiers($this->CI->db->dbprefix('services'));
        $this->CI->db->query(
            'SELECT id FROM ' . $usersTable . ' WHERE id IN (' . implode(',', $ids) . ') ORDER BY id FOR UPDATE',
        );
        $this->CI->db->query('SELECT id FROM ' . $servicesTable . ' WHERE id = ? FOR UPDATE', [$s['service_id']]);
        $this->assertOwnedState($s);
        $run = $s['run_id'];
        $appointments = $this->CI->db
            ->like('notes', 'run:' . $run, 'after')
            ->get('appointments')
            ->result_array();
        foreach ($appointments as $a) {
            if (
                !in_array(
                    $a['notes'],
                    [
                        'run:' . $run,
                        'run:' . $run . ':register-success',
                        'run:' . $run . ':manage-update',
                        'run:' . $run . ':cancel-protect',
                    ],
                    true,
                )
            ) {
                throw new RuntimeException('Canary appointment ownership is ambiguous.');
            }
            if (
                (int) $a['id_users_provider'] !== (int) $s['provider_id'] ||
                (int) $a['id_services'] !== (int) $s['service_id']
            ) {
                throw new RuntimeException('Canary appointment ownership is ambiguous.');
            }
        }
        $foreign = $this->CI->db
            ->where('id_users_provider', (int) $s['provider_id'])
            ->or_where('id_services', (int) $s['service_id'])
            ->get('appointments')
            ->result_array();
        $ownedAppointmentIds = array_map('intval', array_column($appointments, 'id'));
        foreach ($foreign as $a) {
            if (!in_array((int) $a['id'], $ownedAppointmentIds, true)) {
                throw new RuntimeException('Foreign canary appointment reference detected.');
            }
        }
        $relations = $this->CI->db
            ->where_in('id_users', [$s['actor_id'], $s['provider_id']])
            ->or_where('id_services', $s['service_id'])
            ->get('services_providers')
            ->result_array();
        if (
            count($relations) !== 1 ||
            (int) $relations[0]['id_users'] !== $s['provider_id'] ||
            (int) $relations[0]['id_services'] !== $s['service_id']
        ) {
            throw new RuntimeException('Foreign canary service relationship detected.');
        }
        if (
            $this->CI->db
                ->where_in('id_users_secretary', [$s['actor_id'], $s['provider_id']])
                ->or_where_in('id_users_provider', [$s['actor_id'], $s['provider_id']])
                ->get('secretaries_providers')
                ->num_rows() !== 0 ||
            $this->CI->db
                ->where_in('id_users_customer', [$s['actor_id'], $s['provider_id']])
                ->or_where('id_users_provider', $s['actor_id'])
                ->get('appointments')
                ->num_rows() !== 0
        ) {
            throw new RuntimeException('Foreign canary user relationship detected.');
        }
        foreach ($appointments as $a) {
            $this->dbDelete('appointments', ['id' => (int) $a['id']]);
        }
        $customerRole = $this->roleId('customer');
        $customers = $this->CI->db
            ->get_where('users', ['notes' => 'run:' . $run, 'id_roles' => $customerRole])
            ->result_array();
        foreach ($customers as $customer) {
            $references = $this->CI->db
                ->get_where('appointments', ['id_users_customer' => (int) $customer['id']])
                ->result_array();
            if ($references) {
                throw new RuntimeException('Canary customer ownership is ambiguous.');
            }
            $this->dbDelete('user_settings', ['id_users' => (int) $customer['id']]);
            $this->dbDelete('users', [
                'id' => (int) $customer['id'],
                'id_roles' => $customerRole,
                'notes' => 'run:' . $run,
            ]);
        }
        $this->dbDelete('services_providers', [
            'id_users' => (int) $s['provider_id'],
            'id_services' => (int) $s['service_id'],
        ]);
        $this->dbDelete('services', [
            'id' => (int) $s['service_id'],
            'description' => $this->marker(
                $run,
                (int) $s['actor_id'],
                (int) $s['provider_id'],
                (int) $s['service_id'],
                $s['token'],
                (int) $s['expires_at'],
            ),
        ]);
        $this->dbDelete('user_settings', ['id_users' => (int) $s['provider_id']]);
        $this->dbDelete('users', [
            'id' => (int) $s['provider_id'],
            'notes' => $this->marker(
                $run,
                (int) $s['actor_id'],
                (int) $s['provider_id'],
                (int) $s['service_id'],
                $s['token'],
                (int) $s['expires_at'],
            ),
        ]);
        $this->dbDelete('user_settings', ['id_users' => (int) $s['actor_id']]);
        $this->dbDelete('users', [
            'id' => (int) $s['actor_id'],
            'notes' => $this->marker(
                $run,
                (int) $s['actor_id'],
                (int) $s['provider_id'],
                (int) $s['service_id'],
                $s['token'],
                (int) $s['expires_at'],
            ),
        ]);
        if (
            $this->CI->db->get_where('services', ['id' => (int) $s['service_id']])->num_rows() ||
            $this->CI->db->get_where('users', ['id' => (int) $s['provider_id']])->num_rows()
        ) {
            throw new RuntimeException('Canary rows remain after cleanup.');
        }
    }

    private function assertOwnedState(array $s): void
    {
        $this->validateState($s);
        foreach (
            ['schema', 'run_id', 'actor_id', 'provider_id', 'service_id', 'token', 'expires_at', 'created_at']
            as $k
        ) {
            if (!array_key_exists($k, $s)) {
                throw new RuntimeException('Invalid canary state.');
            }
        }
        if (
            $s['schema'] !== self::SCHEMA ||
            !preg_match('/^zs-canary-[0-9a-f]{32}$/', $s['run_id']) ||
            !preg_match('/^[0-9a-f]{64}$/', (string) $s['token']) ||
            !preg_match('/^[0-9a-f]{64}$/', (string) $s['actor_password'])
        ) {
            throw new RuntimeException('Invalid canary state.');
        }
        $marker = $this->marker(
            $s['run_id'],
            (int) $s['actor_id'],
            (int) $s['provider_id'],
            (int) $s['service_id'],
            $s['token'],
            (int) $s['expires_at'],
        );
        $actor = $this->CI->db->get_where('users', ['id' => (int) $s['actor_id'], 'notes' => $marker])->row_array();
        $provider = $this->CI->db
            ->get_where('users', ['id' => (int) $s['provider_id'], 'notes' => $marker])
            ->row_array();
        $service = $this->CI->db
            ->get_where('services', ['id' => (int) $s['service_id'], 'description' => $marker])
            ->row_array();
        if (!$actor || !$provider || !$service) {
            throw new RuntimeException('Canary ownership check failed.');
        }
        if (
            (int) $actor['id_roles'] !== $this->roleId('admin') ||
            (int) $provider['id_roles'] !== $this->roleId('provider') ||
            $this->CI->db
                ->get_where('user_settings', ['id_users' => $s['actor_id'], 'username' => self::ACTOR_USERNAME])
                ->num_rows() !== 1 ||
            $this->CI->db
                ->get_where('user_settings', ['id_users' => $s['provider_id'], 'username' => self::PROVIDER_USERNAME])
                ->num_rows() !== 1
        ) {
            throw new RuntimeException('Canary principal identity changed.');
        }
    }

    private function stateHasNoRows(array $state): bool
    {
        $this->validateState($state);
        $users = $this->CI->db
            ->where_in('id', [$state['actor_id'], $state['provider_id']])
            ->get('users')
            ->num_rows();
        $service = $this->CI->db->get_where('services', ['id' => $state['service_id']])->num_rows();
        if ($users !== 0 || $service !== 0) {
            return false;
        }
        $this->assertNoReservedRows();
        if (
            $this->CI->db->get_where('users', ['notes' => 'run:' . $state['run_id']])->num_rows() !== 0 ||
            $this->CI->db
                ->like('notes', 'run:' . $state['run_id'], 'after')
                ->get('appointments')
                ->num_rows() !== 0
        ) {
            throw new RuntimeException('Orphaned canary children require investigation.');
        }
        return true;
    }

    private function validateState(array $state): void
    {
        $keys = [
            'schema',
            'run_id',
            'actor_id',
            'actor_username',
            'actor_password',
            'provider_id',
            'service_id',
            'token',
            'expires_at',
            'created_at',
        ];
        if (
            count($state) !== count($keys) ||
            array_diff(array_keys($state), $keys) !== [] ||
            ($state['schema'] ?? null) !== self::SCHEMA ||
            !is_string($state['run_id'] ?? null) ||
            !preg_match('/^zs-canary-[a-f0-9]{32}$/D', $state['run_id']) ||
            ($state['actor_username'] ?? null) !== self::ACTOR_USERNAME
        ) {
            throw new RuntimeException('Invalid canary state identity.');
        }
        foreach (['token', 'actor_password'] as $key) {
            if (!is_string($state[$key] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $state[$key])) {
                throw new RuntimeException('Invalid canary state credentials.');
            }
        }
        foreach (['actor_id', 'provider_id', 'service_id', 'expires_at', 'created_at'] as $key) {
            if (!is_int($state[$key] ?? null) || $state[$key] < 1) {
                throw new RuntimeException('Invalid canary state identifier.');
            }
        }
        if ($state['actor_id'] === $state['provider_id'] || $state['expires_at'] - $state['created_at'] !== 600) {
            throw new RuntimeException('Invalid canary state lease.');
        }
    }

    private function assertNoReservedRows(): void
    {
        $actor = $this->CI->db->get_where('user_settings', ['username' => self::ACTOR_USERNAME])->num_rows();
        $provider = $this->CI->db->get_where('user_settings', ['username' => self::PROVIDER_USERNAME])->num_rows();
        $orphanPrincipals = $this->CI->db
            ->where_in('email', [
                self::ACTOR_USERNAME . '@synthetic.invalid',
                self::PROVIDER_USERNAME . '@synthetic.invalid',
            ])
            ->get('users')
            ->num_rows();
        $orphanServices = $this->CI->db
            ->like('description', '{"schema":"' . self::SCHEMA . '",', 'after')
            ->get('services')
            ->num_rows();
        if ($actor !== 0 || $provider !== 0 || $orphanPrincipals !== 0 || $orphanServices !== 0) {
            throw new RuntimeException('Canary state is absent but reserved fixture rows remain.');
        }
    }

    private function insertUser(string $username, int $role, string $run, int $now, bool $admin): int
    {
        $this->insert('users', [
            'first_name' => 'Synthetic',
            'last_name' => $admin ? 'Zero Surprise Canary Admin' : 'Zero Surprise Canary Provider',
            'email' => $username . '@synthetic.invalid',
            'phone_number' => null,
            'mobile_number' => null,
            'notes' => '',
            'timezone' => 'UTC',
            'language' => 'english',
            'is_private' => 1,
            'id_roles' => $role,
            'create_datetime' => date('Y-m-d H:i:s', $now),
            'update_datetime' => date('Y-m-d H:i:s', $now),
        ]);
        return (int) $this->CI->db->insert_id();
    }
    private function insertSettings(int $id, string $username, string $password, string $plan): void
    {
        $salt = $password === '' ? null : generate_salt();
        $this->insert('user_settings', [
            'id_users' => $id,
            'username' => $username,
            'password' => $password === '' ? null : hash_password($salt, $password),
            'salt' => $salt,
            'working_plan' => $plan,
            'notifications' => 0,
            'google_sync' => 0,
            'caldav_sync' => 0,
            'sync_past_days' => 0,
            'sync_future_days' => 0,
        ]);
    }
    private function roleId(string $slug): int
    {
        $r = $this->CI->db->get_where('roles', ['slug' => $slug])->row_array();
        if (!$r) {
            throw new RuntimeException('Canary role missing.');
        }
        return (int) $r['id'];
    }
    private function marker(string $run, int $a, int $p, int $s, string $token, int $expires): string
    {
        return json_encode(
            [
                'schema' => self::SCHEMA,
                'run_id' => $run,
                'token_hash' => hash('sha256', $token),
                'expires_at' => $expires,
                'actor_id' => $a,
                'provider_id' => $p,
                'service_id' => $s,
            ],
            JSON_THROW_ON_ERROR,
        );
    }
    private function updateMarker(int $a, int $p, int $s, string $marker): void
    {
        if (
            !$this->CI->db->update('users', ['notes' => $marker], ['id' => $a]) ||
            !$this->CI->db->update('users', ['notes' => $marker], ['id' => $p]) ||
            !$this->CI->db->update('services', ['description' => $marker], ['id' => $s]) ||
            $this->CI->db->trans_status() === false
        ) {
            throw new RuntimeException('Canary marker update failed.');
        }
    }
    private function insert(string $table, array $data): void
    {
        if (!$this->CI->db->insert($table, $data) || $this->CI->db->trans_status() === false) {
            throw new RuntimeException('Canary insert failed.');
        }
    }
    private function dbDelete(string $table, array $where): void
    {
        if (!$this->CI->db->delete($table, $where) || $this->CI->db->trans_status() === false) {
            throw new RuntimeException('Canary cleanup mutation failed.');
        }
    }
    private function readState(string $path): array
    {
        $d = file_get_contents($path);
        $s = json_decode((string) $d, true);
        if (!is_array($s)) {
            throw new RuntimeException('Invalid canary state.');
        }
        return $s;
    }
    private function writeState(string $path, array $state): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0700, true)) {
            throw new RuntimeException('Could not create canary state directory.');
        }
        $tmp = $path . '.' . bin2hex(random_bytes(8));
        $json = json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $handle = fopen($tmp, 'x');
        if (
            $handle === false ||
            fwrite($handle, $json) !== strlen($json) ||
            !fflush($handle) ||
            (function_exists('fsync') && !fsync($handle)) ||
            !fclose($handle) ||
            !chmod($tmp, 0600) ||
            !link($tmp, $path)
        ) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            @unlink($tmp);
            throw new RuntimeException('Could not persist canary state.');
        }
        unlink($tmp);
    }
    private function lock(): void
    {
        $result = $this->CI->db->query('SELECT GET_LOCK(' . $this->CI->db->escape(self::LOCK_NAME) . ', 30)');
        $row = $result ? $result->row_array() : null;
        if (!$row || (int) array_values($row)[0] !== 1) {
            throw new RuntimeException('Canary lifecycle lock could not be acquired.');
        }
    }
    private function unlock(): void
    {
        $this->CI->db->query('SELECT RELEASE_LOCK(' . $this->CI->db->escape(self::LOCK_NAME) . ')');
    }
    private function assertRootCli(): void
    {
        if (!is_cli() || !function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            throw new RuntimeException('Root CLI required.');
        }
    }
    private function assertSafePath(string $path): void
    {
        $dir = dirname($path);
        if (!file_exists($dir) && !is_link($dir) && !mkdir($dir, 0700)) {
            throw new RuntimeException('Could not create canary state directory.');
        }
        foreach (['/var', '/var/lib', $dir] as $directory) {
            $stat = lstat($directory);
            if (!$stat || ($stat['mode'] & 0170000) !== 0040000 || $stat['uid'] !== 0 || ($stat['mode'] & 0022) !== 0) {
                throw new RuntimeException('Canary state ancestry is unsafe.');
            }
        }
        $stat = lstat($dir);
        if (($stat['mode'] & 0777) !== 0700 || $stat['gid'] !== 0) {
            throw new RuntimeException('Canary state directory must be root-only.');
        }
        if (file_exists($path) || is_link($path)) {
            $stat = lstat($path);
            if (
                !$stat ||
                ($stat['mode'] & 0170000) !== 0100000 ||
                $stat['uid'] !== 0 ||
                $stat['gid'] !== 0 ||
                ($stat['mode'] & 0777) !== 0600 ||
                $stat['nlink'] !== 1
            ) {
                throw new RuntimeException('Canary state must be a single-link root-only regular file.');
            }
        }
    }
}
