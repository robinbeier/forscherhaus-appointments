<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ReleaseGate\GateHttpClient;
use Tests\Integration\Support\DefenseCycleFixtures;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__) . '/Support/DefenseCycleFixtures.php';
require_once dirname(__DIR__) . '/Support/DefenseCycleHttpServer.php';

/** Verify that deleting an appointment invalidates its already opened public reschedule path. */
final class CalendarDeleteRescheduleHttpTest extends TestCase
{
    private ?DefenseCycleFixtures $fixture = null;
    private ?DefenseCycleHttpServer $server = null;
    private ?int $appointmentId = null;
    /** @var array<string, string>|null */
    private ?array $cacheBeforeTest = null;
    private bool $resourcesCleaned = false;

    protected function setUp(): void
    {
        if (getenv('FH_DEFENSE_ISOLATED') !== '1') {
            self::markTestSkipped('Run with the fresh isolated synthetic stack.');
        }

        try {
            $this->fixture = new DefenseCycleFixtures();
            $this->fixture->create();
            $this->cacheBeforeTest = $this->ownedCacheFiles();
            $this->server = new DefenseCycleHttpServer();
        } catch (Throwable $error) {
            $this->closeAndClean();
            throw $error;
        }
    }

    protected function tearDown(): void
    {
        $this->closeAndClean();
    }

