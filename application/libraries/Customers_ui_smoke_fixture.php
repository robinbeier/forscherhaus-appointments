<?php defined('BASEPATH') or exit('No direct script access allowed');

require_once __DIR__ . '/../core/Customers_ui_smoke_access_policy.php';

/* ----------------------------------------------------------------------------
 * Easy!Appointments - Online Appointment Scheduler
 *
 * @package     EasyAppointments
 * @author      A.Tselegidis <alextselegidis@gmail.com>
 * @copyright   Copyright (c) Alex Tselegidis
 * @license     https://opensource.org/licenses/GPL-3.0 - GPLv3
 * @link        https://easyappointments.org
 * ---------------------------------------------------------------------------- */

/**
 * Root-only lifecycle for the isolated Customers UI smoke principals.
 *
 * No customer row is created: the Customers controller recognizes these reserved
 * sessions and answers only an empty initial search or the exact synthetic marker.
 */
final class Customers_ui_smoke_fixture
{
    public const DEFAULT_CREDENTIAL_FILE = '/etc/fh/release-gate-customers-ui-smoke.env';

    public const DEFAULT_STATE_FILE = '/var/lib/fh-customers-ui-smoke/active.json';

    private const STATE_VERSION = 1;

    private const LOCK_NAME = 'fh-customers-ui-smoke-v1';

    private const DORMANT_ROLE_NAME = 'Customers UI Smoke Dormant';

    private const CREDENTIAL_PASSWORD_KEY = 'CUSTOMERS_UI_SMOKE_PASSWORD';

    /**
     * @var EA_Controller|CI_Controller
     */
    private EA_Controller|CI_Controller $CI;

    public function __construct()
    {
        $this->CI = &get_instance();
        $this->CI->load->model('customers_model');
        $this->CI->load->model('roles_model');
    }

    public function run(
        string $action,
        string $credentialFile = self::DEFAULT_CREDENTIAL_FILE,
        string $stateFile = self::DEFAULT_STATE_FILE,
    ): string {
        if (!in_array($action, ['install', 'verify', 'activate', 'deactivate', 'remove'], true)) {
            throw new InvalidArgumentException('Unsupported Customers UI smoke action.');
        }

        $this->assertRootCli();
        $this->assertSafeAbsolutePath($stateFile);

        $credentials = null;

        if (in_array($action, ['install', 'verify', 'activate'], true)) {
            $credentials = $this->readCredentials($credentialFile);
        }

        $this->acquireLock();

        try {
            $stateFileExistedBefore = file_exists($stateFile) || is_link($stateFile);

            if (!$this->CI->db->trans_begin()) {
                throw new RuntimeException('Customers UI smoke transaction could not start.');
            }

            $removeStateAfterCommit = false;

            try {
                $state = match ($action) {
                    'install' => $this->install($stateFile),
                    'verify' => $this->verify($stateFile),
                    'activate' => $this->activate($credentials, $stateFile),
                    'deactivate' => $this->deactivate($stateFile, $removeStateAfterCommit),
                    'remove' => $this->remove($stateFile),
                };

                if (!$this->CI->db->trans_commit()) {
                    throw new RuntimeException('Customers UI smoke transaction could not commit.');
                }
            } catch (Throwable $exception) {
                $this->CI->db->trans_rollback();

                if ($action === 'activate' && !$stateFileExistedBefore) {
                    $this->removeStateFileIfPresent($stateFile);
                }

                throw $exception;
            }

            if ($removeStateAfterCommit) {
                $this->removeStateFileIfPresent($stateFile, true);
            }

            return $state;
        } finally {
            $this->releaseLock();
        }
    }

    private function install(string $stateFile): string
    {
        $this->assertStateFileAbsent($stateFile);
        $role = $this->findDormantRole();
        $roleNameQuery = $this->CI->db->get_where('roles', ['name' => self::DORMANT_ROLE_NAME]);

        if ($role === null) {
            if ($roleNameQuery->num_rows() !== 0) {
                throw new RuntimeException('Customers UI smoke dormant role name collision detected.');
            }

            $roleData = [
                'name' => self::DORMANT_ROLE_NAME,
                'slug' => Customers_ui_smoke_access_policy::DORMANT_ROLE_SLUG,
                'is_admin' => 0,
                'appointments' => 0,
                'customers' => 0,
                'services' => 0,
                'users' => 0,
                'system_settings' => 0,
                'user_settings' => 0,
            ];

            foreach (['webhooks', 'blocked_periods'] as $optionalPermission) {
                if ($this->CI->db->field_exists($optionalPermission, 'roles')) {
                    $roleData[$optionalPermission] = 0;
                }
            }

            $roleId = $this->CI->roles_model->save($roleData);
            $role = $this->CI->db->get_where('roles', ['id' => $roleId])->row_array();
        }

        $this->assertDormantRole($role);

        foreach (array_keys(Customers_ui_smoke_access_policy::USERNAMES_BY_ROLE) as $targetRole) {
            $principal = $this->findPrincipal($targetRole);

            if ($principal === null) {
                $this->assertPrincipalMarkersUnused($targetRole);
                $this->insertPrincipal($targetRole, (int) $role['id']);
            }
        }

        $this->assertDormantClean($role);

        return 'dormant';
    }

