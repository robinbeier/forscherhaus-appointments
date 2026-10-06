<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReleaseGate\GateHttpClient;
use Tests\Integration\Support\DefenseCycleFixtures;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__) . '/Support/DefenseCycleFixtures.php';
require_once dirname(__DIR__) . '/Support/DefenseCycleHttpServer.php';

/** Real HTTP regression for ambiguous appointment-hash capability lookup. */
final class BookingCalendarAmbiguousHashHttpTest extends TestCase
{
    private ?DefenseCycleFixtures $fixture = null;
    private ?DefenseCycleHttpServer $server = null;
    private array $credentials = [];
    /** @var list<int> */
    private array $ownedAppointmentIds = [];
    /** @var list<int> */
    private array $ownedCustomerIds = [];

    protected function setUp(): void
    {
        if (getenv('FH_DEFENSE_ISOLATED') !== '1') {
            self::markTestSkipped('Run with the fresh isolated synthetic stack.');
        }

        try {
            $this->fixture = new DefenseCycleFixtures();
            $this->fixture->create();
            $this->credentials = $this->fixture->enableProviderHttpAuth();
            $this->server = new DefenseCycleHttpServer();
        } catch (Throwable $error) {
            $this->server?->close();
            $this->fixture?->cleanup();
            throw $error;
        }
    }

    protected function tearDown(): void
    {
        try {
            $this->server?->close();
        } finally {
            $db = get_instance()->db;
            foreach ($this->ownedAppointmentIds as $id) {
                $db->delete('reschedule_authorities', ['appointment_id' => $id]);
                $db->delete('appointments', ['id' => $id]);
            }
            foreach ($this->ownedCustomerIds as $id) {
                $db->delete('user_settings', ['id_users' => $id]);
                $db->delete('users', ['id' => $id]);
            }
            $this->fixture?->cleanup();
        }
    }

    public function testPublicRescheduleRejectsDuplicateHash(): void
    {
        [$unique, $duplicate, $duplicateHash, $secondCustomer] = $this->seedAmbiguousAppointments();
        $public = $this->server->client();
        $uniquePage = $public->get('booking/reschedule/' . rawurlencode((string) $unique['hash']));
        self::assertSame(200, $uniquePage->statusCode, $uniquePage->body);
        self::assertStringContainsString($this->customerEmail($this->fixture->customerId), $uniquePage->body);
        $publicDuplicate = $public->get('booking/reschedule/' . rawurlencode($duplicateHash));
        $this->assertAmbiguousResponse(
            $publicDuplicate,
            $duplicateHash,
            [$this->customerEmail($this->fixture->customerId), $secondCustomer['email']],
            'public booking reschedule',
        );
    }

    public function testPublicDuplicateRescheduleDoesNotIssueAuthority(): void
    {
        [$unique, $duplicate, $duplicateHash, $secondCustomer] = $this->seedAmbiguousAppointments();
        $public = $this->server->client();
        $before = $this->snapshot(
            [(int) $unique['id'], (int) $duplicate['id']],
            [$this->fixture->customerId, (int) $secondCustomer['id']],
        );
        $publicDuplicate = $public->get('booking/reschedule/' . rawurlencode($duplicateHash));
        self::assertSame(
            $before,
            $this->snapshot(
                [(int) $unique['id'], (int) $duplicate['id']],
                [$this->fixture->customerId, (int) $secondCustomer['id']],
            ),
        );
        self::assertSame(
            [],
            get_instance()
                ->db->get_where('reschedule_authorities', ['appointment_id' => (int) $duplicate['id']])
                ->result_array(),
        );
        $this->assertAmbiguousResponse(
            $publicDuplicate,
            $duplicateHash,
            [$this->customerEmail($this->fixture->customerId), $secondCustomer['email']],
            'public duplicate reschedule',
        );
    }

    #[DataProvider('calendarDuplicatePaths')]
    public function testAuthenticatedCalendarRejectsDuplicateHashWithoutMutation(string $path): void
    {
        [$unique, $duplicate, $duplicateHash, $secondCustomer] = $this->seedAmbiguousAppointments();
        $calendar = $this->loginProvider();
        $uniqueResponse = $calendar->get($path . '/' . rawurlencode((string) $unique['hash']));
        self::assertSame(200, $uniqueResponse->statusCode, $uniqueResponse->body);
        $before = $this->snapshot(
            [(int) $unique['id'], (int) $duplicate['id']],
            [$this->fixture->customerId, (int) $secondCustomer['id']],
        );
        $response = $calendar->get($path . '/' . rawurlencode($duplicateHash));
        self::assertSame(
            $before,
            $this->snapshot(
                [(int) $unique['id'], (int) $duplicate['id']],
                [$this->fixture->customerId, (int) $secondCustomer['id']],
            ),
        );
        $this->assertAmbiguousResponse($response, $duplicateHash, [$secondCustomer['email']], 'authenticated ' . $path);
    }

