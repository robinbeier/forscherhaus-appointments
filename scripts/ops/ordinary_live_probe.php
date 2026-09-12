<?php

declare(strict_types=1);

use ReleaseGate\GateHttpClient;
use ReleaseGate\OrdinaryAccountProbe;
use ReleaseGate\OrdinaryLiveFixture;
use ReleaseGate\OrdinaryProbeSessions;
use ReleaseGate\OrdinarySessionProbe;

// Reviewed operator tool only. Never a web route, configurable HTTP target, or auth exception.
if (PHP_SAPI !== 'cli' || !function_exists('posix_geteuid') || posix_geteuid() !== 0) {
    fwrite(STDERR, "Root CLI required.\n");
    exit(77);
}
$options = getopt('', ['action:', 'app-root:', 'active-app-root:', 'expected-app-identity:', 'expected-release:']);
$action = $options['action'] ?? '';
$appRoot = $options['app-root'] ?? '/var/www/html/easyappointments';
$activeAppRoot = $options['active-app-root'] ?? $appRoot;
$expectedAppIdentity = $options['expected-app-identity'] ?? '';
$expectedRelease = $options['expected-release'] ?? '';
if (
    !in_array($action, ['preflight', 'activate', 'account', 'session', 'deactivate', 'verify'], true) ||
    !is_string($appRoot) ||
    realpath($appRoot) !== $appRoot ||
    is_link($appRoot) ||
    !is_string($activeAppRoot) ||
    !str_starts_with($activeAppRoot, '/') ||
    !is_string($expectedAppIdentity) ||
    !preg_match('/\A[0-9]+:[0-9]+\z/D', $expectedAppIdentity) ||
    !preg_match('/\Aea_[a-zA-Z0-9_]+\z/D', $expectedRelease)
) {
    fwrite(STDERR, "Valid action, canonical application root and expected release required.\n");
    exit(64);
}
umask(0077);
$stateDirectory = '/var/lib/fh-defense-ordinary';
$exitCode = 0;
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
    $sessions = new OrdinaryProbeSessions($stateDirectory, $appRoot . '/storage/sessions');
    $result = ['action' => $action, 'release' => $expectedRelease];
    if ($action === 'preflight') {
        $sessions->assertCleanBeforeActivation();
        $fixture->assertCleanBeforeActivation();
        $result += [
            'fixture' => $fixture->verify(),
            'session_expiration_seconds' => $expiration,
            'config_observation' => 'CLI bootstrap; direct web lifecycle is separate',
        ];
    } elseif ($action === 'activate') {
        $sessions->assertCleanBeforeActivation();
        $fixture->activate();
        $result['fixture'] = $fixture->verify();
    } elseif ($action === 'verify') {
        $result['fixture'] = $fixture->verify();
    } elseif ($action === 'deactivate') {
        // Revoke the identity first, then remove only journaled own session inodes.
        $fixture->deactivate();
        $sessions->cleanup();
        $result['fixture'] = $fixture->verify();
        if ($result['fixture'] !== 'clean') {
            throw new RuntimeException('Ordinary fixture cleanup is incomplete.');
        }
    } else {
        $context = $fixture->read();
        if ($context['expires_at'] <= time() + 60) {
            throw new RuntimeException('Ordinary fixture deadline is too close.');
        }
        $client = new GateHttpClient('http://localhost', additionalHeaders: ['X-FH-Ordinary-Probe' => '1']);
        if ($action === 'account') {
            $result['evidence'] = (new OrdinaryAccountProbe($client, $ci->db, $sessions->remember(...)))->run($context);
        } else {
            $result['evidence'] = (new OrdinarySessionProbe($client, $sessions, 'http://localhost', $expiration))->run(
                $context,
                static function (array $progress): void {
                    echo json_encode($progress, JSON_THROW_ON_ERROR) . PHP_EOL;
                    flush();
                },
                $assertActive,
            );
        }
        if (!in_array($action, ['deactivate', 'verify'], true)) {
            $assertActive();
        }
        if (trim((string) file_get_contents($markerPath)) !== $marker) {
            throw new RuntimeException('Release changed during the probe; evidence is not valid.');
        }
        $result['cleanup'] = 'pending_wrapper';
    }
    echo json_encode($result, JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $error) {
    // Never expose SQL, session contents, credentials, HTTP bodies or exception traces.
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    fwrite(STDERR, "Ordinary live probe failed; retain private state and run controlled cleanup.\n");
    $exitCode = 1;
}
exit($exitCode);