    private function verify(string $stateFile): string
    {
        $this->assertStateFileAbsent($stateFile);
        $role = $this->findDormantRole();
        $principals = $this->findAllPrincipals();

        if ($role === null && $principals === []) {
            $this->assertAllPrincipalMarkersUnused();
            $this->assertSyntheticSearchIsEmpty();

            return 'removed';
        }

        if ($role === null || count($principals) !== count(Customers_ui_smoke_access_policy::USERNAMES_BY_ROLE)) {
            throw new RuntimeException('Customers UI smoke install state is incomplete.');
        }

        $this->assertDormantClean($role);

        return 'dormant';
    }

    /**
     * @param array{password: string} $credentials
     */
    private function activate(array $credentials, string $stateFile): string
    {
        $this->assertStateFileAbsent($stateFile);
        $dormantRole = $this->requireDormantRole();
        $this->assertDormantClean($dormantRole);
        $this->assertPermissionMatrix();
        $this->assertSyntheticSearchIsEmpty();

        $issuedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $expiresAt = $issuedAt->modify('+' . Customers_ui_smoke_access_policy::MAX_LEASE_SECONDS . ' seconds');
        $now = date('Y-m-d H:i:s');
        $ids = [];

        foreach (Customers_ui_smoke_access_policy::USERNAMES_BY_ROLE as $targetRole => $username) {
            $principal = $this->requirePrincipal($targetRole);
            $targetRoleRow = $this->requireUniqueRole($targetRole);
            $salt = generate_salt();
            $passwordHash = hash_password($salt, $credentials['password']);

            $this->updateExactlyOne(
                'users',
                [
                    'id_roles' => $targetRoleRow['id'],
                    'notes' => Customers_ui_smoke_access_policy::buildActiveNotes($targetRole, $issuedAt, $expiresAt),
                    'update_datetime' => $now,
                ],
                ['id' => $principal['id']],
                true,
            );
            $this->updateExactlyOne(
                'user_settings',
                ['password' => $passwordHash, 'salt' => $salt],
                ['id_users' => $principal['id']],
                true,
            );

            $ids[$targetRole] = (int) $principal['id'];
        }

        $state = [
            'version' => self::STATE_VERSION,
            'fixture_key' => Customers_ui_smoke_access_policy::FIXTURE_KEY,
            'fixture_checksum' => $this->fixtureChecksum(),
            'issued_at' => $issuedAt->format('Y-m-d\TH:i:s\Z'),
            'expires_at' => $expiresAt->format('Y-m-d\TH:i:s\Z'),
            'ids' => $ids,
        ];

        $this->assertActiveFixture($state);
        $this->writeStateFile($stateFile, $state);

        return 'active';
    }

    private function deactivate(string $stateFile, bool &$removeStateAfterCommit): string
    {
        $dormantRole = $this->requireDormantRole();
        $state = $this->readStateFile($stateFile);
        $hadStateFile = $state !== null;

        if ($this->isDormantClean($dormantRole)) {
            $removeStateAfterCommit = $hadStateFile;

            return 'dormant';
        }

        if ($state === null) {
            $state = $this->reconstructActiveState();
        }

        $this->assertActiveFixture($state, false, false);
        $now = date('Y-m-d H:i:s');

        foreach ($state['ids'] as $targetRole => $principalId) {
            $this->updateExactlyOne(
                'users',
                [
                    'id_roles' => $dormantRole['id'],
                    'notes' => Customers_ui_smoke_access_policy::dormantNotes($targetRole),
                    'update_datetime' => $now,
                ],
                ['id' => $principalId],
                true,
            );
            $this->updateExactlyOne(
                'user_settings',
                ['password' => null, 'salt' => null],
                ['id_users' => $principalId],
                true,
            );
        }

        $this->assertDormantClean($dormantRole);
        $removeStateAfterCommit = $hadStateFile;

        return 'dormant';
    }

