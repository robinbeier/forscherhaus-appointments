<?php

namespace Tests\Unit\Models;

use Customers_model;
use DateTimeImmutable;
use Tests\Integration\Support\BookingFlowFixtures;
use Tests\TestCase;

class CustomersModelProviderFilterTest extends TestCase
{
    private BookingFlowFixtures $fixtures;
    private Customers_model $customersModel;

    protected function setUp(): void
    {
        parent::setUp();

        $CI = &get_instance();
        $CI->load->model('customers_model');

        $this->fixtures = new BookingFlowFixtures();
        $this->customersModel = $CI->customers_model;
    }

    protected function tearDown(): void
    {
        $this->fixtures->cleanup();

        parent::tearDown();
    }

    public function testSearchWithoutProviderFilterKeepsExistingCustomerSearch(): void
    {
        $unique = bin2hex(random_bytes(4));
        $matchingCustomerId = $this->fixtures->createCustomer([
            'first_name' => 'ProviderFilter',
            'last_name' => 'Visible-' . $unique,
        ]);
        $unrelatedCustomerId = $this->fixtures->createCustomer([
            'first_name' => 'ProviderFilter',
            'last_name' => 'Unrelated-' . $unique,
        ]);

        $results = $this->customersModel->search($unique, 20, 0, 'update_datetime DESC');
        $ids = $this->resultIds($results);

        $this->assertContains($matchingCustomerId, $ids);
        $this->assertContains($unrelatedCustomerId, $ids);
    }

    public function testSearchCanFilterCustomersByProviderAppointments(): void
    {
        $pair = $this->fixtures->resolveProviderServicePair();
        $unique = bin2hex(random_bytes(4));
        $matchingCustomerId = $this->fixtures->createCustomer([
            'first_name' => 'ProviderFilter',
            'last_name' => 'Visible-' . $unique,
        ]);
        $unrelatedCustomerId = $this->fixtures->createCustomer([
            'first_name' => 'ProviderFilter',
            'last_name' => 'Unrelated-' . $unique,
        ]);

        $this->fixtures->createAppointment(
            $pair['provider_id'],
            $matchingCustomerId,
            $pair['service_id'],
            new DateTimeImmutable('2031-01-15 10:00:00'),
        );
        $this->fixtures->createAppointment(
            $pair['provider_id'],
            $matchingCustomerId,
            $pair['service_id'],
            new DateTimeImmutable('2031-01-15 11:00:00'),
        );

        $results = $this->customersModel->search($unique, 20, 0, 'update_datetime DESC', $pair['provider_id']);
        $ids = $this->resultIds($results);

        $this->assertContains($matchingCustomerId, $ids);
        $this->assertNotContains($unrelatedCustomerId, $ids);
        $this->assertSame(1, $this->countResultId($matchingCustomerId, $ids));
    }

    public function testSearchCombinesProviderFilterWithKeyword(): void
    {
        $pair = $this->fixtures->resolveProviderServicePair();
        $unique = bin2hex(random_bytes(4));
        $matchingCustomerId = $this->fixtures->createCustomer([
            'first_name' => 'ProviderFilter',
            'last_name' => 'Visible-' . $unique,
        ]);

        $this->fixtures->createAppointment(
            $pair['provider_id'],
            $matchingCustomerId,
            $pair['service_id'],
            new DateTimeImmutable('2031-01-16 10:00:00'),
        );

        $results = $this->customersModel->search(
            'NoSuchCustomer-' . $unique,
            20,
            0,
            'update_datetime DESC',
            $pair['provider_id'],
        );

        $this->assertSame([], $this->resultIds($results));
    }

    /**
     * @param array<int, array<string, mixed>> $results
     *
     * @return array<int, int>
     */
    private function resultIds(array $results): array
    {
        return array_map(static fn(array $result): int => (int) $result['id'], $results);
    }

    /**
     * @param array<int, int> $ids
     */
    private function countResultId(int $id, array $ids): int
    {
        return count(array_filter($ids, static fn(int $resultId): bool => $resultId === $id));
    }
}
