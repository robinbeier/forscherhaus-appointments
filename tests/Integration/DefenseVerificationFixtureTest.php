<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ReleaseGate\DefenseVerificationFixture;
use ReleaseGate\OrdinaryLiveFixture;

require_once dirname(__DIR__, 2) . '/scripts/release-gate/lib/OrdinaryLiveFixture.php';
require_once dirname(__DIR__, 2) . '/scripts/release-gate/lib/DefenseVerificationFixture.php';

/** Focused lifecycle contract running only in the disposable defense-cycle stack. */
final class DefenseVerificationFixtureTest extends TestCase
{
    private string $stateDirectory;
    private ?OrdinaryLiveFixture $ordinary = null;
    private ?DefenseVerificationFixture $fixture = null;

    protected function setUp(): void
    {
        if (getenv('FH_DEFENSE_ISOLATED') !== '1' || !function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            self::markTestSkipped('Requires the explicitly isolated root Docker fixture run.');
        }
        self::assertSame('testing', ENVIRONMENT);
        $this->stateDirectory = '/var/lib/fh-defense-verification-tests-' . bin2hex(random_bytes(8));
        $this->ordinary = new OrdinaryLiveFixture($this->stateDirectory);
        $this->fixture = new DefenseVerificationFixture($this->stateDirectory);
        self::assertSame('clean', $this->ordinary->verify());
        self::assertSame('clean', $this->fixture->verify());
    }

    protected function tearDown(): void
    {
        if (!isset($this->stateDirectory)) {
            return;
        }
        if ($this->fixture !== null && is_file($this->stateDirectory . '/defense-verification.json')) {
            $this->fixture->deactivate();
        }
        if ($this->ordinary !== null && is_file($this->stateDirectory . '/state.json')) {
            $this->ordinary->deactivate();
        }
        foreach (
            [
                'defense-verification.json',
                'defense-verification.json.tmp',
                'defense-verification.lock',
                'state.json',
                'lifecycle.lock',
            ]
            as $name
        ) {
            $path = $this->stateDirectory . '/' . $name;
            if (is_file($path) && !is_link($path)) {
                unlink($path);
            }
        }
        if (is_dir($this->stateDirectory) && !is_link($this->stateDirectory)) {
            rmdir($this->stateDirectory);
        }
    }

    public function testCustomerBoundaryLifecycleContainsNoCredentialAndCleansAfterExpectedDestroy(): void
    {
        $actor = $this->ordinary->activate(roleSlug: 'admin');
        $state = $this->fixture->activate('customer_boundary', $actor);

        self::assertSame('active', $this->fixture->verify());
        self::assertArrayNotHasKey('password', $state);
        self::assertArrayNotHasKey('credential', $state);
        self::assertSame('customer_boundary', $state['profile']);

        $this->fixture->deactivate();
        self::assertSame('clean', $this->fixture->verify());
        $this->fixture->deactivate();
    }

    public function testCalendarRaceLifecycleCleansAllRelationshipsAndObjects(): void
    {
        $actor = $this->ordinary->activate();
        $state = $this->fixture->activate('calendar_race', $actor);

        self::assertSame('active', $this->fixture->verify());
        self::assertSame('calendar_race', $state['profile']);
        self::assertGreaterThan(0, $state['appointment_id']);

        $this->fixture->deactivate();
        self::assertSame('clean', $this->fixture->verify());
        $db = &get_instance()->db;
        self::assertSame(0, $db->get_where('appointments', ['id' => $state['appointment_id']])->num_rows());
        self::assertSame(0, $db->get_where('services', ['id' => $state['service_id']])->num_rows());
        self::assertSame(0, $db->get_where('services_providers', ['id_services' => $state['service_id']])->num_rows());
    }

    public function testAppointmentsApiIntentRecoversInsertWhenResponseWasNotJournaled(): void
    {
        $actor = $this->ordinary->activate();
        $this->fixture->activate('calendar_race', $actor);
        $this->fixture->prepareAppointmentsApi();
        $payload = $this->fixture->prepareApiAppointment('basic');
        $ci = &get_instance();
        $ci->load->model('appointments_model');
        $id = $ci->appointments_model->save([
            'start_datetime' => $payload['start'],
            'end_datetime' => $payload['end'],
            'location' => $payload['location'],
            'color' => $payload['color'],
            'status' => $payload['status'],
            'notes' => $payload['notes'],
            'is_unavailability' => 0,
            'id_users_customer' => $payload['customerId'],
            'id_users_provider' => $payload['providerId'],
            'id_services' => $payload['serviceId'],
        ]);
        self::assertGreaterThan(0, $id);

        $this->fixture->deactivate();
        self::assertSame('clean', $this->fixture->verify());
        self::assertSame(0, $ci->db->get_where('appointments', ['id' => $id])->num_rows());
    }