    private function remove(string $stateFile): string
    {
        $this->assertStateFileAbsent($stateFile);
        $role = $this->findDormantRole();
        $principals = $this->findAllPrincipals();

        if ($role === null && $principals === []) {
            $this->assertAllPrincipalMarkersUnused();
            $this->assertSyntheticSearchIsEmpty();

            return 'removed';
        }

        if ($role === null || count($principals) !== count(Customers_ui_smoke_access_policy::USERNAMES_BY_ROLE)) {
            throw new RuntimeException('Customers UI smoke install state is incomplete.');
        }

        $this->assertDormantClean($role);

        foreach ($principals as $principal) {
            $this->deleteExactlyOne('user_settings', ['id_users' => $principal['id']]);
            $this->deleteExactlyOne('users', ['id' => $principal['id']]);
        }

        if ($this->CI->db->get_where('users', ['id_roles' => $role['id']])->num_rows() !== 0) {
            throw new RuntimeException('Customers UI smoke dormant role remains referenced.');
        }

        $this->deleteExactlyOne('roles', ['id' => $role['id']]);
        $this->assertAllPrincipalMarkersUnused();
        $this->assertSyntheticSearchIsEmpty();

        return 'removed';
    }

    private function insertPrincipal(string $targetRole, int $dormantRoleId): void
    {
        $now = date('Y-m-d H:i:s');
        $this->insertOrFail('users', [
            'first_name' => 'Synthetic',
            'last_name' => $this->lastName($targetRole),
            'email' => $this->email($targetRole),
            'phone_number' => null,
            'mobile_number' => null,
            'address' => null,
            'city' => null,
            'state' => null,
            'zip_code' => null,
            'notes' => Customers_ui_smoke_access_policy::dormantNotes($targetRole),
            'room' => null,
            'class_size_default' => null,
            'custom_field_1' => null,
            'custom_field_2' => null,
            'custom_field_3' => null,
            'custom_field_4' => null,
            'custom_field_5' => null,
            'timezone' => 'UTC',
            'language' => 'german',
            'is_private' => 1,
            'ldap_dn' => null,
            'id_roles' => $dormantRoleId,
            'create_datetime' => $now,
            'update_datetime' => $now,
        ]);
        $principalId = (int) $this->CI->db->insert_id();

        $this->insertOrFail('user_settings', [
            'id_users' => $principalId,
            'username' => Customers_ui_smoke_access_policy::USERNAMES_BY_ROLE[$targetRole],
            'password' => null,
            'salt' => null,
            'working_plan' => '{}',
            'working_plan_exceptions' => '{}',
            'notifications' => 0,
            'google_sync' => 0,
            'google_token' => null,
            'google_calendar' => null,
            'sync_past_days' => 0,
            'sync_future_days' => 0,
            'calendar_view' => CALENDAR_VIEW_DEFAULT,
            'caldav_sync' => 0,
            'caldav_url' => null,
            'caldav_username' => null,
            'caldav_password' => null,
            'dashboard_range_start' => null,
            'dashboard_range_end' => null,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findPrincipal(string $targetRole): ?array
    {
        $username = Customers_ui_smoke_access_policy::USERNAMES_BY_ROLE[$targetRole] ?? null;

        if ($username === null) {
            throw new InvalidArgumentException('Unknown Customers UI smoke role.');
        }

        $query = $this->CI->db
            ->select(
                'users.*, roles.slug AS role_slug, user_settings.username, user_settings.password, ' .
                    'user_settings.salt, user_settings.working_plan, user_settings.working_plan_exceptions, ' .
                    'user_settings.notifications, user_settings.google_sync, user_settings.google_token, ' .
                    'user_settings.google_calendar, user_settings.sync_past_days, user_settings.sync_future_days, ' .
                    'user_settings.calendar_view, user_settings.caldav_sync, user_settings.caldav_url, ' .
                    'user_settings.caldav_username, user_settings.caldav_password, ' .
                    'user_settings.dashboard_range_start, user_settings.dashboard_range_end',
            )
            ->from('users')
            ->join('roles', 'roles.id = users.id_roles', 'inner')
            ->join('user_settings', 'user_settings.id_users = users.id', 'inner')
            ->where('user_settings.username', $username)
            ->get();

        if ($query->num_rows() > 1) {
            throw new RuntimeException('Customers UI smoke principal cardinality is invalid.');
        }

        return $query->num_rows() === 1 ? $query->row_array() : null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function findAllPrincipals(): array
    {
        $principals = [];

        foreach (array_keys(Customers_ui_smoke_access_policy::USERNAMES_BY_ROLE) as $targetRole) {
            $principal = $this->findPrincipal($targetRole);

            if ($principal !== null) {
                $principals[$targetRole] = $principal;
            }
        }

        return $principals;
    }

    /**
     * @return array<string, mixed>
     */
    private function requirePrincipal(string $targetRole): array
    {
        $principal = $this->findPrincipal($targetRole);

        if ($principal === null) {
            throw new RuntimeException('Customers UI smoke principal is missing.');
        }

        return $principal;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findDormantRole(): ?array
    {
        $query = $this->CI->db->get_where('roles', ['slug' => Customers_ui_smoke_access_policy::DORMANT_ROLE_SLUG]);

        if ($query->num_rows() > 1) {
            throw new RuntimeException('Customers UI smoke dormant role cardinality is invalid.');
        }

        return $query->num_rows() === 1 ? $query->row_array() : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function requireDormantRole(): array
    {
        $role = $this->findDormantRole();

        if ($role === null) {
            throw new RuntimeException('Customers UI smoke dormant role is missing.');
        }

        $this->assertDormantRole($role);

        return $role;
    }

    /**
     * @param array<string, mixed> $role
     */
    private function assertDormantRole(array $role): void
    {
        $nameQuery = $this->CI->db->get_where('roles', ['name' => self::DORMANT_ROLE_NAME]);

        if ($nameQuery->num_rows() !== 1 || (int) $nameQuery->row_array()['id'] !== (int) ($role['id'] ?? 0)) {
            throw new RuntimeException('Customers UI smoke dormant role ownership invariant failed.');
        }

        $expected = [
            'name' => self::DORMANT_ROLE_NAME,
            'slug' => Customers_ui_smoke_access_policy::DORMANT_ROLE_SLUG,
            'is_admin' => 0,
            'appointments' => 0,
            'customers' => 0,
            'services' => 0,
            'users' => 0,
            'system_settings' => 0,
            'user_settings' => 0,
        ];

        foreach ($expected as $field => $value) {
            if ((string) ($role[$field] ?? '') !== (string) $value) {
                throw new RuntimeException('Customers UI smoke dormant role invariant failed.');
            }
        }

        foreach (['webhooks', 'blocked_periods'] as $optionalPermission) {
            if (
                $this->CI->db->field_exists($optionalPermission, 'roles') &&
                (int) ($role[$optionalPermission] ?? -1) !== 0
            ) {
                throw new RuntimeException('Customers UI smoke dormant role invariant failed.');
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function requireUniqueRole(string $slug): array
    {
        $query = $this->CI->db->get_where('roles', ['slug' => $slug]);

        if ($query->num_rows() !== 1) {
            throw new RuntimeException('Customers UI smoke required role cardinality is invalid.');
        }

        return $query->row_array();
    }

    private function assertPermissionMatrix(): void
    {
        foreach (Customers_ui_smoke_access_policy::USERNAMES_BY_ROLE as $role => $_username) {
            $roleRow = $this->requireUniqueRole($role);
            $canViewCustomers = (((int) ($roleRow['customers'] ?? 0)) & PRIV_VIEW) === PRIV_VIEW;

            if ($canViewCustomers !== Customers_ui_smoke_access_policy::isAuthorizedRole($role)) {
                throw new RuntimeException('Customers UI smoke permission matrix does not match the contract.');
            }
        }
    }

    /**
     * @param array<string, mixed> $role
     */
    private function assertDormantClean(array $role): void
    {
        $this->assertDormantRole($role);
        $principals = $this->findAllPrincipals();

        if (count($principals) !== count(Customers_ui_smoke_access_policy::USERNAMES_BY_ROLE)) {
            throw new RuntimeException('Customers UI smoke dormant principal set is incomplete.');
        }

        foreach ($principals as $targetRole => $principal) {
            $this->assertPrincipalShape($principal, $targetRole, Customers_ui_smoke_access_policy::DORMANT_ROLE_SLUG);

            if (
                (int) $principal['id_roles'] !== (int) $role['id'] ||
                $principal['notes'] !== Customers_ui_smoke_access_policy::dormantNotes($targetRole) ||
                $principal['password'] !== null ||
                $principal['salt'] !== null
            ) {
                throw new RuntimeException('Customers UI smoke dormant principal invariant failed.');
            }
        }

        if ($this->CI->db->get_where('users', ['id_roles' => $role['id']])->num_rows() !== count($principals)) {
            throw new RuntimeException('Customers UI smoke dormant role reference invariant failed.');
        }

        $this->assertNoPrincipalRelations(array_column($principals, 'id'));
        $this->assertSyntheticSearchIsEmpty();
    }

    /**
     * @param array<string, mixed> $role
     */
    private function isDormantClean(array $role): bool
    {
        try {
            $this->assertDormantClean($role);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param array<string, mixed> $state
     */
    private function assertActiveFixture(
        array $state,
        bool $requireCurrentLease = true,
        bool $requirePermissionMatrix = true,
    ): void {
        $this->assertValidStateShape($state);

        if ($requirePermissionMatrix) {
            $this->assertPermissionMatrix();
        }

        foreach ($state['ids'] as $targetRole => $principalId) {
            $principal = $this->requirePrincipal($targetRole);

            if ((int) $principal['id'] !== $principalId) {
                throw new RuntimeException('Customers UI smoke active principal identifier changed.');
            }

            $this->assertPrincipalShape($principal, $targetRole, $targetRole);
            $expectedNotes =
                Customers_ui_smoke_access_policy::ACTIVE_NOTES_PREFIX .
                $targetRole .
                ':' .
                $state['issued_at'] .
                ':' .
                $state['expires_at'];

            if (
                $principal['notes'] !== $expectedNotes ||
                empty($principal['password']) ||
                empty($principal['salt']) ||
                ($requireCurrentLease &&
                    !Customers_ui_smoke_access_policy::hasActiveLease((string) $principal['notes'], $targetRole))
            ) {
                throw new RuntimeException('Customers UI smoke active principal invariant failed.');
            }
        }

        $this->assertNoPrincipalRelations(array_values($state['ids']));
        $this->assertSyntheticSearchIsEmpty();
    }

    /**
     * @param array<string, mixed> $principal
     */
    private function assertPrincipalShape(array $principal, string $targetRole, string $currentRole): void
    {
        if (
            $principal['role_slug'] !== $currentRole ||
            $principal['username'] !== Customers_ui_smoke_access_policy::USERNAMES_BY_ROLE[$targetRole] ||
            $principal['first_name'] !== 'Synthetic' ||
            $principal['last_name'] !== $this->lastName($targetRole) ||
            $principal['email'] !== $this->email($targetRole) ||
            $principal['phone_number'] !== null ||
            $principal['mobile_number'] !== null ||
            $principal['address'] !== null ||
            $principal['city'] !== null ||
            $principal['state'] !== null ||
            $principal['zip_code'] !== null ||
            $principal['room'] !== null ||
            $principal['class_size_default'] !== null ||
            $principal['custom_field_1'] !== null ||
            $principal['custom_field_2'] !== null ||
            $principal['custom_field_3'] !== null ||
            $principal['custom_field_4'] !== null ||
            $principal['custom_field_5'] !== null ||
            $principal['timezone'] !== 'UTC' ||
            $principal['language'] !== 'german' ||
            (int) $principal['is_private'] !== 1 ||
            $principal['ldap_dn'] !== null ||
            $principal['working_plan'] !== '{}' ||
            $principal['working_plan_exceptions'] !== '{}' ||
            (int) $principal['notifications'] !== 0 ||
            (int) $principal['google_sync'] !== 0 ||
            $principal['google_token'] !== null ||
            $principal['google_calendar'] !== null ||
            (int) $principal['sync_past_days'] !== 0 ||
            (int) $principal['sync_future_days'] !== 0 ||
            $principal['calendar_view'] !== CALENDAR_VIEW_DEFAULT ||
            (int) $principal['caldav_sync'] !== 0 ||
            $principal['caldav_url'] !== null ||
            $principal['caldav_username'] !== null ||
            $principal['caldav_password'] !== null ||
            $principal['dashboard_range_start'] !== null ||
            $principal['dashboard_range_end'] !== null
        ) {
            throw new RuntimeException('Customers UI smoke principal containment invariant failed.');
        }
    }

    /**
     * @param list<int|string> $principalIds
     */
    private function assertNoPrincipalRelations(array $principalIds): void
    {
        $ids = array_map('intval', $principalIds);
        $appointmentCount = $this->CI->db
            ->group_start()
            ->where_in('id_users_provider', $ids)
            ->or_where_in('id_users_customer', $ids)
            ->group_end()
            ->get('appointments')
            ->num_rows();
        $providerServiceCount = $this->CI->db->where_in('id_users', $ids)->get('services_providers')->num_rows();
        $secretaryProviderCount = $this->CI->db
            ->group_start()
            ->where_in('id_users_secretary', $ids)
            ->or_where_in('id_users_provider', $ids)
            ->group_end()
            ->get('secretaries_providers')
            ->num_rows();

        if ($appointmentCount !== 0 || $providerServiceCount !== 0 || $secretaryProviderCount !== 0) {
            throw new RuntimeException('Customers UI smoke principal has unexpected relations.');
        }
    }

    private function assertSyntheticSearchIsEmpty(): void
    {
        $results = $this->CI->customers_model->search(Customers_ui_smoke_access_policy::SEARCH_MARKER, 1, 0);

        if ($results !== []) {
            throw new RuntimeException('Customers UI smoke synthetic search marker is not empty.');
        }
    }

    private function assertPrincipalMarkersUnused(string $targetRole): void
    {
        $username = Customers_ui_smoke_access_policy::USERNAMES_BY_ROLE[$targetRole];
        $email = $this->email($targetRole);
        $dormantNotes = Customers_ui_smoke_access_policy::dormantNotes($targetRole);
        $usernameCount = $this->CI->db->get_where('user_settings', ['username' => $username])->num_rows();
        $emailCount = $this->CI->db->get_where('users', ['email' => $email])->num_rows();
        $dormantCount = $this->CI->db->get_where('users', ['notes' => $dormantNotes])->num_rows();
        $this->CI->db->like(
            'notes',
            Customers_ui_smoke_access_policy::ACTIVE_NOTES_PREFIX . $targetRole . ':',
            'after',
        );
        $activeCount = $this->CI->db->get('users')->num_rows();

        if ($usernameCount !== 0 || $emailCount !== 0 || $dormantCount !== 0 || $activeCount !== 0) {
            throw new RuntimeException('Customers UI smoke principal marker collision detected.');
        }
    }

    private function assertAllPrincipalMarkersUnused(): void
    {
        foreach (array_keys(Customers_ui_smoke_access_policy::USERNAMES_BY_ROLE) as $targetRole) {
            $this->assertPrincipalMarkersUnused($targetRole);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function reconstructActiveState(): array
    {
        $principals = $this->findAllPrincipals();

        if (count($principals) !== count(Customers_ui_smoke_access_policy::USERNAMES_BY_ROLE)) {
            throw new RuntimeException('Customers UI smoke crash recovery principal set is incomplete.');
        }

        $issuedAt = null;
        $expiresAt = null;
        $ids = [];

        foreach ($principals as $targetRole => $principal) {
            if ($principal['role_slug'] !== $targetRole) {
                throw new RuntimeException('Customers UI smoke crash recovery role invariant failed.');
            }

            $lease = Customers_ui_smoke_access_policy::parseActiveNotes((string) $principal['notes'], $targetRole);

            if ($lease === null) {
                throw new RuntimeException('Customers UI smoke crash recovery lease invariant failed.');
            }

            $currentIssuedAt = $lease['issued_at']->format('Y-m-d\TH:i:s\Z');
            $currentExpiresAt = $lease['expires_at']->format('Y-m-d\TH:i:s\Z');
            $issuedAt ??= $currentIssuedAt;
            $expiresAt ??= $currentExpiresAt;

            if ($issuedAt !== $currentIssuedAt || $expiresAt !== $currentExpiresAt) {
                throw new RuntimeException('Customers UI smoke crash recovery lease set is inconsistent.');
            }

            $ids[$targetRole] = (int) $principal['id'];
        }

        return [
            'version' => self::STATE_VERSION,
            'fixture_key' => Customers_ui_smoke_access_policy::FIXTURE_KEY,
            'fixture_checksum' => $this->fixtureChecksum(),
            'issued_at' => $issuedAt,
            'expires_at' => $expiresAt,
            'ids' => $ids,
        ];
    }

    /**
     * @param array<string, mixed> $state
     */
    private function assertValidStateShape(array $state): void
    {
        $expectedRoles = array_keys(Customers_ui_smoke_access_policy::USERNAMES_BY_ROLE);

        if (
            array_keys($state) !== ['version', 'fixture_key', 'fixture_checksum', 'issued_at', 'expires_at', 'ids'] ||
            $state['version'] !== self::STATE_VERSION ||
            $state['fixture_key'] !== Customers_ui_smoke_access_policy::FIXTURE_KEY ||
            $state['fixture_checksum'] !== $this->fixtureChecksum() ||
            !is_array($state['ids']) ||
            array_keys($state['ids']) !== $expectedRoles
        ) {
            throw new RuntimeException('Customers UI smoke state schema is invalid.');
        }

        foreach ($state['ids'] as $id) {
            if (!is_int($id) || $id <= 0) {
                throw new RuntimeException('Customers UI smoke state identifier is invalid.');
            }
        }

        if (count($state['ids']) !== count(array_unique($state['ids']))) {
            throw new RuntimeException('Customers UI smoke state identifiers are not unique.');
        }

        foreach ($expectedRoles as $role) {
            $notes =
                Customers_ui_smoke_access_policy::ACTIVE_NOTES_PREFIX .
                $role .
                ':' .
                $state['issued_at'] .
                ':' .
                $state['expires_at'];

            if (Customers_ui_smoke_access_policy::parseActiveNotes($notes, $role) === null) {
                throw new RuntimeException('Customers UI smoke state lease is invalid.');
            }
        }
    }

    /**
     * @return array{password: string}
     */
    private function readCredentials(string $path): array
    {
        $this->assertSafeAbsolutePath($path);
        $this->assertProtectedRegularFile($path);
        $credentials = parse_ini_file($path, false, INI_SCANNER_RAW);
        $expected = [self::CREDENTIAL_PASSWORD_KEY];

        foreach (array_keys(Customers_ui_smoke_access_policy::USERNAMES_BY_ROLE) as $role) {
            $expected[] = $this->credentialUsernameKey($role);
        }

        if (!is_array($credentials)) {
            throw new RuntimeException('Customers UI smoke credential schema is invalid.');
        }

        $actual = array_keys($credentials);
        sort($actual);
        sort($expected);

        if ($actual !== $expected) {
            throw new RuntimeException('Customers UI smoke credential schema is invalid.');
        }

        foreach (Customers_ui_smoke_access_policy::USERNAMES_BY_ROLE as $role => $username) {
            if (($credentials[$this->credentialUsernameKey($role)] ?? null) !== $username) {
                throw new RuntimeException('Customers UI smoke credential values are invalid.');
            }
        }

        $password = $credentials[self::CREDENTIAL_PASSWORD_KEY] ?? null;

        if (!is_string($password) || preg_match('/\A[a-f0-9]{64}\z/D', $password) !== 1) {
            throw new RuntimeException('Customers UI smoke credential values are invalid.');
        }

        return ['password' => $password];
    }

    private function credentialUsernameKey(string $role): string
    {
        return 'CUSTOMERS_UI_SMOKE_' . strtoupper($role) . '_USERNAME';
    }

    private function lastName(string $role): string
    {
        return 'Customers UI Smoke ' . ucfirst($role) . ' V1';
    }

    private function email(string $role): string
    {
        return 'customers-ui-smoke-' . $role . '-v1@synthetic.invalid';
    }

    private function fixtureChecksum(): string
    {
        $values = [Customers_ui_smoke_access_policy::FIXTURE_KEY, Customers_ui_smoke_access_policy::SEARCH_MARKER];

        foreach (Customers_ui_smoke_access_policy::USERNAMES_BY_ROLE as $role => $username) {
            array_push($values, $role, $username, $this->email($role));
        }

        return hash('sha256', implode("\n", $values));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function insertOrFail(string $table, array $data): void
    {
        if (!$this->CI->db->insert($table, $data)) {
            throw new RuntimeException('Customers UI smoke insert failed.');
        }
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $where
     */
    private function updateExactlyOne(string $table, array $data, array $where, bool $requireChanged = false): void
    {
        if ($this->CI->db->get_where($table, $where)->num_rows() !== 1) {
            throw new RuntimeException('Customers UI smoke update cardinality is invalid.');
        }

        if (!$this->CI->db->update($table, $data, $where)) {
            throw new RuntimeException('Customers UI smoke update failed.');
        }

        if ($requireChanged && $this->CI->db->affected_rows() !== 1) {
            throw new RuntimeException('Customers UI smoke state transition did not change exactly one row.');
        }
    }

    /**
     * @param array<string, mixed> $where
     */
    private function deleteExactlyOne(string $table, array $where): void
    {
        if ($this->CI->db->get_where($table, $where)->num_rows() !== 1) {
            throw new RuntimeException('Customers UI smoke delete cardinality is invalid.');
        }

        $this->CI->db->delete($table, $where);

        if ($this->CI->db->affected_rows() !== 1) {
            throw new RuntimeException('Customers UI smoke delete failed.');
        }
    }

    private function assertProtectedRegularFile(string $path): void
    {
        $stat = @lstat($path);

        if ($stat === false || !is_file($path) || is_link($path)) {
            throw new RuntimeException('Customers UI smoke protected file is invalid.');
        }

        if (
            ($stat['mode'] & 0777) !== 0600 ||
            (int) $stat['uid'] !== 0 ||
            (int) $stat['nlink'] !== 1 ||
            (int) $stat['size'] < 1 ||
            (int) $stat['size'] > 65536
        ) {
            throw new RuntimeException('Customers UI smoke protected file permissions are invalid.');
        }

        $parent = dirname($path);
        $parentStat = @lstat($parent);

        if (
            $parentStat === false ||
            !is_dir($parent) ||
            is_link($parent) ||
            ((int) $parentStat['mode'] & 0022) !== 0 ||
            (int) $parentStat['uid'] !== 0
        ) {
            throw new RuntimeException('Customers UI smoke protected directory permissions are invalid.');
        }
    }

    private function assertSafeAbsolutePath(string $path): void
    {
        if (
            $path === '' ||
            $path[0] !== '/' ||
            $path === '/' ||
            str_ends_with($path, '/') ||
            str_contains($path, "\0") ||
            str_contains($path, '//') ||
            preg_match('#(?:^|/)\.{1,2}(?:/|$)#', $path) === 1
        ) {
            throw new InvalidArgumentException('Customers UI smoke path is invalid.');
        }
    }

    private function assertRootCli(): void
    {
        if (!is_cli() || !function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            throw new RuntimeException('Customers UI smoke lifecycle requires root CLI.');
        }
    }

    private function acquireLock(): void
    {
        $row = $this->CI->db->query('SELECT GET_LOCK(?, 10) AS acquired', [self::LOCK_NAME])->row_array();

        if ((int) ($row['acquired'] ?? 0) !== 1) {
            throw new RuntimeException('Customers UI smoke advisory lock could not be acquired.');
        }
    }

    private function releaseLock(): void
    {
        try {
            $this->CI->db->query('SELECT RELEASE_LOCK(?)', [self::LOCK_NAME]);
        } catch (Throwable) {
            // The connection closing also releases the lock.
        }
    }

    private function assertStateFileAbsent(string $path): void
    {
        if (file_exists($path) || is_link($path)) {
            throw new RuntimeException('Customers UI smoke state file unexpectedly exists.');
        }
    }

    /**
     * @param array<string, mixed> $state
     */
    private function writeStateFile(string $path, array $state): void
    {
        $directory = dirname($path);

        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Customers UI smoke state directory could not be created.');
        }

        $directoryStat = @lstat($directory);

        if (
            $directoryStat === false ||
            is_link($directory) ||
            ((int) $directoryStat['mode'] & 0777) !== 0700 ||
            (int) $directoryStat['uid'] !== 0
        ) {
            throw new RuntimeException('Customers UI smoke state directory permissions are invalid.');
        }

        $encoded = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($encoded === false) {
            throw new RuntimeException('Customers UI smoke state encoding failed.');
        }

        $temporary = tempnam($directory, '.active.');

        if ($temporary === false) {
            throw new RuntimeException('Customers UI smoke temporary state file could not be created.');
        }

        try {
            if (!chmod($temporary, 0600)) {
                throw new RuntimeException('Customers UI smoke temporary state permissions failed.');
            }

            $handle = fopen($temporary, 'wb');

            if ($handle === false) {
                throw new RuntimeException('Customers UI smoke temporary state open failed.');
            }

            try {
                if (fwrite($handle, $encoded . PHP_EOL) === false || !fflush($handle)) {
                    throw new RuntimeException('Customers UI smoke temporary state write failed.');
                }

                if (function_exists('fsync') && !fsync($handle)) {
                    throw new RuntimeException('Customers UI smoke temporary state sync failed.');
                }
            } finally {
                fclose($handle);
            }

            if (!rename($temporary, $path)) {
                throw new RuntimeException('Customers UI smoke state publish failed.');
            }
        } finally {
            if (file_exists($temporary)) {
                @unlink($temporary);
            }
        }

        $this->assertProtectedRegularFile($path);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readStateFile(string $path): ?array
    {
        if (!file_exists($path) && !is_link($path)) {
            return null;
        }

        $this->assertProtectedRegularFile($path);
        $raw = file_get_contents($path);
        $state = is_string($raw) ? json_decode($raw, true) : null;

        if (!is_array($state)) {
            throw new RuntimeException('Customers UI smoke state file is invalid.');
        }

        $this->assertValidStateShape($state);

        return $state;
    }

    private function removeStateFileIfPresent(string $path, bool $required = false): void
    {
        if (!file_exists($path) && !is_link($path)) {
            if ($required) {
                throw new RuntimeException('Customers UI smoke state file is missing after cleanup.');
            }

            return;
        }

        $this->assertProtectedRegularFile($path);

        if (!unlink($path)) {
            throw new RuntimeException('Customers UI smoke state file cleanup failed.');
        }
    }
}
