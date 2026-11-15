<?php

namespace Tests\Integration;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Reschedule_authority;
use Tests\Integration\Support\BookingFlowFixtures;
use Tests\TestCase;

require_once APPPATH . 'libraries/Reschedule_authority.php';

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class RescheduleCustomerOverlapDatabaseTest extends TestCase
{
    private BookingFlowFixtures $fixtures;
    private ?int $secondProviderId = null;
    private ?int $serviceId = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtures = new BookingFlowFixtures();
    }

    protected function tearDown(): void
    {
        try {
            $this->fixtures->cleanup();
        } finally {
            if ($this->secondProviderId !== null) {
                get_instance()->db->delete('services_providers', [
                    'id_users' => $this->secondProviderId,
                    'id_services' => $this->serviceId,
                ]);
                get_instance()->db->delete('users', ['id' => $this->secondProviderId]);
            }
            parent::tearDown();
        }
    }

    #[DataProvider('customerOverlapIntervals')]
    public function testCustomerOverlapUsesRealDatabaseForCrossProviderReschedule(
        string $existingStart,
        string $existingEnd,
        string $requestedStart,
        string $requestedEnd,
        bool $expected,
    ): void {
        $pair = $this->resolveCrossProviderServicePair();
        $customerId = $this->fixtures->createCustomer();

        $existingAppointmentId = $this->fixtures->createAppointment(
            $pair['existing_provider_id'],
            $customerId,
            $pair['service_id'],
            new DateTimeImmutable($existingStart),
            new DateTimeImmutable($existingEnd),
            'Real database overlap fixture',
        );

        $authority = new Reschedule_authority();

        // The target provider is deliberately different from the existing
        // appointment's provider, matching the public reschedule attack path.
        $this->assertNotSame($pair['target_provider_id'], $pair['existing_provider_id']);
        $this->assertSame($expected, $authority->customerHasOverlap($customerId, $requestedStart, $requestedEnd, null));

        // The fixture row must be real and persisted for the SQL assertion to
        // be meaningful; cleanup removes it after each data-provider case.
        $this->assertNotNull($this->fixtures->findAppointmentById($existingAppointmentId));
    }

    /**
     * @return array<string, array{string, string, string, string, bool}>
     */
    public static function customerOverlapIntervals(): array
    {
        return [
            'existing interval overlaps on the left' => [
                '2035-01-01 09:00:00',
                '2035-01-01 10:00:00',
                '2035-01-01 09:30:00',
                '2035-01-01 10:30:00',
                true,
            ],
            'existing interval overlaps on the right' => [
                '2035-01-01 10:00:00',
                '2035-01-01 11:00:00',
                '2035-01-01 09:30:00',
                '2035-01-01 10:30:00',
                true,
            ],
            'requested interval contains existing interval' => [
                '2035-01-01 10:00:00',
                '2035-01-01 11:00:00',
                '2035-01-01 09:00:00',
                '2035-01-01 12:00:00',
                true,
            ],
            'existing interval contains requested interval' => [
                '2035-01-01 09:00:00',
                '2035-01-01 12:00:00',
                '2035-01-01 10:00:00',
                '2035-01-01 11:00:00',
                true,
            ],
            'back to back interval before request is allowed' => [
                '2035-01-01 08:00:00',
                '2035-01-01 09:00:00',
                '2035-01-01 09:00:00',
                '2035-01-01 10:00:00',
                false,
            ],
            'back to back interval after request is allowed' => [
                '2035-01-01 10:00:00',
                '2035-01-01 11:00:00',
                '2035-01-01 09:00:00',
                '2035-01-01 10:00:00',
                false,
            ],
        ];
    }

    /**
     * @return array{target_provider_id:int, existing_provider_id:int, service_id:int}
     */
    private function resolveCrossProviderServicePair(): array
    {
        $pair = $this->fixtures->resolveProviderServicePair();
        $db = get_instance()->db;
        $provider = $db->get_where('users', ['id' => $pair['provider_id']])->row_array();
        if (empty($provider)) {
            throw new RuntimeException('Seed provider is missing.');
        }
        unset($provider['id']);
        $provider['email'] = 'overlap-' . bin2hex(random_bytes(8)) . '@synthetic.invalid';
        $provider['last_name'] = 'Overlap fixture';
        $db->insert('users', $provider);
        $secondProviderId = (int) $db->insert_id();
        $serviceId = $pair['service_id'];
        $this->secondProviderId = $secondProviderId;
        $this->serviceId = $serviceId;
        $db->insert('services_providers', [
            'id_users' => $secondProviderId,
            'id_services' => $serviceId,
        ]);

        return [
            'target_provider_id' => $pair['provider_id'],
            'existing_provider_id' => $secondProviderId,
            'service_id' => $serviceId,
        ];
    }
}
