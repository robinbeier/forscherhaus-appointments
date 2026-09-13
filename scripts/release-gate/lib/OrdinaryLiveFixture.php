<?php

declare(strict_types=1);

namespace ReleaseGate;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** Root-only lifecycle for one ordinary, disposable operator account. */
final class OrdinaryLiveFixture
{
    private const SCHEMA = 'ordinary-live-fixture.v2';
    private const MAX_TTL = 10800;

    private object $db;
    private string $stateDirectory;
    private string $stateFile;
    private string $lockFile;

    public function __construct(string $stateDirectory)
    {
        $this->assertRootCli();
        $this->stateDirectory = $this->prepareDirectory($stateDirectory);
        $this->stateFile = $this->stateDirectory . DIRECTORY_SEPARATOR . 'state.json';
        $this->lockFile = $this->stateDirectory . DIRECTORY_SEPARATOR . 'lifecycle.lock';
        $ci = &\get_instance();
        $this->db = $ci->db;
    }

    /** @return array<string, mixed> */
    public function activate(int $ttlSeconds = self::MAX_TTL, string $roleSlug = 'provider'): array
    {
        if ($ttlSeconds !== self::MAX_TTL) {
            throw new InvalidArgumentException('The ordinary live fixture TTL is fixed at 10800 seconds.');
        }
        if (!in_array($roleSlug, ['provider', 'admin'], true)) {
            throw new InvalidArgumentException('The ordinary live fixture role must be provider or admin.');
        }

        return $this->withLock(function () use ($ttlSeconds, $roleSlug): array {
            if (is_link($this->stateFile) || is_file($this->stateFile)) {
                throw new RuntimeException('An ordinary live fixture already exists; deactivate it first.');
            }
            $this->assertCleanDatabaseBeforeActivation();
            $role = $this->db->get_where('roles', ['slug' => $roleSlug])->row_array();
            if (empty($role['id'])) {
                throw new RuntimeException('Requested ordinary fixture role is missing.');
            }
            $runId = 'ordinary-live-' . bin2hex(random_bytes(16));
            $username = 'defense_live_' . bin2hex(random_bytes(16));
            $email = $username . '@synthetic.invalid';
            $marker = 'ordinary-live:' . $runId;
            $password = bin2hex(random_bytes(32));
            $created = time();
            $state = [
                'schema' => self::SCHEMA,
                'run_id' => $runId,
                'marker' => $marker,
                'user_id' => 0,
                'role_id' => (int) $role['id'],
                'role_slug' => $roleSlug,
                'username' => $username,
                'email' => $email,
                'password' => $password,
                'created_at' => $created,
                'expires_at' => $created + $ttlSeconds,
                'phase' => 'prepared',
            ];
            $this->assertNoCollision($username, $email, $marker);
            // The intent is durable before the first database insert, so a
            // failed process leaves enough exact identity to recover safely.
            $this->writeState($state);
            try {
                if (!$this->db->trans_begin()) {
                    throw new RuntimeException('Fixture transaction could not start.');
                }
                try {
                    $this->db->insert('users', [
                        'first_name' => 'Defense',
                        'last_name' => $roleSlug === 'admin' ? 'Live Admin' : 'Live Provider',
                        'email' => $email,
                        'phone_number' => '000000000',
                        'notes' => $marker,
                        'timezone' => 'UTC',
                        'language' => 'english',
                        'id_roles' => (int) $role['id'],
                        'is_private' => 1,
                    ]);
                    $userId = (int) $this->db->insert_id();
                    if ($userId < 1) {
                        throw new RuntimeException('Fixture user insert did not return an ID.');
                    }
                    $salt = \generate_salt();
                    $this->db->insert('user_settings', [
                        'id_users' => $userId,
                        'username' => $username,
                        'password' => \hash_password($salt, $password),
                        'salt' => $salt,
                        'working_plan' => '{}',
                        'working_plan_exceptions' => '{}',
                        'notifications' => 0,
                        'google_sync' => 0,
                        'caldav_sync' => 0,
                        'calendar_view' => 'default',
                    ]);
                    if ($this->db->trans_status() === false || !$this->db->trans_commit()) {
                        throw new RuntimeException('Fixture transaction could not commit.');
                    }
                    $state['user_id'] = $userId;
                    $state['phase'] = 'active';
                    $this->writeState($state);
                    return $state;
                } catch (Throwable $e) {
                    $this->db->trans_rollback();
                    throw $e;
                }
            } catch (Throwable $e) {
                // Keep the prepared journal; deactivate() can remove only an
                // exact matching row if the process failed after an insert.
                throw $e;
            }
        });
    }

