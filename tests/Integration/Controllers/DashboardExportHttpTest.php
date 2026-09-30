<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ReleaseGate\GateHttpClient;
use Tests\Integration\Support\DefenseCycleFixtures;
use Tests\Integration\Support\DefenseCycleHttpServer;
use Tests\Integration\Support\ProviderPdfRecordingRenderer;

require_once dirname(__DIR__) . '/Support/DefenseCycleFixtures.php';
require_once dirname(__DIR__) . '/Support/DefenseCycleHttpServer.php';
require_once dirname(__DIR__) . '/Support/ProviderPdfExportTestSupport.php';

/** Isolated HTTP and DB authority regression coverage for dashboard exports. */
final class DashboardExportHttpTest extends TestCase
{
    private ?DefenseCycleFixtures $fixture = null;
    private ?DefenseCycleHttpServer $server = null;
    private ?ProviderPdfRecordingRenderer $renderer = null;
    private string $periodStart = '';
    private string $periodEnd = '';
    private string $originalCustomerEmail = '';
    private string $sensitiveCustomerEmail = '';
    private string $sensitiveCustomerPhone = '';
    private string $originalProviderEmail = '';
    private string $sensitiveProviderEmail = '';
    private int $baseAppointmentId = 0;

    protected function setUp(): void
    {
        if (getenv('FH_DEFENSE_ISOLATED') !== '1') {
            self::markTestSkipped('Run scripts/ci/run_defense_cycle.sh with its fresh synthetic stack.');
        }

        try {
            $this->fixture = new DefenseCycleFixtures();
            $this->fixture->create();
            $this->originalCustomerEmail = $this->fixture->run . '_customer@synthetic.invalid';
            $this->sensitiveCustomerEmail = $this->fixture->run . '_contact@example.invalid';
            $this->sensitiveCustomerPhone = $this->fixture->run . '_phone';
            $this->originalProviderEmail = $this->fixture->run . '_provider@synthetic.invalid';
            $this->sensitiveProviderEmail = $this->fixture->run . '_teacher@example.invalid';
            self::assertTrue(
                get_instance()->db->update(
                    'users',
                    [
                        'first_name' => '',
                        'last_name' => '',
                        'email' => $this->sensitiveCustomerEmail,
                        'phone_number' => $this->sensitiveCustomerPhone,
                    ],
                    ['id' => $this->fixture->customerId],
                ),
            );
            self::assertTrue(
                get_instance()->db->update(
                    'users',
                    [
                        'first_name' => '',
                        'last_name' => '',
                        'email' => $this->sensitiveProviderEmail,
                    ],
                    ['id' => $this->fixture->providerId],
                ),
            );
            $appointment = $this->fixture->appointment();
            $this->baseAppointmentId = (int) ($appointment['id'] ?? 0);
            self::assertGreaterThan(0, $this->baseAppointmentId);
            $appointmentDate = (string) ($appointment['start_datetime'] ?? '');
            self::assertNotSame('', $appointmentDate);
            $monday = (new DateTimeImmutable($appointmentDate))->modify('monday this week');
            $this->periodStart = $monday->format('Y-m-d');
            $this->periodEnd = $monday->modify('+4 days')->format('Y-m-d');
            self::assertTrue(
                get_instance()->db->update('appointments', ['status' => 'Booked'], ['id' => $appointment['id']]),
            );
            $this->renderer = new ProviderPdfRecordingRenderer();
            putenv('PDF_RENDERER_URL=' . $this->renderer->baseUrl);
            $this->server = new DefenseCycleHttpServer();
        } catch (Throwable $error) {
            $this->server?->close();
            $this->renderer?->close();
            $this->restoreSyntheticIdentities();
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
            $this->restoreSyntheticIdentities();
            $this->fixture?->cleanup();
        }
    }

    public function testAdminExportsExcludeContactFieldsFromRendererInput(): void
    {
        foreach ($this->adminExportRendererCalls() as $call) {
            $html = (string) ($call['html'] ?? '');
            self::assertStringNotContainsString($this->sensitiveCustomerEmail, $html);
            self::assertStringNotContainsString($this->sensitiveCustomerPhone, $html);
        }
    }

    public function testAdminExportsExcludeUnnamedProviderEmailFromRendererInput(): void
    {
        foreach ($this->adminExportRendererCalls() as $call) {
            self::assertStringNotContainsString($this->sensitiveProviderEmail, (string) ($call['html'] ?? ''));
        }
    }

