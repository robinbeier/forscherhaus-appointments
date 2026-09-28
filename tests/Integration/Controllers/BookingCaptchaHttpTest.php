<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Tests\Integration\Support\DefenseCycleFixtures;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__) . '/Support/DefenseCycleFixtures.php';
require_once dirname(__DIR__) . '/Support/DefenseCycleHttpServer.php';

/** Real HTTP regression coverage for the public booking CAPTCHA prerequisite. */
final class BookingCaptchaHttpTest extends TestCase
{
    private ?DefenseCycleFixtures $fixture = null;
    private ?DefenseCycleHttpServer $server = null;
    private ?array $privacySetting = null;
    private ?array $termsSetting = null;
    /** @var list<int> */
    private array $baselineConsentIds = [];
    private ?string $ownedConsentEmail = null;

    protected function setUp(): void
    {
        if (getenv('FH_DEFENSE_ISOLATED') !== '1') {
            self::markTestSkipped('Run with the fresh isolated synthetic stack.');
        }

        try {
            $this->fixture = new DefenseCycleFixtures();
            $this->fixture->create();
            $db = get_instance()->db;
            $this->privacySetting =
                $db->get_where('settings', ['name' => 'display_privacy_policy'])->row_array() ?: null;
            $this->termsSetting =
                $db->get_where('settings', ['name' => 'display_terms_and_conditions'])->row_array() ?: null;
            $db->update('settings', ['value' => '1'], ['name' => 'display_privacy_policy']);
            $db->update('settings', ['value' => '0'], ['name' => 'display_terms_and_conditions']);
            $this->server = new DefenseCycleHttpServer();
        } catch (Throwable $error) {
            try {
                $this->server?->close();
            } finally {
                try {
                    $this->restoreConsentSettings();
                } finally {
                    $this->fixture?->cleanup();
                }
            }
            throw $error;
        }
    }

    protected function tearDown(): void
    {
        try {
            $this->server?->close();
        } finally {
            try {
                $this->cleanupOwnedConsents();
            } finally {
                $this->restoreConsentSettings();
                $this->fixture?->cleanup();
            }
        }
    }

    public function testMatchingGeneratedCaptchaBooksAndPersistsPrivacyConsent(): void
    {
        $fixture = $this->fixture;
        $client = $this->server?->client();
        self::assertNotNull($fixture);
        self::assertNotNull($client);

        self::assertTrue(get_instance()->db->update('settings', ['value' => '1'], ['name' => 'require_captcha']));
        $ownedConsentEmail = (string) $fixture->row('users', $fixture->customerId)['email'];
        $beforeConsentIds = array_map(
            static fn(array $row): int => (int) $row['id'],
            get_instance()
                ->db->get_where('consents', ['email' => $ownedConsentEmail])
                ->result_array(),
        );
        $this->baselineConsentIds = $beforeConsentIds;
        $this->ownedConsentEmail = $ownedConsentEmail;
        self::assertSame(200, $client->get('booking')->statusCode);
        self::assertSame(200, $client->get('captcha')->statusCode);
        $captcha = $this->readGeneratedCaptcha($client);
        self::assertNotSame('', $captcha);

        $beforeAppointments = get_instance()->db->count_all('appointments');
        $beforeUsers = get_instance()->db->count_all('users');
        $beforeConsents = get_instance()->db->count_all('consents');
        $payload = $this->payload($fixture);
        $customer = $fixture->row('users', $fixture->customerId);
        $payload['customer'] = array_merge($payload['customer'], [
            'first_name' => $customer['first_name'],
            'last_name' => $customer['last_name'],
            'email' => $customer['email'],
            'phone_number' => $customer['phone_number'],
            'address' => $customer['address'],
            'city' => $customer['city'],
            'zip_code' => $customer['zip_code'],
            'timezone' => $customer['timezone'],
            'language' => $customer['language'],
            'notes' => $customer['notes'],
        ]);

        $response = $client->post('booking/register', [
            'post_data' => $payload,
            'captcha' => $captcha,
        ]);

        self::assertSame(200, $response->statusCode, $response->body);
        $result = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertGreaterThan(0, (int) ($result['appointment_id'] ?? 0));
        self::assertNotEmpty($result['appointment_hash'] ?? null);
        self::assertSame($beforeAppointments + 1, get_instance()->db->count_all('appointments'));
        self::assertSame($beforeUsers, get_instance()->db->count_all('users'));
        self::assertSame($beforeConsents + 1, get_instance()->db->count_all('consents'));

        $booked = get_instance()
            ->db->get_where('appointments', ['id' => (int) $result['appointment_id']])
            ->row_array();
        self::assertSame($fixture->customerId, (int) $booked['id_users_customer']);
        self::assertSame($fixture->serviceId, (int) $booked['id_services']);
        self::assertSame($payload['appointment']['start_datetime'], $booked['start_datetime']);
        $consentRows = get_instance()
            ->db->get_where('consents', [
                'email' => $this->ownedConsentEmail,
                'type' => 'privacy-policy',
            ])
            ->result_array();
        $newConsentRows = array_values(
            array_filter(
                $consentRows,
                static fn(array $row): bool => !in_array((int) $row['id'], $beforeConsentIds, true),
            ),
        );
        self::assertCount(1, $newConsentRows);
        self::assertSame('127.0.0.1', $newConsentRows[0]['ip']);
    }