    public function assertCleanBeforeActivation(): void
    {
        $this->withLock(function (): void {
            if (is_link($this->stateFile) || is_file($this->stateFile)) {
                throw new RuntimeException('An ordinary live fixture already exists; deactivate it first.');
            }
            $this->assertCleanDatabaseBeforeActivation();
        });
    }

    /** @return array<string, mixed> */
    public function read(): array
    {
        if (!is_file($this->stateFile) || is_link($this->stateFile)) {
            throw new RuntimeException('No ordinary live fixture is active.');
        }
        $state = $this->readState();
        $this->validateState($state);
        if ($state['phase'] !== 'active') {
            throw new RuntimeException('A prepared fixture is not an active usable context.');
        }
        $this->assertDatabaseOwnership($state);
        return $state;
    }

    public function verify(): string
    {
        if (!file_exists($this->stateFile)) {
            $leftovers =
                $this->db->like('notes', 'ordinary-live:', 'after')->get('users')->num_rows() +
                $this->db->like('username', 'defense_live_', 'after')->get('user_settings')->num_rows();
            return $leftovers === 0 ? 'clean' : 'cleanup_pending';
        }
        $state = $this->readState();
        $this->validateState($state);
        if ($state['phase'] !== 'active') {
            return 'cleanup_pending';
        }
        $this->assertDatabaseOwnership($state);
        return (int) $state['expires_at'] > time() ? 'active' : 'expired';
    }

    public function deactivate(): void
    {
        $this->withLock(function (): void {
            if (!file_exists($this->stateFile)) {
                return;
            }
            $state = $this->readState();
            $this->validateState($state);
            if (!$this->db->trans_begin()) {
                throw new RuntimeException('Fixture cleanup transaction could not start.');
            }
            try {
                $this->cleanupExact($state);
                if ($this->db->trans_status() === false || !$this->db->trans_commit()) {
                    throw new RuntimeException('Fixture cleanup transaction could not commit.');
                }
            } catch (Throwable $e) {
                $this->db->trans_rollback();
                throw $e;
            }
            if (!unlink($this->stateFile)) {
                throw new RuntimeException('Fixture state could not be removed.');
            }
        });
    }