    public function testAdminExportsRequireCurrentDatabaseAdminRoleAfterSessionCreation(): void
    {
        $fixture = $this->fixture;
        $client = $this->login($fixture->run . '_actor', $fixture->password);
        $db = get_instance()->db;
        $customerRole = $db->get_where('roles', ['slug' => 'customer'])->row_array();
        $adminRole = $db->get_where('roles', ['slug' => 'admin'])->row_array();
        self::assertIsArray($customerRole);
        self::assertIsArray($adminRole);
        $before = $this->ownedSnapshots();
        self::assertTrue($db->update('users', ['id_roles' => $customerRole['id']], ['id' => $fixture->actorId]));
        try {
            $callsBeforeDenied = count($this->renderer->calls());
            foreach ($this->exportRoutes() as $route) {
                $response = $client->get($route, $this->periodQuery());
                self::assertSame(403, $response->statusCode, $route . ' must honor the current database role.');
            }
            self::assertCount($callsBeforeDenied, $this->renderer->calls());
            self::assertSame($before, $this->ownedSnapshots());
        } finally {
            self::assertTrue($db->update('users', ['id_roles' => $adminRole['id']], ['id' => $fixture->actorId]));
        }
    }

    public function testDashboardExportAliasesRejectAnonymousProviderAndNonGetRequestsBeforeRendering(): void
    {
        $fixture = $this->fixture;
        $anonymous = $this->server->client();
        $provider = $this->login($fixture->run . '_provider', $fixture->password);
        $admin = $this->login($fixture->run . '_actor', $fixture->password);
        $routes = $this->exportAliases();
        $callsBeforeDenied = count($this->renderer->calls());

        foreach ([$anonymous, $provider] as $client) {
            foreach ($routes as $route) {
                $response = $client->get($route, $this->periodQuery());
                self::assertSame(403, $response->statusCode, $route);
            }
        }
        self::assertCount($callsBeforeDenied, $this->renderer->calls());

        $before = $this->ownedSnapshots();
        $observed = [];
        foreach ($routes as $route) {
            foreach (['HEAD', 'POST', 'PUT', 'DELETE'] as $method) {
                $response = $admin->requestApp($method, $route, $this->periodQuery(), null, $method === 'POST');
                $observed[$route][$method] = [$response->statusCode, $response->header('allow')];
            }
        }
        foreach ($observed as $route => $methods) {
            foreach ($methods as $method => [$status, $allow]) {
                self::assertSame([405, 'GET'], [$status, $allow], $method . ' ' . $route);
            }
        }
        self::assertCount($callsBeforeDenied, $this->renderer->calls());
        self::assertSame($before, $this->ownedSnapshots());
    }

    public function testAdminPdfExportsDoNotPersistFixedHtmlDebugDumpsWhenDebugFlagIsEnabled(): void
    {
        $paths = [
            dirname(__DIR__, 3) . '/storage/logs/dashboard_principal_pdf_dump.html',
            dirname(__DIR__, 3) . '/storage/logs/dashboard_teacher_pdf_dump.html',
        ];
        foreach ($paths as $path) {
            self::assertFileDoesNotExist($path, 'The isolated test must not overwrite an existing dump.');
        }

        $previousFlag = getenv('PDF_RENDERER_DEBUG_DUMP');
        $created = [];
        $this->server?->close();
        $this->server = null;
        putenv('PDF_RENDERER_DEBUG_DUMP=true');
        try {
            // The real HTTP child must inherit the flag; changing PHPUnit's environment
            // after its server starts would silently leave this regression untested.
            $this->server = new DefenseCycleHttpServer();
            $client = $this->login($this->fixture->run . '_actor', $this->fixture->password);
            foreach (['dashboard/export/principal.pdf', 'dashboard/export/teacher.pdf'] as $route) {
                self::assertSame(200, $client->get($route, $this->periodQuery())->statusCode, $route);
            }
        } finally {
            try {
                foreach ($paths as $path) {
                    if (!file_exists($path) && !is_link($path)) {
                        continue;
                    }
                    $created[] = basename($path);
                    self::assertFileIsReadable($path);
                    $html = file_get_contents($path);
                    self::assertIsString($html);
                    self::assertStringContainsString($this->fixture->run, $html);
                    self::assertTrue(unlink($path), 'Synthetic debug dump cleanup must succeed.');
                }
            } finally {
                if ($previousFlag === false) {
                    putenv('PDF_RENDERER_DEBUG_DUMP');
                } else {
                    putenv('PDF_RENDERER_DEBUG_DUMP=' . $previousFlag);
                }
            }
        }
        self::assertSame([], $created, 'Admin exports must not leave fixed HTML debug dumps.');
    }

