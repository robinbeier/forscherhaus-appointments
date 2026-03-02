<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once __DIR__ . '/lib/OpenApiContractValidator.php';

use CiContract\ContractAssertionException;
use CiContract\OpenApiContractValidator;

const API_OPENAPI_CONTRACT_EXIT_SUCCESS = 0;
const API_OPENAPI_CONTRACT_EXIT_ASSERTION_FAILURE = 1;
const API_OPENAPI_CONTRACT_EXIT_RUNTIME_ERROR = 2;

$checks = [];
$state = [];
$failure = null;
$exitCode = API_OPENAPI_CONTRACT_EXIT_SUCCESS;

try {
    $config = parseCliOptions();
    $matrix = loadContractMatrix($config['matrix_file']);
    $validator = OpenApiContractValidator::fromFile($config['openapi_spec']);

    if (($matrix['read_only'] ?? null) !== true) {
        throw new ContractAssertionException('Contract matrix must explicitly set "read_only" to true.');
    }

    if (!isset($matrix['checks']) || !is_array($matrix['checks']) || $matrix['checks'] === []) {
        throw new ContractAssertionException('Contract matrix must contain a non-empty "checks" array.');
    }

    $runCheck = static function (array $check) use (&$checks, &$state, $config, $validator): void {
        $name = (string) ($check['id'] ?? 'unknown_check');

        try {
            $details = runContractCheck($check, $state, $config, $validator);

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

    foreach ($matrix['checks'] as $check) {
        if (!is_array($check)) {
            throw new ContractAssertionException('Each contract check definition must be an object.');
        }

        $runCheck($check);
    }
} catch (ContractAssertionException $e) {
    $exitCode = API_OPENAPI_CONTRACT_EXIT_ASSERTION_FAILURE;
    $failure = [
        'message' => $e->getMessage(),
        'exception' => get_class($e),
    ];
} catch (Throwable $e) {
    $exitCode = API_OPENAPI_CONTRACT_EXIT_RUNTIME_ERROR;
    $failure = [
        'message' => $e->getMessage(),
        'exception' => get_class($e),
    ];
}

$reportPath = null;

try {
    $reportPath = writeReport($config ?? [], $checks, $state, $failure);
} catch (Throwable $e) {
    fwrite(STDERR, '[WARN] Failed to write contract report: ' . $e->getMessage() . PHP_EOL);
}

$passedChecks = count(array_filter($checks, static fn(array $check): bool => ($check['status'] ?? '') === 'pass'));
$totalChecks = count($checks);

if ($exitCode === API_OPENAPI_CONTRACT_EXIT_SUCCESS) {
    fwrite(
        STDOUT,
        sprintf('[PASS] API OpenAPI contract smoke passed (%d/%d checks).%s', $passedChecks, $totalChecks, PHP_EOL),
    );
} else {
    $failureMessage = $failure['message'] ?? 'unknown failure';
    fwrite(STDERR, sprintf('[FAIL] API OpenAPI contract smoke failed: %s%s', $failureMessage, PHP_EOL));
}

if ($reportPath !== null) {
    fwrite(STDOUT, '[INFO] Report: ' . $reportPath . PHP_EOL);
}

exit($exitCode);

/**
 * @param array<string, mixed> $check
 * @param array<string, mixed> $state
 * @param array<string, mixed> $config
 * @return array<string, mixed>
 */
function runContractCheck(array $check, array &$state, array $config, OpenApiContractValidator $validator): array
{
    $id = requiredString($check, 'id');
    $method = strtoupper(requiredString($check, 'method'));
    $openapiPath = requiredString($check, 'openapi_path');
    $expectedStatus = requiredInt($check, 'expected_status');
    $authMode = (string) ($check['auth'] ?? 'basic');

    if ($method !== 'GET') {
        throw new ContractAssertionException($id . ' must use GET only (read-only contract policy).');
    }

    if (!in_array($authMode, ['none', 'basic'], true)) {
        throw new ContractAssertionException($id . ' has unsupported auth mode: ' . $authMode);
    }

    $validator->getOperation($method, $openapiPath);
    $validator->assertOperationHasResponse($method, $openapiPath, $expectedStatus);
    $validator->assertOperationSupportsKnownAuth($method, $openapiPath);

    $skipIfStateMissing = $check['skip_if_state_missing'] ?? [];
    if (is_array($skipIfStateMissing) && $skipIfStateMissing !== []) {
        $missingKeys = [];
        foreach ($skipIfStateMissing as $stateKey) {
            if (!is_string($stateKey) || $stateKey === '') {
                continue;
            }

            if (!array_key_exists($stateKey, $state) || $state[$stateKey] === null || $state[$stateKey] === '') {
                $missingKeys[] = $stateKey;
            }
        }

        if ($missingKeys !== []) {
            return [
                'skipped' => true,
                'reason' => 'Missing runtime fixture state: ' . implode(', ', $missingKeys),
            ];
        }
    }

    $requestPath = resolveRequestPath($check, $state);
    $query = resolveQuery($check['query'] ?? [], $state);

    $response = httpGet(
        $config,
        $requestPath,
        $query,
        $authMode === 'basic' ? $config['username'] : null,
        $authMode === 'basic' ? $config['password'] : null,
    );

    if ($response['status_code'] !== $expectedStatus) {
        throw new ContractAssertionException(
            sprintf(
                '%s expected HTTP %d, got %d for %s.',
                $id,
                $expectedStatus,
                $response['status_code'],
                $response['url'],
            ),
        );
    }

    if (
        ($check['require_www_authenticate'] ?? false) === true &&
        headerValue($response['headers'], 'www-authenticate') === null
    ) {
        throw new ContractAssertionException($id . ' expected WWW-Authenticate header on unauthorized response.');
    }

    $details = [
        'http_status' => $response['status_code'],
        'url' => $response['url'],
        'duration_ms' => $response['duration_ms'],
    ];

    if ($expectedStatus >= 200 && $expectedStatus < 300) {
        $schema = $validator->getResponseSchema($method, $openapiPath, $expectedStatus);
        $decoded = decodeJson($response['body'], $id);
        $validator->assertValueMatchesSchema($decoded, $schema, $id . ' response');

        if (isset($check['item_schema_ref'])) {
            if (!is_array($decoded) || !array_is_list($decoded)) {
                throw new ContractAssertionException($id . ' expected a JSON array payload.');
            }

            if ($decoded === []) {
                if (($check['allow_empty_items'] ?? false) === true) {
                    $details['items'] = 0;

                    return $details;
                }

                throw new ContractAssertionException($id . ' returned an empty array; expected at least one item.');
            }

            $firstItem = $decoded[0];
            if (!is_array($firstItem) || array_is_list($firstItem)) {
                throw new ContractAssertionException($id . ' first item must be a JSON object.');
            }

            $requiredFields = array_values(array_filter($check['required_fields'] ?? [], 'is_string'));
            $validator->assertObjectFieldsMatchSchema(
                $firstItem,
                (string) $check['item_schema_ref'],
                $requiredFields,
                $id . '.items[0]',
            );

            $details['items'] = count($decoded);
            $details['sample_id'] = $firstItem['id'] ?? null;

            if (isset($check['capture_id_to'])) {
                $captureKey = (string) $check['capture_id_to'];
                $capturedId = $firstItem['id'] ?? null;
                if (!is_int($capturedId) || $capturedId <= 0) {
                    throw new ContractAssertionException(
                        $id . ' could not capture a positive integer "id" from first item.',
                    );
                }

                $state[$captureKey] = $capturedId;
                $details['captured'] = [$captureKey => $capturedId];
            }
        }

        if (isset($check['object_schema_ref'])) {
            if (!is_array($decoded) || array_is_list($decoded)) {
                throw new ContractAssertionException($id . ' expected a JSON object payload.');
            }

            $requiredFields = array_values(array_filter($check['required_fields'] ?? [], 'is_string'));
            $validator->assertObjectFieldsMatchSchema(
                $decoded,
                (string) $check['object_schema_ref'],
                $requiredFields,
                $id . '.object',
            );
        }

        if (isset($check['items_pattern'])) {
            if (!is_array($decoded) || !array_is_list($decoded)) {
                throw new ContractAssertionException($id . ' expected a JSON array payload for pattern checks.');
            }

            $pattern = (string) $check['items_pattern'];
            foreach ($decoded as $index => $item) {
                if (!is_string($item)) {
                    throw new ContractAssertionException(
                        sprintf('%s expected string item at index %d, got %s.', $id, $index, gettype($item)),
                    );
                }

                if (@preg_match($pattern, $item) !== 1) {
                    throw new ContractAssertionException(
                        sprintf('%s item "%s" at index %d does not match pattern %s.', $id, $item, $index, $pattern),
                    );
                }
            }

            $details['items'] = count($decoded);
        }
    }

    return $details;
}

/**
 * @param array<string, mixed> $check
 * @param array<string, mixed> $state
 */
function resolveRequestPath(array $check, array $state): string
{
    if (isset($check['request_path']) && is_string($check['request_path']) && $check['request_path'] !== '') {
        return trim($check['request_path'], '/');
    }

    $template = isset($check['request_path_template']) ? (string) $check['request_path_template'] : '';
    if ($template === '') {
        throw new ContractAssertionException(
            requiredString($check, 'id') . ' must define request_path or request_path_template.',
        );
    }

    $params = $check['path_params'] ?? [];
    if (!is_array($params)) {
        throw new ContractAssertionException(requiredString($check, 'id') . ' path_params must be an object.');
    }

    foreach ($params as $name => $rawValue) {
        if (!is_string($name) || $name === '') {
            continue;
        }

        $resolved = resolveDynamicValue($rawValue, $state);
        if (!is_scalar($resolved)) {
            throw new ContractAssertionException(
                requiredString($check, 'id') . ' path parameter "' . $name . '" must resolve to a scalar value.',
            );
        }

        $template = str_replace('{' . $name . '}', rawurlencode((string) $resolved), $template);
    }

    if (preg_match('/\{[^}]+\}/', $template) === 1) {
        throw new ContractAssertionException(
            requiredString($check, 'id') . ' has unresolved path template placeholders: ' . $template,
        );
    }

    return trim($template, '/');
}

/**
 * @param mixed $query
 * @param array<string, mixed> $state
 * @return array<string, scalar>
 */
function resolveQuery(mixed $query, array $state): array
{
    if ($query === null) {
        return [];
    }

    if (!is_array($query)) {
        throw new ContractAssertionException('Contract check query must be an object.');
    }

    $resolved = [];
    foreach ($query as $key => $value) {
        if (!is_string($key) || $key === '') {
            continue;
        }

        $resolvedValue = resolveDynamicValue($value, $state);
        if (!is_scalar($resolvedValue)) {
            throw new ContractAssertionException('Query value for "' . $key . '" resolved to non-scalar value.');
        }

        $resolved[$key] = $resolvedValue;
    }

    return $resolved;
}

/**
 * @param mixed $value
 * @param array<string, mixed> $state
 * @return mixed
 */
function resolveDynamicValue(mixed $value, array $state): mixed
{
    if (!is_string($value) || $value === '') {
        return $value;
    }

    if ($value === '@tomorrow') {
        return date('Y-m-d', strtotime('+1 day'));
    }

    $statePrefix = '@state.';
    if (str_starts_with($value, $statePrefix)) {
        $stateKey = substr($value, strlen($statePrefix));
        if ($stateKey === '' || !array_key_exists($stateKey, $state)) {
            throw new ContractAssertionException('Missing state value for token "' . $value . '".');
        }

        return $state[$stateKey];
    }

    return $value;
}

/**
 * @param array<string, mixed> $config
 * @param array<string, scalar> $query
 * @return array{status_code:int,headers:array<string, string[]>,body:string,url:string,duration_ms:float}
 */
function httpGet(array $config, string $path, array $query, ?string $username, ?string $password): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('ext-curl is required for API OpenAPI contract smoke.');
    }

    $url = buildAppUrl($config['base_url'], $config['index_page'], $path, $query);

    $curl = curl_init();
    if ($curl === false) {
        throw new RuntimeException('Failed to initialize cURL.');
    }

    $headers = [];

    $headerFn = static function ($ch, string $headerLine) use (&$headers): int {
        $trimmed = trim($headerLine);
        if ($trimmed === '') {
            return strlen($headerLine);
        }

        if (str_starts_with($trimmed, 'HTTP/')) {
            $headers = [];

            return strlen($headerLine);
        }

        $parts = explode(':', $trimmed, 2);
        if (count($parts) !== 2) {
            return strlen($headerLine);
        }

        $name = strtolower(trim($parts[0]));
        $value = trim($parts[1]);
        $headers[$name] ??= [];
        $headers[$name][] = $value;

        return strlen($headerLine);
    };

    $timeout = max(1, (int) $config['http_timeout']);
    $connectTimeout = min(5, $timeout);
    $options = [
        CURLOPT_URL => $url,
        CURLOPT_CUSTOMREQUEST => 'GET',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => $connectTimeout,
        CURLOPT_HEADERFUNCTION => $headerFn,
        CURLOPT_USERAGENT => 'api-openapi-contract-smoke/1.0',
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ];

    if ($username !== null || $password !== null) {
        $options[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
        $options[CURLOPT_USERPWD] = ($username ?? '') . ':' . ($password ?? '');
    }

    curl_setopt_array($curl, $options);

    $startedAt = microtime(true);
    $body = curl_exec($curl);
    $durationMs = (microtime(true) - $startedAt) * 1000;

    if ($body === false) {
        $error = curl_error($curl);
        curl_close($curl);
        throw new RuntimeException('HTTP request failed for "' . $url . '": ' . $error);
    }

    $statusCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $effectiveUrl = (string) curl_getinfo($curl, CURLINFO_EFFECTIVE_URL);
    curl_close($curl);

    return [
        'status_code' => $statusCode,
        'headers' => $headers,
        'body' => (string) $body,
        'url' => $effectiveUrl !== '' ? $effectiveUrl : $url,
        'duration_ms' => round($durationMs, 2),
    ];
}

/**
 * @param array<string, string[]> $headers
 */
function headerValue(array $headers, string $name): ?string
{
    $key = strtolower($name);
    if (!array_key_exists($key, $headers) || !is_array($headers[$key]) || $headers[$key] === []) {
        return null;
    }

    return (string) $headers[$key][0];
}

/**
 * @param array<string, scalar> $query
 */
function buildAppUrl(string $baseUrl, string $indexPage, string $path, array $query = []): string
{
    $segments = [rtrim($baseUrl, '/')];
    $normalizedIndex = trim($indexPage, '/');
    if ($normalizedIndex !== '') {
        $segments[] = $normalizedIndex;
    }

    $normalizedPath = trim($path, '/');
    if ($normalizedPath !== '') {
        $segments[] = $normalizedPath;
    }

    $url = implode('/', $segments);
    if ($query !== []) {
        $queryString = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        if ($queryString !== '') {
            $url .= '?' . $queryString;
        }
    }

    return $url;
}

/**
 * @return mixed
 */
function decodeJson(string $body, string $context): mixed
{
    try {
        return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        throw new ContractAssertionException($context . ' returned invalid JSON: ' . $e->getMessage(), 0, $e);
    }
}

/**
 * @param array<string, mixed> $source
 */
function requiredString(array $source, string $key): string
{
    $value = $source[$key] ?? null;
    if (!is_string($value) || trim($value) === '') {
        throw new ContractAssertionException('Missing required string field "' . $key . '".');
    }

    return $value;
}

/**
 * @param array<string, mixed> $source
 */
function requiredInt(array $source, string $key): int
{
    $value = $source[$key] ?? null;
    if (!is_int($value)) {
        throw new ContractAssertionException('Missing required integer field "' . $key . '".');
    }

    return $value;
}

/**
 * @param array<string, mixed> $config
 * @return array<string, mixed>
 */
function loadContractMatrix(string $matrixFile): array
{
    if (!is_file($matrixFile)) {
        throw new ContractAssertionException('Contract matrix file not found: ' . $matrixFile);
    }

    $matrix = require $matrixFile;
    if (!is_array($matrix)) {
        throw new ContractAssertionException('Contract matrix file must return an array: ' . $matrixFile);
    }

    return $matrix;
}

/**
 * @return array<string, mixed>
 */
function parseCliOptions(): array
{
    $options = getopt('', [
        'base-url:',
        'index-page::',
        'openapi-spec::',
        'matrix-file::',
        'username:',
        'password:',
        'http-timeout::',
        'output-json::',
        'help::',
    ]);

    if (array_key_exists('help', $options)) {
        printHelpAndExit();
    }

    $baseUrl = trim((string) ($options['base-url'] ?? ''));
    $username = (string) ($options['username'] ?? '');
    $password = (string) ($options['password'] ?? '');

    if ($baseUrl === '' || $username === '' || $password === '') {
        throw new ContractAssertionException('Missing required arguments. Use --help for usage.');
    }

    $repoRoot = dirname(__DIR__, 2);
    $openapiSpec = (string) ($options['openapi-spec'] ?? $repoRoot . '/openapi.yml');
    $matrixFile = (string) ($options['matrix-file'] ?? __DIR__ . '/config/api_openapi_contract_matrix.php');
    $timeout = (int) ($options['http-timeout'] ?? 15);

    return [
        'base_url' => $baseUrl,
        'index_page' => (string) ($options['index-page'] ?? 'index.php'),
        'openapi_spec' => $openapiSpec,
        'matrix_file' => $matrixFile,
        'username' => $username,
        'password' => $password,
        'http_timeout' => max(1, $timeout),
        'output_json' => (string) ($options['output-json'] ?? ''),
    ];
}

/**
 * @param array<string, mixed> $config
 * @param array<int, array<string, mixed>> $checks
 * @param array<string, mixed> $state
 * @param array<string, mixed>|null $failure
 */
function writeReport(array $config, array $checks, array $state, ?array $failure): string
{
    $outputPath = trim((string) ($config['output_json'] ?? ''));
    if ($outputPath === '') {
        $timestamp = gmdate('Ymd\THis\Z');
        $outputPath = dirname(__DIR__, 2) . '/storage/logs/ci/api-openapi-contract-' . $timestamp . '.json';
    }

    $directory = dirname($outputPath);
    if (!is_dir($directory)) {
        if (!mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException('Failed to create report directory: ' . $directory);
        }
    }

    $passed = count(array_filter($checks, static fn(array $check): bool => ($check['status'] ?? '') === 'pass'));
    $failed = count(array_filter($checks, static fn(array $check): bool => ($check['status'] ?? '') === 'fail'));

    $report = [
        'generated_at_utc' => gmdate('c'),
        'openapi_spec' => $config['openapi_spec'] ?? null,
        'base_url' => $config['base_url'] ?? null,
        'read_only' => true,
        'summary' => [
            'total' => count($checks),
            'passed' => $passed,
            'failed' => $failed,
        ],
        'state' => $state,
        'checks' => $checks,
        'failure' => $failure,
    ];

    $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    file_put_contents($outputPath, $json . PHP_EOL);

    return $outputPath;
}

function printHelpAndExit(): void
{
    $help = <<<'TXT'
    Usage:
      php scripts/ci/api_openapi_contract_smoke.php \
        --base-url=http://nginx \
        --index-page=index.php \
        --openapi-spec=/var/www/html/openapi.yml \
        --username=administrator \
        --password=administrator

    Optional:
      --matrix-file=PATH
      --http-timeout=15
      --output-json=PATH
    TXT;

    fwrite(STDOUT, $help . PHP_EOL);
    exit(API_OPENAPI_CONTRACT_EXIT_SUCCESS);
}