    /** @param array<string, mixed> $state */
    private function cleanupExact(array $state): void
    {
        $userId = (int) $state['user_id'];
        $query = $this->db
            ->select('users.*')
            ->from('users')
            ->join('user_settings', 'user_settings.id_users = users.id', 'inner')
            ->where('users.notes', $state['marker'])
            ->where('users.email', $state['email'])
            ->where('users.id_roles', (int) $state['role_id'])
            ->where('user_settings.username', $state['username'])
            ->get();
        $user = $query->row_array();
        if ($query->num_rows() === 0 && $userId > 0) {
            if (
                $this->db->get_where('users', ['id' => $userId])->num_rows() !== 0 ||
                $this->db->get_where('user_settings', ['id_users' => $userId])->num_rows() !== 0
            ) {
                throw new RuntimeException('Fixture ownership is ambiguous; refusing cleanup.');
            }
            foreach (
                [
                    ['appointments', ['id_users_provider' => $userId]],
                    ['appointments', ['id_users_customer' => $userId]],
                    ['services_providers', ['id_users' => $userId]],
                    ['secretaries_providers', ['id_users_provider' => $userId]],
                    ['secretaries_providers', ['id_users_secretary' => $userId]],
                ]
                as [$table, $where]
            ) {
                if ($this->db->get_where($table, $where)->num_rows() !== 0) {
                    throw new RuntimeException('Fixture ownership is ambiguous; refusing cleanup.');
                }
            }
            return;
        }
        if ($query->num_rows() !== 1) {
            if ($userId === 0 && $query->num_rows() === 0) {
                $this->assertNoCollision($state['username'], $state['email'], $state['marker']);
                return;
            }
            throw new RuntimeException('Fixture ownership is ambiguous; refusing cleanup.');
        }
        $foundUserId = (int) $user['id'];
        if ($userId !== 0 && $foundUserId !== $userId) {
            throw new RuntimeException('Fixture user ID changed; refusing cleanup.');
        }
        $usersTable = $this->db->escape_identifiers($this->db->dbprefix('users'));
        $lockedUser = $this->db
            ->query('SELECT * FROM ' . $usersTable . ' WHERE id = ? FOR UPDATE', [$foundUserId])
            ->row_array();
        $settingsTable = $this->db->escape_identifiers($this->db->dbprefix('user_settings'));
        $lockedSettings = $this->db
            ->query('SELECT * FROM ' . $settingsTable . ' WHERE id_users = ? FOR UPDATE', [$foundUserId])
            ->row_array();
        if (
            ($lockedUser['notes'] ?? null) !== $state['marker'] ||
            ($lockedUser['email'] ?? null) !== $state['email'] ||
            (int) ($lockedUser['id_roles'] ?? 0) !== (int) $state['role_id'] ||
            ($lockedSettings['username'] ?? null) !== $state['username']
        ) {
            throw new RuntimeException('Fixture identity changed while cleanup was locking it.');
        }
        foreach (
            [
                ['appointments', ['id_users_provider' => $foundUserId]],
                ['appointments', ['id_users_customer' => $foundUserId]],
                ['services_providers', ['id_users' => $foundUserId]],
                ['secretaries_providers', ['id_users_provider' => $foundUserId]],
                ['secretaries_providers', ['id_users_secretary' => $foundUserId]],
            ]
            as [$table, $where]
        ) {
            if ($this->db->get_where($table, $where)->num_rows() !== 0) {
                throw new RuntimeException('Fixture has an unexpected relationship; refusing cleanup.');
            }
        }
        $this->db->delete('user_settings', ['id_users' => $foundUserId, 'username' => $state['username']]);
        $this->db->delete('users', [
            'id' => $foundUserId,
            'notes' => $state['marker'],
            'email' => $state['email'],
            'id_roles' => (int) $state['role_id'],
        ]);
        if (
            $this->db->get_where('users', ['id' => $foundUserId])->num_rows() !== 0 ||
            $this->db->get_where('user_settings', ['id_users' => $foundUserId])->num_rows() !== 0
        ) {
            throw new RuntimeException('Fixture rows remain after cleanup.');
        }
    }

    private function assertNoCollision(string $username, string $email, string $marker): void
    {
        $users = $this->db->where('email', $email)->or_where('notes', $marker)->get('users')->num_rows();
        $settings = $this->db->get_where('user_settings', ['username' => $username])->num_rows();
        if ($users !== 0 || $settings !== 0) {
            throw new RuntimeException('Fixture identity collision detected.');
        }
    }

    private function assertCleanDatabaseBeforeActivation(): void
    {
        $users = $this->db->like('notes', 'ordinary-live:', 'after')->get('users')->num_rows();
        $settings = $this->db->like('username', 'defense_live_', 'after')->get('user_settings')->num_rows();
        if ($users !== 0 || $settings !== 0) {
            throw new RuntimeException('Orphaned ordinary fixture rows require explicit recovery.');
        }
    }

