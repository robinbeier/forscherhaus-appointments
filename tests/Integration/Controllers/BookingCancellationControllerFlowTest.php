<?php

namespace Tests\Integration\Controllers;

use Booking_cancellation;
use DateTimeImmutable;
use Tests\Integration\Support\BookingFlowFixtures;
use Tests\TestCase;

require_once APPPATH . 'controllers/Booking_cancellation.php';

class BookingCancellationControllerFlowTest extends TestCase
{
    private BookingFlowFixtures $fixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixtures = new BookingFlowFixtures();
        $this->fixtures->snapshotSettings(['disable_booking']);

        $this->fixtures->setSetting('disable_booking', '0');

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

        $_POST['cancellation_reason'] = 'Flow cancellation test';
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

        $_POST['cancellation_reason'] = 'Flow cancellation not-found';
        $_SERVER['REQUEST_METHOD'] = 'POST';

        $controller = $this->createCancellationController();
        $controller->of('missing-flow-appointment-hash');

        $this->assertNotNull($this->fixtures->findAppointmentById($appointmentId));
        $this->assertSame(lang('appointment_not_found'), html_vars('message_title'));
        $this->assertSame(lang('appointment_does_not_exist_in_db'), html_vars('message_text'));
    }

    private function createCancellationController(): Booking_cancellation
    {
        $controller = new class extends Booking_cancellation {
            public function __construct()
            {
            }
        };

        $this->wireCancellationDependencies($controller);
        $controller->synchronization = BookingFlowFixtures::createNoopSynchronization();
        $controller->notifications = BookingFlowFixtures::createNoopNotifications();
        $controller->webhooks_client = BookingFlowFixtures::createNoopWebhooksClient();

        return $controller;
    }

    private function wireCancellationDependencies(Booking_cancellation $controller): void
    {
        $CI = &get_instance();
        $CI->load->model('appointments_model');
        $CI->load->model('providers_model');
        $CI->load->model('services_model');
        $CI->load->model('customers_model');

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
