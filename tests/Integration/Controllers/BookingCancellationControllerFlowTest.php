<?php

namespace Tests\Integration\Controllers;

use Booking_cancellation;
use DateTimeImmutable;
use DateTimeZone;
use ReflectionMethod;
use RuntimeException;
use Tests\Integration\Support\BookingFlowFixtures;
use Tests\TestCase;

require_once APPPATH . 'controllers/Booking_cancellation.php';

/**
 * Isolate controller integration tests from Unit test global state during coverage runs.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class BookingCancellationControllerFlowTest extends TestCase
{
    private BookingFlowFixtures $fixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixtures = new BookingFlowFixtures();
        $this->fixtures->snapshotSettings(['disable_booking', 'book_advance_timeout']);

        $this->fixtures->setSetting('disable_booking', '0');
        $this->fixtures->setSetting('book_advance_timeout', '60');

        $this->resetRuntimeState('POST');
    }

    protected function tearDown(): void
    {
        $this->resetRuntimeState('POST');
        $this->fixtures->restoreSettings();
        $this->fixtures->cleanup();

        parent::tearDown();
    }

    public function testCancelSuccessDeletesAppointmentAndRendersCancellationState(): void
    {
        $pair = $this->fixtures->resolveProviderServicePair();
        $customerId = $this->fixtures->createCustomer();
        $appointmentId = $this->fixtures->createAppointment(
            $pair['provider_id'],
            $customerId,
            $pair['service_id'],
            new DateTimeImmutable('+2 days 10:00:00'),
        );

        $appointment = $this->fixtures->findAppointmentById($appointmentId);
        $this->assertNotNull($appointment);

        $_SERVER['REQUEST_METHOD'] = 'POST';

        $controller = $this->createCancellationController();
        $controller->of($appointment['hash']);

        $this->assertNull($this->fixtures->findAppointmentById($appointmentId));
        $this->assertSame(lang('appointment_cancelled_title'), html_vars('page_title'));
    }

    public function testCancelUnknownHashRendersNotFoundMessageState(): void
    {
        $pair = $this->fixtures->resolveProviderServicePair();
        $customerId = $this->fixtures->createCustomer();
        $appointmentId = $this->fixtures->createAppointment(
            $pair['provider_id'],
            $customerId,
            $pair['service_id'],
            new DateTimeImmutable('+2 days 12:00:00'),
        );

        $_SERVER['REQUEST_METHOD'] = 'POST';

        $controller = $this->createCancellationController();
        $controller->of('missing-flow-appointment-hash');

        $this->assertNotNull($this->fixtures->findAppointmentById($appointmentId));
        $this->assertSame(lang('appointment_not_found'), html_vars('message_title'));
        $this->assertSame(lang('appointment_does_not_exist_in_db'), html_vars('message_text'));
    }

    public function testCancellationCutoffKeepsExactEqualityAllowedAtSecondPrecision(): void
    {
        $timezone = new DateTimeZone('Europe/Berlin');
        $now = new DateTimeImmutable('2026-01-15 10:00:00.900000', $timezone);

        $this->assertFalse(
            $this->invokeCancellationCutoff('2026-01-15 11:00:00', $timezone, 60, $now),
            'A stored appointment exactly at the second-resolution limit must remain cancellable.',
        );
    }

    public function testCancellationCutoffRejectsNonexistentProviderLocalTime(): void
    {
        $timezone = new DateTimeZone('Europe/Berlin');
        $now = new DateTimeImmutable('2026-03-28 10:00:00', $timezone);

        $this->expectException(RuntimeException::class);
        $this->invokeCancellationCutoff('2026-03-29 02:30:00', $timezone, 60, $now);
    }

    private function createCancellationController(): Booking_cancellation
    {
        $controller = new class extends Booking_cancellation {
            public function __construct() {}
        };

        $this->wireCancellationDependencies($controller);

        return $controller;
    }

    private function invokeCancellationCutoff(
        string $startDatetime,
        DateTimeZone $timezone,
        int $advanceTimeout,
        DateTimeImmutable $now,
    ): bool {
        $method = new ReflectionMethod(Booking_cancellation::class, 'isCancellationCutoffReached');

        return $method->invoke($this->createCancellationController(), $startDatetime, $timezone, $advanceTimeout, $now);
    }

    private function wireCancellationDependencies(Booking_cancellation $controller): void
    {
        $CI = &get_instance();
        $CI->load->model('appointments_model');
        $CI->load->model('providers_model');
        $CI->load->model('services_model');
        $CI->load->model('customers_model');

        $controller->db = $CI->db;
        $controller->load = $CI->load;
        $controller->input = $CI->input;
        $controller->output = $CI->output;
        $controller->appointments_model = $CI->appointments_model;
        $controller->providers_model = $CI->providers_model;
        $controller->services_model = $CI->services_model;
        $controller->customers_model = $CI->customers_model;
    }

    private function resetRuntimeState(string $requestMethod): void
    {
        $_POST = [];
        $_GET = [];
        $_SERVER['REQUEST_METHOD'] = $requestMethod;

        config([
            'html_vars' => [],
            'script_vars' => [],
            'layout' => [
                'filename' => 'test-layout',
                'sections' => [],
                'tmp' => [],
            ],
        ]);

        get_instance()->output->set_output('');
    }
}
