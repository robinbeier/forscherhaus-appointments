<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ReleaseGate\GateHttpClient;
use Tests\Integration\Support\DefenseCycleFixtures;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__) . '/Support/DefenseCycleFixtures.php';
require_once dirname(__DIR__) . '/Support/DefenseCycleHttpServer.php';

/** Anonymous booking confirmation and calendar downloads for owned fixture hashes. */
final class BookingDownloadHttpTest extends TestCase
{
    private ?DefenseCycleFixtures $fixture = null;
    private ?DefenseCycleHttpServer $server = null;

    protected function setUp(): void
    {
        if (getenv('FH_DEFENSE_ISOLATED') !== '1') {
            self::markTestSkipped('Run scripts/ci/run_defense_cycle.sh with its fresh synthetic stack.');
        }
        try {
            $this->fixture = new DefenseCycleFixtures();
            $this->fixture->create();
            $this->server = new DefenseCycleHttpServer(disableSessionCacheLimiter: true);
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

    public function testOwnedModernAndLegacyHashesServeConfirmationAndIcsDownloads(): void
    {
        $fixture = $this->fixture;
        $modern = $fixture->appointment();
        $this->moveAppointmentToDistinctSlot((int) $modern['id'], '+15 days');
        $modern = $fixture->row('appointments', (int) $modern['id']);
        $legacy = $fixture->appointment(true);
        $modernHash = (string) $modern['hash'];
        $legacyHash = (string) $legacy['hash'];
        self::assertSame(64, strlen($modernHash));
        self::assertSame(12, strlen($legacyHash));

        $ownedSnapshots = $this->ownedSnapshots($modern, $legacy);
        $client = $this->anonymousClient();
        foreach ([$modernHash, $legacyHash] as $hash) {
            $confirmation = $client->get('booking_confirmation/of/' . $hash);
            self::assertSame(200, $confirmation->statusCode, $hash . ' confirmation must succeed.');
            self::assertStringStartsWith('text/html', strtolower((string) $confirmation->header('content-type')));
            self::assertSame('no-store', strtolower((string) $confirmation->header('cache-control')));
            self::assertStringContainsString('data-generate-pdf', $confirmation->body);
            self::assertStringContainsString($fixture->run, $confirmation->body);
            self::assertStringContainsString('/booking/reschedule/' . $hash, $confirmation->body);
            $this->assertOwnedSnapshotsUnchanged($ownedSnapshots);

            $ics = $client->get('appointments/ics/' . $hash);
            self::assertSame(200, $ics->statusCode, $hash . ' ICS download must succeed.');
            self::assertStringStartsWith('text/calendar', strtolower((string) $ics->header('content-type')));
            self::assertStringContainsString(
                'attachment; filename="appointment-' . $hash . '.ics"',
                strtolower((string) $ics->header('content-disposition')),
            );
            self::assertSame('no-store', strtolower((string) $ics->header('cache-control')));
            self::assertStringContainsString($fixture->run, $ics->body);
            $unfoldedIcs = preg_replace("/\r?\n[ \t]/", '', $ics->body);
            self::assertIsString($unfoldedIcs);
            self::assertStringContainsString($hash, $unfoldedIcs);
            self::assertStringNotContainsString($fixture->run . '_customer@synthetic.invalid', $unfoldedIcs);
            self::assertStringNotContainsString($fixture->run . '_provider@synthetic.invalid', $unfoldedIcs);
            self::assertStringNotContainsString('ATTENDEE:', $unfoldedIcs);
            self::assertStringNotContainsString('ORGANIZER:', $unfoldedIcs);
            self::assertSame(2, substr_count($unfoldedIcs, 'BEGIN:VALARM'));
            self::assertSame(2, substr_count($unfoldedIcs, 'ACTION:DISPLAY'));
            self::assertStringNotContainsString('ACTION:EMAIL', $unfoldedIcs);
            $this->assertOwnedSnapshotsUnchanged($ownedSnapshots);
        }
    }

    public function testEndedModernAndLegacyLinksNoLongerServeAppointmentData(): void
    {
        $fixture = $this->fixture;
        $modern = $fixture->appointment();
        $legacy = $fixture->appointment(true);
        self::assertTrue(
            get_instance()->db->update('appointments', ['hash' => 'legacyCodeG1'], ['id' => $legacy['id']]),
        );
        $legacy = $fixture->row('appointments', (int) $legacy['id']);
        $historical32 = $fixture->appointment();
        self::assertTrue(
            get_instance()->db->update(
                'appointments',
                ['hash' => '0123456789abcdef0123456789abcdef'],
                ['id' => $historical32['id']],
            ),
        );
        $historical32 = $fixture->row('appointments', (int) $historical32['id']);
        self::assertSame(32, strlen((string) $historical32['hash']));
        self::assertSame(12, strlen((string) $legacy['hash']));
        self::assertMatchesRegularExpression('/^[A-Za-z0-9]{12}$/D', (string) $legacy['hash']);
        self::assertMatchesRegularExpression('/[G-Zg-z]/', (string) $legacy['hash']);
        $endedAt = date('Y-m-d H:i:s', time() - 60);
        self::assertTrue(
            get_instance()->db->update(
                'appointments',
                ['start_datetime' => date('Y-m-d H:i:s', time() - 1800), 'end_datetime' => $endedAt],
                ['id' => $modern['id']],
            ),
        );
        self::assertTrue(
            get_instance()->db->update(
                'appointments',
                ['start_datetime' => date('Y-m-d H:i:s', time() - 1800), 'end_datetime' => $endedAt],
                ['id' => $legacy['id']],
            ),
        );
        self::assertTrue(
            get_instance()->db->update(
                'appointments',
                ['start_datetime' => date('Y-m-d H:i:s', time() - 1800), 'end_datetime' => $endedAt],
                ['id' => $historical32['id']],
            ),
        );

        $client = $this->anonymousClient();
        foreach ([$modern, $legacy, $historical32] as $appointment) {
            $hash = (string) $appointment['hash'];
            $confirmation = $client->get('booking_confirmation/of/' . $hash);
            self::assertSame(307, $confirmation->statusCode, 'Ended confirmation must redirect without data.');
            self::assertStringContainsString('/appointments', (string) $confirmation->header('location'));
            self::assertStringNotContainsString($fixture->run, $confirmation->body);
            self::assertStringNotContainsString('/booking/reschedule/', $confirmation->body);

            $ics = $client->get('appointments/ics/' . $hash);
            self::assertSame(404, $ics->statusCode, 'Ended ICS must be unavailable.');
            self::assertStringNotContainsString('text/calendar', strtolower((string) $ics->header('content-type')));
            self::assertNull($ics->header('content-disposition'));
            self::assertStringNotContainsString($fixture->run, $ics->body);
        }
    }

    public function testExactEndDatetimeBoundaryIsExpired(): void
    {
        $fixture = $this->fixture;
        $appointment = $fixture->appointment();
        $provider = $fixture->row('users', $fixture->providerId);
        $timezone = new DateTimeZone((string) $provider['timezone']);
        $nearEnd = (new DateTimeImmutable('now', $timezone))->modify('+60 seconds');
        self::assertTrue(
            get_instance()->db->update(
                'appointments',
                [
                    'start_datetime' => $nearEnd->modify('-1800 seconds')->format('Y-m-d H:i:s'),
                    'end_datetime' => $nearEnd->format('Y-m-d H:i:s'),
                ],
                ['id' => $appointment['id']],
            ),
        );

        $hash = (string) $appointment['hash'];
        $client = $this->anonymousClient();
        self::assertSame(200, $client->get('booking_confirmation/of/' . $hash)->statusCode);
        self::assertSame(200, $client->get('appointments/ics/' . $hash)->statusCode);

        $atEnd = new DateTimeImmutable('now', $timezone);
        self::assertTrue(
            get_instance()->db->update(
                'appointments',
                [
                    'start_datetime' => $atEnd->modify('-1800 seconds')->format('Y-m-d H:i:s'),
                    'end_datetime' => $atEnd->format('Y-m-d H:i:s'),
                ],
                ['id' => $appointment['id']],
            ),
        );
        self::assertSame(307, $client->get('booking_confirmation/of/' . $hash)->statusCode);
        self::assertSame(404, $client->get('appointments/ics/' . $hash)->statusCode);
    }

    public function testMalformedAppointmentTimeZoneFailsClosedForBothPublicLinks(): void
    {
        $fixture = $this->fixture;
        $appointment = $fixture->appointment();
        self::assertTrue(
            get_instance()->db->update('users', ['timezone' => 'not/a-timezone'], ['id' => $fixture->providerId]),
        );

        $hash = (string) $appointment['hash'];
        $client = $this->anonymousClient();
        $confirmation = $client->get('booking_confirmation/of/' . $hash);
        self::assertSame(404, $confirmation->statusCode);
        self::assertStringNotContainsString($fixture->run, $confirmation->body);

        $ics = $client->get('appointments/ics/' . $hash);
        self::assertSame(404, $ics->statusCode);
        self::assertStringNotContainsString('text/calendar', strtolower((string) $ics->header('content-type')));
        self::assertStringNotContainsString($fixture->run, $ics->body);
    }

    public function testAmbiguousStoredHashFailsClosedForBothPublicLinks(): void
    {
        $fixture = $this->fixture;
        $current = $fixture->appointment();
        $ended = $fixture->appointment();
        $provider = $fixture->row('users', $fixture->providerId);
        $timezone = new DateTimeZone((string) $provider['timezone']);
        $endedAt = new DateTimeImmutable('-1 hour', $timezone);

        self::assertTrue(
            get_instance()->db->update(
                'appointments',
                [
                    'hash' => $current['hash'],
                    'start_datetime' => $endedAt->modify('-30 minutes')->format('Y-m-d H:i:s'),
                    'end_datetime' => $endedAt->format('Y-m-d H:i:s'),
                ],
                ['id' => $ended['id']],
            ),
        );

        $client = $this->anonymousClient();
        $hash = (string) $current['hash'];
        $confirmation = $client->get('booking_confirmation/of/' . $hash);
        self::assertSame(307, $confirmation->statusCode);
        self::assertStringNotContainsString($fixture->run, $confirmation->body);
        $ics = $client->get('appointments/ics/' . $hash);
        self::assertSame(404, $ics->statusCode);
        self::assertStringNotContainsString('text/calendar', strtolower((string) $ics->header('content-type')));
        self::assertNull($ics->header('content-disposition'));
        self::assertStringNotContainsString($fixture->run, $ics->body);
    }

    public function testExternalCalendarLinksKeepEventDataWithoutCapabilityOrAttendeeDisclosure(): void
    {
        $fixture = $this->fixture;
        $appointment = $fixture->appointment();
        $hash = (string) $appointment['hash'];
        $provider = $fixture->row('users', $fixture->providerId);
        $customer = $fixture->row('users', $fixture->customerId);
        $client = $this->anonymousClient();

        $response = $client->get('booking_confirmation/of/' . $hash);
        self::assertSame(200, $response->statusCode, 'Owned confirmation must be available.');

        $links = $this->externalCalendarLinks($response->body);
        self::assertArrayHasKey('google', $links, 'Confirmation must expose the Google calendar link.');
        self::assertArrayHasKey('outlook', $links, 'Confirmation must expose the Outlook calendar link.');

        $start = new DateTimeImmutable((string) $appointment['start_datetime'], new DateTimeZone('UTC'));
        $end = new DateTimeImmutable((string) $appointment['end_datetime'], new DateTimeZone('UTC'));
        $googleQuery = $this->queryParameters($links['google']);
        $outlookQuery = $this->queryParameters($links['outlook']);

        self::assertTrue(
            parse_url($links['google'], PHP_URL_HOST) === 'calendar.google.com',
            'Google link must use the external calendar host.',
        );
        self::assertTrue(
            parse_url($links['outlook'], PHP_URL_HOST) === 'outlook.office.com',
            'Outlook link must use the external calendar host.',
        );
        self::assertTrue(($googleQuery['action'] ?? null) === 'TEMPLATE', 'Google link must retain its event action.');
        self::assertTrue(
            ($googleQuery['dates'] ?? null) === $start->format('Ymd\THis\Z') . '/' . $end->format('Ymd\THis\Z'),
            'Google link must retain the appointment interval.',
        );
        self::assertTrue(
            ($outlookQuery['path'] ?? null) === '/calendar/action/compose',
            'Outlook link must retain its compose path.',
        );
        self::assertTrue(
            ($outlookQuery['startdt'] ?? null) === $start->format(DateTimeInterface::ATOM),
            'Outlook link must retain the appointment start.',
        );
        self::assertTrue(
            ($outlookQuery['enddt'] ?? null) === $end->format(DateTimeInterface::ATOM),
            'Outlook link must retain the appointment end.',
        );
        self::assertTrue(
            ($googleQuery['text'] ?? null) === (string) $fixture->run,
            'Google link must retain the synthetic event title.',
        );
        self::assertTrue(
            ($outlookQuery['subject'] ?? null) === (string) $fixture->run,
            'Outlook link must retain the synthetic event title.',
        );

        foreach ($links as $link) {
            $decodedLink = rawurldecode($link);
            self::assertTrue(
                !str_contains($decodedLink, $hash) && !str_contains($decodedLink, '/booking/reschedule/'),
                'External calendar links must not contain the appointment management capability.',
            );
            foreach ([(string) ($provider['email'] ?? ''), (string) ($customer['email'] ?? '')] as $email) {
                if ($email === '') {
                    continue;
                }
                self::assertTrue(
                    !str_contains($decodedLink, $email),
                    'External calendar links must not disclose attendee email addresses.',
                );
            }
        }
    }

    public function testConfirmationJsonRoundTripsOwnedNamesWithoutScriptBreakout(): void
    {
        $fixture = $this->fixture;
        $serviceName = 'Musik & Mathe – Jörg </ScRiPt><script>alert("service")</script> 🚀';
        $providerLastName = 'O\'Connor </sCrIpT><img src=x onerror=alert(1)> – 李';
        $db = get_instance()->db;
        self::assertTrue($db->update('services', ['name' => $serviceName], ['id' => $fixture->serviceId]));
        self::assertTrue($db->update('users', ['last_name' => $providerLastName], ['id' => $fixture->providerId]));

        $appointment = $fixture->appointment();
        $hash = (string) $appointment['hash'];
        self::assertSame(64, strlen($hash));

        $response = $this->anonymousClient()->get('booking_confirmation/of/' . $hash);
        self::assertSame(200, $response->statusCode);
        self::assertStringStartsWith('text/html', strtolower((string) $response->header('content-type')));
        self::assertStringContainsString('data-generate-pdf', $response->body);
        self::assertStringContainsString('data-share-url', $response->body);
        self::assertStringContainsString('/booking/reschedule/' . $hash, $response->body);

        $sharePayload = $this->embeddedJson($response->body, 'sharePayload');
        $pdfData = $this->embeddedJson($response->body, 'appointmentPdfData');
        self::assertSame($serviceName . ' – Synthetic ' . $providerLastName, $sharePayload['title']);
        self::assertStringContainsString($serviceName, $sharePayload['title']);
        self::assertStringContainsString($providerLastName, $sharePayload['title']);
        self::assertSame($serviceName, $pdfData['title']);
        self::assertSame('Synthetic ' . $providerLastName, $pdfData['teacher']);
        self::assertStringContainsString('/booking/reschedule/' . $hash, $sharePayload['url']);
        self::assertStringContainsString('/booking/reschedule/' . $hash, $pdfData['manageUrl']);
        self::assertStringNotContainsString(
            '</script',
            strtolower($this->embeddedJsonLiteral($response->body, 'sharePayload')),
        );
        self::assertStringNotContainsString(
            '</script',
            strtolower($this->embeddedJsonLiteral($response->body, 'appointmentPdfData')),
        );
        self::assertStringNotContainsString(
            '<script>alert("service")',
            strtolower($this->embeddedJsonLiteral($response->body, 'sharePayload')),
        );
        self::assertStringNotContainsString(
            '<img src=x onerror=',
            strtolower($this->embeddedJsonLiteral($response->body, 'appointmentPdfData')),
        );
    }

    public function testUnknownAndMalformedHashesDoNotExposeOwnedDownloadData(): void
    {
        $fixture = $this->fixture;
        $modern = $fixture->appointment();
        $this->moveAppointmentToDistinctSlot((int) $modern['id'], '+15 days');
        $modern = $fixture->row('appointments', (int) $modern['id']);
        $legacy = $fixture->appointment(true);
        $modernHash = (string) $modern['hash'];
        $legacyHash = (string) $legacy['hash'];
        $ownedSnapshots = $this->ownedSnapshots($modern, $legacy);
        $client = $this->anonymousClient();

        foreach (['', str_repeat('a', 64), str_repeat('b', 12), 'malformed'] as $hash) {
            $confirmation = $client->get('booking_confirmation/of/' . $hash);
            self::assertSame(307, $confirmation->statusCode, $hash . ' confirmation must preserve its redirect.');
            self::assertStringContainsString('/appointments', (string) $confirmation->header('location'));
            self::assertStringNotContainsString($fixture->run, $confirmation->body);
            self::assertStringNotContainsString('data-generate-pdf', $confirmation->body);
            self::assertStringNotContainsString($modernHash, $confirmation->body);
            self::assertStringNotContainsString($legacyHash, $confirmation->body);
            self::assertStringNotContainsString('/booking/reschedule/', $confirmation->body);
            $this->assertOwnedSnapshotsUnchanged($ownedSnapshots);

            $ics = $client->get('appointments/ics/' . $hash);
            self::assertSame(
                404,
                $ics->statusCode,
                $hash . ' ICS must return the source-defined missing-hash response.',
            );
            self::assertStringNotContainsString('text/calendar', strtolower((string) $ics->header('content-type')));
            self::assertNull($ics->header('content-disposition'));
            $unfoldedIcs = preg_replace("/\r?\n[ \t]/", '', $ics->body);
            self::assertIsString($unfoldedIcs);
            foreach ([$fixture->run, $modernHash, $legacyHash] as $value) {
                self::assertStringNotContainsString($value, $ics->body);
                self::assertStringNotContainsString($value, $unfoldedIcs);
            }
            $this->assertOwnedSnapshotsUnchanged($ownedSnapshots);
        }
    }

    public function testMissingHashCannotSelectAnAppointmentWithoutACapability(): void
    {
        $fixture = $this->fixture;
        $appointment = $fixture->appointment();
        $client = $this->anonymousClient();
        foreach ([null, ''] as $storedHash) {
            self::assertTrue(
                get_instance()->db->update('appointments', ['hash' => $storedHash], ['id' => $appointment['id']]),
            );
            $before = $fixture->row('appointments', (int) $appointment['id']);
            $confirmation = $client->get('booking_confirmation/of/');
            self::assertSame(307, $confirmation->statusCode);
            self::assertStringContainsString('/appointments', (string) $confirmation->header('location'));
            self::assertStringNotContainsString($fixture->run, $confirmation->body);
            self::assertStringNotContainsString('data-generate-pdf', $confirmation->body);
            self::assertSame($before, $fixture->row('appointments', (int) $appointment['id']));
            $ics = $client->get('appointments/ics/');
            self::assertSame(404, $ics->statusCode);
            self::assertStringNotContainsString($fixture->run, $ics->body);
            self::assertNull($ics->header('content-disposition'));
            self::assertSame($before, $fixture->row('appointments', (int) $appointment['id']));
        }
    }

    private function anonymousClient(): GateHttpClient
    {
        // Additional headers disable redirect-following in GateHttpClient, exposing the denial response.
        return new GateHttpClient($this->server->baseUrl, additionalHeaders: ['Accept' => 'text/html']);
    }

    /** @return array{google?: string, outlook?: string} */
    private function externalCalendarLinks(string $html): array
    {
        preg_match_all('~href="(https://(?:calendar\.google\.com|outlook\.office\.com)[^"]+)"~i', $html, $matches);
        $links = [];
        foreach ($matches[1] ?? [] as $rawLink) {
            $link = html_entity_decode($rawLink, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $host = parse_url($link, PHP_URL_HOST);
            if ($host === 'calendar.google.com') {
                self::assertArrayNotHasKey('google', $links, 'Confirmation must not render a second Google link.');
                $links['google'] = $link;
            } elseif ($host === 'outlook.office.com') {
                self::assertArrayNotHasKey('outlook', $links, 'Confirmation must not render a second Outlook link.');
                $links['outlook'] = $link;
            }
        }
        return $links;
    }

    /** @return array<string, string> */
    private function queryParameters(string $link): array
    {
        $query = parse_url($link, PHP_URL_QUERY);
        $parameters = [];
        parse_str(is_string($query) ? $query : '', $parameters);
        return array_map(static fn($value): string => (string) $value, $parameters);
    }

    private function moveAppointmentToDistinctSlot(int $id, string $dayOffset): void
    {
        $db = get_instance()->db;
        self::assertTrue(
            $db->update(
                'appointments',
                [
                    'start_datetime' => date('Y-m-d 10:00:00', strtotime($dayOffset)),
                    'end_datetime' => date('Y-m-d 10:30:00', strtotime($dayOffset)),
                ],
                ['id' => $id],
            ),
        );
    }

    /** @return array<string, array<string, mixed>> */
    private function ownedSnapshots(array $modern, array $legacy): array
    {
        $fixture = $this->fixture;
        return [
            'modern' => $fixture->row('appointments', (int) $modern['id']),
            'legacy' => $fixture->row('appointments', (int) $legacy['id']),
            'customer' => $fixture->row('users', $fixture->customerId),
            'provider' => $fixture->row('users', $fixture->providerId),
            'service' => $fixture->row('services', $fixture->serviceId),
        ];
    }

    /** @param array<string, array<string, mixed>> $snapshots */
    private function assertOwnedSnapshotsUnchanged(array $snapshots): void
    {
        $fixture = $this->fixture;
        self::assertSame($snapshots['modern'], $fixture->row('appointments', (int) $snapshots['modern']['id']));
        self::assertSame($snapshots['legacy'], $fixture->row('appointments', (int) $snapshots['legacy']['id']));
        self::assertSame($snapshots['customer'], $fixture->row('users', $fixture->customerId));
        self::assertSame($snapshots['provider'], $fixture->row('users', $fixture->providerId));
        self::assertSame($snapshots['service'], $fixture->row('services', $fixture->serviceId));
    }

    /** @return array<string, mixed> */
    private function embeddedJson(string $html, string $name): array
    {
        $decoded = json_decode($this->embeddedJsonLiteral($html, $name), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded, $name . ' must decode to an object.');
        return $decoded;
    }

    private function embeddedJsonLiteral(string $html, string $name): string
    {
        $pattern = '/const ' . preg_quote($name, '/') . ' = (?<json>\{.*?\});/s';
        self::assertSame(1, preg_match($pattern, $html, $matches), $name . ' JSON literal must be present.');
        self::assertArrayHasKey('json', $matches);
        return $matches['json'];
    }
}