    /** @param array<string, mixed> $state */
    private function assertDatabaseOwnership(array $state): void
    {
        $userId = (int) $state['user_id'];
        if ($userId < 1) {
            throw new RuntimeException('Fixture state has no active user.');
        }
        $user = $this->db->get_where('users', ['id' => $userId])->row_array();
        $settings = $this->db->get_where('user_settings', ['id_users' => $userId])->row_array();
        $role = $this->db->get_where('roles', ['id' => (int) $state['role_id']])->row_array();
        if (
            !is_array($user) ||
            !is_array($settings) ||
            $user === [] ||
            $settings === [] ||
            $user['notes'] !== $state['marker'] ||
            $user['email'] !== $state['email'] ||
            (int) $user['id_roles'] !== (int) $state['role_id'] ||
            ($role['slug'] ?? null) !== $state['role_slug'] ||
            (int) ($user['is_private'] ?? 0) !== 1 ||
            $settings['username'] !== $state['username'] ||
            (int) ($settings['notifications'] ?? 1) !== 0 ||
            (int) ($settings['google_sync'] ?? 1) !== 0 ||
            (int) ($settings['caldav_sync'] ?? 1) !== 0 ||
            !hash_equals((string) $settings['password'], \hash_password((string) $settings['salt'], $state['password']))
        ) {
            throw new RuntimeException('Fixture database ownership or integration settings are invalid.');
        }
        foreach (
            [
                ['appointments', ['id_users_provider' => $userId]],
                ['appointments', ['id_users_customer' => $userId]],
                ['services_providers', ['id_users' => $userId]],
                ['secretaries_providers', ['id_users_provider' => $userId]],
                ['secretaries_providers', ['id_users_secretary' => $userId]],
            ]
            as [$table, $where]
        ) {
            if ($this->db->get_where($table, $where)->num_rows() !== 0) {
                throw new RuntimeException('Fixture has an unexpected relationship.');
            }
        }
    }

