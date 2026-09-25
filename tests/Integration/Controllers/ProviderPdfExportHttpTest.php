<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ReleaseGate\GateHttpClient;
use Tests\Integration\Support\DefenseCycleFixtures;
use Tests\Integration\Support\DefenseCycleHttpServer;
use Tests\Integration\Support\ProviderPdfExportTestSupport;
use Tests\Integration\Support\ProviderPdfRecordingRenderer;

require_once dirname(__DIR__) . '/Support/DefenseCycleFixtures.php';
require_once dirname(__DIR__) . '/Support/DefenseCycleHttpServer.php';
require_once dirname(__DIR__) . '/Support/ProviderPdfExportTestSupport.php';

/** Isolated provider PDF ownership, authentication, and method regression. */
final class ProviderPdfExportHttpTest extends TestCase
{
    private ?DefenseCycleFixtures $fixture = null;
    private ?ProviderPdfExportTestSupport $peer = null;
    private ?DefenseCycleHttpServer $server = null;
    private ?ProviderPdfRecordingRenderer $renderer = null;
    private int $baseAppointmentId = 0;
    private string $periodStart = '';
    private string $periodEnd = '';

    protected function setUp(): void
    {
        if (getenv('FH_DEFENSE_ISOLATED') !== '1') {
            self::markTestSkipped('Run scripts/ci/run_defense_cycle.sh with its fresh synthetic stack.');
        }
        try {
            $monday = new DateTimeImmutable('next monday');
            $this->periodStart = $monday->format('Y-m-d');
            $this->periodEnd = $monday->modify('+4 days')->format('Y-m-d');
            $this->fixture = new DefenseCycleFixtures();
            $this->fixture->create();
            $this->baseAppointmentId = (int) ($this->fixture->appointment()['id'] ?? 0);
            self::assertTrue(
                get_instance()->db->update('appointments', ['status' => 'Booked'], ['id' => $this->baseAppointmentId]),
            );
            self::assertTrue(
                get_instance()->db->update(
                    'users',
                    ['last_name' => $this->fixture->run . '_parent'],
                    ['id' => $this->fixture->customerId],
                ),
            );
            $this->peer = new ProviderPdfExportTestSupport($this->fixture);
            $this->moveBaseAppointmentToTuesday();
            $this->renderer = new ProviderPdfRecordingRenderer();
            putenv('PDF_RENDERER_URL=' . $this->renderer->baseUrl);
            $this->server = new DefenseCycleHttpServer();
        } catch (Throwable $error) {
            $this->server?->close();
            $this->renderer?->close();
            $this->peer?->cleanup();
            $this->fixture?->cleanup();
            throw $error;
        }
    }

    protected function tearDown(): void
    {
        try {
            $this->server?->close();
            $this->renderer?->close();
        } finally {
            putenv('PDF_RENDERER_URL');
            try {
                $this->peer?->cleanup();
            } finally {
                $this->fixture?->cleanup();
            }
        }
    }

    public function testBothProviderPdfRoutesBindSessionProviderAndRejectForeignAndUnauthenticatedRequests(): void
    {
        $fixture = $this->fixture;
        $peer = $this->peer;
        $provider = $this->login($this->fixture->run . '_provider', $fixture->password);
        $peerClient = $this->login($peer->providerUsername, $peer->password);
        $routes = [
            'dashboard/export/provider-parent-appointments.pdf',
            'dashboard/export/provider-preparation.pdf',
            'dashboard_export/provider_parent_appointments_pdf',
            'dashboard_export/provider_preparation_pdf',
        ];
        $range = '?start_date=' . $this->periodStart . '&end_date=' . $this->periodEnd;
        $before = $this->ownedSnapshots();

        foreach ([$provider, $peerClient] as $index => $client) {
            $ownName = $index === 0 ? $fixture->run . '_parent' : $peer->run . '_parent';
            $otherName = $index === 0 ? $peer->run . '_parent' : $fixture->run . '_parent';
            foreach ($routes as $route) {
                $response = $client->get(
                    $route . $range . '&provider_id=' . ($index === 0 ? $peer->providerId : $fixture->providerId),
                );
                self::assertSame(200, $response->statusCode, $route);
                self::assertSame('application/pdf', strtolower((string) $response->header('content-type')));
                $this->assertRendererPayloadContainsOwnData($ownName, $otherName);
            }
        }

        $anonymous = $this->server->client();
        $admin = $this->login($fixture->run . '_actor', $fixture->password);
        $callsBeforeDenied = count($this->renderer->calls());
        foreach ([$anonymous, $admin] as $client) {
            foreach ($routes as $route) {
                $response = $client->get($route . $range);
                self::assertSame(403, $response->statusCode, $route);
                self::assertStringNotContainsString('%PDF-FAKE-ROB628', $response->body);
            }
        }
        self::assertCount($callsBeforeDenied, $this->renderer->calls(), 'Denied identities must not call renderer.');
        $callsBeforeMethods = count($this->renderer->calls());
        foreach ([$provider, $peerClient] as $client) {
            foreach (array_slice($routes, 2) as $route) {
                foreach (['HEAD', 'PUT', 'DELETE'] as $method) {
                    $response = $client->requestApp($method, $route . $range);
                    self::assertSame(405, $response->statusCode, $method . ' ' . $route);
                    self::assertSame('GET', $response->header('allow'));
                }
            }
        }
        self::assertCount($callsBeforeMethods, $this->renderer->calls(), 'Rejected methods must not call renderer.');
        self::assertSame($before, $this->ownedSnapshots(), 'Rejected requests must not mutate owned rows.');
    }

