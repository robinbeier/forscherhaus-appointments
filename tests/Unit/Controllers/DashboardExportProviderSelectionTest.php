<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers;

use Appointments_model;
use Dashboard_export;
use DateTimeImmutable;
use InvalidArgumentException;
use Providers_model;
use Tests\TestCase;

require_once APPPATH . 'controllers/Dashboard_export.php';

final class DashboardExportProviderSelectionTest extends TestCase
{
    private Appointments_model $appointments;
    private Providers_model $providers;

    /** @var array<string, array{present: bool, value: mixed}>|null */
    private ?array $modelState = null;

    /** @var list<string>|null */
    private ?array $loaderModels = null;

    protected function setUp(): void
    {
        parent::setUp();
        $ci = get_instance();
        $this->snapshotLoaderState($ci);
        try {
            $ci->load->model('appointments_model');
            $ci->load->model('providers_model');
            $this->appointments = $ci->appointments_model;
            $this->providers = $ci->providers_model;
        } catch (\Throwable $error) {
            $this->restoreLoaderState($ci);
            throw $error;
        }
    }

    protected function tearDown(): void
    {
        $this->restoreLoaderState(get_instance());
        parent::tearDown();
    }

    private function snapshotLoaderState(object $ci): void
    {
        $this->modelState = [];
        foreach (['appointments_model', 'unavailabilities_model', 'services_model', 'providers_model'] as $name) {
            $this->modelState[$name] = [
                'present' => array_key_exists($name, get_object_vars($ci)),
                'value' => $ci->{$name} ?? null,
            ];
        }
        $reflection = new \ReflectionProperty($ci->load, '_ci_models');
        $this->loaderModels = $reflection->getValue($ci->load);
    }

    private function restoreLoaderState(object $ci): void
    {
        if ($this->modelState === null || $this->loaderModels === null) {
            return;
        }
        foreach ($this->modelState as $name => $state) {
            if ($state['present']) {
                $ci->{$name} = $state['value'];
            } elseif (array_key_exists($name, get_object_vars($ci))) {
                unset($ci->{$name});
            }
        }
        $reflection = new \ReflectionProperty($ci->load, '_ci_models');
        $reflection->setValue($ci->load, $this->loaderModels);
        $this->modelState = null;
        $this->loaderModels = null;
    }

    public function test_loader_selects_only_booked_in_period_rows_for_each_provider(): void
    {
        $db = get_instance()->db;
        self::assertTrue($db->trans_begin());
        try {
            $fixture = $this->createFixture();
            $controller = new ExposedDashboardExport($this->appointments);
            $start = new DateTimeImmutable('2099-02-01 00:00:00');
            $end = new DateTimeImmutable('2099-02-28 23:59:59');
            foreach ($fixture['providers'] as $providerId) {
                $rows = $controller->loadProviderRows($providerId, $start, $end);
                self::assertCount(count($fixture['expected'][$providerId]), $rows);
                self::assertSame(
                    ['start_datetime', 'end_datetime', 'customer_first_name', 'customer_last_name'],
                    array_keys($rows[0]),
                );
                foreach ($fixture['expected'][$providerId] as $rowIndex => $expected) {
                    self::assertSame($expected['start'], $rows[$rowIndex]['start_datetime']);
                    self::assertSame($expected['end'], $rows[$rowIndex]['end_datetime']);
                    self::assertSame($expected['first'], $rows[$rowIndex]['customer_first_name']);
                    self::assertSame($expected['last'], $rows[$rowIndex]['customer_last_name']);
                }
                self::assertStringNotContainsString('sensitive', json_encode($rows, JSON_THROW_ON_ERROR));
                $view = $controller->mapProviderRows($rows);
                self::assertCount(count($fixture['expected'][$providerId]), $view);
                foreach ($fixture['expected'][$providerId] as $rowIndex => $expected) {
                    self::assertSame(['parent_name', 'date', 'start', 'end'], array_keys($view[$rowIndex]));
                    self::assertSame($expected['first'] . ' ' . $expected['last'], $view[$rowIndex]['parent_name']);
                    self::assertSame($expected['date'], $view[$rowIndex]['date']);
                    self::assertSame($expected['start_time'], $view[$rowIndex]['start']);
                    self::assertSame($expected['end_time'], $view[$rowIndex]['end']);
                }
                self::assertStringNotContainsString('sensitive', json_encode($view, JSON_THROW_ON_ERROR));
            }
            self::assertSame(
                [],
                $controller->loadProviderRows(
                    $fixture['providers'][0],
                    new DateTimeImmutable('2100-01-01'),
                    new DateTimeImmutable('2100-01-31'),
                ),
            );
        } finally {
            self::assertTrue($db->trans_rollback());
        }
        self::assertFalse($db->trans_active());
    }

