<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReleaseGate\BackofficeTargetReadProbe;
use ReleaseGate\DefenseVerificationFixture;
use ReleaseGate\GateHttpClient;
use ReleaseGate\OrdinaryLiveFixture;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__, 2) . '/scripts/release-gate/lib/GateHttpClient.php';
require_once dirname(__DIR__, 2) . '/scripts/release-gate/lib/OrdinaryLiveFixture.php';
require_once dirname(__DIR__, 2) . '/scripts/release-gate/lib/DefenseVerificationFixture.php';
require_once dirname(__DIR__, 2) . '/scripts/release-gate/lib/BackofficeTargetReadProbe.php';
require_once __DIR__ . '/Support/DefenseCycleHttpServer.php';

final class BackofficeTargetReadProbeTest extends TestCase
{
    private string $stateDirectory;
    private ?OrdinaryLiveFixture $actor = null;
    private ?DefenseVerificationFixture $targets = null;
    private ?DefenseCycleHttpServer $server = null;

    protected function setUp(): void
    {
        if (getenv('FH_DEFENSE_ISOLATED') !== '1' || !function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            self::markTestSkipped('Requires the explicitly isolated root Docker fixture run.');
        }
        $this->stateDirectory = '/var/lib/fh-backoffice-target-' . bin2hex(random_bytes(8));
        $this->actor = new OrdinaryLiveFixture($this->stateDirectory);
        $this->targets = new DefenseVerificationFixture($this->stateDirectory);
        $this->actor->activate(roleSlug: 'admin');
        $this->server = new DefenseCycleHttpServer();
    }

    protected function tearDown(): void
    {
        try {
            $this->server?->close();
        } finally {
            try {
                $this->targets?->deactivate();
            } finally {
                $this->actor?->deactivate();
                foreach (
                    ['state.json', 'lifecycle.lock', 'defense-verification.json', 'defense-verification.lock']
                    as $file
                ) {
                    $path = $this->stateDirectory . '/' . $file;
                    if (is_file($path) && !is_link($path)) {
                        unlink($path);
                    }
                }
                if (is_dir($this->stateDirectory) && !is_link($this->stateDirectory)) {
                    rmdir($this->stateDirectory);
                }
            }
        }
    }

    #[DataProvider('areas')]
    public function testOwnedProjectionIsBoundedAndSameSessionLosesReadAccess(string $area, string $profile): void
    {
        self::assertNotNull($this->actor);
        self::assertNotNull($this->targets);
        self::assertNotNull($this->server);
        $this->targets->activate($profile, $this->actor->read());
        $sessions = [];
        $privateClient = $this->client();
        $publicClient = $this->publicClient();
        $result = (new BackofficeTargetReadProbe(
            $privateClient,
            $publicClient,
            $this->actor,
            $this->targets,
            static function (?string $session) use (&$sessions): void {
                if ($session !== null && $session !== '') {
                    $sessions[] = $session;
                }
            },
        ))->run($area);

        self::assertSame('verified', $result['status']);
        self::assertSame(
            $area === 'secretaries' ? 'bounded_secretary_reads' : 'bounded_service_reads',
            $result['coverage'],
        );
        self::assertNotEmpty($sessions);
        if ($area === 'services') {
            self::assertNotNull($publicClient->getCookie('ea_session'));
            self::assertContains($publicClient->getCookie('ea_session'), $sessions);
            self::assertGreaterThanOrEqual(2, count(array_unique($sessions)));
        }
        self::assertSame('cleanup_pending', $this->actor->verify());
        self::assertSame('active', $this->targets->verify());
        $before = array_fill_keys(array_keys($result['before_statuses']), 200);
        $before['GET alias'] = 307;
        $before['POST alias'] = $area === 'secretaries' ? 303 : 405;
        self::assertSame($before, $result['before_statuses']);
        $after = array_fill_keys(array_keys($result['after_statuses']), 403);
        $after['GET alias'] = 307;
        $after['POST alias'] = $area === 'secretaries' ? 303 : 405;
        self::assertSame($after, $result['after_statuses']);
        self::assertSame($area === 'services' ? 'verified' : 'not_applicable', $result['public_service_exclusion']);
        self::assertSame($area === 'services' ? 200 : null, $result['public_booking_status']);
        self::assertSame(403, $result['calendar_status']);
        self::assertSame(200, $result['logout_status']);
        $this->targets->deactivate();
        $this->actor->deactivate();
        self::assertSame('clean', $this->targets->verify());
        self::assertSame('clean', $this->actor->verify());
    }

    public static function areas(): array
    {
        return [
            'secretaries' => ['secretaries', 'backoffice_secretary_read'],
            'services' => ['services', 'backoffice_service_read'],
        ];
    }

    public function testGenericSuccessPageCannotStandInForOwnedServiceIndex(): void
    {
        self::assertNotNull($this->actor);
        self::assertNotNull($this->targets);
        self::assertNotNull($this->server);
        $this->targets->activate('backoffice_service_read', $this->actor->read());
        $router = $this->server->directory . '/router.php';
        $source = file_get_contents($router);
        self::assertIsString($source);
        file_put_contents(
            $router,
            '<?php if (preg_match("~/(?:index\\.php/)?services/?$~", (string) ($_SERVER["REQUEST_URI"] ?? "")) === 1) { http_response_code(200); echo "generic success"; exit; } ?>' .
                "\n" .
                $source,
        );
        try {
            (new BackofficeTargetReadProbe($this->client(), $this->publicClient(), $this->actor, $this->targets))->run(
                'services',
            );
            self::fail('Generic HTTP success must not count as a Backoffice projection.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('did not render the expected Backoffice page', $error->getMessage());
        }
        self::assertSame('active', $this->actor->verify());
        self::assertSame('active', $this->targets->verify());
    }

    private function client(): GateHttpClient
    {
        self::assertNotNull($this->server);
        return new GateHttpClient(
            $this->server->baseUrl,
            indexPage: (string) config_item('index_page'),
            csrfCookieName: (string) config_item('csrf_cookie_name'),
            csrfTokenName: (string) config_item('csrf_token_name'),
            additionalHeaders: ['X-FH-Ordinary-Probe' => '1'],
        );
    }

    private function publicClient(): GateHttpClient
    {
        self::assertNotNull($this->server);
        return new GateHttpClient(
            $this->server->baseUrl,
            indexPage: (string) config_item('index_page'),
            csrfCookieName: (string) config_item('csrf_cookie_name'),
            csrfTokenName: (string) config_item('csrf_token_name'),
        );
    }
}
