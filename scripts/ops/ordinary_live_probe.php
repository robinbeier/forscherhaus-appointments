<?php

declare(strict_types=1);

use ReleaseGate\GateHttpClient;
use ReleaseGate\AccountSecurityMatrixProbe;
use ReleaseGate\CalendarResponsibilityRaceProbe;
use ReleaseGate\CustomerRoleBoundaryProbe;
use ReleaseGate\DefenseVerificationFixture;
use ReleaseGate\OrdinaryAccountProbe;
use ReleaseGate\OrdinaryLiveFixture;
use ReleaseGate\OrdinaryProbeSessions;
use ReleaseGate\OrdinarySessionProbe;
use ReleaseGate\OrdinaryProbeEvidence;
use ReleaseGate\UnconfirmedCalendarRequestTermination;

// Reviewed operator tool only. Never a web route, configurable HTTP target, or auth exception.
if (PHP_SAPI !== 'cli' || !function_exists('posix_geteuid') || posix_geteuid() !== 0) {
    fwrite(STDERR, "Root CLI required.\n");
    exit(77);
}
$options = getopt('', [
    'action:',
    'app-root:',
    'active-app-root:',
    'expected-app-identity:',
    'expected-release:',
    'fixture-role:',
]);
$action = $options['action'] ?? '';
$appRoot = $options['app-root'] ?? '/var/www/html/easyappointments';
$activeAppRoot = $options['active-app-root'] ?? $appRoot;
$expectedAppIdentity = $options['expected-app-identity'] ?? '';
$expectedRelease = $options['expected-release'] ?? '';
$fixtureRole = $options['fixture-role'] ?? 'provider';
if (
    !in_array(
        $action,
        [
            'preflight',
            'activate',
            'account',
            'methods',
            'customer-boundary',
            'calendar-race',
            'session',
            'deactivate',
            'verify',
        ],
        true,
    ) ||
    !is_string($appRoot) ||
    realpath($appRoot) !== $appRoot ||
    is_link($appRoot) ||
    !is_string($activeAppRoot) ||
    !str_starts_with($activeAppRoot, '/') ||
    !is_string($expectedAppIdentity) ||
    !preg_match('/\A[0-9]+:[0-9]+\z/D', $expectedAppIdentity) ||
    !preg_match('/\Aea_[a-zA-Z0-9_]+\z/D', $expectedRelease) ||
    !is_string($fixtureRole) ||
    !in_array($fixtureRole, ['provider', 'admin'], true)
) {
    fwrite(STDERR, "Valid action, canonical application root and expected release required.\n");
    exit(64);
}
umask(0077);
$stateDirectory = '/var/lib/fh-defense-ordinary';
$requestRecoveryPath = $stateDirectory . '/request-unconfirmed';
$requestRecoveryRequired = false;
$exitCode = 0;
$markRequestRecovery = static function () use ($stateDirectory, $requestRecoveryPath): void {
    if (!file_exists($requestRecoveryPath) && !is_link($requestRecoveryPath)) {
        if (!mkdir($requestRecoveryPath, 0700)) {
            throw new RuntimeException('Request recovery marker could not be created.');
        }
    }
    $stat = lstat($requestRecoveryPath);
    if (
        !is_array($stat) ||
        is_link($requestRecoveryPath) ||
        !is_dir($requestRecoveryPath) ||
        $stat['uid'] !== 0 ||
        ($stat['mode'] & 0777) !== 0700
    ) {
        throw new RuntimeException('Request recovery marker is unsafe.');
    }
    $directory = fopen($stateDirectory, 'r');
    if ($directory === false) {
        throw new RuntimeException('Request recovery directory is unavailable.');
    }
    try {
        if (!fsync($directory)) {
            throw new RuntimeException('Request recovery marker synchronization failed.');
        }
    } finally {
        fclose($directory);
    }
};
try {
    $assertActive = static function () use ($activeAppRoot, $expectedAppIdentity, $expectedRelease): void {
        clearstatcache(true, $activeAppRoot);
        $stat = @stat($activeAppRoot);
        if ($stat === false || is_link($activeAppRoot) || $stat['dev'] . ':' . $stat['ino'] !== $expectedAppIdentity) {
            throw new RuntimeException('Active application identity changed during the probe.');
        }
        $markerPath = $activeAppRoot . '/_RELEASE';
        if (
            is_link($markerPath) ||
            !is_file($markerPath) ||
            explode(' ', trim((string) file_get_contents($markerPath)))[0] !== $expectedRelease
        ) {
            throw new RuntimeException('Active application release changed during the probe.');
        }
    };
    if (!in_array($action, ['deactivate', 'verify'], true)) {
        $assertActive();
    }
    $rootStat = lstat($appRoot);
    if (!$rootStat || $rootStat['uid'] !== 0 || ($rootStat['mode'] & 0022) !== 0) {
        throw new RuntimeException('Application root is not root-controlled.');
    }
    $markerPath = $appRoot . '/_RELEASE';
    if (is_link($markerPath) || !is_file($markerPath)) {
        throw new RuntimeException('Installed release marker is unavailable.');
    }
    $marker = trim((string) file_get_contents($markerPath));
    if (explode(' ', $marker)[0] !== $expectedRelease) {
        throw new RuntimeException('Installed release changed; no test requests allowed.');
    }
    // Same read-only application bootstrap used by operator diagnostics. Discard endpoint output.
    $_SERVER['argv'] = ['index.php', 'healthz'];
    $_SERVER['argc'] = 2;
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/healthz';
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $_SERVER['HTTP_HOST'] = 'localhost';
    putenv('APP_ENV=production');
    chdir($appRoot);
    ob_start();
    require $appRoot . '/index.php';
    ob_end_clean();
    $ci = &get_instance();
    $ci->db->db_debug = false;
    require_once dirname(__DIR__) . '/release-gate/lib/GateHttpClient.php';
    require_once dirname(__DIR__) . '/release-gate/lib/OrdinaryLiveFixture.php';
    require_once dirname(__DIR__) . '/release-gate/lib/OrdinaryAccountProbe.php';
    require_once dirname(__DIR__) . '/release-gate/lib/OrdinaryProbeSessions.php';
    require_once dirname(__DIR__) . '/release-gate/lib/OrdinarySessionProbe.php';
    require_once dirname(__DIR__) . '/release-gate/lib/OrdinaryProbeEvidence.php';
    require_once dirname(__DIR__) . '/release-gate/lib/AccountSecurityMatrixProbe.php';
    require_once dirname(__DIR__) . '/release-gate/lib/CustomerRoleBoundaryProbe.php';
    require_once dirname(__DIR__) . '/release-gate/lib/CalendarResponsibilityRaceProbe.php';
    require_once dirname(__DIR__) . '/release-gate/lib/DefenseVerificationFixture.php';
    if (
        !in_array($action, ['deactivate', 'verify'], true) &&
        (config_item('sess_driver') !== 'files' ||
            config_item('sess_cookie_name') !== 'ea_session' ||
            config_item('sess_match_ip') ||
            realpath((string) config_item('sess_save_path')) !== $appRoot . '/storage/sessions')
    ) {
        throw new RuntimeException('Unsupported production session configuration.');
    }
    $expiration = (int) config_item('sess_expiration');
    if (!in_array($action, ['deactivate', 'verify'], true) && ($expiration < 1 || $expiration > 7200)) {
        throw new RuntimeException('Expiration exceeds the independently bounded fixture window.');
    }
    $fixture = new OrdinaryLiveFixture($stateDirectory);
    $verificationFixture = new DefenseVerificationFixture($stateDirectory);
    $sessions = new OrdinaryProbeSessions($stateDirectory, $appRoot . '/storage/sessions');
    $evidence = new OrdinaryProbeEvidence($stateDirectory);
    $result = ['action' => $action, 'release' => $expectedRelease];
    if ($action === 'deactivate' && (file_exists($requestRecoveryPath) || is_link($requestRecoveryPath))) {
        throw new RuntimeException('Unconfirmed calendar request requires explicit recovery.');
    }
    if ($action === 'preflight') {
        $sessions->assertCleanBeforeActivation();
        $fixture->assertCleanBeforeActivation();
        $verificationFixture->assertCleanBeforeActivation();
        $result += [
            'fixture' => $fixture->verify(),
            'verification_fixture' => $verificationFixture->verify(),
            'session_expiration_seconds' => $expiration,
            'config_observation' => 'CLI bootstrap; direct web lifecycle is separate',
        ];
    } elseif ($action === 'activate') {
        $sessions->assertCleanBeforeActivation();
        $verificationFixture->assertCleanBeforeActivation();
        $evidence->begin($expectedRelease);
        $evidence->run('activate', fn(): array => $fixture->activate(roleSlug: $fixtureRole));
        $result['fixture'] = $evidence->run('verify', static function () use ($fixture): string {
            $status = $fixture->verify();
            if ($status !== 'active') {
                throw new RuntimeException('Activated ordinary fixture is not active.');
            }
            return $status;
        });
    } elseif ($action === 'verify') {
        $result['fixture'] = $fixture->verify();
        $result['verification_fixture'] = $verificationFixture->verify();
    } elseif ($action === 'deactivate') {
        $result['fixture'] = $evidence->run(
            'deactivate',
            static function () use ($fixture, $verificationFixture, $sessions, $evidence): string {
                // Remove dependent synthetic rows before revoking their ordinary actor.
                $verificationFixture->deactivate();
                $fixture->deactivate();
                // Persist a non-secret known-object receipt before retiring private session provenance.
                $sessions->cleanup($evidence->cleaned(...));
                $status = $fixture->verify();
                if ($status !== 'clean') {
                    throw new RuntimeException('Ordinary fixture cleanup is incomplete.');
                }
                if ($verificationFixture->verify() !== 'clean') {
                    throw new RuntimeException('Defense verification fixture cleanup is incomplete.');
                }
                return $status;
            },
            alwaysAttempt: true,
        );
    } else {
        $context = $evidence->run('verify', static function () use ($fixture): array {
            $context = $fixture->read();
            if ($context['expires_at'] <= time() + 60) {
                throw new RuntimeException('Ordinary fixture deadline is too close.');
            }
            return $context;
        });
        $newClient = static fn(): GateHttpClient => new GateHttpClient(
            'http://localhost',
            indexPage: (string) config_item('index_page'),
            csrfCookieName: (string) config_item('csrf_cookie_name'),
            csrfTokenName: (string) config_item('csrf_token_name'),
            additionalHeaders: ['X-FH-Ordinary-Probe' => '1'],
        );
        $client = $newClient();
        if ($action === 'account') {
            $result['evidence'] = (new OrdinaryAccountProbe($client, $ci->db, $sessions->remember(...)))->run(
                $context,
                $evidence->step(...),
            );
        } elseif ($action === 'methods') {
            $result['evidence'] = (new AccountSecurityMatrixProbe(
                $newClient,
                $ci->db,
                $sessions->remember(...),
                (string) config_item('csrf_cookie_name'),
                (string) config_item('csrf_token_name'),
            ))->run($context, $evidence->step(...));
        } elseif ($action === 'customer-boundary') {
            $supplemental = $evidence->run(
                'supplemental_activate',
                fn(): array => $verificationFixture->activate('customer_boundary', $context),
            );
            $result['evidence'] = (new CustomerRoleBoundaryProbe($client, $ci->db, $sessions->remember(...)))->run(
                $context,
                $supplemental,
                $evidence->step(...),
            );
        } elseif ($action === 'calendar-race') {
            $supplemental = $evidence->run(
                'supplemental_activate',
                fn(): array => $verificationFixture->activate('calendar_race', $context),
            );
            $retainCalendarRecovery = static function () use (
                &$requestRecoveryRequired,
                $markRequestRecovery,
                $verificationFixture,
            ): void {
                $requestRecoveryRequired = true;
                $markerRetained = false;
                $fixtureRetained = false;
                try {
                    $markRequestRecovery();
                    $markerRetained = true;
                } catch (Throwable) {
                    // The fixture recovery phase is the independent fallback.
                }
                try {
                    $verificationFixture->retainForRecovery('calendar_request_termination_unconfirmed');
                    $fixtureRetained = true;
                } catch (Throwable) {
                    // The separate request-unconfirmed marker is the independent fallback.
                }
                if (!$markerRetained && !$fixtureRetained) {
                    throw new RuntimeException('Calendar request recovery state could not be retained.');
                }
            };
            try {
                $result['evidence'] = (new CalendarResponsibilityRaceProbe(
                    $client,
                    $ci->db,
                    'http://localhost',
                    indexPage: (string) config_item('index_page'),
                    csrfCookieName: (string) config_item('csrf_cookie_name'),
                    csrfTokenName: (string) config_item('csrf_token_name'),
                    rememberSession: $sessions->remember(...),
                    retainRecovery: $retainCalendarRecovery,
                ))->run($context, $supplemental, $evidence->step(...));
            } catch (UnconfirmedCalendarRequestTermination $error) {
                try {
                    $retainCalendarRecovery();
                } catch (Throwable) {
                    // Exit code 86 independently forces wrapper-side blocking.
                }
                throw $error;
            }
        } else {
            $result['evidence'] = $evidence->run(
                'session',
                fn() => (new OrdinarySessionProbe($client, $sessions, 'http://localhost', $expiration))->run(
                    $context,
                    static function (array $progress) use ($evidence): void {
                        $evidence->step('waiting', 'started');
                        echo json_encode($progress, JSON_THROW_ON_ERROR) . PHP_EOL;
                        flush();
                    },
                    $assertActive,
                ),
            );
        }
        $evidence->run('verify', static function () use ($assertActive, $markerPath, $marker): void {
            $assertActive();
            if (trim((string) file_get_contents($markerPath)) !== $marker) {
                throw new RuntimeException('Release changed during the probe; evidence is not valid.');
            }
        });
        if (
            in_array($action, ['customer-boundary', 'calendar-race'], true) &&
            $verificationFixture->verify() !== 'active'
        ) {
            throw new RuntimeException('Defense verification fixture changed before wrapper cleanup.');
        }
        $result['cleanup'] = 'pending_wrapper';
    }
    echo json_encode($result, JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $error) {
    // Never expose SQL, session contents, credentials, HTTP bodies or exception traces.
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    // Fixed action code only: no dynamic exception messages or traces.
    fwrite(STDERR, json_encode(['status' => 'failed', 'action' => $action], JSON_THROW_ON_ERROR) . PHP_EOL);
    fwrite(STDERR, "Ordinary live probe failed; retain private state and run controlled cleanup.\n");
    $exitCode = $requestRecoveryRequired ? 86 : 1;
}
exit($exitCode);
