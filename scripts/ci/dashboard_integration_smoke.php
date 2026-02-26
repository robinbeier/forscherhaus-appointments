#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../release-gate/lib/GateAssertions.php';
require_once __DIR__ . '/../release-gate/lib/GateCliSupport.php';
require_once __DIR__ . '/../release-gate/lib/GateHttpClient.php';

use ReleaseGate\GateAssertionException;
use ReleaseGate\GateAssertions;
use ReleaseGate\GateCliSupport;
use ReleaseGate\GateHttpClient;

const INTEGRATION_SMOKE_EXIT_SUCCESS = 0;
const INTEGRATION_SMOKE_EXIT_ASSERTION_FAILURE = 1;
const INTEGRATION_SMOKE_EXIT_RUNTIME_ERROR = 2;

$checks = [];
$failure = null;
$exitCode = INTEGRATION_SMOKE_EXIT_SUCCESS;

$repoRoot = dirname(__DIR__, 2);
$csrfDefaults = GateCliSupport::resolveCsrfNamesFromConfig($repoRoot . '/application/config/config.php');

try {
    $config = parseCliOptions($csrfDefaults);

    $client = new GateHttpClient(
        $config['base_url'],
        $config['index_page'],
        $config['http_timeout'],
        'dashboard-integration-smoke/1.0',
        $config['csrf_cookie_name'],
        $config['csrf_token_name'],
    );

    $runCheck = static function (string $name, callable $callback) use (&$checks): void {
        try {
            $details = $callback();

            if (!is_array($details)) {
                $details = ['detail' => (string) $details];
            }

            $checks[] = array_merge(
                [
                    'name' => $name,
                    'status' => 'pass',
                ],
                $details,
            );

            fwrite(STDOUT, '[PASS] ' . $name . PHP_EOL);
        } catch (Throwable $e) {
            $checks[] = [
                'name' => $name,
                'status' => 'fail',
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ];

            fwrite(STDERR, '[FAIL] ' . $name . ': ' . $e->getMessage() . PHP_EOL);
            throw $e;
        }
    };

    $runCheck('readiness_login_page', static function () use ($client, $config): array {
        $response = $client->get('login', [], $config['http_timeout']);
        GateAssertions::assertStatus($response->statusCode, 200, 'GET /login');

        $csrfCookie = $client->getCookie($config['csrf_cookie_name']);
        if ($csrfCookie === null || $csrfCookie === '') {
            throw new GateAssertionException('GET /login did not set cookie "' . $config['csrf_cookie_name'] . '".');
        }

        return [
            'http_status' => $response->statusCode,
            'url' => $response->url,
            'csrf_cookie_present' => true,
        ];
    });

    $runCheck('auth_login_validate', static function () use ($client, $config): array {
        $response = $client->post(
            'login/validate',
            [
                'username' => $config['username'],
                'password' => $config['password'],
            ],
            $config['http_timeout'],
            true,
        );

        GateAssertions::assertStatus($response->statusCode, 200, 'POST /login/validate');
        $payload = GateAssertions::decodeJson($response->body, 'POST /login/validate');
        GateAssertions::assertLoginPayload($payload);

        return [
            'http_status' => $response->statusCode,
            'url' => $response->url,
        ];
    });

    $runCheck('dashboard_metrics', static function () use ($client, $config): array {
        $response = $client->post('dashboard/metrics', buildMetricsPayload($config), $config['http_timeout'], true);

        GateAssertions::assertStatus($response->statusCode, 200, 'POST /dashboard/metrics');
        $decoded = GateAssertions::decodeJson($response->body, 'POST /dashboard/metrics');
        $summary = GateAssertions::assertMetricsPayload($decoded, true);

        return [
            'http_status' => $response->statusCode,
            'url' => $response->url,
            'providers' => $summary['providers'],
            'booked_total' => $summary['booked_total'],
        ];
    });
} catch (GateAssertionException $e) {
    $exitCode = GateCliSupport::classifyAssertionExitCode($checks);
    $failure = [
        'message' => $e->getMessage(),
        'exception' => get_class($e),
    ];
} catch (Throwable $e) {
    $exitCode = INTEGRATION_SMOKE_EXIT_RUNTIME_ERROR;
    $failure = [
        'message' => $e->getMessage(),
        'exception' => get_class($e),
    ];
}