    public function testCalendarDeleteClosesPreviouslyAuthorizedPublicRescheduleSession(): void
    {
        $fixture = $this->fixture;
        $server = $this->server;
        self::assertNotNull($fixture);
        self::assertNotNull($server);

        $db = get_instance()->db;
        self::assertTrue(
            (bool) $db->update(
                'services',
                ['buffer_before' => 30, 'buffer_after' => 30],
                ['id' => $fixture->serviceId, 'description' => $fixture->run],
            ),
        );

        $appointment = $fixture->appointment();
        $this->appointmentId = (int) $appointment['id'];
        $beforeCustomer = $fixture->row('users', $fixture->customerId);
        $beforeProvider = $fixture->row('users', $fixture->providerId);
        $beforeService = $fixture->row('services', $fixture->serviceId);
        $consentIdentity = [
            'first_name' => (string) $beforeCustomer['first_name'],
            'last_name' => (string) $beforeCustomer['last_name'],
            'email' => (string) $beforeCustomer['email'],
        ];
        $beforeConsents = $db->get_where('consents', $consentIdentity)->result_array();
        $beforeBuffers = $db
            ->where('id_parent_appointment', $this->appointmentId)
            ->where('is_unavailability', 1)
            ->get('appointments')
            ->result_array();
        self::assertCount(2, $beforeBuffers, 'The synthetic service must create before- and after-buffer rows.');
        self::assertNotSame($beforeBuffers[0]['start_datetime'], $beforeBuffers[1]['start_datetime']);
        $beforeCounts = $this->mutationCounts();

        $public = $server->client();
        $reschedulePath = 'booking/reschedule/' . rawurlencode((string) $appointment['hash']);
        $page = $public->get($reschedulePath);
        self::assertSame(200, $page->statusCode, $page->body);
        $cacheAfterPage = $this->ownedCacheFiles();
        $newTokenCacheFiles = array_diff_key($cacheAfterPage, $this->cacheBeforeTest ?? []);
        self::assertCount(1, $newTokenCacheFiles, 'The authorized GET must create exactly one owned customer token.');
        $authorityBefore = $db
            ->get_where('reschedule_authorities', ['appointment_id' => $this->appointmentId])
            ->row_array();
        self::assertNotEmpty($authorityBefore, 'The public GET must issue a session-bound authority.');
        self::assertNull($authorityBefore['consumed_at'] ?? null);

        $calendar = $this->authenticatedClient($server, $fixture);
        $deleted = $calendar->post('calendar/delete_appointment', ['appointment_id' => $this->appointmentId]);
        self::assertSame(200, $deleted->statusCode, $deleted->body);
        self::assertTrue((bool) (json_decode($deleted->body, true, 512, JSON_THROW_ON_ERROR)['success'] ?? false));

        self::assertSame([], $fixture->row('appointments', $this->appointmentId));
        self::assertSame(
            [],
            $db->where('id_parent_appointment', $this->appointmentId)->get('appointments')->result_array(),
            'Calendar delete must remove the appointment-owned buffer rows.',
        );
        self::assertSame(
            0,
            $db
                ->where('id_users_provider', $fixture->providerId)
                ->where('id_services', $fixture->serviceId)
                ->count_all_results('appointments'),
            'The owned appointment and its buffers must stay absent after the old link and both POSTs.',
        );
        self::assertSame($beforeCustomer, $fixture->row('users', $fixture->customerId));
        self::assertSame($beforeProvider, $fixture->row('users', $fixture->providerId));
        self::assertSame($beforeService, $fixture->row('services', $fixture->serviceId));

        $payload = $this->reschedulePayload(
            $appointment,
            $beforeCustomer,
            (new DateTimeImmutable('today'))->modify('+21 days')->setTime(10, 0),
        );
        $attempt = $public->post('booking/register', ['post_data' => $payload]);
        self::assertSame(403, $attempt->statusCode, $attempt->body);
        self::assertSame(
            ['success' => false, 'message' => lang('appointment_not_found')],
            json_decode($attempt->body, true, 512, JSON_THROW_ON_ERROR),
        );
        $this->assertDeletedState($beforeCustomer, $beforeProvider, $beforeService, $beforeConsents, $consentIdentity);
        self::assertSame(
            [],
            $db->get_where('reschedule_authorities', ['appointment_id' => $this->appointmentId])->result_array(),
        );

        $replay = $public->post('booking/register', ['post_data' => $payload]);
        self::assertSame(403, $replay->statusCode, $replay->body);
        self::assertSame(
            ['success' => false, 'message' => lang('appointment_not_found')],
            json_decode($replay->body, true, 512, JSON_THROW_ON_ERROR),
        );
        $this->assertDeletedState($beforeCustomer, $beforeProvider, $beforeService, $beforeConsents, $consentIdentity);
        self::assertSame(
            [],
            $db->get_where('reschedule_authorities', ['appointment_id' => $this->appointmentId])->result_array(),
        );

        $oldLink = $public->get($reschedulePath);
        self::assertSame(200, $oldLink->statusCode, $oldLink->body);
        self::assertStringContainsString(lang('appointment_not_found'), $oldLink->body);
        self::assertStringContainsString(lang('appointment_does_not_exist_in_db'), $oldLink->body);
        self::assertSame($cacheAfterPage, $this->ownedCacheFiles(), 'The deleted link must not issue another token.');

        self::assertSame($beforeCounts['users'], $db->count_all('users'));
        self::assertSame($beforeCounts['services'], $db->count_all('services'));
        self::assertSame($beforeCounts['consents'], $db->count_all('consents'));
        self::assertSame([], $db->get_where('appointments', ['id' => $this->appointmentId])->result_array());
        self::assertSame(
            [],
            $db->get_where('reschedule_authorities', ['appointment_id' => $this->appointmentId])->result_array(),
        );
    }

    private function assertDeletedState(
        array $beforeCustomer,
        array $beforeProvider,
        array $beforeService,
        array $beforeConsents,
        array $consentIdentity,
    ): void {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $db = get_instance()->db;
        self::assertSame($beforeCustomer, $fixture->row('users', $fixture->customerId));
        self::assertSame($beforeProvider, $fixture->row('users', $fixture->providerId));
        self::assertSame($beforeService, $fixture->row('services', $fixture->serviceId));
        self::assertSame($beforeConsents, $db->get_where('consents', $consentIdentity)->result_array());
        self::assertSame(
            [],
            $db
                ->where('id_users_provider', $fixture->providerId)
                ->where('id_services', $fixture->serviceId)
                ->get('appointments')
                ->result_array(),
            'The full owned appointment/buffer snapshot must remain unchanged after denial.',
        );
        self::assertSame([], $db->get_where('appointments', ['id' => $this->appointmentId])->result_array());
    }

    private function authenticatedClient(DefenseCycleHttpServer $server, DefenseCycleFixtures $fixture): GateHttpClient
    {
        $client = $server->client();
        self::assertSame(200, $client->get('login')->statusCode);
        $login = $client->post('login/validate', [
            'username' => $fixture->run . '_actor',
            'password' => $fixture->password,
        ]);
        self::assertSame(200, $login->statusCode, $login->body);
        self::assertTrue((bool) (json_decode($login->body, true, 512, JSON_THROW_ON_ERROR)['success'] ?? false));
        return $client;
    }