    /** @return mixed */
    private function withLock(callable $callback): mixed
    {
        if (is_link($this->lockFile)) {
            throw new RuntimeException('Fixture lifecycle lock cannot be a symlink.');
        }
        if (file_exists($this->lockFile)) {
            $this->assertOwnedFile($this->lockFile, 0600);
        }
        $previousUmask = umask(0077);
        try {
            $handle = fopen($this->lockFile, 'c');
        } finally {
            umask($previousUmask);
        }
        if ($handle === false || !flock($handle, LOCK_EX)) {
            throw new RuntimeException('Fixture lifecycle lock could not be acquired.');
        }
        chmod($this->lockFile, 0600);
        $this->assertOwnedFile($this->lockFile, 0600);
        try {
            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** @param array<string, mixed> $state */
    private function writeState(array $state): void
    {
        $tmp = $this->stateFile . '.tmp-' . bin2hex(random_bytes(8));
        $json = json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
        $handle = fopen($tmp, 'x');
        if (
            $handle === false ||
            !chmod($tmp, 0600) ||
            fwrite($handle, $json) !== strlen($json) ||
            !fflush($handle) ||
            !function_exists('fsync') ||
            !fsync($handle)
        ) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            @unlink($tmp);
            throw new RuntimeException('Fixture state could not be durably written.');
        }
        fclose($handle);
        if (!rename($tmp, $this->stateFile)) {
            @unlink($tmp);
            throw new RuntimeException('Fixture state could not be written atomically.');
        }
        $directory = fopen($this->stateDirectory, 'r');
        if ($directory === false) {
            throw new RuntimeException('Fixture journal directory could not be opened for synchronization.');
        }
        try {
            if (!fsync($directory)) {
                throw new RuntimeException('Fixture journal directory synchronization failed.');
            }
        } finally {
            fclose($directory);
        }
        $this->assertOwnedFile($this->stateFile, 0600);
    }

    /** @return array<string, mixed> */
    private function readState(): array
    {
        $this->assertOwnedFile($this->stateFile, 0600);
        $state = json_decode((string) file_get_contents($this->stateFile), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($state)) {
            throw new RuntimeException('Fixture state is invalid.');
        }
        return $state;
    }

    /** @param array<string, mixed> $state */
    private function validateState(array $state): void
    {
        foreach (
            [
                'schema',
                'run_id',
                'marker',
                'user_id',
                'role_id',
                'role_slug',
                'username',
                'email',
                'password',
                'created_at',
                'expires_at',
                'phase',
            ]
            as $key
        ) {
            if (!array_key_exists($key, $state)) {
                throw new RuntimeException('Fixture state is incomplete.');
            }
        }
        if (
            $state['schema'] !== self::SCHEMA ||
            !preg_match('/^ordinary-live-[0-9a-f]{32}$/', (string) $state['run_id']) ||
            $state['marker'] !== 'ordinary-live:' . $state['run_id'] ||
            !preg_match('/^defense_live_[0-9a-f]{32}$/', (string) $state['username']) ||
            $state['email'] !== $state['username'] . '@synthetic.invalid' ||
            !in_array($state['role_slug'], ['provider', 'admin'], true) ||
            !in_array($state['phase'], ['prepared', 'active'], true) ||
            !is_int($state['user_id']) ||
            $state['user_id'] < 0 ||
            !is_int($state['role_id']) ||
            $state['role_id'] < 1 ||
            !is_int($state['created_at']) ||
            $state['created_at'] < 1 ||
            !is_int($state['expires_at']) ||
            $state['expires_at'] - $state['created_at'] !== self::MAX_TTL ||
            !is_string($state['password']) ||
            !preg_match('/\A[0-9a-f]{64}\z/D', $state['password'])
        ) {
            throw new RuntimeException('Fixture state identity is invalid.');
        }
    }

    private function prepareDirectory(string $path): string
    {
        if ($path === '' || $path[0] !== '/' || str_contains($path, "\0")) {
            throw new InvalidArgumentException('State directory must be an absolute path.');
        }
        $path = rtrim($path, '/');
        $parts = explode('/', ltrim($path, '/'));
        $current = '/';
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            $current .= $part;
            $stat = lstat($current);
            if (
                $stat !== false &&
                (is_link($current) ||
                    (function_exists('posix_geteuid') && $stat['uid'] !== posix_geteuid()) ||
                    ($stat['mode'] & 0022) !== 0)
            ) {
                throw new RuntimeException('State directory ancestors must be owned by root and non-symlink.');
            }
            $current .= '/';
        }
        if (is_dir($path)) {
            $existing = lstat($path);
            if ($existing === false || ($existing['mode'] & 0777) !== 0700) {
                throw new RuntimeException('An existing state directory must already be mode 0700.');
            }
        } else {
            if (!is_dir(dirname($path))) {
                throw new RuntimeException('State directory parent must already exist.');
            }
            if (!mkdir($path, 0700) && !is_dir($path)) {
                throw new RuntimeException('State directory could not be created.');
            }
        }
        if (is_link($path) || realpath($path) !== $path) {
            throw new RuntimeException('State directory must be canonical and non-symlink.');
        }
        if ((lstat($path)['mode'] & 0777) !== 0700) {
            throw new RuntimeException('State directory must be mode 0700.');
        }
        $this->assertOwnedFile($path, 0700);
        $parent = fopen(dirname($path), 'r');
        if ($parent === false) {
            throw new RuntimeException('State directory parent could not be opened for synchronization.');
        }
        try {
            if (!fsync($parent)) {
                throw new RuntimeException('State directory parent synchronization failed.');
            }
        } finally {
            fclose($parent);
        }
        return $path;
    }

    private function assertOwnedFile(string $path, int $mode): void
    {
        $stat = lstat($path);
        if (
            $stat === false ||
            is_link($path) ||
            (function_exists('posix_geteuid') && $stat['uid'] !== posix_geteuid()) ||
            ($stat['mode'] & 0777) !== $mode
        ) {
            throw new RuntimeException('Fixture path ownership or permissions are unsafe.');
        }
    }

    private function assertRootCli(): void
    {
        if (PHP_SAPI !== 'cli' || !function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            throw new RuntimeException('Ordinary live fixture requires root CLI execution.');
        }
    }
}
