<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ReleaseGate\GateHttpClient;
use Tests\Integration\Support\DefenseCycleFixtures;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__) . '/Support/DefenseCycleFixtures.php';
require_once dirname(__DIR__) . '/Support/DefenseCycleHttpServer.php';

/** Authenticated calendar reads must stay within the UI's bounded date range. */
final class CalendarRangeLimitHttpTest extends TestCase
{
    private ?DefenseCycleFixtures $fixture = null;
    private ?DefenseCycleHttpServer $server = null;
    private array $credentials = [];

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
            $this->fixture?->cleanup();
        }
    }

    public function testNormalUiMonthSpanReachesAppointmentForBothCalendarFeeds(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $appointment = $fixture->appointment();
        $client = $this->login();

        foreach (['calendar/get_calendar_appointments', 'calendar/get_calendar_appointments_for_table_view'] as $path) {
            $response = $client->post($path, $this->rangePayload(13, 54));
            self::assertSame(200, $response->statusCode, $path . ': ' . $response->body);
            $body = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($body);

            $appointmentIds = array_map(
                static fn(array $row): int => (int) ($row['id'] ?? 0),
                $body['appointments'] ?? [],
            );
            self::assertContains((int) $appointment['id'], $appointmentIds, $path);
        }
    }

    public function testExtremeRangeIsRejectedBeforeCalendarDataIsReturned(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $appointment = $fixture->appointment();
        $client = $this->login();

        foreach (['calendar/get_calendar_appointments', 'calendar/get_calendar_appointments_for_table_view'] as $path) {
            $response = $client->post($path, $this->rangePayload(-3650, 3650));
            self::assertSame(400, $response->statusCode, $path . ': ' . $response->body);
            $body = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($body);
            self::assertFalse((bool) ($body['success'] ?? true), $path);
            self::assertStringContainsStringIgnoringCase('range', (string) ($body['message'] ?? ''), $path);
            self::assertStringNotContainsString((string) $appointment['hash'], $response->body, $path);
        }
    }

    private function login(): GateHttpClient
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $client = $this->server?->client();
        self::assertNotNull($client);
        self::assertSame(200, $client->get('login')->statusCode);
        $response = $client->post('login/validate', [
            'username' => $this->credentials['provider_username'],
            'password' => $this->credentials['password'],
        ]);
        self::assertSame(200, $response->statusCode, $response->body);
        self::assertTrue((bool) (json_decode($response->body, true, 512, JSON_THROW_ON_ERROR)['success'] ?? false));

        return $client;
    }

    /** @return array<string, mixed> */
    private function rangePayload(int $startOffset, int $endOffset): array
    {
        return [
            'start_date' => date('Y-m-d', strtotime(sprintf('%+d days', $startOffset))),
            'end_date' => date('Y-m-d', strtotime(sprintf('%+d days', $endOffset))),
            'record_id' => FILTER_TYPE_ALL,
            'filter_type' => '',
            'is_all' => '1',
        ];
    }
}
