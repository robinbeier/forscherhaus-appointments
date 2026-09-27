<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once APPPATH . 'libraries/Zero_surprise_canary_fixture.php';

final class ZeroSurpriseCanaryFixtureTest extends TestCase
{
    protected function setUp(): void
    {
        if (getenv('FH_CANARY_INTEGRATION') !== '1' || !function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            self::markTestSkipped('Requires the explicitly isolated root Docker fixture run.');
        }
        self::assertSame('testing', ENVIRONMENT);
        self::assertSame('clean', (new Zero_surprise_canary_fixture())->run('verify'));
    }

    public function testActivateVerifyAndCompleteCleanup(): void
    {
        $fixture = new Zero_surprise_canary_fixture();
        try {
            self::assertSame('active', $fixture->run('activate'));
            self::assertSame('active', $fixture->run('verify'));
            $state = json_decode(
                file_get_contents(Zero_surprise_canary_fixture::DEFAULT_STATE_FILE),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
            $ci = &get_instance();
            $settings = $ci->db->get_where('user_settings', ['id_users' => $state['provider_id']])->row_array();
            self::assertSame(
                ['start' => '08:00', 'end' => '18:00', 'breaks' => []],
                json_decode($settings['working_plan'], true)['monday'],
            );
            self::assertSame(0, (int) $settings['notifications']);
            self::assertSame(0, (int) $settings['caldav_sync']);
            self::assertSame(600, $state['expires_at'] - $state['created_at']);
        } finally {
            self::assertSame('clean', $fixture->run('deactivate'));
        }
        self::assertSame('clean', $fixture->run('verify'));
        self::assertFileDoesNotExist(Zero_surprise_canary_fixture::DEFAULT_STATE_FILE);
    }

    public function testFailedCommitLeavesRecoverableJournalAndNoRows(): void
    {
        $ci = &get_instance();
        $database = $ci->db;
        $ci->db = new class ($database) {
            public function __construct(private object $database) {}
            public function trans_commit(): bool
            {
                return false;
            }
            public function __call(string $name, array $args): mixed
            {
                return $this->database->$name(...$args);
            }
        };
        $fixture = new Zero_surprise_canary_fixture();
        $failure = null;
        try {
            $fixture->run('activate');
        } catch (RuntimeException $e) {
            $failure = $e;
        } finally {
            $ci->db = $database;
        }
        try {
            self::assertNotNull($failure);
            self::assertStringContainsString('commit', $failure->getMessage());
            self::assertSame('cleanup_pending', $fixture->run('verify'));
        } finally {
            self::assertSame('clean', $fixture->run('deactivate'));
        }
        self::assertSame('clean', $fixture->run('verify'));
        self::assertFileDoesNotExist(Zero_surprise_canary_fixture::DEFAULT_STATE_FILE);
    }

    public function testCleanupRemovesOnlyConsentsForMarkedCanaryCustomers(): void
    {
        $fixture = new Zero_surprise_canary_fixture();
        $db = get_instance()->db;
        $foreignEmail = 'zero-surprise-unrelated-' . bin2hex(random_bytes(8)) . '@synthetic.invalid';
        $ownedEmail = null;

        try {
            self::assertSame('active', $fixture->run('activate'));
            $state = json_decode(
                file_get_contents(Zero_surprise_canary_fixture::DEFAULT_STATE_FILE),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
            $ownedRunCustomerMarker = 'booking-customer-' . $state['run_id'] . '-01';
            $normalizedMarker = preg_replace('/[^a-z0-9]+/', '-', strtolower($ownedRunCustomerMarker)) ?: 'ci-write';
            $ownedEmail = 'ci-' . substr(hash('sha256', $normalizedMarker), 0, 20) . '@synthetic.invalid';
            $ownedLastName = strtoupper(substr($ownedRunCustomerMarker, -6));
            $customerRole = $db->get_where('roles', ['slug' => 'customer'])->row_array();
            self::assertIsArray($customerRole);
            self::assertTrue(
                $db->insert('users', [
                    'first_name' => 'Synthetic',
                    'last_name' => $ownedLastName,
                    'email' => $ownedEmail,
                    'phone_number' => '000000000',
                    'notes' => 'run:' . $state['run_id'],
                    'timezone' => 'UTC',
                    'language' => 'english',
                    'is_private' => 1,
                    'id_roles' => (int) $customerRole['id'],
                ]),
            );
            self::assertTrue(
                $db->insert('consents', [
                    'first_name' => 'CI',
                    'last_name' => $ownedLastName,
                    'email' => $ownedEmail,
                    'ip' => '127.0.0.1',
                    'type' => 'privacy-policy',
                ]),
            );
            self::assertTrue(
                $db->insert('consents', [
                    'first_name' => 'Unrelated',
                    'last_name' => 'Synthetic Consent',
                    'email' => $foreignEmail,
                    'ip' => '127.0.0.1',
                    'type' => 'privacy-policy',
                ]),
            );
            self::assertTrue(
                $db->insert('consents', [
                    'first_name' => 'Foreign',
                    'last_name' => 'Same Email',
                    'email' => $ownedEmail,
                    'ip' => '127.0.0.1',
                    'type' => 'privacy-policy',
                ]),
            );
            self::assertTrue($db->delete('users', ['email' => $ownedEmail, 'notes' => 'run:' . $state['run_id']]));

            try {
                $fixture->run('deactivate');
                self::fail('Same-email foreign consent must block ambiguous cleanup.');
            } catch (RuntimeException $error) {
                self::assertStringContainsString('ambiguous', $error->getMessage());
            }
            self::assertFileExists(Zero_surprise_canary_fixture::DEFAULT_STATE_FILE);
            self::assertSame(2, $db->get_where('consents', ['email' => $ownedEmail])->num_rows());
            self::assertSame('active', $fixture->run('verify'));
            $db->delete('consents', ['email' => $ownedEmail, 'first_name' => 'Foreign']);
            self::assertSame('clean', $fixture->run('deactivate'));
            self::assertSame(0, $db->get_where('consents', ['email' => $ownedEmail])->num_rows());
            self::assertSame(1, $db->get_where('consents', ['email' => $foreignEmail])->num_rows());
        } finally {
            if (is_file(Zero_surprise_canary_fixture::DEFAULT_STATE_FILE)) {
                $fixture->run('deactivate');
            }
            $db->delete('consents', ['email' => $foreignEmail]);
        }
        self::assertSame('clean', $fixture->run('verify'));
    }

    public function testCleanupPreservesAConsentBelowTheCanaryFloorEvenWithTheSameEmail(): void
    {
        $fixture = new Zero_surprise_canary_fixture();
        $db = get_instance()->db;
        $baselineId = null;
        $newId = null;
        try {
            self::assertTrue(
                $db->insert('consents', [
                    'first_name' => 'Earlier',
                    'last_name' => 'Synthetic',
                    'email' => 'earlier-' . bin2hex(random_bytes(8)) . '@synthetic.invalid',
                    'ip' => '127.0.0.1',
                    'type' => 'privacy-policy',
                ]),
            );
            $baselineId = (int) $db->insert_id();
            self::assertSame('active', $fixture->run('activate'));
            $state = json_decode(
                file_get_contents(Zero_surprise_canary_fixture::DEFAULT_STATE_FILE),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
            self::assertSame($baselineId, $state['consent_floor']);
            $marker = 'booking-customer-' . $state['run_id'] . '-01';
            $normalized = preg_replace('/[^a-z0-9]+/', '-', strtolower($marker)) ?: 'ci-write';
            $email = 'ci-' . substr(hash('sha256', $normalized), 0, 20) . '@synthetic.invalid';
            $lastName = strtoupper(substr($marker, -6));
            self::assertTrue(
                $db->update(
                    'consents',
                    [
                        'first_name' => 'CI',
                        'last_name' => $lastName,
                        'email' => $email,
                    ],
                    ['id' => $baselineId],
                ),
            );
            self::assertTrue(
                $db->insert('consents', [
                    'first_name' => 'CI',
                    'last_name' => $lastName,
                    'email' => $email,
                    'ip' => '127.0.0.1',
                    'type' => 'privacy-policy',
                ]),
            );
            $newId = (int) $db->insert_id();

            self::assertSame('clean', $fixture->run('deactivate'));
            self::assertSame(1, $db->get_where('consents', ['id' => $baselineId])->num_rows());
            self::assertSame(0, $db->get_where('consents', ['id' => $newId])->num_rows());
        } finally {
            if (is_file(Zero_surprise_canary_fixture::DEFAULT_STATE_FILE)) {
                $fixture->run('deactivate');
            }
            if ($baselineId !== null) {
                $db->delete('consents', ['id' => $baselineId]);
            }
            if ($newId !== null) {
                $db->delete('consents', ['id' => $newId]);
            }
        }
        self::assertSame('clean', $fixture->run('verify'));
    }
}