    public function testAdminExportProviderFilterExcludesPeerProviderFromPdfAndZipRendererInput(): void
    {
        $fixture = $this->fixture;
        $peer = null;
        $before = $this->ownedSnapshots();
        try {
            $peer = new Tests\Integration\Support\ProviderPdfExportTestSupport($fixture);
            $baseAppointment = get_instance()
                ->db->get_where('appointments', ['id' => $this->baseAppointmentId])
                ->row_array();
            self::assertIsArray($baseAppointment);
            self::assertTrue(
                get_instance()->db->update(
                    'appointments',
                    [
                        'start_datetime' => $baseAppointment['start_datetime'],
                        'end_datetime' => $baseAppointment['end_datetime'],
                    ],
                    ['id' => $peer->appointmentId],
                ),
            );

            $client = $this->login($fixture->run . '_actor', $fixture->password);
            $query = $this->periodQuery();
            $query['provider_ids'] = [(string) $fixture->providerId];
            $callsBefore = count($this->renderer->calls());
            foreach (
                ['dashboard/export/principal.pdf', 'dashboard/export/teacher.pdf', 'dashboard/export/teacher.zip']
                as $route
            ) {
                self::assertSame(200, $client->get($route, $query)->statusCode, $route);
                $calls = $this->renderer->calls();
                self::assertSame(
                    1,
                    count($calls) - $callsBefore,
                    $route . ' must render exactly one selected provider output.',
                );
                foreach (array_slice($calls, $callsBefore) as $call) {
                    $html = (string) ($call['html'] ?? '');
                    $selectedName = (string) $fixture->providerId;
                    self::assertTrue(
                        str_contains($html, '<div class="name">' . $selectedName . '</div>') ||
                            str_contains($html, '<h2 class="teacher__name">' . $selectedName . '</h2>'),
                        $route . ' must include the selected provider.',
                    );
                    self::assertStringNotContainsString($peer->run, $html, $route);
                    self::assertStringNotContainsString($peer->run . '_parent', $html, $route);
                }
                $callsBefore = count($calls);
            }
            self::assertSame($before, $this->ownedSnapshots());
        } finally {
            $peer?->cleanup();
        }
    }

    /** @return list<string> */
    private function exportAliases(): array
    {
        return ['dashboard_export/principal_pdf', 'dashboard_export/teacher_pdf', 'dashboard_export/teacher_zip'];
    }

    /** @return list<string> */
    private function exportRoutes(): array
    {
        return [
            'dashboard/export/principal.pdf',
            'dashboard/export/teacher.pdf',
            'dashboard/export/teacher.zip',
            ...$this->exportAliases(),
        ];
    }

    /** @return array{start_date:string,end_date:string} */
    private function periodQuery(): array
    {
        return ['start_date' => $this->periodStart, 'end_date' => $this->periodEnd];
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

    private function adminExportRendererCalls(): array
    {
        $client = $this->login($this->fixture->run . '_actor', $this->fixture->password);
        foreach ($this->exportRoutes() as $route) {
            self::assertSame(200, $client->get($route, $this->periodQuery())->statusCode, $route);
        }
        $calls = $this->renderer->calls();
        self::assertNotEmpty($calls, 'Successful exports must reach the recording renderer.');
        return $calls;
    }

    private function restoreSyntheticIdentities(): void
    {
        if ($this->fixture === null || $this->fixture->customerId <= 0 || $this->originalCustomerEmail === '') {
            return;
        }
        get_instance()->db->update(
            'users',
            [
                'first_name' => 'Synthetic',
                'last_name' => 'customer',
                'email' => $this->originalCustomerEmail,
                'phone_number' => '000000000',
            ],
            ['id' => $this->fixture->customerId, 'email' => $this->sensitiveCustomerEmail],
        );
        get_instance()->db->update(
            'users',
            [
                'first_name' => 'Synthetic',
                'last_name' => 'provider',
                'email' => $this->originalProviderEmail,
            ],
            ['id' => $this->fixture->providerId, 'email' => $this->sensitiveProviderEmail],
        );
    }

    private function ownedSnapshots(): array
    {
        $db = get_instance()->db;
        return [
            'provider' => $db->get_where('users', ['id' => $this->fixture->providerId])->row_array(),
            'customer' => $db->get_where('users', ['id' => $this->fixture->customerId])->row_array(),
            'appointments' => $db
                ->where('id_users_provider', $this->fixture->providerId)
                ->where('id_users_customer', $this->fixture->customerId)
                ->get('appointments')
                ->result_array(),
        ];
    }
}
