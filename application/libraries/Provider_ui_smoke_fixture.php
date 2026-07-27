<?php defined('BASEPATH') or exit('No direct script access allowed');

require_once __DIR__ . '/../core/Provider_ui_smoke_access_policy.php';

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
 * Root-only lifecycle for the isolated production provider UI smoke fixture.
 *
 * The permanent principal is dormant and has neither permissions nor a password
 * verifier. Activation temporarily creates synthetic rows, grants the provider
 * role and installs a bounded password verifier. Deactivation deletes the exact
 * fixture rows child-first and returns the principal to its non-authenticatable
 * dormant state.
 */
final class Provider_ui_smoke_fixture
{
    public const DEFAULT_CREDENTIAL_FILE = '/etc/fh/release-gate-provider-ui-smoke.env';

    public const DEFAULT_STATE_FILE = '/var/lib/fh-provider-ui-smoke/active.json';

    public const PROVIDER_FIRST_NAME = 'Synthetic';

    public const PROVIDER_LAST_NAME = 'Provider UI Smoke V1';

    public const PROVIDER_EMAIL = 'provider-ui-smoke-v1@synthetic.invalid';

    public const CUSTOMER_FIRST_NAME = 'Synthetic';

    public const CUSTOMER_LAST_NAME = 'Parent UI Smoke V1';

    public const CUSTOMER_EMAIL = 'customer-provider-ui-smoke-v1@synthetic.invalid';

    public const CUSTOMER_PHONE = '0000000000';

    public const CUSTOMER_NOTES = 'PROD_PROVIDER_UI_SMOKE_V1_PRIVATE_NOTE_SENTINEL';

    public const SERVICE_NAME = Provider_ui_smoke_access_policy::SERVICE_NAME;

    public const SERVICE_DESCRIPTION = Provider_ui_smoke_access_policy::SERVICE_DESCRIPTION;

    public const APPOINTMENT_BOOKED_INSIDE_NOTES = '__EA_PROVIDER_UI_SMOKE_V1_APPOINTMENT_BOOKED_INSIDE__';

    public const APPOINTMENT_CANCELLED_INSIDE_NOTES = '__EA_PROVIDER_UI_SMOKE_V1_APPOINTMENT_CANCELLED_INSIDE__';

    public const APPOINTMENT_BOOKED_OUTSIDE_NOTES = '__EA_PROVIDER_UI_SMOKE_V1_APPOINTMENT_BOOKED_OUTSIDE__';

    public const BOOKED_INSIDE_START = '2099-02-12 10:00:00';

    public const BOOKED_INSIDE_END = '2099-02-12 10:30:00';

    public const CANCELLED_INSIDE_START = '2099-02-12 11:00:00';

    public const CANCELLED_INSIDE_END = '2099-02-12 11:30:00';

    public const BOOKED_OUTSIDE_START = '2099-03-12 12:00:00';

    public const BOOKED_OUTSIDE_END = '2099-03-12 12:30:00';

    private const STATE_VERSION = 1;

    private const LOCK_NAME = 'fh-provider-ui-smoke-v1';

    private const DORMANT_ROLE_NAME = 'Provider UI Smoke Dormant';

    private const CREDENTIAL_USERNAME_KEY = 'PROVIDER_UI_SMOKE_USERNAME';

    private const CREDENTIAL_PASSWORD_KEY = 'PROVIDER_UI_SMOKE_PASSWORD';

    /**
     * @var EA_Controller|CI_Controller
     */
    private EA_Controller|CI_Controller $CI;

    public function __construct()
    {
        $this->CI = &get_instance();

        $this->CI->load->model('roles_model');
    }

    /**
     * Run one lifecycle action and return its non-sensitive state label.
     */
    public function run(
        string $action,
        string $credential_file = self::DEFAULT_CREDENTIAL_FILE,
        string $state_file = self::DEFAULT_STATE_FILE,
    ): string {
        if (!in_array($action, ['install', 'verify', 'activate', 'deactivate', 'remove'], true)) {
            throw new InvalidArgumentException('Unsupported provider UI smoke action.');
        }

        $this->assertRootCli();
        $this->assertSafeAbsolutePath($state_file);
        $state_file_existed_before = file_exists($state_file) || is_link($state_file);

        $credentials = null;

        if (in_array($action, ['install', 'verify', 'activate'], true)) {
            $credentials = $this->readCredentials($credential_file);
        }

        $this->acquireLock();

        try {
            if (!$this->CI->db->trans_begin()) {
                throw new RuntimeException('Provider UI smoke transaction could not start.');
            }

            $remove_state_after_commit = false;

            try {
                $state = match ($action) {
                    'install' => $this->install($state_file),
                    'verify' => $this->verify($state_file),
                    'activate' => $this->activate($credentials, $state_file),
                    'deactivate' => $this->deactivate($state_file, $remove_state_after_commit),
                    'remove' => $this->remove($state_file),
                };

                if (!$this->CI->db->trans_commit()) {
                    throw new RuntimeException('Provider UI smoke transaction could not commit.');
                }
            } catch (Throwable $exception) {
                $this->CI->db->trans_rollback();

                if ($action === 'activate' && !$state_file_existed_before) {
                    $this->removeStateFileIfPresent($state_file);
                }

                throw $exception;
            }

            if ($remove_state_after_commit) {
                $this->removeStateFileIfPresent($state_file, true);
            }

            return $state;
        } finally {
            $this->releaseLock();
        }
    }