    /** @return array{providers: list<int>, expected: array<int,list<array{start:string,end:string,first:string,last:string,date:string,start_time:string,end_time:string}>>} */
    private function createFixture(): array
    {
        $providers = [$this->createProvider('one'), $this->createProvider('two')];
        $customers = [$this->createCustomer('one'), $this->createCustomer('two')];
        $serviceId = $this->createService();
        $expected = [];
        foreach ($providers as $index => $providerId) {
            $start = sprintf('2099-02-%02d 10:00:00', 10 + $index);
            $expected[$providerId] = [
                [
                    'start' => $start,
                    'end' => (new DateTimeImmutable($start))->modify('+30 minutes')->format('Y-m-d H:i:s'),
                    'first' => 'Parent',
                    'last' => 'Fixture ' . ($index + 1),
                    'date' => '2099-02-' . (10 + $index),
                    'start_time' => '10:00',
                    'end_time' => '10:30',
                ],
            ];
            $this->appointment(
                $providerId,
                $customers[$index],
                $serviceId,
                $start,
                'Booked',
                false,
                'sensitive booked notes',
            );
            if ($index === 0) {
                $earlier = '2099-02-10 09:00:00';
                array_unshift($expected[$providerId], [
                    'start' => $earlier,
                    'end' => '2099-02-10 09:30:00',
                    'first' => 'Parent',
                    'last' => 'Fixture 1',
                    'date' => '2099-02-10',
                    'start_time' => '09:00',
                    'end_time' => '09:30',
                ]);
                $this->appointment(
                    $providerId,
                    $customers[$index],
                    $serviceId,
                    $earlier,
                    'Booked',
                    false,
                    'sensitive earlier notes',
                );
            }
            $this->appointment(
                $providerId,
                $customers[$index],
                $serviceId,
                '2099-02-15 12:00:00',
                'Pending',
                false,
                'sensitive pending',
            );
            $this->appointment(
                $providerId,
                $customers[$index],
                $serviceId,
                '2099-02-16 12:00:00',
                'Booked',
                true,
                'sensitive unavailable',
            );
            $this->appointment(
                $providerId,
                $customers[$index],
                $serviceId,
                '2099-03-10 12:00:00',
                'Booked',
                false,
                'sensitive outside',
            );
        }
        return ['providers' => $providers, 'expected' => $expected];
    }

    private function createProvider(string $case): int
    {
        return $this->providers->save([
            'first_name' => 'Provider',
            'last_name' => $case,
            'email' => 'export-provider-' . $case . '-' . bin2hex(random_bytes(4)) . '@example.test',
            'services' => [],
            'settings' => [
                'username' => 'export-provider-' . $case . '-' . bin2hex(random_bytes(4)),
                'password' => 'synthetic-export-password',
            ],
        ]);
    }

    private function createCustomer(string $case): int
    {
        $role = get_instance()
            ->db->get_where('roles', ['slug' => DB_SLUG_CUSTOMER])
            ->row_array();
        if (!$role) {
            throw new InvalidArgumentException('Customer role unavailable for synthetic export fixture.');
        }
        $db = get_instance()->db;
        self::assertTrue(
            $db->insert('users', [
                'first_name' => 'Parent',
                'last_name' => 'Fixture ' . ($case === 'one' ? '1' : '2'),
                'email' => 'sensitive-' . $case . '-' . bin2hex(random_bytes(4)) . '@example.test',
                'phone_number' => 'sensitive-phone',
                'notes' => 'sensitive customer notes',
                'timezone' => 'UTC',
                'language' => 'english',
                'id_roles' => $role['id'],
                'create_datetime' => date('Y-m-d H:i:s'),
                'update_datetime' => date('Y-m-d H:i:s'),
            ]),
        );
        return (int) $db->insert_id();
    }

    private function createService(): int
    {
        $db = get_instance()->db;
        self::assertTrue(
            $db->insert('services', [
                'name' => 'Export fixture ' . bin2hex(random_bytes(4)),
                'duration' => 30,
                'attendants_number' => 1,
                'buffer_before' => 0,
                'buffer_after' => 0,
                'create_datetime' => date('Y-m-d H:i:s'),
                'update_datetime' => date('Y-m-d H:i:s'),
            ]),
        );
        return (int) $db->insert_id();
    }

    private function appointment(
        int $provider,
        int $customer,
        int $service,
        string $start,
        string $status,
        bool $unavailability,
        string $notes,
    ): void {
        $db = get_instance()->db;
        $end = (new DateTimeImmutable($start))->modify('+30 minutes')->format('Y-m-d H:i:s');
        self::assertTrue(
            $db->insert('appointments', [
                'book_datetime' => date('Y-m-d H:i:s'),
                'start_datetime' => $start,
                'end_datetime' => $end,
                'notes' => $notes,
                'hash' => 'export-' . bin2hex(random_bytes(6)),
                'is_unavailability' => $unavailability,
                'status' => $status,
                'id_users_provider' => $provider,
                'id_users_customer' => $customer,
                'id_services' => $service,
                'create_datetime' => date('Y-m-d H:i:s'),
                'update_datetime' => date('Y-m-d H:i:s'),
            ]),
        );
    }
}

final class ExposedDashboardExport extends Dashboard_export
{
    public function __construct(Appointments_model $appointments)
    {
        $this->appointmentsModel = $appointments;
    }

    public function loadProviderRows(int $providerId, DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        return $this->loadProviderParentAppointments($providerId, $start, $end);
    }

    public function mapProviderRows(array $rows): array
    {
        return $this->mapProviderParentAppointmentsForView($rows);
    }

    /** Deterministic formatting double: this test covers selection/projection, not locale formatting. */
    protected function formatDate(DateTimeImmutable $date): string
    {
        return $date->format('Y-m-d');
    }

    /** Deterministic formatting double: this test covers selection/projection, not locale formatting. */
    protected function formatTime(DateTimeImmutable $date): string
    {
        return $date->format('H:i');
    }
}