    private function reschedulePayload(array $appointment, array $customer, DateTimeImmutable $start): array
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        return [
            'appointment' => [
                'id' => (int) $appointment['id'],
                'start_datetime' => $start->format('Y-m-d H:i:s'),
                'end_datetime' => $start->modify('+30 minutes')->format('Y-m-d H:i:s'),
                'id_services' => (int) $appointment['id_services'],
                'id_users_provider' => (int) $appointment['id_users_provider'],
                'location' => '',
                'notes' => $fixture->run . '_deleted-reschedule',
                'color' => '',
            ],
            'customer' => [
                'id' => (int) $customer['id'],
                'first_name' => $customer['first_name'],
                'last_name' => $customer['last_name'],
                'email' => $customer['email'],
                'phone_number' => $customer['phone_number'] ?: '+49123456789',
                'address' => $customer['address'] ?: 'Teststrasse 1',
                'city' => $customer['city'] ?: 'Berlin',
                'zip_code' => $customer['zip_code'] ?: '10115',
                'timezone' => $customer['timezone'] ?: 'UTC',
                'notes' => $customer['notes'] ?? '',
            ],
            'manage_mode' => true,
        ];
    }

    /** @return array{appointments:int,users:int,services:int,consents:int} */
    private function mutationCounts(): array
    {
        $db = get_instance()->db;
        return [
            'appointments' => $db->count_all('appointments'),
            'users' => $db->count_all('users'),
            'services' => $db->count_all('services'),
            'consents' => $db->count_all('consents'),
        ];
    }

    /** @return array<string, string> */
    private function ownedCacheFiles(): array
    {
        $fixture = $this->fixture;
        $cachePath = rtrim((string) config('cache_path'), DIRECTORY_SEPARATOR);
        if (!is_dir($cachePath) || $fixture === null) {
            return [];
        }

        $files = [];
        foreach (glob($cachePath . DIRECTORY_SEPARATOR . 'customer-token-*') ?: [] as $path) {
            if (!is_file($path) || is_link($path) || !preg_match('/^customer-token-[a-f0-9]{64}$/', basename($path))) {
                continue;
            }
            $raw = file_get_contents($path);
            $entry = $raw === false ? false : @unserialize($raw, ['allowed_classes' => false]);
            if (
                !is_array($entry) ||
                ($entry['data'] ?? null) !== $fixture->customerId ||
                ($entry['ttl'] ?? null) !== 600
            ) {
                continue;
            }
            $files[$path] = hash_file('sha256', $path) ?: '';
        }
        ksort($files);
        return $files;
    }

    private function cleanupOwnedCacheFiles(): void
    {
        if ($this->cacheBeforeTest === null) {
            return;
        }
        $after = $this->ownedCacheFiles();
        foreach (array_diff_key($after, $this->cacheBeforeTest) as $path => $hash) {
            self::assertSame($hash, $this->ownedCacheFiles()[$path] ?? null);
            self::assertTrue(unlink($path), 'Could not remove test-created customer token cache file: ' . $path);
            self::assertFalse(is_file($path), 'Test-created customer token cache file remains: ' . $path);
        }
        foreach ($this->cacheBeforeTest as $path => $hash) {
            self::assertFileExists($path);
            self::assertSame($hash, hash_file('sha256', $path));
        }
    }

    private function closeAndClean(): void
    {
        if ($this->resourcesCleaned) {
            return;
        }

        try {
            $this->server?->close();
        } finally {
            try {
                $this->cleanupOwnedCacheFiles();
            } finally {
                try {
                    if ($this->fixture !== null && isset($this->fixture->providerId)) {
                        $db = get_instance()->db;
                        if ($this->appointmentId !== null) {
                            $db->delete('reschedule_authorities', ['appointment_id' => $this->appointmentId]);
                        }
                    }
                } finally {
                    $this->fixture?->cleanup();
                    $this->resourcesCleaned = true;
                }
            }
        }
    }
}