    /**
     * Install the permanent dormant principal and zero-rights role.
     */
    private function install(string $state_file): string
    {
        $this->assertStateFileAbsent($state_file);
        $role = $this->findDormantRole();
        $role_name_query = $this->CI->db->get_where('roles', ['name' => self::DORMANT_ROLE_NAME]);

        if ($role === null) {
            if ($role_name_query->num_rows() !== 0) {
                throw new RuntimeException('Provider UI smoke dormant role name collision detected.');
            }

            $role_data = [
                'name' => self::DORMANT_ROLE_NAME,
                'slug' => Provider_ui_smoke_access_policy::DORMANT_ROLE_SLUG,
                'is_admin' => 0,
                'appointments' => 0,
                'customers' => 0,
                'services' => 0,
                'users' => 0,
                'system_settings' => 0,
                'user_settings' => 0,
            ];

            foreach (['webhooks', 'blocked_periods'] as $optional_permission) {
                if ($this->CI->db->field_exists($optional_permission, 'roles')) {
                    $role_data[$optional_permission] = 0;
                }
            }

            $role_id = $this->CI->roles_model->save($role_data);
            $role = $this->CI->db->get_where('roles', ['id' => $role_id])->row_array();
        }

        $this->assertDormantRole($role);
        $principal = $this->findPrincipal();

        if ($principal === null) {
            $this->assertPrincipalMarkersUnused();
            $now = date('Y-m-d H:i:s');

            $this->insertOrFail('users', [
                'first_name' => self::PROVIDER_FIRST_NAME,
                'last_name' => self::PROVIDER_LAST_NAME,
                'email' => self::PROVIDER_EMAIL,
                'phone_number' => null,
                'mobile_number' => null,
                'address' => null,
                'city' => null,
                'state' => null,
                'zip_code' => null,
                'notes' => Provider_ui_smoke_access_policy::DORMANT_NOTES,
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
                'id_roles' => $role['id'],
                'create_datetime' => $now,
                'update_datetime' => $now,
            ]);

            $principal_id = (int) $this->CI->db->insert_id();

            $this->insertOrFail('user_settings', [
                'id_users' => $principal_id,
                'username' => Provider_ui_smoke_access_policy::USERNAME,
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

            $principal = $this->findPrincipal();
        }

        $this->assertDormantClean($principal, $role);

        return 'dormant';
    }

    /**
     * Verify the expected safe resting state.
     */
    private function verify(string $state_file): string
    {
        $this->assertStateFileAbsent($state_file);
        $role = $this->findDormantRole();
        $principal = $this->findPrincipal();

        if ($role === null && $principal === null) {
            $this->assertPrincipalMarkersUnused();
            $this->assertFixtureMarkersUnused();

            return 'removed';
        }

        if ($role === null || $principal === null) {
            throw new RuntimeException('Provider UI smoke install state is incomplete.');
        }

        $this->assertDormantClean($principal, $role);

        return 'dormant';
    }

    /**
     * Create the short-lived synthetic fixture and activate the reserved principal.
     *
     * @param array{username: string, password: string} $credentials
     */
    private function activate(array $credentials, string $state_file): string
    {
        $this->assertStateFileAbsent($state_file);
        $dormant_role = $this->requireDormantRole();
        $principal = $this->requirePrincipal();
        $this->assertDormantClean($principal, $dormant_role);
        $provider_role = $this->requireUniqueRole(DB_SLUG_PROVIDER);
        $customer_role = $this->requireUniqueRole(DB_SLUG_CUSTOMER);
        $this->assertFixtureMarkersUnused((int) $principal['id']);

        $issued_at = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $expires_at = $issued_at->modify('+' . Provider_ui_smoke_access_policy::MAX_LEASE_SECONDS . ' seconds');
        $active_notes = Provider_ui_smoke_access_policy::buildActiveNotes($issued_at, $expires_at);
        $now = date('Y-m-d H:i:s');

        $this->insertOrFail('services', [
            'create_datetime' => $now,
            'update_datetime' => $now,
            'name' => self::SERVICE_NAME,
            'duration' => 30,
            'buffer_before' => 0,
            'buffer_after' => 0,
            'price' => 0,
            'currency' => 'EUR',
            'description' => self::SERVICE_DESCRIPTION,
            'location' => null,
            'color' => '#6c757d',
            'availabilities_type' => AVAILABILITIES_TYPE_FLEXIBLE,
            'attendants_number' => 1,
            'is_private' => 1,
            'id_service_categories' => null,
        ]);
        $service_id = (int) $this->CI->db->insert_id();

        $this->insertOrFail('users', [
            'first_name' => self::CUSTOMER_FIRST_NAME,
            'last_name' => self::CUSTOMER_LAST_NAME,
            'email' => self::CUSTOMER_EMAIL,
            'phone_number' => self::CUSTOMER_PHONE,
            'mobile_number' => null,
            'address' => null,
            'city' => null,
            'state' => null,
            'zip_code' => null,
            'notes' => self::CUSTOMER_NOTES,
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
            'id_roles' => $customer_role['id'],
            'create_datetime' => $now,
            'update_datetime' => $now,
        ]);
        $customer_id = (int) $this->CI->db->insert_id();

        $provider_id = (int) $principal['id'];
        $this->insertOrFail('services_providers', [
            'id_users' => $provider_id,
            'id_services' => $service_id,
        ]);

        $appointment_ids = [
            'booked_inside' => $this->insertAppointment(
                $provider_id,
                $customer_id,
                $service_id,
                self::BOOKED_INSIDE_START,
                self::BOOKED_INSIDE_END,
                'Booked',
                self::APPOINTMENT_BOOKED_INSIDE_NOTES,
            ),
            'cancelled_inside' => $this->insertAppointment(
                $provider_id,
                $customer_id,
                $service_id,
                self::CANCELLED_INSIDE_START,
                self::CANCELLED_INSIDE_END,
                'Cancelled',
                self::APPOINTMENT_CANCELLED_INSIDE_NOTES,
            ),
            'booked_outside' => $this->insertAppointment(
                $provider_id,
                $customer_id,
                $service_id,
                self::BOOKED_OUTSIDE_START,
                self::BOOKED_OUTSIDE_END,
                'Booked',
                self::APPOINTMENT_BOOKED_OUTSIDE_NOTES,
            ),
        ];

        $salt = generate_salt();
        $password_hash = hash_password($salt, $credentials['password']);

        $this->updateExactlyOne(
            'users',
            [
                'id_roles' => $provider_role['id'],
                'notes' => $active_notes,
                'update_datetime' => $now,
            ],
            ['id' => $provider_id],
            true,
        );
        $this->updateExactlyOne(
            'user_settings',
            [
                'salt' => $salt,
                'password' => $password_hash,
                'dashboard_range_start' => '2099-02-01',
                'dashboard_range_end' => '2099-02-28',
            ],
            ['id_users' => $provider_id],
            true,
        );

        $state = [
            'version' => self::STATE_VERSION,
            'fixture_key' => Provider_ui_smoke_access_policy::FIXTURE_KEY,
            'fixture_checksum' => $this->fixtureChecksum(),
            'username' => Provider_ui_smoke_access_policy::USERNAME,
            'issued_at' => $issued_at->format('Y-m-d\TH:i:s\Z'),
            'expires_at' => $expires_at->format('Y-m-d\TH:i:s\Z'),
            'ids' => [
                'provider' => $provider_id,
                'service' => $service_id,
                'customer' => $customer_id,
                'appointments' => $appointment_ids,
            ],
        ];

        $this->assertActiveFixture($state);
        $this->writeStateFile($state_file, $state);

        return 'active';
    }

    /**
     * Delete the exact active fixture and disable authentication again.
     */
    private function deactivate(string $state_file, bool &$remove_state_after_commit): string
    {
        $dormant_role = $this->requireDormantRole();
        $principal = $this->requirePrincipal();
        $state = $this->readStateFile($state_file);
        $had_state_file = $state !== null;

        if ($this->isDormantClean($principal, $dormant_role)) {
            $remove_state_after_commit = $state !== null;

            return 'dormant';
        }

        if ($state === null) {
            $state = $this->reconstructActiveState($principal);
        }

        $this->assertActiveFixture($state);
        $ids = $state['ids'];
        $appointment_ids = array_values($ids['appointments']);
        $buffer_ids = $this->loadAndAssertBufferChildren($appointment_ids);

        if ($buffer_ids !== []) {
            throw new RuntimeException('Provider UI smoke fixture has unexpected buffer children.');
        }

        $this->CI->db->where_in('id', $appointment_ids)->delete('appointments');

        if ($this->CI->db->affected_rows() !== 3) {
            throw new RuntimeException('Provider UI smoke appointment cleanup failed.');
        }

        $this->deleteExactlyOne('services_providers', [
            'id_users' => $ids['provider'],
            'id_services' => $ids['service'],
        ]);
        $this->deleteExactlyOne('users', ['id' => $ids['customer']]);
        $this->deleteExactlyOne('services', ['id' => $ids['service']]);
        $now = date('Y-m-d H:i:s');
        $this->updateExactlyOne(
            'users',
            [
                'id_roles' => $dormant_role['id'],
                'notes' => Provider_ui_smoke_access_policy::DORMANT_NOTES,
                'update_datetime' => $now,
            ],
            ['id' => $ids['provider']],
            true,
        );
        $this->updateExactlyOne(
            'user_settings',
            [
                'password' => null,
                'salt' => null,
                'dashboard_range_start' => null,
                'dashboard_range_end' => null,
            ],
            ['id_users' => $ids['provider']],
            true,
        );

        $principal = $this->requirePrincipal();
        $this->assertDormantClean($principal, $dormant_role);
        $remove_state_after_commit = $had_state_file;

        return 'dormant';
    }

    /**
     * Remove the clean dormant principal and its dedicated role.
     */
    private function remove(string $state_file): string
    {
        $this->assertStateFileAbsent($state_file);
        $principal = $this->findPrincipal();
        $role = $this->findDormantRole();

        if ($principal === null && $role === null) {
            $this->assertPrincipalMarkersUnused();
            $this->assertFixtureMarkersUnused();

            return 'removed';
        }

        if ($principal === null || $role === null) {
            throw new RuntimeException('Provider UI smoke install state is incomplete.');
        }

        $this->assertDormantClean($principal, $role);
        $this->deleteExactlyOne('user_settings', ['id_users' => $principal['id']]);
        $this->deleteExactlyOne('users', ['id' => $principal['id']]);

        $role_references = $this->CI->db->get_where('users', ['id_roles' => $role['id']])->num_rows();

        if ($role_references !== 0) {
            throw new RuntimeException('Provider UI smoke dormant role remains referenced.');
        }

        $this->deleteExactlyOne('roles', ['id' => $role['id']]);
        $this->assertPrincipalMarkersUnused();
        $this->assertFixtureMarkersUnused();

        if ($this->findPrincipal() !== null || $this->findDormantRole() !== null) {
            throw new RuntimeException('Provider UI smoke removal verification failed.');
        }

        return 'removed';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findPrincipal(): ?array
    {
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
            ->where('user_settings.username', Provider_ui_smoke_access_policy::USERNAME)
            ->get();

        if ($query->num_rows() > 1) {
            throw new RuntimeException('Provider UI smoke principal cardinality is invalid.');
        }

        return $query->num_rows() === 1 ? $query->row_array() : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function requirePrincipal(): array
    {
        $principal = $this->findPrincipal();

        if ($principal === null) {
            throw new RuntimeException('Provider UI smoke principal is missing.');
        }

        return $principal;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findDormantRole(): ?array
    {
        $query = $this->CI->db->get_where('roles', [
            'slug' => Provider_ui_smoke_access_policy::DORMANT_ROLE_SLUG,
        ]);

        if ($query->num_rows() > 1) {
            throw new RuntimeException('Provider UI smoke dormant role cardinality is invalid.');
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
            throw new RuntimeException('Provider UI smoke dormant role is missing.');
        }

        $this->assertDormantRole($role);

        return $role;
    }

    /**
     * @param array<string, mixed> $role
     */
    private function assertDormantRole(array $role): void
    {
        $name_query = $this->CI->db->get_where('roles', ['name' => self::DORMANT_ROLE_NAME]);

        if ($name_query->num_rows() !== 1 || (int) $name_query->row_array()['id'] !== (int) ($role['id'] ?? 0)) {
            throw new RuntimeException('Provider UI smoke dormant role ownership invariant failed.');
        }

        $expected = [
            'name' => self::DORMANT_ROLE_NAME,
            'slug' => Provider_ui_smoke_access_policy::DORMANT_ROLE_SLUG,
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
                throw new RuntimeException('Provider UI smoke dormant role invariant failed.');
            }
        }

        foreach (['webhooks', 'blocked_periods'] as $optional_permission) {
            if (
                $this->CI->db->field_exists($optional_permission, 'roles') &&
                (int) ($role[$optional_permission] ?? -1) !== 0
            ) {
                throw new RuntimeException('Provider UI smoke dormant role invariant failed.');
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
            throw new RuntimeException('Provider UI smoke required role cardinality is invalid.');
        }

        return $query->row_array();
    }

    /**
     * @param array<string, mixed> $principal
     * @param array<string, mixed> $role
     */
    private function assertDormantClean(array $principal, array $role): void
    {
        $this->assertDormantRole($role);

        if (
            (int) $principal['id_roles'] !== (int) $role['id'] ||
            $principal['role_slug'] !== Provider_ui_smoke_access_policy::DORMANT_ROLE_SLUG ||
            $principal['username'] !== Provider_ui_smoke_access_policy::USERNAME ||
            $principal['first_name'] !== self::PROVIDER_FIRST_NAME ||
            $principal['last_name'] !== self::PROVIDER_LAST_NAME ||
            $principal['email'] !== self::PROVIDER_EMAIL ||
            $principal['notes'] !== Provider_ui_smoke_access_policy::DORMANT_NOTES ||
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
            $principal['password'] !== null ||
            $principal['salt'] !== null ||
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
            throw new RuntimeException('Provider UI smoke dormant principal invariant failed.');
        }

        $this->assertFixtureMarkersUnused((int) $principal['id']);

        $provider_email_count = $this->CI->db->get_where('users', ['email' => self::PROVIDER_EMAIL])->num_rows();
        $dormant_note_count = $this->CI->db
            ->get_where('users', ['notes' => Provider_ui_smoke_access_policy::DORMANT_NOTES])
            ->num_rows();
        $this->CI->db->like('notes', Provider_ui_smoke_access_policy::ACTIVE_NOTES_PREFIX, 'after');
        $active_note_count = $this->CI->db->get('users')->num_rows();
        $role_reference_count = $this->CI->db->get_where('users', ['id_roles' => $role['id']])->num_rows();

        if (
            $provider_email_count !== 1 ||
            $dormant_note_count !== 1 ||
            $active_note_count !== 0 ||
            $role_reference_count !== 1
        ) {
            throw new RuntimeException('Provider UI smoke dormant ownership invariant failed.');
        }
    }

    /**
     * @param array<string, mixed> $principal
     * @param array<string, mixed> $role
     */
    private function isDormantClean(array $principal, array $role): bool
    {
        try {
            $this->assertDormantClean($principal, $role);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function assertPrincipalMarkersUnused(): void
    {
        $username_count = $this->CI->db
            ->get_where('user_settings', ['username' => Provider_ui_smoke_access_policy::USERNAME])
            ->num_rows();
        $email_count = $this->CI->db->get_where('users', ['email' => self::PROVIDER_EMAIL])->num_rows();

        if ($username_count !== 0 || $email_count !== 0) {
            throw new RuntimeException('Provider UI smoke principal marker collision detected.');
        }

        $this->CI->db->like('notes', Provider_ui_smoke_access_policy::ACTIVE_NOTES_PREFIX, 'after');
        $active_note_count = $this->CI->db->get('users')->num_rows();
        $dormant_note_count = $this->CI->db
            ->get_where('users', ['notes' => Provider_ui_smoke_access_policy::DORMANT_NOTES])
            ->num_rows();

        if ($active_note_count !== 0 || $dormant_note_count !== 0) {
            throw new RuntimeException('Provider UI smoke principal state collision detected.');
        }
    }

    private function assertFixtureMarkersUnused(?int $provider_id = null): void
    {
        $this->CI->db
            ->group_start()
            ->where('name', self::SERVICE_NAME)
            ->or_where('description', self::SERVICE_DESCRIPTION)
            ->group_end();
        $service_count = $this->CI->db->get('services')->num_rows();

        $this->CI->db
            ->group_start()
            ->where('email', self::CUSTOMER_EMAIL)
            ->or_where('notes', self::CUSTOMER_NOTES)
            ->group_end();
        $customer_count = $this->CI->db->get('users')->num_rows();

        $appointment_count = $this->CI->db
            ->where_in('notes', $this->appointmentMarkers())
            ->get('appointments')
            ->num_rows();

        if ($service_count !== 0 || $customer_count !== 0 || $appointment_count !== 0) {
            throw new RuntimeException('Provider UI smoke fixture marker collision detected.');
        }

        if ($provider_id !== null) {
            $provider_appointments = $this->CI->db
                ->get_where('appointments', ['id_users_provider' => $provider_id])
                ->num_rows();
            $provider_services = $this->CI->db
                ->get_where('services_providers', ['id_users' => $provider_id])
                ->num_rows();
            $provider_secretaries = $this->CI->db
                ->get_where('secretaries_providers', ['id_users_provider' => $provider_id])
                ->num_rows();

            if ($provider_appointments !== 0 || $provider_services !== 0 || $provider_secretaries !== 0) {
                throw new RuntimeException('Provider UI smoke principal has unexpected relations.');
            }
        }
    }

    /**
     * @param array<string, mixed> $state
     */
    private function assertActiveFixture(array $state): void
    {
        $this->assertValidStateShape($state);
        $ids = $state['ids'];
        $provider_query = $this->CI->db
            ->select(
                'users.id, users.first_name, users.last_name, users.email, users.phone_number, users.mobile_number, ' .
                    'users.address, users.city, users.state, users.zip_code, users.notes, users.room, ' .
                    'users.class_size_default, users.custom_field_1, users.custom_field_2, users.custom_field_3, ' .
                    'users.custom_field_4, users.custom_field_5, users.timezone, users.language, users.is_private, ' .
                    'users.ldap_dn, ' .
                    'roles.slug AS role_slug, user_settings.username, user_settings.password, user_settings.salt, ' .
                    'user_settings.working_plan, user_settings.working_plan_exceptions, user_settings.notifications, ' .
                    'user_settings.google_sync, user_settings.google_token, user_settings.google_calendar, ' .
                    'user_settings.sync_past_days, user_settings.sync_future_days, user_settings.calendar_view, ' .
                    'user_settings.caldav_sync, user_settings.caldav_url, user_settings.caldav_username, ' .
                    'user_settings.caldav_password, user_settings.dashboard_range_start, ' .
                    'user_settings.dashboard_range_end',
            )
            ->from('users')
            ->join('roles', 'roles.id = users.id_roles', 'inner')
            ->join('user_settings', 'user_settings.id_users = users.id', 'inner')
            ->where('users.id', $ids['provider'])
            ->where('user_settings.username', Provider_ui_smoke_access_policy::USERNAME)
            ->get();

        if ($provider_query->num_rows() !== 1) {
            throw new RuntimeException('Provider UI smoke active principal cardinality is invalid.');
        }

        $provider = $provider_query->row_array();
        $expected_notes =
            Provider_ui_smoke_access_policy::ACTIVE_NOTES_PREFIX . $state['issued_at'] . ':' . $state['expires_at'];

        if (
            $provider['role_slug'] !== DB_SLUG_PROVIDER ||
            $provider['first_name'] !== self::PROVIDER_FIRST_NAME ||
            $provider['last_name'] !== self::PROVIDER_LAST_NAME ||
            $provider['email'] !== self::PROVIDER_EMAIL ||
            $provider['notes'] !== $expected_notes ||
            $provider['phone_number'] !== null ||
            $provider['mobile_number'] !== null ||
            $provider['address'] !== null ||
            $provider['city'] !== null ||
            $provider['state'] !== null ||
            $provider['zip_code'] !== null ||
            $provider['room'] !== null ||
            $provider['class_size_default'] !== null ||
            $provider['custom_field_1'] !== null ||
            $provider['custom_field_2'] !== null ||
            $provider['custom_field_3'] !== null ||
            $provider['custom_field_4'] !== null ||
            $provider['custom_field_5'] !== null ||
            $provider['timezone'] !== 'UTC' ||
            $provider['language'] !== 'german' ||
            (int) $provider['is_private'] !== 1 ||
            $provider['ldap_dn'] !== null ||
            empty($provider['password']) ||
            empty($provider['salt']) ||
            $provider['working_plan'] !== '{}' ||
            $provider['working_plan_exceptions'] !== '{}' ||
            (int) $provider['notifications'] !== 0 ||
            (int) $provider['google_sync'] !== 0 ||
            $provider['google_token'] !== null ||
            $provider['google_calendar'] !== null ||
            (int) $provider['sync_past_days'] !== 0 ||
            (int) $provider['sync_future_days'] !== 0 ||
            $provider['calendar_view'] !== CALENDAR_VIEW_DEFAULT ||
            (int) $provider['caldav_sync'] !== 0 ||
            $provider['caldav_url'] !== null ||
            $provider['caldav_username'] !== null ||
            $provider['caldav_password'] !== null
        ) {
            throw new RuntimeException('Provider UI smoke active principal invariant failed.');
        }

        $this->assertValidActiveDashboardRange($provider['dashboard_range_start'], $provider['dashboard_range_end']);

        $service_query = $this->CI->db->get_where('services', ['id' => $ids['service']]);

        if ($service_query->num_rows() !== 1) {
            throw new RuntimeException('Provider UI smoke service cardinality is invalid.');
        }

        $service = $service_query->row_array();

        if (
            $service['name'] !== self::SERVICE_NAME ||
            $service['description'] !== self::SERVICE_DESCRIPTION ||
            (int) $service['duration'] !== 30 ||
            (int) $service['buffer_before'] !== 0 ||
            (int) $service['buffer_after'] !== 0 ||
            (int) $service['attendants_number'] !== 1 ||
            (int) $service['is_private'] !== 1 ||
            (float) $service['price'] !== 0.0 ||
            $service['currency'] !== 'EUR' ||
            $service['location'] !== null ||
            $service['color'] !== '#6c757d' ||
            $service['availabilities_type'] !== AVAILABILITIES_TYPE_FLEXIBLE ||
            $service['id_service_categories'] !== null ||
            empty($service['create_datetime']) ||
            $service['create_datetime'] !== $service['update_datetime']
        ) {
            throw new RuntimeException('Provider UI smoke service invariant failed.');
        }

        $customer_query = $this->CI->db->get_where('users', ['id' => $ids['customer']]);

        if ($customer_query->num_rows() !== 1) {
            throw new RuntimeException('Provider UI smoke customer cardinality is invalid.');
        }

        $customer = $customer_query->row_array();
        $customer_role = $this->requireUniqueRole(DB_SLUG_CUSTOMER);
        $customer_settings_count = $this->CI->db
            ->get_where('user_settings', ['id_users' => $ids['customer']])
            ->num_rows();

        if (
            $customer['first_name'] !== self::CUSTOMER_FIRST_NAME ||
            $customer['last_name'] !== self::CUSTOMER_LAST_NAME ||
            $customer['email'] !== self::CUSTOMER_EMAIL ||
            $customer['phone_number'] !== self::CUSTOMER_PHONE ||
            $customer['notes'] !== self::CUSTOMER_NOTES ||
            (int) $customer['id_roles'] !== (int) $customer_role['id'] ||
            (int) $customer['is_private'] !== 1 ||
            $customer['ldap_dn'] !== null ||
            $customer['mobile_number'] !== null ||
            $customer['address'] !== null ||
            $customer['city'] !== null ||
            $customer['state'] !== null ||
            $customer['zip_code'] !== null ||
            $customer['room'] !== null ||
            $customer['class_size_default'] !== null ||
            $customer['custom_field_1'] !== null ||
            $customer['custom_field_2'] !== null ||
            $customer['custom_field_3'] !== null ||
            $customer['custom_field_4'] !== null ||
            $customer['custom_field_5'] !== null ||
            $customer['timezone'] !== 'UTC' ||
            $customer['language'] !== 'german' ||
            empty($customer['create_datetime']) ||
            $customer['create_datetime'] !== $customer['update_datetime'] ||
            $customer_settings_count !== 0
        ) {
            throw new RuntimeException('Provider UI smoke customer invariant failed.');
        }

        $this->assertExactAppointment(
            $ids['appointments']['booked_inside'],
            $ids,
            self::BOOKED_INSIDE_START,
            self::BOOKED_INSIDE_END,
            'Booked',
            self::APPOINTMENT_BOOKED_INSIDE_NOTES,
        );
        $this->assertExactAppointment(
            $ids['appointments']['cancelled_inside'],
            $ids,
            self::CANCELLED_INSIDE_START,
            self::CANCELLED_INSIDE_END,
            'Cancelled',
            self::APPOINTMENT_CANCELLED_INSIDE_NOTES,
        );
        $this->assertExactAppointment(
            $ids['appointments']['booked_outside'],
            $ids,
            self::BOOKED_OUTSIDE_START,
            self::BOOKED_OUTSIDE_END,
            'Booked',
            self::APPOINTMENT_BOOKED_OUTSIDE_NOTES,
        );

        $appointment_count = $this->CI->db
            ->get_where('appointments', ['id_users_provider' => $ids['provider']])
            ->num_rows();
        $service_appointment_count = $this->CI->db
            ->get_where('appointments', ['id_services' => $ids['service']])
            ->num_rows();
        $customer_appointment_count = $this->CI->db
            ->get_where('appointments', ['id_users_customer' => $ids['customer']])
            ->num_rows();
        $provider_service_count = $this->CI->db
            ->get_where('services_providers', [
                'id_users' => $ids['provider'],
                'id_services' => $ids['service'],
            ])
            ->num_rows();
        $provider_service_total = $this->CI->db
            ->get_where('services_providers', ['id_users' => $ids['provider']])
            ->num_rows();
        $provider_secretary_total = $this->CI->db
            ->get_where('secretaries_providers', ['id_users_provider' => $ids['provider']])
            ->num_rows();

        $this->CI->db
            ->group_start()
            ->where('name', self::SERVICE_NAME)
            ->or_where('description', self::SERVICE_DESCRIPTION)
            ->group_end();
        $service_marker_count = $this->CI->db->get('services')->num_rows();

        $this->CI->db
            ->group_start()
            ->where('email', self::CUSTOMER_EMAIL)
            ->or_where('notes', self::CUSTOMER_NOTES)
            ->group_end();
        $customer_marker_count = $this->CI->db->get('users')->num_rows();
        $appointment_marker_count = $this->CI->db
            ->where_in('notes', $this->appointmentMarkers())
            ->get('appointments')
            ->num_rows();
        $buffer_ids = $this->loadAndAssertBufferChildren(array_values($ids['appointments']));
        $owned_appointment_total = 3 + count($buffer_ids);

        if (
            $appointment_count !== $owned_appointment_total ||
            $service_appointment_count !== $owned_appointment_total ||
            $customer_appointment_count !== 3 ||
            $provider_service_count !== 1 ||
            $provider_service_total !== 1 ||
            $provider_secretary_total !== 0 ||
            $service_marker_count !== 1 ||
            $customer_marker_count !== 1 ||
            $appointment_marker_count !== 3
        ) {
            throw new RuntimeException('Provider UI smoke active relation invariant failed.');
        }
    }

    private function assertValidActiveDashboardRange(mixed $start_value, mixed $end_value): void
    {
        if (!is_string($start_value) || !is_string($end_value)) {
            throw new RuntimeException('Provider UI smoke active dashboard range invariant failed.');
        }

        $timezone = new DateTimeZone('UTC');
        $start = DateTimeImmutable::createFromFormat('!Y-m-d', $start_value, $timezone);
        $end = DateTimeImmutable::createFromFormat('!Y-m-d', $end_value, $timezone);

        if (
            !$start ||
            !$end ||
            $start->format('Y-m-d') !== $start_value ||
            $end->format('Y-m-d') !== $end_value ||
            $start > $end
        ) {
            throw new RuntimeException('Provider UI smoke active dashboard range invariant failed.');
        }
    }

    /**
     * @param array<string, mixed> $ids
     */
    private function assertExactAppointment(
        int $appointment_id,
        array $ids,
        string $start,
        string $end,
        string $status,
        string $notes,
    ): void {
        $query = $this->CI->db->get_where('appointments', ['id' => $appointment_id]);

        if ($query->num_rows() !== 1) {
            throw new RuntimeException('Provider UI smoke appointment cardinality is invalid.');
        }

        $appointment = $query->row_array();

        if (
            $appointment['start_datetime'] !== $start ||
            $appointment['end_datetime'] !== $end ||
            $appointment['status'] !== $status ||
            $appointment['notes'] !== $notes ||
            $appointment['book_datetime'] !== '2099-01-01 00:00:00' ||
            $appointment['location'] !== null ||
            $appointment['color'] !== '#6c757d' ||
            preg_match('/\A[a-f0-9]{64}\z/D', (string) $appointment['hash']) !== 1 ||
            empty($appointment['create_datetime']) ||
            $appointment['create_datetime'] !== $appointment['update_datetime'] ||
            (int) $appointment['is_unavailability'] !== 0 ||
            (int) $appointment['id_users_provider'] !== (int) $ids['provider'] ||
            (int) $appointment['id_users_customer'] !== (int) $ids['customer'] ||
            (int) $appointment['id_services'] !== (int) $ids['service'] ||
            $appointment['id_google_calendar'] !== null ||
            $appointment['id_caldav_calendar'] !== null ||
            $appointment['id_parent_appointment'] !== null
        ) {
            throw new RuntimeException('Provider UI smoke appointment invariant failed.');
        }
    }

    /**
     * Load only buffer children owned by the exact synthetic parent set.
     *
     * @param list<int> $parent_ids
     *
     * @return list<int>
     */
    private function loadAndAssertBufferChildren(array $parent_ids): array
    {
        $query = $this->CI->db->where_in('id_parent_appointment', $parent_ids)->get('appointments');
        $buffer_ids = [];

        foreach ($query->result_array() as $buffer) {
            $buffer_ids[] = (int) $buffer['id'];
        }

        if ($buffer_ids !== []) {
            throw new RuntimeException('Provider UI smoke fixture has unexpected buffer children.');
        }

        return $buffer_ids;
    }

    /**
     * Reconstruct an active state only from exact, unique synthetic markers.
     *
     * @param array<string, mixed> $principal
     *
     * @return array<string, mixed>
     */
    private function reconstructActiveState(array $principal): array
    {
        if (
            $principal['role_slug'] !== DB_SLUG_PROVIDER ||
            !Provider_ui_smoke_access_policy::parseActiveNotes((string) $principal['notes'])
        ) {
            throw new RuntimeException('Provider UI smoke crash recovery precondition failed.');
        }

        $this->CI->db
            ->group_start()
            ->where('name', self::SERVICE_NAME)
            ->or_where('description', self::SERVICE_DESCRIPTION)
            ->group_end();
        $service_query = $this->CI->db->get('services');

        $this->CI->db
            ->group_start()
            ->where('email', self::CUSTOMER_EMAIL)
            ->or_where('notes', self::CUSTOMER_NOTES)
            ->group_end();
        $customer_query = $this->CI->db->get('users');
        $appointment_query = $this->CI->db->where_in('notes', $this->appointmentMarkers())->get('appointments');

        if (
            $service_query->num_rows() !== 1 ||
            $customer_query->num_rows() !== 1 ||
            $appointment_query->num_rows() !== 3
        ) {
            throw new RuntimeException('Provider UI smoke crash recovery cardinality is invalid.');
        }

        $appointments = [];

        foreach ($appointment_query->result_array() as $appointment) {
            $key = match ($appointment['notes']) {
                self::APPOINTMENT_BOOKED_INSIDE_NOTES => 'booked_inside',
                self::APPOINTMENT_CANCELLED_INSIDE_NOTES => 'cancelled_inside',
                self::APPOINTMENT_BOOKED_OUTSIDE_NOTES => 'booked_outside',
                default => null,
            };

            if ($key === null || isset($appointments[$key])) {
                throw new RuntimeException('Provider UI smoke crash recovery marker set is invalid.');
            }

            $appointments[$key] = (int) $appointment['id'];
        }

        if (count($appointments) !== 3) {
            throw new RuntimeException('Provider UI smoke crash recovery marker set is incomplete.');
        }

        $lease = Provider_ui_smoke_access_policy::parseActiveNotes((string) $principal['notes']);

        return [
            'version' => self::STATE_VERSION,
            'fixture_key' => Provider_ui_smoke_access_policy::FIXTURE_KEY,
            'fixture_checksum' => $this->fixtureChecksum(),
            'username' => Provider_ui_smoke_access_policy::USERNAME,
            'issued_at' => $lease['issued_at']->format('Y-m-d\TH:i:s\Z'),
            'expires_at' => $lease['expires_at']->format('Y-m-d\TH:i:s\Z'),
            'ids' => [
                'provider' => (int) $principal['id'],
                'service' => (int) $service_query->row_array()['id'],
                'customer' => (int) $customer_query->row_array()['id'],
                'appointments' => $appointments,
            ],
        ];
    }

    private function insertAppointment(
        int $provider_id,
        int $customer_id,
        int $service_id,
        string $start,
        string $end,
        string $status,
        string $notes,
    ): int {
        $now = date('Y-m-d H:i:s');

        $this->insertOrFail('appointments', [
            'create_datetime' => $now,
            'update_datetime' => $now,
            'book_datetime' => '2099-01-01 00:00:00',
            'start_datetime' => $start,
            'end_datetime' => $end,
            'location' => null,
            'color' => '#6c757d',
            'status' => $status,
            'notes' => $notes,
            'hash' => bin2hex(random_bytes(32)),
            'is_unavailability' => 0,
            'id_users_provider' => $provider_id,
            'id_users_customer' => $customer_id,
            'id_services' => $service_id,
            'id_parent_appointment' => null,
            'id_google_calendar' => null,
            'id_caldav_calendar' => null,
        ]);

        return (int) $this->CI->db->insert_id();
    }

    /**
     * @return list<string>
     */
    private function appointmentMarkers(): array
    {
        return [
            self::APPOINTMENT_BOOKED_INSIDE_NOTES,
            self::APPOINTMENT_CANCELLED_INSIDE_NOTES,
            self::APPOINTMENT_BOOKED_OUTSIDE_NOTES,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function insertOrFail(string $table, array $data): void
    {
        if (!$this->CI->db->insert($table, $data)) {
            throw new RuntimeException('Provider UI smoke insert failed.');
        }
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $where
     */
    private function updateExactlyOne(string $table, array $data, array $where, bool $require_changed = false): void
    {
        if ($this->CI->db->get_where($table, $where)->num_rows() !== 1) {
            throw new RuntimeException('Provider UI smoke update cardinality is invalid.');
        }

        if (!$this->CI->db->update($table, $data, $where)) {
            throw new RuntimeException('Provider UI smoke update failed.');
        }

        if ($require_changed && $this->CI->db->affected_rows() !== 1) {
            throw new RuntimeException('Provider UI smoke state transition did not change exactly one row.');
        }
    }

    /**
     * @param array<string, mixed> $where
     */
    private function deleteExactlyOne(string $table, array $where): void
    {
        if ($this->CI->db->get_where($table, $where)->num_rows() !== 1) {
            throw new RuntimeException('Provider UI smoke delete cardinality is invalid.');
        }

        $this->CI->db->delete($table, $where);

        if ($this->CI->db->affected_rows() !== 1) {
            throw new RuntimeException('Provider UI smoke delete failed.');
        }
    }

    /**
     * @return array{username: string, password: string}
     */
    private function readCredentials(string $path): array
    {
        $this->assertSafeAbsolutePath($path);
        $this->assertProtectedRegularFile($path);
        $credentials = parse_ini_file($path, false, INI_SCANNER_RAW);

        if (
            !is_array($credentials) ||
            count($credentials) !== 2 ||
            !array_key_exists(self::CREDENTIAL_USERNAME_KEY, $credentials) ||
            !array_key_exists(self::CREDENTIAL_PASSWORD_KEY, $credentials)
        ) {
            throw new RuntimeException('Provider UI smoke credential schema is invalid.');
        }

        $username = $credentials[self::CREDENTIAL_USERNAME_KEY] ?? null;
        $password = $credentials[self::CREDENTIAL_PASSWORD_KEY] ?? null;

        if (
            $username !== Provider_ui_smoke_access_policy::USERNAME ||
            !is_string($password) ||
            preg_match('/\A[a-f0-9]{64}\z/D', $password) !== 1
        ) {
            throw new RuntimeException('Provider UI smoke credential values are invalid.');
        }

        return [
            'username' => $username,
            'password' => $password,
        ];
    }

    private function assertProtectedRegularFile(string $path): void
    {
        $stat = @lstat($path);

        if ($stat === false || !is_file($path) || is_link($path)) {
            throw new RuntimeException('Provider UI smoke protected file is invalid.');
        }

        if (
            ($stat['mode'] & 0777) !== 0600 ||
            (int) $stat['uid'] !== 0 ||
            (int) $stat['nlink'] !== 1 ||
            (int) $stat['size'] < 1 ||
            (int) $stat['size'] > 65536
        ) {
            throw new RuntimeException('Provider UI smoke protected file permissions are invalid.');
        }

        $parent = dirname($path);
        $parent_stat = @lstat($parent);

        if (
            $parent_stat === false ||
            !is_dir($parent) ||
            is_link($parent) ||
            ((int) $parent_stat['mode'] & 0022) !== 0 ||
            (int) $parent_stat['uid'] !== 0
        ) {
            throw new RuntimeException('Provider UI smoke protected directory permissions are invalid.');
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
            throw new InvalidArgumentException('Provider UI smoke path is invalid.');
        }
    }

    private function assertRootCli(): void
    {
        if (!is_cli() || !function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            throw new RuntimeException('Provider UI smoke lifecycle requires root CLI.');
        }
    }

    private function acquireLock(): void
    {
        $row = $this->CI->db->query('SELECT GET_LOCK(?, 10) AS acquired', [self::LOCK_NAME])->row_array();

        if ((int) ($row['acquired'] ?? 0) !== 1) {
            throw new RuntimeException('Provider UI smoke advisory lock could not be acquired.');
        }
    }

    private function releaseLock(): void
    {
        try {
            $this->CI->db->query('SELECT RELEASE_LOCK(?)', [self::LOCK_NAME]);
        } catch (Throwable) {
            // The connection closing also releases the lock; never mask the lifecycle result.
        }
    }

    private function assertStateFileAbsent(string $path): void
    {
        if (file_exists($path) || is_link($path)) {
            throw new RuntimeException('Provider UI smoke state file unexpectedly exists.');
        }
    }

    /**
     * @param array<string, mixed> $state
     */
    private function writeStateFile(string $path, array $state): void
    {
        $directory = dirname($path);

        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Provider UI smoke state directory could not be created.');
        }

        $directory_stat = @lstat($directory);

        if (
            $directory_stat === false ||
            is_link($directory) ||
            ((int) $directory_stat['mode'] & 0777) !== 0700 ||
            (int) $directory_stat['uid'] !== 0
        ) {
            throw new RuntimeException('Provider UI smoke state directory permissions are invalid.');
        }

        $encoded = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($encoded === false) {
            throw new RuntimeException('Provider UI smoke state encoding failed.');
        }

        $temporary = tempnam($directory, '.active.');

        if ($temporary === false) {
            throw new RuntimeException('Provider UI smoke temporary state file could not be created.');
        }

        try {
            if (!chmod($temporary, 0600)) {
                throw new RuntimeException('Provider UI smoke temporary state permissions failed.');
            }

            $handle = fopen($temporary, 'wb');

            if ($handle === false) {
                throw new RuntimeException('Provider UI smoke temporary state open failed.');
            }

            try {
                if (fwrite($handle, $encoded . PHP_EOL) === false || !fflush($handle)) {
                    throw new RuntimeException('Provider UI smoke temporary state write failed.');
                }

                if (function_exists('fsync') && !fsync($handle)) {
                    throw new RuntimeException('Provider UI smoke temporary state sync failed.');
                }
            } finally {
                fclose($handle);
            }

            if (!rename($temporary, $path)) {
                throw new RuntimeException('Provider UI smoke state publish failed.');
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
            throw new RuntimeException('Provider UI smoke state file is invalid.');
        }

        $this->assertValidStateShape($state);

        return $state;
    }

    private function removeStateFileIfPresent(string $path, bool $required = false): void
    {
        if (!file_exists($path) && !is_link($path)) {
            if ($required) {
                throw new RuntimeException('Provider UI smoke state file is missing after cleanup.');
            }

            return;
        }

        $this->assertProtectedRegularFile($path);

        if (!unlink($path)) {
            throw new RuntimeException('Provider UI smoke state file cleanup failed.');
        }
    }

    /**
     * @param array<string, mixed> $state
     */
    private function assertValidStateShape(array $state): void
    {
        $expected_top_keys = [
            'version',
            'fixture_key',
            'fixture_checksum',
            'username',
            'issued_at',
            'expires_at',
            'ids',
        ];

        if (
            array_keys($state) !== $expected_top_keys ||
            $state['version'] !== self::STATE_VERSION ||
            $state['fixture_key'] !== Provider_ui_smoke_access_policy::FIXTURE_KEY ||
            $state['fixture_checksum'] !== $this->fixtureChecksum() ||
            $state['username'] !== Provider_ui_smoke_access_policy::USERNAME ||
            !is_array($state['ids']) ||
            array_keys($state['ids']) !== ['provider', 'service', 'customer', 'appointments'] ||
            !is_array($state['ids']['appointments']) ||
            array_keys($state['ids']['appointments']) !== ['booked_inside', 'cancelled_inside', 'booked_outside']
        ) {
            throw new RuntimeException('Provider UI smoke state schema is invalid.');
        }

        $all_ids = [
            $state['ids']['provider'],
            $state['ids']['service'],
            $state['ids']['customer'],
            ...array_values($state['ids']['appointments']),
        ];

        foreach ($all_ids as $id) {
            if (!is_int($id) || $id <= 0) {
                throw new RuntimeException('Provider UI smoke state identifier is invalid.');
            }
        }

        $appointment_ids = array_values($state['ids']['appointments']);

        if (count($appointment_ids) !== count(array_unique($appointment_ids))) {
            throw new RuntimeException('Provider UI smoke state appointment identifiers are not unique.');
        }

        $notes =
            Provider_ui_smoke_access_policy::ACTIVE_NOTES_PREFIX . $state['issued_at'] . ':' . $state['expires_at'];

        if (Provider_ui_smoke_access_policy::parseActiveNotes($notes) === null) {
            throw new RuntimeException('Provider UI smoke state lease is invalid.');
        }
    }

    private function fixtureChecksum(): string
    {
        return hash(
            'sha256',
            implode("\n", [
                Provider_ui_smoke_access_policy::FIXTURE_KEY,
                Provider_ui_smoke_access_policy::USERNAME,
                self::PROVIDER_EMAIL,
                self::CUSTOMER_EMAIL,
                self::CUSTOMER_NOTES,
                self::SERVICE_NAME,
                self::SERVICE_DESCRIPTION,
                self::BOOKED_INSIDE_START,
                self::BOOKED_INSIDE_END,
                self::CANCELLED_INSIDE_START,
                self::CANCELLED_INSIDE_END,
                self::BOOKED_OUTSIDE_START,
                self::BOOKED_OUTSIDE_END,
                ...$this->appointmentMarkers(),
            ]),
        );
    }
}