    public function testRevokedProviderRoleCannotExportWithOldSession(): void
    {
        $fixture = $this->fixture;
        $provider = $this->login($fixture->run . '_provider', $fixture->password);
        $db = get_instance()->db;
        $customerRole = $db->get_where('roles', ['slug' => 'customer'])->row_array();
        $providerRole = $db->get_where('roles', ['slug' => 'provider'])->row_array();
        self::assertIsArray($customerRole);
        self::assertIsArray($providerRole);

        $before = $this->ownedSnapshots();
        $callsBefore = count($this->renderer->calls());
        self::assertTrue($db->update('users', ['id_roles' => $customerRole['id']], ['id' => $fixture->providerId]));
        try {
            foreach (
                ['dashboard/export/provider-parent-appointments.pdf', 'dashboard/export/provider-preparation.pdf']
                as $route
            ) {
                $response = $provider->get($route, [
                    'start_date' => $this->periodStart,
                    'end_date' => $this->periodEnd,
                ]);
                self::assertSame(403, $response->statusCode, $route . ' must honor current database role.');
            }
            self::assertCount($callsBefore, $this->renderer->calls());
            self::assertSame($before, $this->ownedSnapshots());
        } finally {
            self::assertTrue($db->update('users', ['id_roles' => $providerRole['id']], ['id' => $fixture->providerId]));
        }
    }

    public function testProviderPdfRoutesDoNotPersistSensitiveHtmlWhenDebugFlagIsEnabled(): void
    {
        $root = dirname(__DIR__, 3);
        $paths = [
            $root . '/storage/logs/provider_parent_appointments_pdf_dump.html',
            $root . '/storage/logs/provider_preparation_pdf_dump.html',
        ];
        foreach ($paths as $path) {
            self::assertFileDoesNotExist($path, 'The isolated test must not overwrite an existing dump.');
        }

        $previousFlag = getenv('PDF_RENDERER_DEBUG_DUMP');
        $unexpectedDumps = [];
        $this->server?->close();
        $this->server = null;
        putenv('PDF_RENDERER_DEBUG_DUMP=true');
        try {
            $this->server = new DefenseCycleHttpServer();
            $provider = $this->login($this->fixture->run . '_provider', $this->fixture->password);
            foreach (
                ['dashboard/export/provider-parent-appointments.pdf', 'dashboard/export/provider-preparation.pdf']
                as $route
            ) {
                $response = $provider->get($route, [
                    'start_date' => $this->periodStart,
                    'end_date' => $this->periodEnd,
                ]);
                self::assertSame(200, $response->statusCode, $route);
            }
        } finally {
            try {
                foreach ($paths as $path) {
                    if (!file_exists($path) && !is_link($path)) {
                        continue;
                    }
                    $unexpectedDumps[] = basename($path);
                    if (is_link($path) || !is_file($path)) {
                        throw new RuntimeException('Unsafe PDF debug dump cleanup target.');
                    }
                    $html = file_get_contents($path);
                    if (!is_string($html) || !str_contains($html, $this->fixture->run . '_parent') || !unlink($path)) {
                        throw new RuntimeException('Synthetic PDF debug dump cleanup could not be verified.');
                    }
                }
            } finally {
                $this->server?->close();
                $this->server = null;
                if ($previousFlag === false) {
                    putenv('PDF_RENDERER_DEBUG_DUMP');
                } else {
                    putenv('PDF_RENDERER_DEBUG_DUMP=' . $previousFlag);
                }
            }
        }
        self::assertSame([], $unexpectedDumps, 'Provider PDF exports must not leave fixed HTML debug dumps.');
    }

    private function login(string $username, string $password): GateHttpClient
    {
        $client = $this->server->client();
        self::assertSame(200, $client->get('login')->statusCode);
        $response = $client->post('login/validate', ['username' => $username, 'password' => $password]);
        self::assertSame(200, $response->statusCode);
        self::assertTrue((bool) (json_decode($response->body, true, 512, JSON_THROW_ON_ERROR)['success'] ?? false));
        return $client;
    }

    private function moveBaseAppointmentToTuesday(): void
    {
        self::assertTrue(
            get_instance()->db->update(
                'appointments',
                [
                    'start_datetime' =>
                        (new DateTimeImmutable($this->periodStart))->modify('+1 day')->format('Y-m-d') . ' 11:00:00',
                    'end_datetime' =>
                        (new DateTimeImmutable($this->periodStart))->modify('+1 day')->format('Y-m-d') . ' 11:30:00',
                ],
                ['id' => $this->baseAppointmentId],
            ),
        );
    }

    private function assertRendererPayloadContainsOwnData(string $ownName, string $otherRun): void
    {
        $calls = $this->renderer->calls();
        self::assertNotEmpty($calls);
        $html = (string) ($calls[array_key_last($calls)]['html'] ?? '');
        self::assertTrue(str_contains($html, $ownName), 'Owned parent must reach the PDF renderer.');
        self::assertFalse(str_contains($html, $otherRun), 'Another provider parent must not reach the PDF renderer.');
    }

    private function ownedSnapshots(): array
    {
        $db = get_instance()->db;
        return [
            'base' => $db->get_where('appointments', ['id' => $this->baseAppointmentId])->row_array(),
            'peer' => $db->get_where('appointments', ['id' => $this->peer->appointmentId])->row_array(),
        ];
    }
}