$passedChecks = count(array_filter($checks, static fn(array $check): bool => ($check['status'] ?? null) === 'pass'));
$failedChecks = count($checks) - $passedChecks;

if ($exitCode === INTEGRATION_SMOKE_EXIT_SUCCESS) {
    fwrite(STDOUT, sprintf('[PASS] Dashboard integration smoke passed (%d checks).%s', $passedChecks, PHP_EOL));
} else {
    $message = $failure['message'] ?? 'unknown failure';
    fwrite(STDERR, sprintf('[FAIL] Dashboard integration smoke failed (exit %d): %s%s', $exitCode, $message, PHP_EOL));
}

exit($exitCode);

/**
 * @return array{
 *   base_url:string,
 *   index_page:string,
 *   username:string,
 *   password:string,
 *   start_date:string,
 *   end_date:string,
 *   http_timeout:int,
 *   csrf_token_name:string,
 *   csrf_cookie_name:string
 * }
 */
function parseCliOptions(array $csrfDefaults): array
{
    $options = getopt('', [
        'base-url:',
        'index-page::',
        'username:',
        'password:',
        'start-date:',
        'end-date:',
        'http-timeout::',
    ]);

    if (!is_array($options)) {
        throw new InvalidArgumentException('Failed to parse CLI options.');
    }

    $baseUrl = trim(getRequiredOption($options, 'base-url'));
    $username = trim(getRequiredOption($options, 'username'));
    $password = getRequiredOption($options, 'password');
    $startDate = trim(getRequiredOption($options, 'start-date'));
    $endDate = trim(getRequiredOption($options, 'end-date'));

    $indexPageRaw = getOptionalOption($options, 'index-page', 'index.php');
    $indexPage = $indexPageRaw === null ? 'index.php' : trim((string) $indexPageRaw);
    $httpTimeout = parsePositiveInt(getOptionalOption($options, 'http-timeout', 15), 'http-timeout');

    if ($baseUrl === '') {
        throw new InvalidArgumentException('Option --base-url must not be empty.');
    }

    validateDate($startDate, 'start-date');
    validateDate($endDate, 'end-date');

    if ($startDate > $endDate) {
        throw new InvalidArgumentException('start-date must be <= end-date.');
    }

    return [
        'base_url' => $baseUrl,
        'index_page' => $indexPage,
        'username' => $username,
        'password' => $password,
        'start_date' => $startDate,
        'end_date' => $endDate,
        'http_timeout' => $httpTimeout,
        'csrf_token_name' => $csrfDefaults['csrf_token_name'],
        'csrf_cookie_name' => $csrfDefaults['csrf_cookie_name'],
    ];
}

/**
 * @param array<string, mixed> $options
 */
function getRequiredOption(array $options, string $name): string
{
    $value = $options[$name] ?? null;

    if (!is_string($value) || trim($value) === '') {
        throw new InvalidArgumentException('Missing required option --' . $name . '.');
    }

    return $value;
}

/**
 * @param array<string, mixed> $options
 */
function getOptionalOption(array $options, string $name, mixed $default): mixed
{
    $value = $options[$name] ?? $default;

    if (is_array($value)) {
        $value = end($value);
    }

    if ($value === false) {
        return $default;
    }

    return $value;
}

function parsePositiveInt(mixed $value, string $name): int
{
    if (is_int($value)) {
        $parsed = $value;
    } elseif (is_string($value) && preg_match('/^\d+$/', trim($value)) === 1) {
        $parsed = (int) trim($value);
    } else {
        throw new InvalidArgumentException('Option --' . $name . ' must be a positive integer.');
    }

    if ($parsed <= 0) {
        throw new InvalidArgumentException('Option --' . $name . ' must be a positive integer.');
    }

    return $parsed;
}

function validateDate(string $value, string $name): void
{
    $parsed = DateTimeImmutable::createFromFormat('Y-m-d', $value);

    if (!$parsed || $parsed->format('Y-m-d') !== $value) {
        throw new InvalidArgumentException('Option --' . $name . ' must be in YYYY-MM-DD format.');
    }
}

/**
 * @param array{
 *   start_date:string,
 *   end_date:string
 * } $config
 *
 * @return array{
 *   start_date:string,
 *   end_date:string,
 *   statuses:array<int, string>
 * }
 */
function buildMetricsPayload(array $config): array
{
    return [
        'start_date' => $config['start_date'],
        'end_date' => $config['end_date'],
        'statuses' => ['Booked'],
    ];
}