    public function testAppointmentsApiPrincipalRecoversFailureBetweenUserAndSettingsInsert(): void
    {
        $actor = $this->ordinary->activate();
        $state = $this->fixture->activate('calendar_race', $actor);
        $ci = &get_instance();
        $database = $ci->db;
        $proxy = new class ($database) {
            public bool $failed = false;

            public function __construct(private readonly object $database) {}

            public function insert(string $table, array $row): bool
            {
                if (
                    !$this->failed &&
                    $table === 'user_settings' &&
                    str_ends_with((string) ($row['username'] ?? ''), '_api_admin')
                ) {
                    $this->failed = true;
                    return false;
                }
                return $this->database->insert($table, $row);
            }

            public function __call(string $name, array $arguments): mixed
            {
                return $this->database->$name(...$arguments);
            }
        };
        $databaseProperty = new ReflectionProperty(DefenseVerificationFixture::class, 'db');
        $databaseProperty->setValue($this->fixture, $proxy);
        try {
            $this->fixture->prepareAppointmentsApi();
            self::fail('Supplemental principal creation must surface the injected settings failure.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('user_settings', $error->getMessage());
        } finally {
            $databaseProperty->setValue($this->fixture, $database);
        }
        self::assertTrue($proxy->failed);

        $journal = json_decode(
            (string) file_get_contents($this->stateDirectory . '/defense-verification.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertSame('settings_prepared', $journal['intents']['users']['api_admin']['stage']);
        self::assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/D',
            $journal['intents']['users']['api_admin']['settings_digest'],
        );
        self::assertArrayHasKey('api_admin', $journal['ids']);
        self::assertSame('cleanup_pending', $this->fixture->verify());

        $email = 'defense_verify_' . $state['run_id'] . '_api_admin@synthetic.invalid';
        $user = $ci->db
            ->get_where('users', [
                'email' => $email,
                'notes' => $state['marker'],
                'id_roles' => $journal['intents']['users']['api_admin']['role_id'],
            ])
            ->row_array();
        self::assertIsArray($user);
        $userId = (int) $user['id'];
        self::assertSame(0, $ci->db->get_where('user_settings', ['id_users' => $userId])->num_rows());

        $salt = generate_salt();
        $preparedUsername = $journal['intents']['users']['api_admin']['username'];
        self::assertTrue(
            $ci->db->insert('user_settings', [
                'id_users' => $userId,
                'username' => $preparedUsername,
                'password' => hash_password($salt, bin2hex(random_bytes(16))),
                'salt' => $salt,
                'working_plan' => '{}',
                'working_plan_exceptions' => '{}',
                'notifications' => 0,
                'google_sync' => 0,
                'caldav_sync' => 0,
            ]),
        );
        try {
            $this->fixture->deactivate();
            self::fail('Cleanup must reject settings that differ from the prepared digest.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('settings drifted', $error->getMessage());
        }
        self::assertSame(1, $ci->db->get_where('users', ['id' => $userId])->num_rows());
        self::assertSame(1, $ci->db->get_where('user_settings', ['id_users' => $userId])->num_rows());

        self::assertTrue($ci->db->delete('user_settings', ['id_users' => $userId, 'username' => $preparedUsername]));
        $this->fixture->deactivate();
        self::assertSame('clean', $this->fixture->verify());
        self::assertSame(0, $ci->db->get_where('users', ['id' => $userId])->num_rows());
    }

    public function testAppointmentsApiPrincipalRecoversFailureAfterSettingsInsertBeforeCompleteJournal(): void
    {
        $actor = $this->ordinary->activate();
        $state = $this->fixture->activate('calendar_race', $actor);
        $ci = &get_instance();
        $database = $ci->db;
        $proxy = new class ($database) {
            public bool $inserted = false;

            public function __construct(private readonly object $database) {}

            public function insert(string $table, array $row): bool
            {
                if (
                    !$this->inserted &&
                    $table === 'user_settings' &&
                    str_ends_with((string) ($row['username'] ?? ''), '_api_admin')
                ) {
                    if (!$this->database->insert($table, $row)) {
                        return false;
                    }
                    $this->inserted = true;
                    throw new RuntimeException('Injected failure after Appointments API settings insert.');
                }
                return $this->database->insert($table, $row);
            }

            public function __call(string $name, array $arguments): mixed
            {
                return $this->database->$name(...$arguments);
            }
        };
        $databaseProperty = new ReflectionProperty(DefenseVerificationFixture::class, 'db');
        $databaseProperty->setValue($this->fixture, $proxy);
        try {
            $this->fixture->prepareAppointmentsApi();
            self::fail('Supplemental principal creation must surface the post-settings failure.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('after Appointments API settings insert', $error->getMessage());
        } finally {
            $databaseProperty->setValue($this->fixture, $database);
        }
        self::assertTrue($proxy->inserted);

        $journalText = (string) file_get_contents($this->stateDirectory . '/defense-verification.json');
        $journal = json_decode($journalText, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('settings_prepared', $journal['intents']['users']['api_admin']['stage']);
        self::assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/D',
            $journal['intents']['users']['api_admin']['settings_digest'],
        );
        self::assertArrayHasKey('api_admin', $journal['ids']);
        self::assertArrayNotHasKey('api_credentials', $journal);
        self::assertSame('cleanup_pending', $this->fixture->verify());

        $userId = (int) $journal['ids']['api_admin'];
        $settings = $ci->db->get_where('user_settings', ['id_users' => $userId])->row_array();
        self::assertIsArray($settings);
        self::assertStringNotContainsString((string) $settings['password'], $journalText);
        self::assertStringNotContainsString((string) $settings['salt'], $journalText);
        self::assertSame(1, $ci->db->get_where('users', ['id' => $userId, 'notes' => $state['marker']])->num_rows());

        $this->fixture->deactivate();
        self::assertSame('clean', $this->fixture->verify());
        self::assertSame(0, $ci->db->get_where('users', ['id' => $userId])->num_rows());
        self::assertSame(0, $ci->db->get_where('user_settings', ['id_users' => $userId])->num_rows());
    }

    public function testAppointmentsApiPrincipalRecoversFailureBeforeUserInsert(): void
    {
        $actor = $this->ordinary->activate();
        $state = $this->fixture->activate('calendar_race', $actor);
        $ci = &get_instance();
        $database = $ci->db;
        $proxy = new class ($database) {
            public bool $failed = false;

            public function __construct(private readonly object $database) {}

            public function insert(string $table, array $row): bool
            {
                if (
                    !$this->failed &&
                    $table === 'users' &&
                    str_ends_with((string) ($row['email'] ?? ''), '_api_admin@synthetic.invalid')
                ) {
                    $this->failed = true;
                    return false;
                }
                return $this->database->insert($table, $row);
            }

            public function __call(string $name, array $arguments): mixed
            {
                return $this->database->$name(...$arguments);
            }
        };
        $databaseProperty = new ReflectionProperty(DefenseVerificationFixture::class, 'db');
        $databaseProperty->setValue($this->fixture, $proxy);
        try {
            $this->fixture->prepareAppointmentsApi();
            self::fail('Supplemental principal creation must surface the injected user failure.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('users', $error->getMessage());
        } finally {
            $databaseProperty->setValue($this->fixture, $database);
        }
        self::assertTrue($proxy->failed);

        $journal = json_decode(
            (string) file_get_contents($this->stateDirectory . '/defense-verification.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertSame('prepared', $journal['intents']['users']['api_admin']['stage']);
        self::assertArrayNotHasKey('api_admin', $journal['ids']);
        self::assertSame('cleanup_pending', $this->fixture->verify());

        $email = 'defense_verify_' . $state['run_id'] . '_api_admin@synthetic.invalid';
        $username = 'defense_verify_' . $state['run_id'] . '_api_admin';
        self::assertSame(0, $ci->db->get_where('users', ['email' => $email])->num_rows());
        self::assertSame(0, $ci->db->get_where('user_settings', ['username' => $username])->num_rows());

        self::assertTrue(
            $ci->db->insert('users', [
                'first_name' => 'Foreign partial',
                'last_name' => 'API admin',
                'email' => $email,
                'phone_number' => '000000000',
                'notes' => 'foreign-prepared-api-admin',
                'timezone' => 'UTC',
                'language' => 'english',
                'id_roles' => $journal['intents']['users']['api_admin']['role_id'],
                'is_private' => 1,
            ]),
        );
        $foreignUserId = (int) $ci->db->insert_id();
        try {
            $this->fixture->deactivate();
            self::fail('Cleanup must retain an unresolved user with the prepared email.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('could not be resolved exactly', $error->getMessage());
        }
        self::assertFileExists($this->stateDirectory . '/defense-verification.json');
        self::assertSame(1, $ci->db->get_where('users', ['id' => $foreignUserId, 'email' => $email])->num_rows());

        self::assertTrue(
            $ci->db->delete('users', [
                'id' => $foreignUserId,
                'email' => $email,
                'notes' => 'foreign-prepared-api-admin',
            ]),
        );

        $this->fixture->deactivate();
        self::assertSame('clean', $this->fixture->verify());
        self::assertSame(0, $ci->db->get_where('users', ['email' => $email])->num_rows());
        self::assertSame(0, $ci->db->get_where('user_settings', ['username' => $username])->num_rows());
    }

    public function testPreparedAppointmentsApiPrincipalRejectsSettingsInsertedBeforeUserLock(): void
    {
        $actor = $this->ordinary->activate();
        $this->fixture->activate('calendar_race', $actor);
        $state = $this->fixture->prepareAppointmentsApi();
        $ci = &get_instance();
        $userId = (int) $state['ids']['api_admin'];
        $username = (string) $state['usernames']['api_admin'];
        self::assertTrue($ci->db->delete('user_settings', ['id_users' => $userId, 'username' => $username]));

        $journalPath = $this->stateDirectory . '/defense-verification.json';
        $journal = json_decode((string) file_get_contents($journalPath), true, 512, JSON_THROW_ON_ERROR);
        $journal['intents']['users']['api_admin']['stage'] = 'prepared';
        unset($journal['ids']['api_admin'], $journal['usernames']['api_admin'], $journal['api_credentials']);
        self::assertNotFalse(
            file_put_contents($journalPath, json_encode($journal, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n"),
        );
        self::assertSame('cleanup_pending', $this->fixture->verify());

        $salt = generate_salt();
        $settings = [
            'id_users' => $userId,
            'username' => $username,
            'password' => hash_password($salt, bin2hex(random_bytes(16))),
            'salt' => $salt,
            'working_plan' => '{}',
            'working_plan_exceptions' => '{}',
            'notifications' => 0,
            'google_sync' => 0,
            'caldav_sync' => 0,
        ];
        $database = $ci->db;
        $secondary = $ci->load->database('', true);
        $proxy = new class ($database, $secondary, $settings) {
            public bool $injected = false;

            public function __construct(
                private readonly object $database,
                private readonly object $secondary,
                private readonly array $settings,
            ) {}

            public function query(string $sql, mixed ...$arguments): mixed
            {
                if (
                    !$this->injected &&
                    str_contains($sql, $this->database->dbprefix('users')) &&
                    str_contains($sql, 'FOR UPDATE')
                ) {
                    if (!$this->secondary->insert('user_settings', $this->settings)) {
                        throw new RuntimeException('Could not inject prepared principal settings.');
                    }
                    $this->injected = true;
                }
                return $this->database->query($sql, ...$arguments);
            }

            public function __call(string $name, array $arguments): mixed
            {
                return $this->database->$name(...$arguments);
            }
        };
        $databaseProperty = new ReflectionProperty(DefenseVerificationFixture::class, 'db');
        $databaseProperty->setValue($this->fixture, $proxy);
        try {
            $this->fixture->deactivate();
            self::fail('Cleanup must reject settings inserted between its precheck and user lock.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('unexpected settings', $error->getMessage());
        } finally {
            $databaseProperty->setValue($this->fixture, $database);
            $secondary->close();
        }
        self::assertTrue($proxy->injected);
        self::assertSame(1, $ci->db->get_where('users', ['id' => $userId])->num_rows());
        self::assertSame(1, $ci->db->get_where('user_settings', ['id_users' => $userId])->num_rows());

        self::assertTrue($ci->db->delete('user_settings', ['id_users' => $userId, 'username' => $username]));
        $this->fixture->deactivate();
        self::assertSame('clean', $this->fixture->verify());
        self::assertSame(0, $ci->db->get_where('users', ['id' => $userId])->num_rows());
    }

    public function testAppointmentsApiDeleteGuardRefusesChildBeforeCallbackAndCleanupCanResume(): void
    {
        $actor = $this->ordinary->activate();
        $this->fixture->activate('calendar_race', $actor);
        $this->fixture->prepareAppointmentsApi();
        $ci = &get_instance();
        $ci->load->model('appointments_model');
        $payload = $this->fixture->prepareApiAppointment('basic');
        $id = $ci->appointments_model->save($this->decodeApiPayload($payload));
        $this->fixture->confirmApiAppointmentCreated('basic', $id);
        $update = $this->fixture->prepareApiAppointmentUpdate('basic');
        $ci->appointments_model->save(['id' => $id] + $this->decodeApiPayload($update));
        $this->fixture->confirmApiAppointmentUpdated('basic');
        $this->fixture->prepareApiAppointmentDelete('basic');

        $ci->db->insert('appointments', [
            'create_datetime' => date('Y-m-d H:i:s'),
            'update_datetime' => date('Y-m-d H:i:s'),
            'book_datetime' => date('Y-m-d H:i:s'),
            'start_datetime' => $update['start'],
            'end_datetime' => $update['end'],
            'location' => null,
            'color' => '#6c757d',
            'status' => 'Booked',
            'notes' => 'foreign-api-delete-child',
            'hash' => bin2hex(random_bytes(32)),
            'is_unavailability' => 1,
            'id_users_provider' => $update['providerId'],
            'id_users_customer' => null,
            'id_services' => null,
            'id_parent_appointment' => $id,
            'id_google_calendar' => null,
            'id_caldav_calendar' => null,
        ]);
        $childId = (int) $ci->db->insert_id();
        $called = false;
        try {
            $this->fixture->guardApiAppointmentDelete('basic', static function () use (&$called): void {
                $called = true;
            });
            self::fail('Delete guard must reject an unexpected child.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('unexpected child', $error->getMessage());
        }
        self::assertFalse($called);
        self::assertSame(1, $ci->db->get_where('appointments', ['id' => $id])->num_rows());

        try {
            $this->fixture->deactivate();
            self::fail('Cleanup must remain fail-closed while the child exists.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('generated appointment child', $error->getMessage());
        }
        $ci->db->delete('appointments', ['id' => $childId, 'notes' => 'foreign-api-delete-child']);
        $this->fixture->deactivate();
        self::assertSame('clean', $this->fixture->verify());
    }

    public function testAppointmentsApiDeleteGuardRevalidatesTargetAfterParentLocks(): void
    {
        $actor = $this->ordinary->activate();
        $this->fixture->activate('calendar_race', $actor);
        $this->fixture->prepareAppointmentsApi();
        $ci = &get_instance();
        $ci->load->model('appointments_model');
        $payload = $this->fixture->prepareApiAppointment('basic');
        $id = $ci->appointments_model->save($this->decodeApiPayload($payload));
        $this->fixture->confirmApiAppointmentCreated('basic', $id);
        $update = $this->fixture->prepareApiAppointmentUpdate('basic');
        $ci->appointments_model->save(['id' => $id] + $this->decodeApiPayload($update));
        $this->fixture->confirmApiAppointmentUpdated('basic');
        $this->fixture->prepareApiAppointmentDelete('basic');

        $originalHash = (string) $ci->db->get_where('appointments', ['id' => $id])->row_array()['hash'];
        $driftedHash = str_repeat($originalHash[0] === 'a' ? 'b' : 'a', 64);
        $secondary = $ci->load->database('', true);
        $database = $ci->db;
        $proxy = new class ($database, $secondary, $id, $driftedHash) {
            public bool $injected = false;

            public function __construct(
                private readonly object $database,
                private readonly object $secondary,
                private readonly int $appointmentId,
                private readonly string $driftedHash,
            ) {}

            public function query(string $sql, mixed ...$arguments): mixed
            {
                if (
                    !$this->injected &&
                    str_contains($sql, $this->database->dbprefix('users')) &&
                    str_contains($sql, 'FOR UPDATE')
                ) {
                    if (
                        !$this->secondary->update(
                            'appointments',
                            ['hash' => $this->driftedHash],
                            ['id' => $this->appointmentId],
                        )
                    ) {
                        throw new RuntimeException('Could not inject appointment drift.');
                    }
                    $this->injected = true;
                }
                return $this->database->query($sql, ...$arguments);
            }

            public function __call(string $name, array $arguments): mixed
            {
                return $this->database->$name(...$arguments);
            }
        };
        $databaseProperty = new ReflectionProperty(DefenseVerificationFixture::class, 'db');
        $databaseProperty->setValue($this->fixture, $proxy);
        $called = false;
        try {
            $this->fixture->guardApiAppointmentDelete('basic', static function () use (&$called): void {
                $called = true;
            });
            self::fail('Delete guard must reject target drift after acquiring parent locks.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('hash drift', $error->getMessage());
        } finally {
            $databaseProperty->setValue($this->fixture, $database);
            $secondary->close();
        }
        self::assertTrue($proxy->injected);
        self::assertFalse($called);
        self::assertSame($driftedHash, $ci->db->get_where('appointments', ['id' => $id])->row_array()['hash']);

        self::assertTrue($ci->db->update('appointments', ['hash' => $originalHash], ['id' => $id]));
        $this->fixture->deactivate();
        self::assertSame('clean', $this->fixture->verify());
    }

    private function decodeApiPayload(array $payload): array
    {
        return [
            'start_datetime' => $payload['start'],
            'end_datetime' => $payload['end'],
            'location' => $payload['location'],
            'color' => $payload['color'],
            'status' => $payload['status'],
            'notes' => $payload['notes'],
            'is_unavailability' => 0,
            'id_users_customer' => $payload['customerId'],
            'id_users_provider' => $payload['providerId'],
            'id_services' => $payload['serviceId'],
        ];
    }

    public function testCleanupRefusesForeignProviderLinkWithoutDeletingService(): void
    {
        $actor = $this->ordinary->activate();
        $state = $this->fixture->activate('calendar_race', $actor);
        $db = &get_instance()->db;
        $providerRole = $db->get_where('roles', ['slug' => 'provider'])->row_array();
        self::assertIsArray($providerRole);
        $db->insert('users', [
            'first_name' => 'Synthetic foreign child',
            'last_name' => 'cleanup regression',
            'email' => bin2hex(random_bytes(8)) . '@synthetic.invalid',
            'phone_number' => '000000000',
            'notes' => 'foreign-defense-verification-provider-child',
            'timezone' => 'UTC',
            'language' => 'english',
            'id_roles' => (int) $providerRole['id'],
            'is_private' => 1,
        ]);
        $extraProviderId = (int) $db->insert_id();
        $db->insert('services_providers', [
            'id_users' => $extraProviderId,
            'id_services' => (int) $state['service_id'],
        ]);
        try {
            $this->fixture->deactivate();
            self::fail('Cleanup must refuse a foreign provider relationship.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('Unexpected service provider relationship', $error->getMessage());
        }
        self::assertSame(1, $db->get_where('services', ['id' => $state['service_id']])->num_rows());
        self::assertSame(
            1,
            $db
                ->get_where('services_providers', [
                    'id_users' => $extraProviderId,
                    'id_services' => (int) $state['service_id'],
                ])
                ->num_rows(),
        );
        $db->delete('services_providers', [
            'id_users' => $extraProviderId,
            'id_services' => (int) $state['service_id'],
        ]);
        $db->delete('users', ['id' => $extraProviderId]);
        $this->fixture->deactivate();
        self::assertSame('clean', $this->fixture->verify());
    }

    public function testCleanupRefusesForeignAppointmentWithoutDeletingItOrService(): void
    {
        $actor = $this->ordinary->activate();
        $state = $this->fixture->activate('calendar_race', $actor);
        $db = &get_instance()->db;
        $foreignAppointment = [
            'create_datetime' => date('Y-m-d H:i:s'),
            'update_datetime' => date('Y-m-d H:i:s'),
            'book_datetime' => date('Y-m-d H:i:s'),
            'start_datetime' => '2099-12-01 10:00:00',
            'end_datetime' => '2099-12-01 10:30:00',
            'location' => null,
            'color' => '#6c757d',
            'status' => 'Booked',
            'notes' => 'foreign-defense-verification-child',
            'hash' => bin2hex(random_bytes(32)),
            'is_unavailability' => 0,
            'id_users_provider' => (int) $state['foreign_provider_id'],
            'id_users_customer' => (int) $state['customer_id'],
            'id_services' => (int) $state['service_id'],
            'id_parent_appointment' => null,
            'id_google_calendar' => null,
            'id_caldav_calendar' => null,
        ];
        $db->insert('appointments', $foreignAppointment);
        $foreignAppointmentId = (int) $db->insert_id();
        try {
            $this->fixture->deactivate();
            self::fail('Cleanup must refuse foreign service children.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('service appointment relationship drifted', $error->getMessage());
        }
        self::assertSame(1, $db->get_where('appointments', ['id' => $foreignAppointmentId])->num_rows());
        self::assertSame(1, $db->get_where('services', ['id' => $state['service_id']])->num_rows());
        $db->delete('appointments', ['id' => $foreignAppointmentId]);
        $this->fixture->deactivate();
        self::assertSame('clean', $this->fixture->verify());
    }

    public function testCleanupRefusesForeignSecretaryRelationshipAndCanResume(): void
    {
        $actor = $this->ordinary->activate();
        $state = $this->fixture->activate('calendar_race', $actor);
        $db = &get_instance()->db;
        $secretaryRole = $db->get_where('roles', ['slug' => 'secretary'])->row_array();
        self::assertIsArray($secretaryRole);
        $db->insert('users', [
            'first_name' => 'Synthetic secretary',
            'last_name' => 'cleanup regression',
            'email' => bin2hex(random_bytes(8)) . '@synthetic.invalid',
            'phone_number' => '000000000',
            'notes' => 'foreign-defense-verification-secretary',
            'timezone' => 'UTC',
            'language' => 'english',
            'id_roles' => (int) $secretaryRole['id'],
            'is_private' => 1,
        ]);
        $secretaryId = (int) $db->insert_id();
        $db->insert('secretaries_providers', [
            'id_users_provider' => (int) $state['foreign_provider_id'],
            'id_users_secretary' => $secretaryId,
        ]);
        try {
            $this->fixture->deactivate();
            self::fail('Cleanup must refuse a foreign secretary relationship.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('secretary relationship', $error->getMessage());
        }
        self::assertSame(1, $db->get_where('services', ['id' => $state['service_id']])->num_rows());
        self::assertSame(
            1,
            $db
                ->get_where('secretaries_providers', [
                    'id_users_provider' => (int) $state['foreign_provider_id'],
                    'id_users_secretary' => $secretaryId,
                ])
                ->num_rows(),
        );
        $db->delete('secretaries_providers', [
            'id_users_provider' => (int) $state['foreign_provider_id'],
            'id_users_secretary' => $secretaryId,
        ]);
        $db->delete('users', ['id' => $secretaryId]);
        $this->fixture->deactivate();
        self::assertSame('clean', $this->fixture->verify());
    }

    public function testPreparedJournalRecoversExactRowsWhenPublishedIdsAreMissing(): void
    {
        $actor = $this->ordinary->activate();
        $state = $this->fixture->activate('calendar_race', $actor);
        $journalPath = $this->stateDirectory . '/defense-verification.json';
        $prepared = $state;
        $prepared['phase'] = 'prepared';
        unset($prepared['ids']['appointment'], $prepared['ids']['service'], $prepared['ids']['foreign_provider']);
        file_put_contents($journalPath, json_encode($prepared, JSON_THROW_ON_ERROR));
        chmod($journalPath, 0600);

        self::assertSame('cleanup_pending', $this->fixture->verify());
        $this->fixture->deactivate();
        self::assertSame('clean', $this->fixture->verify());
        $db = &get_instance()->db;
        self::assertSame(0, $db->get_where('appointments', ['id' => $state['appointment_id']])->num_rows());
        self::assertSame(0, $db->get_where('services', ['id' => $state['service_id']])->num_rows());
        self::assertSame(0, $db->get_where('users', ['id' => $state['foreign_provider_id']])->num_rows());
    }

    public function testPreparedCleanupRetainsMissingLinkAllowanceAcrossAFailedAttempt(): void
    {
        $actor = $this->ordinary->activate();
        $state = $this->fixture->activate('calendar_race', $actor);
        $missingLink = $state['links']['foreign_service'];
        $db = &get_instance()->db;
        $db->delete('services_providers', [
            'id_users' => (int) $missingLink['id_users'],
            'id_services' => (int) $missingLink['id_services'],
        ]);
        $secretaryRole = $db->get_where('roles', ['slug' => 'secretary'])->row_array();
        self::assertIsArray($secretaryRole);
        $db->insert('users', [
            'first_name' => 'Synthetic prepared secretary',
            'last_name' => 'cleanup retry regression',
            'email' => bin2hex(random_bytes(8)) . '@synthetic.invalid',
            'phone_number' => '000000000',
            'notes' => 'foreign-prepared-cleanup-secretary',
            'timezone' => 'UTC',
            'language' => 'english',
            'id_roles' => (int) $secretaryRole['id'],
            'is_private' => 1,
        ]);
        $secretaryId = (int) $db->insert_id();
        $db->insert('secretaries_providers', [
            'id_users_provider' => (int) $state['foreign_provider_id'],
            'id_users_secretary' => $secretaryId,
        ]);
        $journalPath = $this->stateDirectory . '/defense-verification.json';
        $state['phase'] = 'prepared';
        file_put_contents($journalPath, json_encode($state, JSON_THROW_ON_ERROR));
        chmod($journalPath, 0600);

        self::assertSame('cleanup_pending', $this->fixture->verify());
        try {
            $this->fixture->deactivate();
            self::fail('Prepared cleanup must still refuse an unjournaled secretary relationship.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('secretary relationship', $error->getMessage());
        }
        $persisted = json_decode((string) file_get_contents($journalPath), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('cleaning', $persisted['phase']);
        self::assertSame('prepared', $persisted['cleanup_origin_phase']);
        $db->delete('secretaries_providers', [
            'id_users_provider' => (int) $state['foreign_provider_id'],
            'id_users_secretary' => $secretaryId,
        ]);
        $db->delete('users', ['id' => $secretaryId]);
        $this->fixture->deactivate();
        self::assertSame('clean', $this->fixture->verify());
        self::assertSame(
            0,
            $db
                ->get_where('services_providers', [
                    'id_users' => (int) $missingLink['id_users'],
                    'id_services' => (int) $missingLink['id_services'],
                ])
                ->num_rows(),
        );
    }

    public function testPreparedServiceBeforeAppointmentJournalRejectsForeignAppointmentAndResumes(): void
    {
        $actor = $this->ordinary->activate();
        $state = $this->fixture->activate('calendar_race', $actor);
        $db = &get_instance()->db;
        $db->delete('appointments', ['id' => (int) $state['appointment_id']]);
        $db->insert('appointments', [
            'create_datetime' => date('Y-m-d H:i:s'),
            'update_datetime' => date('Y-m-d H:i:s'),
            'book_datetime' => date('Y-m-d H:i:s'),
            'start_datetime' => '2099-12-02 10:00:00',
            'end_datetime' => '2099-12-02 10:30:00',
            'location' => null,
            'color' => '#6c757d',
            'status' => 'Booked',
            'notes' => 'foreign-prepared-appointment',
            'hash' => bin2hex(random_bytes(32)),
            'is_unavailability' => 0,
            'id_users_provider' => (int) $state['foreign_provider_id'],
            'id_users_customer' => (int) $state['customer_id'],
            'id_services' => (int) $state['service_id'],
            'id_parent_appointment' => null,
            'id_google_calendar' => null,
            'id_caldav_calendar' => null,
        ]);
        $foreignAppointmentId = (int) $db->insert_id();
        $journalPath = $this->stateDirectory . '/defense-verification.json';
        $state['phase'] = 'prepared';
        unset($state['ids']['appointment'], $state['intents']['appointment']);
        file_put_contents($journalPath, json_encode($state, JSON_THROW_ON_ERROR));
        chmod($journalPath, 0600);
        try {
            $this->fixture->deactivate();
            self::fail('Prepared cleanup must reject an unjournaled service appointment.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('appointment relationship', $error->getMessage());
        }
        self::assertSame(1, $db->get_where('appointments', ['id' => $foreignAppointmentId])->num_rows());
        self::assertSame(1, $db->get_where('services', ['id' => $state['service_id']])->num_rows());
        $db->delete('appointments', ['id' => $foreignAppointmentId]);
        $this->fixture->deactivate();
        self::assertSame('clean', $this->fixture->verify());
    }

    public function testCleanupRefusesGeneratedBufferChildAndResumesAfterRemoval(): void
    {
        $actor = $this->ordinary->activate();
        $state = $this->fixture->activate('calendar_race', $actor);
        $db = &get_instance()->db;
        $db->insert('appointments', [
            'create_datetime' => date('Y-m-d H:i:s'),
            'update_datetime' => date('Y-m-d H:i:s'),
            'book_datetime' => date('Y-m-d H:i:s'),
            'start_datetime' => '2099-12-03 10:00:00',
            'end_datetime' => '2099-12-03 10:30:00',
            'location' => null,
            'color' => '#6c757d',
            'status' => 'Booked',
            'notes' => 'foreign-generated-buffer-child',
            'hash' => bin2hex(random_bytes(32)),
            'is_unavailability' => 1,
            'id_users_provider' => (int) $state['actor_id'],
            'id_users_customer' => null,
            'id_services' => null,
            'id_parent_appointment' => (int) $state['appointment_id'],
            'id_google_calendar' => null,
            'id_caldav_calendar' => null,
        ]);
        $childId = (int) $db->insert_id();
        $db->delete('appointments', ['id' => (int) $state['appointment_id']]);
        $journalPath = $this->stateDirectory . '/defense-verification.json';
        $state['phase'] = 'prepared';
        unset($state['ids']['appointment'], $state['intents']['appointment']);
        file_put_contents($journalPath, json_encode($state, JSON_THROW_ON_ERROR));
        chmod($journalPath, 0600);
        try {
            $this->fixture->deactivate();
            self::fail('Cleanup must refuse a generated appointment child.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('generated appointment child', $error->getMessage());
        }
        self::assertSame(1, $db->get_where('appointments', ['id' => $childId])->num_rows());
        self::assertSame(0, $db->get_where('appointments', ['id' => $state['appointment_id']])->num_rows());
        self::assertSame(1, $db->get_where('services', ['id' => $state['service_id']])->num_rows());
        $db->delete('appointments', ['id' => $childId]);
        $this->fixture->deactivate();
        self::assertSame('clean', $this->fixture->verify());
    }

    public function testPreparedMissingAppointmentIdWithReboundParentFailsClosed(): void
    {
        $actor = $this->ordinary->activate();
        $state = $this->fixture->activate('calendar_race', $actor);
        $db = &get_instance()->db;
        $parent = $db->get_where('appointments', ['id' => (int) $state['appointment_id']])->row_array();
        self::assertIsArray($parent);
        $db->update(
            'appointments',
            [
                'id_users_provider' => (int) $state['foreign_provider_id'],
            ],
            ['id' => (int) $state['appointment_id']],
        );
        $db->insert('appointments', [
            'create_datetime' => date('Y-m-d H:i:s'),
            'update_datetime' => date('Y-m-d H:i:s'),
            'book_datetime' => date('Y-m-d H:i:s'),
            'start_datetime' => '2099-12-04 10:00:00',
            'end_datetime' => '2099-12-04 10:30:00',
            'location' => null,
            'color' => '#6c757d',
            'status' => 'Booked',
            'notes' => 'foreign-rebound-buffer',
            'hash' => bin2hex(random_bytes(32)),
            'is_unavailability' => 1,
            'id_users_provider' => (int) $state['foreign_provider_id'],
            'id_users_customer' => null,
            'id_services' => null,
            'id_parent_appointment' => (int) $state['appointment_id'],
            'id_google_calendar' => null,
            'id_caldav_calendar' => null,
        ]);
        $childId = (int) $db->insert_id();
        $journalPath = $this->stateDirectory . '/defense-verification.json';
        $state['phase'] = 'prepared';
        unset($state['ids']['appointment']);
        file_put_contents($journalPath, json_encode($state, JSON_THROW_ON_ERROR));
        chmod($journalPath, 0600);
        try {
            $this->fixture->deactivate();
            self::fail('Unreconstructed appointment intent must block cleanup.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('Appointment intent', $error->getMessage());
        }
        self::assertSame(1, $db->get_where('services', ['id' => $state['service_id']])->num_rows());
        self::assertSame(1, $db->get_where('appointments', ['id' => $state['appointment_id']])->num_rows());
        self::assertSame(1, $db->get_where('appointments', ['id' => $childId])->num_rows());
        $db->delete('appointments', ['id' => (int) $state['appointment_id']]);
        try {
            $this->fixture->deactivate();
            self::fail('An orphaned buffer must not resolve an unreconstructed appointment intent.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('Appointment intent', $error->getMessage());
        }
        self::assertSame(1, $db->get_where('services', ['id' => $state['service_id']])->num_rows());
        self::assertSame(0, $db->get_where('appointments', ['id' => $state['appointment_id']])->num_rows());
        self::assertSame(1, $db->get_where('appointments', ['id' => $childId])->num_rows());
        $db->insert('appointments', $parent);
        $db->delete('appointments', ['id' => $childId]);
        $this->fixture->deactivate();
        self::assertSame('clean', $this->fixture->verify());
    }

    public function testOwnershipDriftRefusesCleanupUntilExactStateIsRestored(): void
    {
        $actor = $this->ordinary->activate();
        $state = $this->fixture->activate('calendar_race', $actor);
        $db = &get_instance()->db;
        $db->update('services', ['description' => 'foreign'], ['id' => $state['service_id']]);
        self::assertSame('cleanup_pending', $this->fixture->verify());

        try {
            $this->fixture->deactivate();
            self::fail('Cleanup must refuse a drifted synthetic service.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('drift', strtolower($error->getMessage()));
        } finally {
            $db->update(
                'services',
                ['description' => $state['marker']],
                ['id' => $state['service_id'], 'description' => 'foreign'],
            );
        }

        $this->fixture->deactivate();
        self::assertSame('clean', $this->fixture->verify());
    }

    public function testInterruptedTemporaryJournalBlocksVerificationAndActivation(): void
    {
        $temporary = $this->stateDirectory . '/defense-verification.json.tmp';
        file_put_contents($temporary, 'synthetic interrupted journal');
        chmod($temporary, 0600);

        self::assertSame('cleanup_pending', $this->fixture->verify());
        try {
            $this->fixture->assertCleanBeforeActivation();
            self::fail('An interrupted temporary journal must block activation.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('already exists', $error->getMessage());
        }
        self::assertSame('synthetic interrupted journal', file_get_contents($temporary));

        unlink($temporary);
        self::assertSame('clean', $this->fixture->verify());
    }

    public function testMissingJournalDoesNotHideReservedSyntheticRows(): void
    {
        $db = &get_instance()->db;
        $role = $db->get_where('roles', ['slug' => 'customer'])->row_array();
        $marker = 'defense-verification:' . bin2hex(random_bytes(16));
        $email = bin2hex(random_bytes(16)) . '@synthetic.invalid';
        $db->insert('users', [
            'first_name' => 'Synthetic',
            'last_name' => 'Orphan',
            'email' => $email,
            'phone_number' => '000000000',
            'notes' => $marker,
            'timezone' => 'UTC',
            'language' => 'english',
            'id_roles' => (int) $role['id'],
            'is_private' => 1,
        ]);
        $id = (int) $db->insert_id();
        try {
            self::assertSame('cleanup_pending', $this->fixture->verify());
            $this->expectException(RuntimeException::class);
            $this->fixture->assertCleanBeforeActivation();
        } finally {
            $db->delete('users', ['id' => $id, 'email' => $email, 'notes' => $marker]);
        }
    }

    public function testUnconfirmedCalendarRequestRetainsFixtureForExplicitRecovery(): void
    {
        $actor = $this->ordinary->activate();
        $this->fixture->activate('calendar_race', $actor);
        $this->fixture->retainForRecovery('calendar_request_termination_unconfirmed');

        self::assertSame('cleanup_pending', $this->fixture->verify());
        try {
            $this->fixture->deactivate();
            self::fail('Automatic cleanup must not release an unconfirmed request fixture.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('explicit request recovery', $error->getMessage());
        }

        $journalPath = $this->stateDirectory . '/defense-verification.json';
        $state = json_decode((string) file_get_contents($journalPath), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('recovery_required', $state['phase']);
        self::assertSame('calendar_request_termination_unconfirmed', $state['recovery_reason']);

        // This simulates the separate operator decision only inside the disposable test stack.
        $state['phase'] = 'active';
        unset($state['recovery_reason']);
        file_put_contents($journalPath, json_encode($state, JSON_THROW_ON_ERROR));
        chmod($journalPath, 0600);
        $this->fixture->deactivate();
        self::assertSame('clean', $this->fixture->verify());
    }
}
