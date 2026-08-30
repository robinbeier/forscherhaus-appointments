<?php

namespace Tests\Integration\Controllers;

use Booking;
use DateInterval;
use DateTimeImmutable;
use Tests\Integration\Support\BookingFlowFixtures;
use Tests\TestCase;

require_once APPPATH . 'controllers/Booking.php';

/**
 * Isolate controller integration tests from Unit test global state during coverage runs.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class BookingControllerFlowTest extends TestCase
{
    private BookingFlowFixtures $fixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixtures = new BookingFlowFixtures();
        $this->fixtures->snapshotSettings([
            'disable_booking',
            'require_captcha',
            'display_terms_and_conditions',
            'display_privacy_policy',
            'appointment_status_options',
            'book_advance_timeout',
        ]);

        $this->fixtures->setSetting('disable_booking', '0');
        $this->fixtures->setSetting('require_captcha', '0');
        $this->fixtures->setSetting('display_terms_and_conditions', '0');
        $this->fixtures->setSetting('display_privacy_policy', '0');
        $this->fixtures->setSetting('appointment_status_options', json_encode(['Booked', 'Cancelled']));
        $this->fixtures->setSetting('book_advance_timeout', '60');

        session([
            'public_reschedule_authority' => null,
            'public_reschedule_authority_context' => null,
        ]);
        $this->resetRuntimeState('POST');
    }

    protected function tearDown(): void
    {
        $this->resetRuntimeState('POST');
        session([
            'public_reschedule_authority' => null,
            'public_reschedule_authority_context' => null,
        ]);
        $this->fixtures->restoreSettings();
        $this->fixtures->cleanup();

        parent::tearDown();
    }

    public function testRegisterSuccessCreatesAppointmentAndReturnsHash(): void
    {
        $pair = $this->fixtures->resolveProviderServicePair();
        $customerEmail = 'register-success-' . bin2hex(random_bytes(4)) . '@example.org';
        $startAt = new DateTimeImmutable('tomorrow 09:00:00');
        $endAt = $startAt->add(new DateInterval('PT' . EVENT_MINIMUM_DURATION . 'M'));

        $_POST['post_data'] = [
            'appointment' => [
                'start_datetime' => $startAt->format('Y-m-d H:i:s'),
                'end_datetime' => $endAt->format('Y-m-d H:i:s'),
                'id_services' => $pair['service_id'],
                'id_users_provider' => $pair['provider_id'],
                'location' => '',
                'notes' => 'Flow register success',
                'color' => '',
            ],
            'customer' => [
                'first_name' => 'Flow',
                'last_name' => 'Register Success',
                'email' => $customerEmail,
                'phone_number' => '+49123456789',
                'address' => 'Teststrasse 1',
                'city' => 'Berlin',
                'zip_code' => '10115',
                'timezone' => setting('default_timezone') ?: 'UTC',
                'notes' => '',
            ],
            'manage_mode' => false,
        ];

        $controller = $this->createBookingControllerWithForcedAvailability($pair['provider_id']);

        $controller->register();

        $response = json_decode(get_instance()->output->get_output(), true);

        $this->assertIsArray($response);
        $this->assertArrayHasKey('appointment_id', $response);
        $this->assertArrayHasKey('appointment_hash', $response);

        $appointment = $this->fixtures->findAppointmentById((int) $response['appointment_id']);

        $this->assertNotNull($appointment);
        $this->assertSame((int) $response['appointment_id'], (int) $appointment['id']);
        $this->assertSame((string) $response['appointment_hash'], (string) $appointment['hash']);
        $this->assertSame($pair['provider_id'], (int) $appointment['id_users_provider']);
        $this->assertSame($pair['service_id'], (int) $appointment['id_services']);
        $this->assertTrue($this->fixtures->customerExistsByEmail($customerEmail));
    }

    public function testRegisterManageModeUpdatesExistingAppointment(): void
    {
        $pair = $this->fixtures->resolveProviderServicePair();
        $customerEmail = 'register-manage-' . bin2hex(random_bytes(4)) . '@example.org';

        $customerId = $this->fixtures->createCustomer([
            'first_name' => 'Manage',
            'last_name' => 'Mode',
            'email' => $customerEmail,
            'timezone' => setting('default_timezone') ?: 'UTC',
        ]);

        $initialStart = new DateTimeImmutable('+2 days 09:00:00');
        $appointmentId = $this->fixtures->createAppointment(
            $pair['provider_id'],
            $customerId,
            $pair['service_id'],
            $initialStart,
        );

        $existing = $this->fixtures->findAppointmentById($appointmentId);
        $this->assertNotNull($existing);
        $controller = $this->createBookingControllerWithForcedAvailability($pair['provider_id']);
        $this->issueRescheduleAuthority($controller, (string) $existing['hash']);

        $updatedStart = new DateTimeImmutable('+2 days 11:00:00');
        $updatedEnd = $updatedStart->add(new DateInterval('PT' . EVENT_MINIMUM_DURATION . 'M'));

        $_POST['post_data'] = [
            'appointment' => [
                'id' => $appointmentId,
                'start_datetime' => $updatedStart->format('Y-m-d H:i:s'),
                'end_datetime' => $updatedEnd->format('Y-m-d H:i:s'),
                'id_services' => $pair['service_id'],
                'id_users_provider' => $pair['provider_id'],
                'location' => '',
                'notes' => 'Flow manage update',
                'color' => '',
            ],
            'customer' => [
                'id' => $customerId,
                'first_name' => 'Manage',
                'last_name' => 'Mode',
                'email' => $customerEmail,
                'phone_number' => '+49123456789',
                'address' => 'Teststrasse 1',
                'city' => 'Berlin',
                'zip_code' => '10115',
                'timezone' => setting('default_timezone') ?: 'UTC',
                'notes' => '',
            ],
            'manage_mode' => true,
        ];

        $controller->register();

        $response = json_decode(get_instance()->output->get_output(), true);

        $this->assertIsArray($response);
        $this->assertArrayHasKey('appointment_id', $response);
        $this->assertSame($appointmentId, (int) $response['appointment_id']);

        $updated = $this->fixtures->findAppointmentById($appointmentId);

        $this->assertNotNull($updated);
        $this->assertSame($existing['hash'], $updated['hash']);
        $this->assertSame($updatedStart->format('Y-m-d H:i:s'), $updated['start_datetime']);
        $this->assertSame(1, $this->fixtures->countAppointmentsByHash($existing['hash']));
    }

    public function testCanonicalAuthorityCanBeClaimedAndVerifiedDirectly(): void
    {
        $scenario = $this->createRescheduleScenario(8);
        $controller = $this->createBookingControllerWithForcedAvailability($scenario['provider_id']);
        $this->issueRescheduleAuthority($controller, $scenario['hash']);

        $claim = $controller->reschedule_authority->claim($scenario['appointment_id'], $scenario['customer_id']);

        $this->assertTrue(get_instance()->db->trans_begin());

        try {
            $state = $controller->reschedule_authority->verifyLockedState(
                $claim,
                $scenario['provider_id'],
                $scenario['service_id'],
            );

            $this->assertSame($scenario['appointment_id'], $state->appointmentId);
            $this->assertSame($scenario['customer_id'], $state->customerId);
        } finally {
            get_instance()->db->trans_rollback();
        }
    }

    public function testForgedManageModeWithoutAuthorityRejectsWithoutMutation(): void
    {
        $scenario = $this->createRescheduleScenario(9);
        $controller = $this->createBookingControllerWithForcedAvailability($scenario['provider_id']);
        $beforeAppointment = $this->fixtures->findAppointmentById($scenario['appointment_id']);
        $beforeCustomer = $this->fixtures->findCustomerById($scenario['customer_id']);

        $this->setReschedulePayload($scenario, true, $scenario['appointment_id'], $scenario['customer_id'], [
            'first_name' => 'Forged mutation',
        ]);
        $controller->register();

        $this->assertAuthorityRejectedWithoutMutation(
            $controller,
            $scenario['appointment_id'],
            $scenario['customer_id'],
            $beforeAppointment,
            $beforeCustomer,
        );
    }

    public function testAppointmentIdWithoutManageModeStillRequiresAuthority(): void
    {
        $scenario = $this->createRescheduleScenario(10);
        $controller = $this->createBookingControllerWithForcedAvailability($scenario['provider_id']);
        $beforeAppointment = $this->fixtures->findAppointmentById($scenario['appointment_id']);
        $beforeCustomer = $this->fixtures->findCustomerById($scenario['customer_id']);

        $this->setReschedulePayload($scenario, false, $scenario['appointment_id'], $scenario['customer_id'], [
            'last_name' => 'ID-only mutation',
        ]);
        $controller->register();

        $this->assertAuthorityRejectedWithoutMutation(
            $controller,
            $scenario['appointment_id'],
            $scenario['customer_id'],
            $beforeAppointment,
            $beforeCustomer,
        );
    }

    public function testMalformedAppointmentIdCannotFallBackToNormalCreation(): void
    {
        $scenario = $this->createRescheduleScenario(10);
        $controller = $this->createBookingControllerWithForcedAvailability($scenario['provider_id']);
        $beforeAppointment = $this->fixtures->findAppointmentById($scenario['appointment_id']);
        $beforeCustomer = $this->fixtures->findCustomerById($scenario['customer_id']);
        $beforeCount = $this->fixtures->countAppointmentsForCustomer($scenario['customer_id']);
        $this->setReschedulePayload($scenario, false);
        $_POST['post_data']['appointment']['id'] = 'not-an-id';

        $controller->register();

        $this->assertAuthorityRejectedWithoutMutation(
            $controller,
            $scenario['appointment_id'],
            $scenario['customer_id'],
            $beforeAppointment,
            $beforeCustomer,
        );
        $this->assertSame($beforeCount, $this->fixtures->countAppointmentsForCustomer($scenario['customer_id']));
    }

    public function testAuthorityRejectsForeignAppointmentAndCustomerIdsWithoutMutation(): void
    {
        $canonical = $this->createRescheduleScenario(11);
        $foreign = $this->createRescheduleScenario(12);
        $controller = $this->createBookingControllerWithForcedAvailability($canonical['provider_id']);
        $this->issueRescheduleAuthority($controller, $canonical['hash']);

        $canonicalAppointment = $this->fixtures->findAppointmentById($canonical['appointment_id']);
        $canonicalCustomer = $this->fixtures->findCustomerById($canonical['customer_id']);
        $foreignAppointment = $this->fixtures->findAppointmentById($foreign['appointment_id']);
        $foreignCustomer = $this->fixtures->findCustomerById($foreign['customer_id']);

        $this->setReschedulePayload($canonical, true, $foreign['appointment_id'], $foreign['customer_id'], [
            'first_name' => 'Foreign mutation',
        ]);
        $controller->register();

        $this->assertAuthorityRejectedWithoutMutation(
            $controller,
            $canonical['appointment_id'],
            $canonical['customer_id'],
            $canonicalAppointment,
            $canonicalCustomer,
        );
        $this->assertSame($foreignAppointment, $this->fixtures->findAppointmentById($foreign['appointment_id']));
        $this->assertSame($foreignCustomer, $this->fixtures->findCustomerById($foreign['customer_id']));
    }

    public function testExpiredAuthorityRejectsWithoutMutation(): void
    {
        $scenario = $this->createRescheduleScenario(13);
        $controller = $this->createBookingControllerWithForcedAvailability($scenario['provider_id']);
        $this->issueRescheduleAuthority($controller, $scenario['hash']);
        $this->fixtures->expireRescheduleAuthority($scenario['appointment_id']);
        $beforeAppointment = $this->fixtures->findAppointmentById($scenario['appointment_id']);
        $beforeCustomer = $this->fixtures->findCustomerById($scenario['customer_id']);

        $this->setReschedulePayload($scenario);
        $controller->register();

        $this->assertAuthorityRejectedWithoutMutation(
            $controller,
            $scenario['appointment_id'],
            $scenario['customer_id'],
            $beforeAppointment,
            $beforeCustomer,
        );
    }

    public function testConsumedAuthorityCannotBeReplayed(): void
    {
        $scenario = $this->createRescheduleScenario(14);
        $controller = $this->createBookingControllerWithForcedAvailability($scenario['provider_id']);
        $this->issueRescheduleAuthority($controller, $scenario['hash']);
        $this->setReschedulePayload($scenario);

        $controller->register();
        $firstResponse = json_decode(get_instance()->output->get_output(), true);
        $this->assertSame($scenario['appointment_id'], (int) ($firstResponse['appointment_id'] ?? 0));

        $afterAppointment = $this->fixtures->findAppointmentById($scenario['appointment_id']);
        $afterCustomer = $this->fixtures->findCustomerById($scenario['customer_id']);
        $replayController = $this->createBookingControllerWithForcedAvailability($scenario['provider_id']);
        $this->resetRuntimeState('POST');
        $this->setReschedulePayload($scenario, true, $scenario['appointment_id'], $scenario['customer_id'], [], 4);

        $replayController->register();

        $this->assertAuthorityRejectedWithoutMutation(
            $replayController,
            $scenario['appointment_id'],
            $scenario['customer_id'],
            $afterAppointment,
            $afterCustomer,
        );
    }

    public function testHashDriftAfterIssuanceRejectsWithoutMutation(): void
    {
        $scenario = $this->createRescheduleScenario(15);
        $controller = $this->createBookingControllerWithForcedAvailability($scenario['provider_id']);
        $this->issueRescheduleAuthority($controller, $scenario['hash']);
        $this->fixtures->updateAppointment($scenario['appointment_id'], [
            'hash' => 'drift-' . bin2hex(random_bytes(6)),
            'update_datetime' => date('Y-m-d H:i:s'),
        ]);
        $beforeAppointment = $this->fixtures->findAppointmentById($scenario['appointment_id']);
        $beforeCustomer = $this->fixtures->findCustomerById($scenario['customer_id']);
        $this->setReschedulePayload($scenario);

        $controller->register();

        $this->assertAuthorityRejectedWithoutMutation(
            $controller,
            $scenario['appointment_id'],
            $scenario['customer_id'],
            $beforeAppointment,
            $beforeCustomer,
        );
    }

    public function testCustomerIdentityDriftAfterIssuanceRejectsWithoutMutation(): void
    {
        $scenario = $this->createRescheduleScenario(16);
        $controller = $this->createBookingControllerWithForcedAvailability($scenario['provider_id']);
        $this->issueRescheduleAuthority($controller, $scenario['hash']);
        $this->fixtures->updateCustomer($scenario['customer_id'], [
            'last_name' => 'Canonical identity drift',
            'update_datetime' => date('Y-m-d H:i:s'),
        ]);
        $beforeAppointment = $this->fixtures->findAppointmentById($scenario['appointment_id']);
        $beforeCustomer = $this->fixtures->findCustomerById($scenario['customer_id']);
        $this->setReschedulePayload($scenario, true, null, null, ['last_name' => 'Attempted overwrite']);

        $controller->register();

        $this->assertAuthorityRejectedWithoutMutation(
            $controller,
            $scenario['appointment_id'],
            $scenario['customer_id'],
            $beforeAppointment,
            $beforeCustomer,
        );
    }

    public function testProviderDriftAfterIssuanceRejectsWithoutMutation(): void
    {
        $scenario = $this->createRescheduleScenario(7);
        $controller = $this->createBookingControllerWithForcedAvailability($scenario['provider_id']);
        $this->issueRescheduleAuthority($controller, $scenario['hash']);
        $this->fixtures->updateSharedProvider($scenario['provider_id'], [
            'room' => 'authority-drift-' . bin2hex(random_bytes(4)),
            'update_datetime' => date('Y-m-d H:i:s'),
        ]);
        $beforeAppointment = $this->fixtures->findAppointmentById($scenario['appointment_id']);
        $beforeCustomer = $this->fixtures->findCustomerById($scenario['customer_id']);
        $this->setReschedulePayload($scenario);

        $controller->register();

        $this->assertAuthorityRejectedWithoutMutation(
            $controller,
            $scenario['appointment_id'],
            $scenario['customer_id'],
            $beforeAppointment,
            $beforeCustomer,
        );
    }

    public function testServiceDriftAfterIssuanceRejectsWithoutMutation(): void
    {
        $scenario = $this->createRescheduleScenario(6);
        $controller = $this->createBookingControllerWithForcedAvailability($scenario['provider_id']);
        $this->issueRescheduleAuthority($controller, $scenario['hash']);
        $service = get_instance()
            ->db->get_where('services', ['id' => $scenario['service_id']])
            ->row_array();
        $this->assertNotEmpty($service);
        $this->fixtures->updateSharedService($scenario['service_id'], [
            'duration' => (int) $service['duration'] + EVENT_MINIMUM_DURATION,
            'update_datetime' => date('Y-m-d H:i:s'),
        ]);
        $beforeAppointment = $this->fixtures->findAppointmentById($scenario['appointment_id']);
        $beforeCustomer = $this->fixtures->findCustomerById($scenario['customer_id']);
        $this->setReschedulePayload($scenario);

        $controller->register();

        $this->assertAuthorityRejectedWithoutMutation(
            $controller,
            $scenario['appointment_id'],
            $scenario['customer_id'],
            $beforeAppointment,
            $beforeCustomer,
        );
    }

    public function testCustomerOverlapProtectionRemainsAtomicForAuthorizedReschedule(): void
    {
        $scenario = $this->createRescheduleScenario(5);
        $controller = $this->createBookingControllerWithForcedAvailability($scenario['provider_id']);
        $this->issueRescheduleAuthority($controller, $scenario['hash']);
        $conflictStart = new DateTimeImmutable('+4 days 07:00:00');
        $this->fixtures->createAppointment(
            $scenario['provider_id'],
            $scenario['customer_id'],
            $scenario['service_id'],
            $conflictStart,
            $conflictStart->add(new DateInterval('PT4H')),
            'Customer overlap guard',
        );
        $beforeAppointment = $this->fixtures->findAppointmentById($scenario['appointment_id']);
        $beforeCustomer = $this->fixtures->findCustomerById($scenario['customer_id']);
        $this->setReschedulePayload($scenario);

        $controller->register();

        $response = json_decode(get_instance()->output->get_output(), true);
        $this->assertIsArray($response);
        $this->assertFalse($response['success'] ?? true);
        $this->assertSame(lang('customer_is_already_booked'), $response['message'] ?? null);
        $this->assertSame($beforeAppointment, $this->fixtures->findAppointmentById($scenario['appointment_id']));
        $this->assertSame($beforeCustomer, $this->fixtures->findCustomerById($scenario['customer_id']));
        $this->assertSame(0, $controller->synchronization->savedCalls);
        $this->assertSame(0, $controller->notifications->savedCalls);
        $this->assertSame(0, $controller->webhooks_client->calls);
    }

    public function testAppointmentRaceDriftAfterIssuanceRejectsWithoutMutation(): void
    {
        $scenario = $this->createRescheduleScenario(17);
        $controller = $this->createBookingControllerWithForcedAvailability($scenario['provider_id']);
        $this->issueRescheduleAuthority($controller, $scenario['hash']);
        $driftedStart = new DateTimeImmutable('+4 days 17:30:00');
        $this->fixtures->updateAppointment($scenario['appointment_id'], [
            'start_datetime' => $driftedStart->format('Y-m-d H:i:s'),
            'end_datetime' => $driftedStart
                ->add(new DateInterval('PT' . EVENT_MINIMUM_DURATION . 'M'))
                ->format('Y-m-d H:i:s'),
            'update_datetime' => date('Y-m-d H:i:s'),
        ]);
        $beforeAppointment = $this->fixtures->findAppointmentById($scenario['appointment_id']);
        $beforeCustomer = $this->fixtures->findCustomerById($scenario['customer_id']);
        $this->setReschedulePayload($scenario);

        $controller->register();

        $this->assertAuthorityRejectedWithoutMutation(
            $controller,
            $scenario['appointment_id'],
            $scenario['customer_id'],
            $beforeAppointment,
            $beforeCustomer,
        );
    }

    public function testCaptchaRejectionDoesNotMutateOrConsumeValidAuthority(): void
    {
        $scenario = $this->createRescheduleScenario(18);
        $controller = $this->createBookingControllerWithForcedAvailability($scenario['provider_id']);
        $this->fixtures->setSetting('require_captcha', '1');
        session(['captcha_phrase' => 'CORRECT']);
        $this->issueRescheduleAuthority($controller, $scenario['hash']);
        $beforeAppointment = $this->fixtures->findAppointmentById($scenario['appointment_id']);
        $beforeCustomer = $this->fixtures->findCustomerById($scenario['customer_id']);
        $this->setReschedulePayload($scenario);
        $_POST['captcha'] = 'wrong';

        $controller->register();

        $response = json_decode(get_instance()->output->get_output(), true);
        $this->assertFalse($response['captcha_verification'] ?? true);
        $this->assertSame($beforeAppointment, $this->fixtures->findAppointmentById($scenario['appointment_id']));
        $this->assertSame($beforeCustomer, $this->fixtures->findCustomerById($scenario['customer_id']));
        $this->assertSame(0, $controller->synchronization->savedCalls);
        $this->assertSame(0, $controller->notifications->savedCalls);
        $this->assertSame(0, $controller->webhooks_client->calls);

        $this->resetRuntimeState('POST');
        $this->setReschedulePayload($scenario);
        $_POST['captcha'] = 'CORRECT';
        $controller->register();
        $success = json_decode(get_instance()->output->get_output(), true);
        $this->assertSame($scenario['appointment_id'], (int) ($success['appointment_id'] ?? 0));
    }

    public function testRegisterReturnsErrorWhenDateTimeUnavailable(): void
    {
        $pair = $this->fixtures->resolveProviderServicePair();
        $customerEmail = 'register-unavailable-' . bin2hex(random_bytes(4)) . '@example.org';
        $startAt = new DateTimeImmutable('tomorrow 10:00:00');
        $endAt = $startAt->add(new DateInterval('PT' . EVENT_MINIMUM_DURATION . 'M'));

        $_POST['post_data'] = [
            'appointment' => [
                'start_datetime' => $startAt->format('Y-m-d H:i:s'),
                'end_datetime' => $endAt->format('Y-m-d H:i:s'),
                'id_services' => $pair['service_id'],
                'id_users_provider' => $pair['provider_id'],
                'location' => '',
                'notes' => 'Flow unavailable',
                'color' => '',
            ],
            'customer' => [
                'first_name' => 'Flow',
                'last_name' => 'Unavailable',
                'email' => $customerEmail,
                'phone_number' => '+49123456789',
                'address' => 'Teststrasse 1',
                'city' => 'Berlin',
                'zip_code' => '10115',
                'timezone' => setting('default_timezone') ?: 'UTC',
                'notes' => '',
            ],
            'manage_mode' => false,
        ];

        $controller = $this->createBookingControllerWithForcedAvailability(null);

        $controller->register();

        $response = json_decode(get_instance()->output->get_output(), true);

        $this->assertIsArray($response);
        $this->assertArrayHasKey('success', $response);
        $this->assertArrayHasKey('message', $response);
        $this->assertFalse($response['success'] ?? true);
        $this->assertIsString($response['message']);
        $this->assertNotSame('', trim($response['message']));
        $this->assertSame(lang('requested_hour_is_unavailable'), $response['message']);
        $this->assertArrayNotHasKey('trace', $response);
        $this->assertFalse($this->fixtures->customerExistsByEmail($customerEmail));
    }

    public function testRescheduleSetsManageModeForValidHash(): void
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

        $controller = $this->createBookingControllerWithForcedAvailability($pair['provider_id']);
        $controller->reschedule($appointment['hash']);

        $this->assertTrue((bool) script_vars('manage_mode'));
        $this->assertTrue((bool) html_vars('manage_mode'));

        $appointmentData = script_vars('appointment_data');
        $providerData = script_vars('provider_data');
        $customerData = script_vars('customer_data');

        $this->assertIsArray($appointmentData);
        $this->assertIsArray($providerData);
        $this->assertIsArray($customerData);
        $this->assertSame($appointmentId, (int) $appointmentData['id']);
        $this->assertSame($pair['provider_id'], (int) $providerData['id']);
        $this->assertSame($customerId, (int) $customerData['id']);
    }

    public function testRescheduleBootstrapsCacheWhenRateLimitBypassSkippedIt(): void
    {
        $pair = $this->fixtures->resolveProviderServicePair();
        $customerId = $this->fixtures->createCustomer();
        $appointmentId = $this->fixtures->createAppointment(
            $pair['provider_id'],
            $customerId,
            $pair['service_id'],
            new DateTimeImmutable('+2 days 11:00:00'),
        );

        $appointment = $this->fixtures->findAppointmentById($appointmentId);
        $this->assertNotNull($appointment);

        $controller = $this->createBookingControllerWithForcedAvailability($pair['provider_id'], false);
        $controller->reschedule($appointment['hash']);

        $this->assertTrue((bool) script_vars('manage_mode'));
        $this->assertTrue((bool) html_vars('manage_mode'));
        $this->assertIsString(script_vars('customer_token'));
        $this->assertNotSame('', trim((string) script_vars('customer_token')));
    }

    public function testRescheduleShowsLockedMessageWhenInsideAdvanceTimeout(): void
    {
        $this->fixtures->setSetting('book_advance_timeout', '120');

        $pair = $this->fixtures->resolveProviderServicePair();
        $customerId = $this->fixtures->createCustomer();
        $appointmentId = $this->fixtures->createAppointment(
            $pair['provider_id'],
            $customerId,
            $pair['service_id'],
            new DateTimeImmutable('+30 minutes'),
        );

        $appointment = $this->fixtures->findAppointmentById($appointmentId);
        $this->assertNotNull($appointment);

        $controller = $this->createBookingControllerWithForcedAvailability($pair['provider_id']);
        $controller->reschedule($appointment['hash']);

        $this->assertTrue((bool) html_vars('show_message'));
        $this->assertSame(lang('appointment_locked'), html_vars('message_title'));
        $this->assertStringContainsString('02:00', (string) html_vars('message_text'));
    }

    private function createBookingControllerWithForcedAvailability(?int $providerId, bool $injectCache = true): Booking
    {
        $controller = new class ($providerId) extends Booking {
            private ?int $forcedProviderId;

            public function __construct(?int $forcedProviderId)
            {
                $this->forcedProviderId = $forcedProviderId;
            }

            protected function check_datetime_availability(\BookingRegisterRequestDto $register_request): ?int
            {
                return $this->forcedProviderId;
            }
        };

        $this->wireBookingDependencies($controller, $injectCache);

        $controller->synchronization = BookingFlowFixtures::createNoopSynchronization();
        $controller->notifications = BookingFlowFixtures::createNoopNotifications();
        $controller->webhooks_client = BookingFlowFixtures::createNoopWebhooksClient();

        return $controller;
    }

    /**
     * @return array{provider_id:int,service_id:int,customer_id:int,appointment_id:int,hash:string,hour:int}
     */
    private function createRescheduleScenario(int $hour): array
    {
        $pair = $this->fixtures->resolveProviderServicePair();
        $customerId = $this->fixtures->createCustomer([
            'first_name' => 'Authority',
            'last_name' => 'Scenario-' . $hour,
        ]);
        $appointmentId = $this->fixtures->createAppointment(
            $pair['provider_id'],
            $customerId,
            $pair['service_id'],
            new DateTimeImmutable(sprintf('+4 days %02d:00:00', $hour)),
        );
        $appointment = $this->fixtures->findAppointmentById($appointmentId);

        $this->assertNotNull($appointment);

        return [
            'provider_id' => $pair['provider_id'],
            'service_id' => $pair['service_id'],
            'customer_id' => $customerId,
            'appointment_id' => $appointmentId,
            'hash' => (string) $appointment['hash'],
            'hour' => $hour,
        ];
    }

    /**
     * @param array{provider_id:int,service_id:int,customer_id:int,appointment_id:int,hash:string,hour:int} $scenario
     * @param array<string, mixed> $customerOverrides
     */
    private function setReschedulePayload(
        array $scenario,
        bool $manageMode = true,
        ?int $appointmentId = null,
        ?int $customerId = null,
        array $customerOverrides = [],
        int $hourOffset = 2,
    ): void {
        $canonicalCustomer = $this->fixtures->findCustomerById($scenario['customer_id']);
        $this->assertNotNull($canonicalCustomer);
        $startAt = new DateTimeImmutable(sprintf('+4 days %02d:00:00', ($scenario['hour'] + $hourOffset) % 24));
        $endAt = $startAt->add(new DateInterval('PT' . EVENT_MINIMUM_DURATION . 'M'));
        $customer = array_merge(
            [
                'id' => $customerId ?? $scenario['customer_id'],
                'first_name' => $canonicalCustomer['first_name'],
                'last_name' => $canonicalCustomer['last_name'],
                'email' => $canonicalCustomer['email'],
                'phone_number' => $canonicalCustomer['phone_number'] ?: '+49123456789',
                'address' => $canonicalCustomer['address'] ?: 'Teststrasse 1',
                'city' => $canonicalCustomer['city'] ?: 'Berlin',
                'zip_code' => $canonicalCustomer['zip_code'] ?: '10115',
                'timezone' => $canonicalCustomer['timezone'] ?? (setting('default_timezone') ?: 'UTC'),
                'notes' => $canonicalCustomer['notes'] ?? '',
            ],
            $customerOverrides,
        );

        $_POST['post_data'] = [
            'appointment' => [
                'id' => $appointmentId ?? $scenario['appointment_id'],
                'start_datetime' => $startAt->format('Y-m-d H:i:s'),
                'end_datetime' => $endAt->format('Y-m-d H:i:s'),
                'id_services' => $scenario['service_id'],
                'id_users_provider' => $scenario['provider_id'],
                'location' => '',
                'notes' => 'Authority-gated reschedule',
                'color' => '',
            ],
            'customer' => $customer,
            'manage_mode' => $manageMode,
        ];
    }

    private function issueRescheduleAuthority(Booking $controller, string $appointmentHash): void
    {
        $this->resetRuntimeState('GET');
        $controller->reschedule($appointmentHash);
        $this->resetRuntimeState('POST');
    }

    private function assertAuthorityRejectedWithoutMutation(
        Booking $controller,
        int $appointmentId,
        int $customerId,
        ?array $expectedAppointment,
        ?array $expectedCustomer,
    ): void {
        $response = json_decode(get_instance()->output->get_output(), true);

        $this->assertIsArray($response);
        $this->assertFalse($response['success'] ?? true);
        $this->assertSame(lang('appointment_not_found'), $response['message'] ?? null);
        $this->assertSame($expectedAppointment, $this->fixtures->findAppointmentById($appointmentId));
        $this->assertSame($expectedCustomer, $this->fixtures->findCustomerById($customerId));
        $this->assertSame(0, $controller->synchronization->savedCalls);
        $this->assertSame(0, $controller->notifications->savedCalls);
        $this->assertSame(0, $controller->webhooks_client->calls);
    }

    private function wireBookingDependencies(Booking $controller, bool $injectCache = true): void
    {
        $CI = &get_instance();
        $CI->load->model('appointments_model');
        $CI->load->model('providers_model');
        $CI->load->model('admins_model');
        $CI->load->model('secretaries_model');
        $CI->load->model('service_categories_model');
        $CI->load->model('services_model');
        $CI->load->model('customers_model');
        $CI->load->model('settings_model');
        $CI->load->model('consents_model');
        $CI->load->library('timezones');
        $CI->load->library('availability');
        $CI->load->library('reschedule_authority');

        $controller->load = $CI->load;
        $controller->db = $CI->db;
        $controller->input = $CI->input;
        $controller->output = $CI->output;
        if ($injectCache) {
            $controller->cache = new class {
                public function save(...$args): bool
                {
                    return true;
                }
            };
        }
        $controller->appointments_model = $CI->appointments_model;
        $controller->providers_model = $CI->providers_model;
        $controller->admins_model = $CI->admins_model;
        $controller->secretaries_model = $CI->secretaries_model;
        $controller->service_categories_model = $CI->service_categories_model;
        $controller->services_model = $CI->services_model;
        $controller->customers_model = $CI->customers_model;
        $controller->settings_model = $CI->settings_model;
        $controller->consents_model = $CI->consents_model;
        $controller->timezones = $CI->timezones;
        $controller->availability = $CI->availability;
        $controller->reschedule_authority = $CI->reschedule_authority;
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
        http_response_code(200);
    }
}