    public function testGeneratedCaptchaCannotBeReplayedForASecondBooking(): void
    {
        $fixture = $this->fixture;
        $client = $this->server?->client();
        self::assertNotNull($fixture);
        self::assertNotNull($client);

        self::assertTrue(get_instance()->db->update('settings', ['value' => '1'], ['name' => 'require_captcha']));
        $ownedConsentEmail = (string) $fixture->row('users', $fixture->customerId)['email'];
        $this->baselineConsentIds = array_map(
            static fn(array $row): int => (int) $row['id'],
            get_instance()
                ->db->get_where('consents', ['email' => $ownedConsentEmail])
                ->result_array(),
        );
        $this->ownedConsentEmail = $ownedConsentEmail;
        self::assertSame(200, $client->get('booking')->statusCode);
        self::assertSame(200, $client->get('captcha')->statusCode);
        $captcha = $this->readGeneratedCaptcha($client);
        self::assertNotSame('', $captcha);

        $firstPayload = $this->payloadForFixtureCustomer($fixture);
        $beforeAppointments = get_instance()->db->count_all('appointments');
        $beforeUsers = get_instance()->db->count_all('users');
        $beforeConsents = get_instance()->db->count_all('consents');
        $first = $client->post('booking/register', [
            'post_data' => $firstPayload,
            'captcha' => $captcha,
        ]);
        self::assertSame(200, $first->statusCode, $first->body);
        $firstResult = json_decode($first->body, true, 512, JSON_THROW_ON_ERROR);
        $firstAppointmentId = (int) ($firstResult['appointment_id'] ?? 0);
        self::assertGreaterThan(0, $firstAppointmentId);
        self::assertSame($beforeAppointments + 1, get_instance()->db->count_all('appointments'));
        self::assertSame($beforeUsers, get_instance()->db->count_all('users'));
        self::assertSame($beforeConsents + 1, get_instance()->db->count_all('consents'));
        $firstAppointment = get_instance()
            ->db->get_where('appointments', ['id' => $firstAppointmentId])
            ->row_array();
        $firstCustomer = $fixture->row('users', $fixture->customerId);

        $secondPayload = $firstPayload;
        $secondStart = (new DateTimeImmutable($firstPayload['appointment']['start_datetime']))->modify('+1 hour');
        $secondPayload['appointment']['start_datetime'] = $secondStart->format('Y-m-d H:i:s');
        $secondPayload['appointment']['end_datetime'] = $secondStart->modify('+30 minutes')->format('Y-m-d H:i:s');
        $second = $client->post('booking/register', [
            'post_data' => $secondPayload,
            'captcha' => $captcha,
        ]);

        self::assertSame(200, $second->statusCode, $second->body);
        self::assertSame(['captcha_verification' => false], json_decode($second->body, true));
        self::assertSame($beforeAppointments + 1, get_instance()->db->count_all('appointments'));
        self::assertSame($beforeUsers, get_instance()->db->count_all('users'));
        self::assertSame($beforeConsents + 1, get_instance()->db->count_all('consents'));
        self::assertSame(
            $firstAppointment,
            get_instance()
                ->db->get_where('appointments', ['id' => $firstAppointmentId])
                ->row_array(),
        );
        self::assertSame($firstCustomer, $fixture->row('users', $fixture->customerId));

        self::assertSame(200, $client->get('captcha')->statusCode);
        $replacementCaptcha = $this->readGeneratedCaptcha($client);
        self::assertNotSame('', $replacementCaptcha);
        $thirdPayload = $secondPayload;
        $third = $client->post('booking/register', [
            'post_data' => $thirdPayload,
            'captcha' => $replacementCaptcha,
        ]);

        self::assertSame(200, $third->statusCode, $third->body);
        $thirdResult = json_decode($third->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertGreaterThan(0, (int) ($thirdResult['appointment_id'] ?? 0));
        self::assertSame($beforeAppointments + 2, get_instance()->db->count_all('appointments'));
        self::assertSame($beforeUsers, get_instance()->db->count_all('users'));
        self::assertSame($beforeConsents + 2, get_instance()->db->count_all('consents'));
        self::assertSame(
            $thirdPayload['appointment']['start_datetime'],
            get_instance()
                ->db->get_where('appointments', ['id' => (int) $thirdResult['appointment_id']])
                ->row_array()['start_datetime'],
        );
    }

    public function testMissingSessionChallengeAndCaptchaInputRejectBeforeMutation(): void
    {
        $fixture = $this->fixture;
        $client = $this->server?->client();
        self::assertNotNull($fixture);
        self::assertNotNull($client);

        self::assertTrue(get_instance()->db->update('settings', ['value' => '1'], ['name' => 'require_captcha']));
        $existingAppointment = $fixture->appointment();
        $before = $this->snapshot($existingAppointment, $fixture->customerId);
        self::assertSame(200, $client->get('booking')->statusCode);

        $response = $client->post('booking/register', ['post_data' => $this->payload($fixture)]);

        self::assertSame(200, $response->statusCode, $response->body);
        self::assertSame(['captcha_verification' => false], json_decode($response->body, true));
        self::assertSame($before, $this->snapshot($existingAppointment, $fixture->customerId));

        $blank = $client->post('booking/register', [
            'post_data' => $this->payload($fixture),
            'captcha' => '   ',
        ]);
        self::assertSame(200, $blank->statusCode, $blank->body);
        self::assertSame(['captcha_verification' => false], json_decode($blank->body, true));
        self::assertSame($before, $this->snapshot($existingAppointment, $fixture->customerId));
    }

    public function testWrongCaptchaAfterSessionChallengeRejectsBeforeMutation(): void
    {
        $fixture = $this->fixture;
        $client = $this->server?->client();
        self::assertNotNull($fixture);
        self::assertNotNull($client);

        self::assertTrue(get_instance()->db->update('settings', ['value' => '1'], ['name' => 'require_captcha']));
        $existingAppointment = $fixture->appointment();
        $before = $this->snapshot($existingAppointment, $fixture->customerId);
        $challenge = $client->get('captcha');
        self::assertSame(200, $challenge->statusCode, $challenge->body);

        $response = $client->post('booking/register', [
            'post_data' => $this->payload($fixture),
            'captcha' => 'WRONG-SYNTHETIC-CAPTCHA',
        ]);

        self::assertSame(200, $response->statusCode, $response->body);
        self::assertSame(['captcha_verification' => false], json_decode($response->body, true));
        self::assertSame($before, $this->snapshot($existingAppointment, $fixture->customerId));

        $blank = $client->post('booking/register', [
            'post_data' => $this->payload($fixture),
            'captcha' => '   ',
        ]);
        self::assertSame(200, $blank->statusCode, $blank->body);
        self::assertSame(['captcha_verification' => false], json_decode($blank->body, true));
        self::assertSame($before, $this->snapshot($existingAppointment, $fixture->customerId));
    }

    /** @return array<string, mixed> */
    private function payload(DefenseCycleFixtures $fixture): array
    {
        $start = (new DateTimeImmutable('today'))->modify('next monday')->modify('+14 days')->setTime(11, 0);
        return [
            'appointment' => [
                'start_datetime' => $start->format('Y-m-d H:i:s'),
                'end_datetime' => $start->modify('+30 minutes')->format('Y-m-d H:i:s'),
                'id_services' => $fixture->serviceId,
                'id_users_provider' => $fixture->providerId,
                'location' => '',
                'notes' => $fixture->run,
                'color' => '',
            ],
            'customer' => [
                'first_name' => 'Captcha',
                'last_name' => 'Synthetic',
                'email' => $fixture->run . '@synthetic.invalid',
                'phone_number' => '0000000000',
                'address' => '',
                'city' => '',
                'zip_code' => '',
                'timezone' => 'UTC',
                'language' => 'english',
                'notes' => $fixture->run,
            ],
            'manage_mode' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function payloadForFixtureCustomer(DefenseCycleFixtures $fixture): array
    {
        $payload = $this->payload($fixture);
        $customer = $fixture->row('users', $fixture->customerId);
        $payload['customer'] = array_merge($payload['customer'], [
            'first_name' => $customer['first_name'],
            'last_name' => $customer['last_name'],
            'email' => $customer['email'],
            'phone_number' => $customer['phone_number'],
            'address' => $customer['address'],
            'city' => $customer['city'],
            'zip_code' => $customer['zip_code'],
            'timezone' => $customer['timezone'],
            'language' => $customer['language'],
            'notes' => $customer['notes'],
        ]);

        return $payload;
    }

    private function readGeneratedCaptcha(\ReleaseGate\GateHttpClient $client): string
    {
        $sessionId = $client->getCookie('ea_session');
        self::assertNotEmpty($sessionId);
        $path = $this->server?->directory . '/sessions/ea_session' . $sessionId;
        self::assertIsString($path);
        self::assertFileExists($path);
        $serialized = file_get_contents($path);
        self::assertIsString($serialized);
        self::assertSame(1, preg_match('/(?:^|;)captcha_phrase\|s:\d+:"([^"]*)";/', $serialized, $match));

        return $match[1];
    }

    private function cleanupOwnedConsents(): void
    {
        if ($this->ownedConsentEmail === null) {
            return;
        }
        $db = get_instance()->db;
        $rows = $db->get_where('consents', ['email' => $this->ownedConsentEmail])->result_array();
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            if (in_array($id, $this->baselineConsentIds, true)) {
                continue;
            }
            if (!$db->delete('consents', ['id' => $id, 'email' => $this->ownedConsentEmail])) {
                throw new RuntimeException('Owned CAPTCHA consent cleanup failed.');
            }
            self::assertSame(
                [],
                $db
                    ->get_where('consents', [
                        'id' => $id,
                        'email' => $this->ownedConsentEmail,
                    ])
                    ->row_array() ?:
                [],
            );
        }
        $this->baselineConsentIds = [];
    }

    /** @return array<string, mixed> */
    private function snapshot(array $appointment, int $customerId): array
    {
        $db = get_instance()->db;
        return [
            'appointments' => $db->count_all('appointments'),
            'users' => $db->count_all('users'),
            'consents' => $db->count_all('consents'),
            'reschedule_authorities' => $db->count_all('reschedule_authorities'),
            'appointment' => $this->fixture?->row('appointments', (int) $appointment['id']) ?? [],
            'customer' => $this->fixture?->row('users', $customerId) ?? [],
        ];
    }

    private function restoreConsentSettings(): void
    {
        $db = get_instance()->db;
        foreach (
            [
                'display_privacy_policy' => $this->privacySetting,
                'display_terms_and_conditions' => $this->termsSetting,
            ]
            as $name => $row
        ) {
            if ($row !== null) {
                $db->update('settings', ['value' => $row['value']], ['name' => $name]);
            }
        }
    }
}
