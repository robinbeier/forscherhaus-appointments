<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Tests\Integration\Support\DefenseCycleFixtures;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__) . '/Support/DefenseCycleFixtures.php';
require_once dirname(__DIR__) . '/Support/DefenseCycleHttpServer.php';

/** Verify that analytics remain on the ordinary booking page and off capability responses. */
final class BookingAnalyticsCapabilityHttpTest extends TestCase
{
    private const GOOGLE_CODE = 'G-SYNTHETIC654';
    private const MATOMO_URL = 'https://matomo.synthetic.invalid/';
    private const MATOMO_SITE_ID = '7654';

    private ?DefenseCycleFixtures $fixture = null;
    private ?DefenseCycleHttpServer $server = null;
    /** @var array<string, array<string, mixed>> */
    private array $analyticsSettings = [];

    protected function setUp(): void
    {
        if (getenv('FH_DEFENSE_ISOLATED') !== '1') {
            self::markTestSkipped('Run with the fresh isolated synthetic stack.');
        }

        try {
            $this->fixture = new DefenseCycleFixtures();
            $this->fixture->create();
            $this->configureSyntheticAnalytics();
            $this->server = new DefenseCycleHttpServer();
        } catch (Throwable $error) {
            try {
                $this->server?->close();
            } finally {
                try {
                    $this->restoreAnalyticsSettings();
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
                $this->restoreAnalyticsSettings();
            } finally {
                $this->fixture?->cleanup();
            }
        }
    }

    public function testAnalyticsAreLimitedToOrdinaryBookingPage(): void
    {
        $fixture = $this->fixture;
        $client = $this->server?->client();
        self::assertNotNull($fixture);
        self::assertNotNull($client);

        $ordinary = $client->get('booking');
        self::assertSame(200, $ordinary->statusCode, 'The ordinary booking page must remain available.');
        $this->assertAnalyticsPresent($ordinary->body);

        $confirmationAppointment = $fixture->appointment();
        $confirmationBefore = $fixture->row('appointments', (int) $confirmationAppointment['id']);
        $confirmation = $client->get('booking_confirmation/of/' . $confirmationAppointment['hash']);
        self::assertSame(200, $confirmation->statusCode, 'Valid confirmation must remain available.');
        self::assertStringContainsString('data-generate-pdf', $confirmation->body);
        $this->assertAnalyticsAbsent($confirmation->body);
        self::assertSame($confirmationBefore, $fixture->row('appointments', (int) $confirmationAppointment['id']));

        $rescheduleAppointment = $fixture->appointment();
        $rescheduleBefore = $fixture->row('appointments', (int) $rescheduleAppointment['id']);
        $reschedule = $client->get('booking/reschedule/' . $rescheduleAppointment['hash']);
        self::assertSame(200, $reschedule->statusCode, 'Valid reschedule capability must remain available.');
        self::assertStringContainsString('book-appointment-wizard', $reschedule->body);
        $this->assertAnalyticsAbsent($reschedule->body);
        self::assertSame($rescheduleBefore, $fixture->row('appointments', (int) $rescheduleAppointment['id']));

        self::assertTrue(get_instance()->db->update('settings', ['value' => '60'], ['name' => 'book_advance_timeout']));
        $lockedAppointment = $fixture->appointment();
        $lockedStart = time() + 30 * 60;
        self::assertTrue(
            get_instance()->db->update(
                'appointments',
                [
                    'start_datetime' => date('Y-m-d H:i:s', $lockedStart),
                    'end_datetime' => date('Y-m-d H:i:s', $lockedStart + 1800),
                ],
                ['id' => $lockedAppointment['id']],
            ),
        );
        $lockedBefore = $fixture->row('appointments', (int) $lockedAppointment['id']);
        $locked = $client->get('booking/reschedule/' . $lockedAppointment['hash']);
        self::assertSame(200, $locked->statusCode, 'Locked reschedule must return its message page.');
        self::assertStringContainsString(lang('appointment_locked'), $locked->body);
        $this->assertAnalyticsAbsent($locked->body);
        self::assertSame($lockedBefore, $fixture->row('appointments', (int) $lockedAppointment['id']));

        self::assertTrue(get_instance()->db->update('settings', ['value' => '0'], ['name' => 'book_advance_timeout']));
        $cancelAppointment = $fixture->appointment();
        $cancelBefore = $fixture->row('appointments', (int) $cancelAppointment['id']);
        $customerBefore = $fixture->row('users', $fixture->customerId);
        $providerBefore = $fixture->row('users', $fixture->providerId);
        $serviceBefore = $fixture->row('services', $fixture->serviceId);
        $cancelled = $client->requestApp('POST', 'booking_cancellation/of/' . $cancelAppointment['hash'], [], 15);
        self::assertSame(200, $cancelled->statusCode, 'Valid cancellation must remain available.');
        self::assertStringContainsString(lang('appointment_cancelled_title'), $cancelled->body);
        $this->assertAnalyticsAbsent($cancelled->body);
        self::assertSame([], $fixture->row('appointments', (int) $cancelAppointment['id']));
        self::assertNotSame([], $cancelBefore, 'The synthetic cancellation appointment must have existed.');
        self::assertSame($customerBefore, $fixture->row('users', $fixture->customerId));
        self::assertSame($providerBefore, $fixture->row('users', $fixture->providerId));
        self::assertSame($serviceBefore, $fixture->row('services', $fixture->serviceId));

        $unknown = $client->requestApp('POST', 'booking_cancellation/of/' . $fixture->run . '-unknown', [], 15);
        self::assertSame(200, $unknown->statusCode, 'Unknown cancellation must retain its message response.');
        self::assertStringContainsString(lang('appointment_not_found'), $unknown->body);
        self::assertStringContainsString(lang('appointment_does_not_exist_in_db'), $unknown->body);
        $this->assertAnalyticsAbsent($unknown->body);
        self::assertSame($confirmationBefore, $fixture->row('appointments', (int) $confirmationAppointment['id']));
        self::assertSame($rescheduleBefore, $fixture->row('appointments', (int) $rescheduleAppointment['id']));
        self::assertSame($lockedBefore, $fixture->row('appointments', (int) $lockedAppointment['id']));
        self::assertSame($customerBefore, $fixture->row('users', $fixture->customerId));
        self::assertSame($providerBefore, $fixture->row('users', $fixture->providerId));
        self::assertSame($serviceBefore, $fixture->row('services', $fixture->serviceId));
    }

    private function configureSyntheticAnalytics(): void
    {
        $db = get_instance()->db;
        foreach (
            [
                'google_analytics_code' => self::GOOGLE_CODE,
                'matomo_analytics_url' => self::MATOMO_URL,
                'matomo_analytics_site_id' => self::MATOMO_SITE_ID,
            ]
            as $name => $value
        ) {
            $row = $db->get_where('settings', ['name' => $name])->row_array();
            if (!$row) {
                throw new RuntimeException('Synthetic analytics setting is missing: ' . $name);
            }
            $this->analyticsSettings[$name] = $row;
            if (!$db->update('settings', ['value' => $value], ['id' => (int) $row['id']])) {
                throw new RuntimeException('Synthetic analytics setting could not be updated: ' . $name);
            }
        }
    }

    private function restoreAnalyticsSettings(): void
    {
        if ($this->analyticsSettings === []) {
            return;
        }
        $db = get_instance()->db;
        foreach ($this->analyticsSettings as $row) {
            $db->update('settings', ['value' => $row['value']], ['id' => (int) $row['id']]);
        }
        $this->analyticsSettings = [];
    }

    private function assertAnalyticsPresent(string $body): void
    {
        self::assertStringContainsString(self::GOOGLE_CODE, $body);
        self::assertStringContainsString('googletagmanager.com/gtag/js?id=' . self::GOOGLE_CODE, $body);
        self::assertStringContainsString(self::MATOMO_URL, $body);
        self::assertStringContainsString("g.src = u + 'matomo.js'", $body);
        self::assertStringContainsString("_paq.push(['trackPageView'])", $body);
        self::assertStringContainsString(self::MATOMO_URL . 'matomo.php', $body);
    }

    private function assertAnalyticsAbsent(string $body): void
    {
        foreach (
            [
                self::GOOGLE_CODE,
                self::MATOMO_URL,
                'googletagmanager.com/gtag/js',
                'google-analytics.com',
                'matomo.js',
                'matomo.php',
                'window._paq',
                'gtag("config"',
            ]
            as $marker
        ) {
            self::assertStringNotContainsString(
                $marker,
                $body,
                'Capability response leaked analytics marker: ' . $marker,
            );
        }
    }
}