    public static function calendarDuplicatePaths(): array
    {
        return [['calendar/index'], ['calendar/reschedule']];
    }

    /** @return array{0:array,1:array,2:string,3:array} */
    private function seedAmbiguousAppointments(): array
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $unique = $fixture->appointment();
        $canonical = $fixture->appointment();
        $duplicate = $fixture->appointment();
        $secondCustomerPayload = $fixture->customerWritePayload('ambiguous');
        $customerRole = get_instance()
            ->db->get_where('roles', ['slug' => DB_SLUG_CUSTOMER])
            ->row_array();
        self::assertNotEmpty($customerRole);
        self::assertTrue(
            (bool) get_instance()->db->insert(
                'users',
                $secondCustomerPayload + [
                    'timezone' => 'UTC',
                    'language' => 'english',
                    'id_roles' => (int) $customerRole['id'],
                    'is_private' => 0,
                ],
            ),
        );
        $secondCustomerId = (int) get_instance()->db->insert_id();
        $this->ownedCustomerIds[] = $secondCustomerId;
        self::assertTrue(
            (bool) get_instance()->db->update(
                'appointments',
                ['id_users_customer' => $secondCustomerId],
                ['id' => (int) $duplicate['id']],
            ),
        );
        $duplicateHash = (string) $canonical['hash'];
        self::assertNotSame((int) $unique['id'], (int) $duplicate['id']);
        self::assertTrue(
            (bool) get_instance()->db->update(
                'appointments',
                ['hash' => $duplicateHash],
                ['id' => (int) $duplicate['id']],
            ),
        );
        $this->ownedAppointmentIds = [(int) $canonical['id'], (int) $duplicate['id']];
        $duplicate = $fixture->row('appointments', (int) $duplicate['id']);
        self::assertSame($duplicateHash, $duplicate['hash']);
        return [$unique, $duplicate, $duplicateHash, $fixture->row('users', $secondCustomerId)];
    }

    private function loginProvider(): GateHttpClient
    {
        $client = $this->server->client();
        self::assertSame(200, $client->get('login')->statusCode);
        $response = $client->post('login/validate', [
            'username' => $this->credentials['provider_username'],
            'password' => $this->credentials['password'],
        ]);
        self::assertSame(200, $response->statusCode, $response->body);
        return $client;
    }

    private function customerEmail(int $id): string
    {
        return (string) (get_instance()
            ->db->get_where('users', ['id' => $id])
            ->row_array()['email'] ?? '');
    }

    private function assertAmbiguousResponse(object $response, string $hash, array $emails, string $label): void
    {
        self::assertContains($response->statusCode, [200, 403, 404], $label . ' must fail closed.');
        self::assertStringNotContainsString($hash, $response->body, $label . ' leaked the ambiguous hash.');
        foreach ($emails as $email) {
            self::assertStringNotContainsString($email, $response->body, $label . ' leaked customer data.');
        }
        if ($response->statusCode === 200) {
            self::assertTrue(
                str_contains($response->body, '<h4 class="mb-5">Appointment Not Found</h4>') ||
                    str_contains($response->body, '<h4 class="mb-5">Termin nicht gefunden.</h4>'),
                $label . ' did not show the generic localized not-found page.',
            );
        }
    }

    /** @return array<string, mixed> */
    private function snapshot(array $appointmentIds, array $customerIds): array
    {
        $db = get_instance()->db;
        return [
            'appointments' => $db->count_all('appointments'),
            'users' => $db->count_all('users'),
            'authorities' => $db->count_all('reschedule_authorities'),
            'appointments_rows' => array_map(
                fn(int $id): array => $this->fixture->row('appointments', $id),
                $appointmentIds,
            ),
            'customer_rows' => array_map(fn(int $id): array => $this->fixture->row('users', $id), $customerIds),
        ];
    }
}
