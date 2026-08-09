<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;
use ReleaseGate\CustomersUiSmokeContract;

require_once __DIR__ . '/../../../scripts/release-gate/lib/CustomersUiSmokeContract.php';

final class CustomersUiSmokeGateContractTest extends TestCase
{
    public function testCredentialAndRoleContractMatchesApplicationPolicy(): void
    {
        $policy = $this->readRepoFile('application/core/Customers_ui_smoke_access_policy.php');
        $contract = $this->readRepoFile('scripts/release-gate/lib/CustomersUiSmokeContract.php');

        foreach ([...CustomersUiSmokeContract::USERNAMES_BY_ROLE, CustomersUiSmokeContract::SEARCH_MARKER] as $marker) {
            self::assertStringContainsString($marker, $policy);
            self::assertStringContainsString($marker, $contract);
        }

        self::assertSame(['admin', 'provider', 'secretary'], CustomersUiSmokeContract::AUTHORIZED_ROLES);
        self::assertContains('customer_filter_providers', CustomersUiSmokeContract::FORBIDDEN_KEYS);
        self::assertContains('google_client_secret', CustomersUiSmokeContract::FORBIDDEN_KEYS);
        self::assertContains('caldav_password', CustomersUiSmokeContract::FORBIDDEN_KEYS);
        self::assertContains('customer_filter_providers', CustomersUiSmokeContract::FORBIDDEN_RESPONSE_MARKERS);
        self::assertContains('webhook_secret', CustomersUiSmokeContract::FORBIDDEN_RESPONSE_MARKERS);
    }

    public function testGateUsesPrivateStorageStateAndAlwaysRemovesArtifacts(): void
    {
        $gate = $this->readRepoFile('scripts/release-gate/customers_ui_smoke.php');

        self::assertStringContainsString("['open', 'about:blank']", $gate);
        self::assertStringContainsString("['state-load', \$statePath]", $gate);
        self::assertStringContainsString("\$client->get('dashboard')", $gate);
        self::assertStringContainsString("CustomersUiSmokeContract::SEARCH_MARKER . '-not-allowed'", $gate);
        self::assertStringContainsString("putenv('PLAYWRIGHT_MCP_OUTPUT_DIR=' . \$tempDirectory)", $gate);
        self::assertStringContainsString('customersUiSmokeFinalizeCleanup(', $gate);
        $finalize = strrpos($gate, '$cleanupOk = customersUiSmokeFinalizeCleanup(');
        $artifactsStatus = strrpos(
            $gate,
            '$temporaryArtifactsRemoved = customersUiSmokeTemporaryArtifactsRemoved($tempDirectory);',
        );
        $storageStatus = strrpos(
            $gate,
            '$storageStatesRemoved = customersUiSmokeStorageStatesRemoved($tempDirectory);',
        );
        $reportStatus = strrpos($gate, "'storage_state_removed' => \$storageStatesRemoved");
        self::assertIsInt($finalize);
        self::assertIsInt($artifactsStatus);
        self::assertIsInt($storageStatus);
        self::assertIsInt($reportStatus);
        self::assertLessThan($storageStatus, $finalize);
        self::assertLessThan($artifactsStatus, $finalize);
        self::assertLessThan($reportStatus, $storageStatus);
        self::assertLessThan($reportStatus, $artifactsStatus);
        self::assertStringContainsString("\$failureCode = 'cleanup_failed';", $gate);
        self::assertStringNotContainsString("['screenshot'", $gate);
        self::assertStringNotContainsString("['network'", $gate);
        self::assertStringNotContainsString("['tracing-start'", $gate);
        self::assertStringNotContainsString("'stdout' =>", $gate);
        self::assertStringNotContainsString("'stderr' =>", $gate);
    }

    public function testPlaywrightFlowAllowsOnlyCustomersAssetsAndExactEmptySearches(): void
    {
        $snippet = $this->readRepoFile('scripts/release-gate/playwright/customers_ui_smoke.js');
        $client = $this->readRepoFile('assets/js/http/customers_http_client.js');

        self::assertStringContainsString("context.route('**/*', routeHandler)", $snippet);
        self::assertStringContainsString("route.abort('blockedbyclient')", $snippet);
        self::assertStringContainsString("['', config.search_marker].includes(values.keyword)", $snippet);
        self::assertStringContainsString('order_by: orderBy || undefined', $client);
        self::assertStringContainsString('Object.create(null)', $snippet);
        self::assertStringContainsString('script_vars_safe', $snippet);
        self::assertStringContainsString('dom_safe', $snippet);
        self::assertStringContainsString('response_bodies_safe', $snippet);
        self::assertStringContainsString('config.forbidden_response_markers', $snippet);
        self::assertStringContainsString('initial_search_empty', $snippet);
        self::assertStringContainsString('synthetic_search_empty', $snippet);
        self::assertStringNotContainsString('page.screenshot', $snippet);
        self::assertStringNotContainsString('context.tracing', $snippet);
        self::assertStringNotContainsString('error.message', $snippet);
        self::assertStringNotContainsString('message.text()', $snippet);
    }

    public function testPlaywrightSearchPolicyExecutesExactClientBodyContractFailClosed(): void
    {
        $marker = rawurlencode(CustomersUiSmokeContract::SEARCH_MARKER);
        $validEmpty = $this->executeActualCustomersSearch('');
        $validMarker = $this->executeActualCustomersSearch(CustomersUiSmokeContract::SEARCH_MARKER);
        $inheritedKey = $this->executeActualCustomersSearch('', true);
        self::assertSame('csrf_token=token&keyword=&limit=20&offset=&order_by=', $validEmpty);
        self::assertSame('csrf_token=token&keyword=' . $marker . '&limit=20&offset=&order_by=', $validMarker);
        self::assertSame($validEmpty . '&inherited=value', $inheritedKey);

        foreach (
            [
                'actual empty client body' => [$validEmpty, 'continue'],
                'actual marker client body' => [$validMarker, 'continue'],
                'unknown key' => [$validEmpty . '&unexpected=value', 'abort'],
                'prototype key' => [$validEmpty . '&__proto__=polluted', 'abort'],
                'inherited key serialized by transport' => [$inheritedKey, 'abort'],
                'duplicate key' => [$validEmpty . '&keyword=duplicate', 'abort'],
                'non-empty order' => ['csrf_token=token&keyword=&limit=20&offset=&order_by=first_name', 'abort'],
                'missing offset' => ['csrf_token=token&keyword=&limit=20&order_by=', 'abort'],
                'missing order' => ['csrf_token=token&keyword=&limit=20&offset=', 'abort'],
                'wrong limit' => ['csrf_token=token&keyword=&limit=40&offset=&order_by=', 'abort'],
                'non-empty offset' => ['csrf_token=token&keyword=&limit=20&offset=20&order_by=', 'abort'],
            ]
            as $label => [$body, $expectedAction]
        ) {
            self::assertSame($expectedAction, $this->executeBrowserSearchPolicy($body), $label);
        }
    }

    public function testActualPlaywrightSnippetCompletesNavigationAndBothSearchesWithClientSerializedBodies(): void
    {
        $initialBody = $this->executeActualCustomersSearch('');
        $syntheticBody = $this->executeActualCustomersSearch(CustomersUiSmokeContract::SEARCH_MARKER);
        $execution = $this->executeFullBrowserFlow($initialBody, $syntheticBody);

        self::assertSame(
            [
                'navigation' => 'continue',
                'static_asset' => 'continue',
                'initial_search_post' => 'continue',
                'synthetic_search_post' => 'continue',
            ],
            $execution['route_actions'],
        );
        self::assertSame(
            [
                'ok' => true,
                'network_policy_installed' => true,
                'page_loaded' => true,
                'initial_search_empty' => true,
                'synthetic_search_empty' => true,
                'empty_state_visible' => true,
                'script_vars_safe' => true,
                'dom_safe' => true,
                'response_bodies_safe' => true,
                'initial_search_request_seen' => true,
                'initial_search_request_allowed' => true,
                'initial_search_response_seen' => true,
                'initial_search_body_read' => true,
                'synthetic_search_request_seen' => true,
                'synthetic_search_request_allowed' => true,
                'synthetic_search_response_seen' => true,
                'synthetic_search_body_read' => true,
                'search_response_count' => 2,
                'blocked_request_count' => 0,
                'blocked_initial_search_post_count' => 0,
                'blocked_synthetic_search_post_count' => 0,
                'blocked_navigation_count' => 0,
                'blocked_static_asset_count' => 0,
                'blocked_other_same_origin_count' => 0,
                'blocked_cross_origin_count' => 0,
                'page_error_count' => 0,
                'console_error_count' => 0,
                'flow_error_count' => 0,
            ],
            $execution['result'],
        );
    }

    public function testActualPlaywrightSnippetRejectsForbiddenMarkersInEitherSearchResponse(): void
    {
        $initialBody = $this->executeActualCustomersSearch('');
        $syntheticBody = $this->executeActualCustomersSearch(CustomersUiSmokeContract::SEARCH_MARKER);

        foreach (['initial', 'synthetic'] as $contaminatedStage) {
            $execution = $this->executeFullBrowserFlow(
                $initialBody,
                $syntheticBody,
                'success',
                $contaminatedStage === 'initial' ? '["customer_filter_providers"]' : '[]',
                $contaminatedStage === 'synthetic' ? '["customer_filter_providers"]' : '[]',
            );

            self::assertFalse($execution['result']['response_bodies_safe'], $contaminatedStage);
            self::assertFalse($execution['result']['ok'], $contaminatedStage);
            self::assertSame(2, $execution['result']['search_response_count'], $contaminatedStage);
            self::assertTrue($execution['result']['initial_search_body_read'], $contaminatedStage);
            self::assertTrue($execution['result']['synthetic_search_body_read'], $contaminatedStage);
        }
    }

    public function testActualPlaywrightSnippetClassifiesMalformedSearchPostsByActiveStage(): void
    {
        $initialBody = $this->executeActualCustomersSearch('');
        $syntheticBody = $this->executeActualCustomersSearch(CustomersUiSmokeContract::SEARCH_MARKER);
        $cases = [
            'initial duplicate keyword' => ['initial', $initialBody . '&keyword=duplicate'],
            'initial invalid encoding' => ['initial', str_replace('csrf_token=token', 'csrf_token=%ZZ', $initialBody)],
            'synthetic duplicate keyword' => ['synthetic', $syntheticBody . '&keyword=duplicate'],
            'synthetic invalid encoding' => [
                'synthetic',
                str_replace('csrf_token=token', 'csrf_token=%ZZ', $syntheticBody),
            ],
        ];

        foreach ($cases as $label => [$stage, $malformedBody]) {
            $execution = $this->executeFullBrowserFlow(
                $stage === 'initial' ? $malformedBody : $initialBody,
                $stage === 'synthetic' ? $malformedBody : $syntheticBody,
                'malformed-' . $stage,
            );
            $counter = 'blocked_' . $stage . '_search_post_count';
            $seen = $stage . '_search_request_seen';
            $allowed = $stage . '_search_request_allowed';
            $classifiedCount = array_sum(
                array_intersect_key(
                    $execution['result'],
                    array_flip([
                        'blocked_initial_search_post_count',
                        'blocked_synthetic_search_post_count',
                        'blocked_navigation_count',
                        'blocked_static_asset_count',
                        'blocked_other_same_origin_count',
                        'blocked_cross_origin_count',
                    ]),
                ),
            );

            self::assertSame('abort', $execution['route_actions'][$stage . '_search_post'], $label);
            self::assertSame(1, $execution['result']['blocked_request_count'], $label);
            self::assertSame(1, $execution['result'][$counter], $label);
            self::assertSame(0, $execution['result']['blocked_other_same_origin_count'], $label);
            self::assertSame($execution['result']['blocked_request_count'], $classifiedCount, $label);
            self::assertTrue($execution['result'][$seen], $label);
            self::assertFalse($execution['result'][$allowed], $label);
            self::assertSame(1, $execution['result']['flow_error_count'], $label);
            self::assertFalse($execution['result']['ok'], $label);
        }
    }

    public function testActualPlaywrightSnippetClassifiesBlockedRequestsWithoutRawDetails(): void
    {
        $execution = $this->executeFullBrowserFlow(
            $this->executeActualCustomersSearch(''),
            $this->executeActualCustomersSearch(CustomersUiSmokeContract::SEARCH_MARKER),
            'blocked',
        );

        self::assertSame(5, $execution['result']['blocked_request_count']);
        self::assertSame(1, $execution['result']['blocked_initial_search_post_count']);
        self::assertSame(0, $execution['result']['blocked_synthetic_search_post_count']);
        self::assertSame(1, $execution['result']['blocked_navigation_count']);
        self::assertSame(1, $execution['result']['blocked_static_asset_count']);
        self::assertSame(1, $execution['result']['blocked_other_same_origin_count']);
        self::assertSame(1, $execution['result']['blocked_cross_origin_count']);
        self::assertTrue($execution['result']['initial_search_request_seen']);
        self::assertFalse($execution['result']['initial_search_request_allowed']);
        self::assertFalse($execution['result']['initial_search_response_seen']);
        self::assertFalse($execution['result']['initial_search_body_read']);
        self::assertFalse($execution['result']['synthetic_search_request_seen']);
        self::assertFalse($execution['result']['synthetic_search_request_allowed']);
        self::assertFalse($execution['result']['synthetic_search_response_seen']);
        self::assertFalse($execution['result']['synthetic_search_body_read']);
        self::assertSame(1, $execution['result']['flow_error_count']);

        $encodedResult = json_encode($execution['result'], JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('example.test', $encodedResult);
        self::assertStringNotContainsString('csrf_token', $encodedResult);
        self::assertStringNotContainsString('unexpected', $encodedResult);
        self::assertStringNotContainsString('dashboard', $encodedResult);
    }

    public function testPrivateTempDirectoryCleanupRejectsSymlinksAndRemovesFiles(): void
    {
        $directory = CustomersUiSmokeContract::createPrivateTempDirectory();
        $file = $directory . '/state.json';
        self::assertNotFalse(file_put_contents($file, '{}'));
        self::assertTrue(chmod($file, 0600));
        self::assertTrue(CustomersUiSmokeContract::removePrivateTempDirectory($directory));
        self::assertDirectoryDoesNotExist($directory);

        $outside = tempnam(sys_get_temp_dir(), 'fh-customers-ui-smoke-outside-');
        self::assertIsString($outside);
        $link = sys_get_temp_dir() . '/fh-customers-ui-smoke-' . bin2hex(random_bytes(4));
        self::assertTrue(symlink($outside, $link));
        self::assertFalse(CustomersUiSmokeContract::removePrivateTempDirectory($link));
        unlink($link);
        unlink($outside);
    }

    private function readRepoFile(string $relativePath): string
    {
        $content = file_get_contents(__DIR__ . '/../../../' . $relativePath);
        self::assertIsString($content);

        return $content;
    }

    private function executeBrowserSearchPolicy(string $body): string
    {
        $snippetPath = __DIR__ . '/../../../scripts/release-gate/playwright/customers_ui_smoke.js';
        $nodeScript = <<<'JS'
        const fs = require('fs');

        (async () => {
            const config = {
                customers_url: 'https://example.test/index.php/customers',
                allowed_origin: 'https://example.test',
                customers_route_path: '/index.php/customers',
                search_route_path: '/index.php/customers/search',
                asset_path_prefix: '/assets/',
                favicon_path: '/assets/img/favicon.ico',
                search_marker: '__EA_CUSTOMERS_UI_SMOKE_V1_EMPTY_SEARCH__',
                forbidden_keys: [],
                forbidden_response_markers: [],
                open_timeout_ms: 100,
            };
            const source = fs
                .readFileSync(process.argv[1], 'utf8')
                .replace('__CUSTOMERS_UI_SMOKE_CONFIG__', JSON.stringify(config))
                .replace(/;\s*$/, '');
            const smoke = eval('(' + source + ')');
            const body = process.argv[2];
            let action = 'missing';
            let routeHandler;
            const context = {
                route: async (_pattern, handler) => {
                    routeHandler = handler;
                },
            };
            const page = {
                context: () => context,
                on: () => {},
                waitForResponse: () => new Promise(() => {}),
                goto: async () => {
                    const request = {
                        method: () => 'POST',
                        url: () => config.allowed_origin + config.search_route_path,
                        postData: () => body,
                    };
                    await routeHandler({
                        request: () => request,
                        continue: async () => {
                            action = 'continue';
                        },
                        abort: async () => {
                            action = 'abort';
                        },
                    });
                    throw new Error('policy probe complete');
                },
            };

            await smoke(page);
            process.stdout.write(action);
        })().catch(() => process.exit(1));
        JS;
        $process = proc_open(
            ['node', '-e', $nodeScript, $snippetPath, $body],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), is_string($stderr) ? $stderr : '');
        self::assertIsString($stdout);

        return trim($stdout);
    }

    /**
     * @return array{route_actions: array<string, string>, result: array<string, bool|int>}
     */
    private function executeFullBrowserFlow(
        string $initialBody,
        string $syntheticBody,
        string $mode = 'success',
        string $initialResponseBody = '[]',
        string $syntheticResponseBody = '[]',
    ): array {
        $snippetPath = __DIR__ . '/../../../scripts/release-gate/playwright/customers_ui_smoke.js';
        $nodeScript = <<<'JS'
        const fs = require('fs');

        (async () => {
            const config = {
                customers_url: 'https://example.test/index.php/customers',
                allowed_origin: 'https://example.test',
                customers_route_path: '/index.php/customers',
                search_route_path: '/index.php/customers/search',
                asset_path_prefix: '/assets/',
                favicon_path: '/assets/img/favicon.ico',
                search_marker: '__EA_CUSTOMERS_UI_SMOKE_V1_EMPTY_SEARCH__',
                forbidden_keys: ['customer_filter_providers'],
                forbidden_response_markers: ['customer_filter_providers'],
                open_timeout_ms: 100,
            };
            const source = fs
                .readFileSync(process.argv[1], 'utf8')
                .replace('__CUSTOMERS_UI_SMOKE_CONFIG__', JSON.stringify(config))
                .replace(/;\s*$/, '');
            const smoke = eval('(' + source + ')');
            const bodies = {
                initial: process.argv[2],
                synthetic: process.argv[3],
            };
            const mode = process.argv[4];
            const responseBodies = {
                initial: process.argv[5],
                synthetic: process.argv[6],
            };
            const routeActions = {};
            const responseWaiters = [];
            let routeHandler = null;
            let currentKeyword = '';

            const request = (method, url, postData = '') => ({
                method: () => method,
                url: () => url,
                postData: () => postData,
            });
            const dispatch = async (label, interceptedRequest, responseBody = null) => {
                let action = 'missing';
                await routeHandler({
                    request: () => interceptedRequest,
                    continue: async () => {
                        action = 'continue';
                    },
                    abort: async () => {
                        action = 'abort';
                    },
                });
                routeActions[label] = action;

                if (action !== 'continue' || responseBody === null) {
                    return;
                }

                const response = {
                    url: () => interceptedRequest.url(),
                    request: () => interceptedRequest,
                    text: async () => responseBody,
                    status: () => 200,
                };
                const waiterIndex = responseWaiters.findIndex(({predicate}) => predicate(response));
                if (waiterIndex === -1) {
                    throw new Error('response emitted before matching waitForResponse');
                }
                const [{resolve}] = responseWaiters.splice(waiterIndex, 1);
                resolve(response);
            };
            const context = {
                route: async (_pattern, handler) => {
                    routeHandler = handler;
                },
            };
            const page = {
                context: () => context,
                on: () => {},
                waitForResponse: (predicate) => new Promise((resolve) => responseWaiters.push({predicate, resolve})),
                goto: async () => {
                    if (mode === 'blocked') {
                        await dispatch(
                            'initial_search_post',
                            request(
                                'POST',
                                config.allowed_origin + config.search_route_path,
                                bodies.initial + '&unexpected=value',
                            ),
                        );
                        await dispatch('navigation', request('POST', config.customers_url));
                        await dispatch('static_asset', request('POST', config.allowed_origin + '/assets/js/app.js'));
                        await dispatch('other_same_origin', request('GET', config.allowed_origin + '/index.php/dashboard'));
                        await dispatch('cross_origin', request('GET', 'https://blocked.example.test/pixel'));
                        throw new Error('blocked classification complete');
                    }

                    if (mode === 'malformed-initial') {
                        await dispatch(
                            'initial_search_post',
                            request('POST', config.allowed_origin + config.search_route_path, bodies.initial),
                        );
                        throw new Error('initial classification complete');
                    }

                    await dispatch('navigation', request('GET', config.customers_url));
                    await dispatch('static_asset', request('GET', config.allowed_origin + '/assets/js/app.js'));
                    await dispatch(
                        'initial_search_post',
                        request('POST', config.allowed_origin + config.search_route_path, bodies.initial),
                        responseBodies.initial,
                    );
                    return {status: () => 200};
                },
                locator: (selector) => ({
                    count: async () => (selector === '#customers-page' ? 1 : 0),
                    fill: async (value) => {
                        currentKeyword = value;
                    },
                    press: async () => {
                        if (currentKeyword !== config.search_marker) {
                            throw new Error('synthetic marker was not filled before submit');
                        }
                        await dispatch(
                            'synthetic_search_post',
                            request('POST', config.allowed_origin + config.search_route_path, bodies.synthetic),
                            mode === 'malformed-synthetic' ? null : responseBodies.synthetic,
                        );
                        if (mode === 'malformed-synthetic') {
                            throw new Error('synthetic classification complete');
                        }
                    },
                    isVisible: async () => selector === '#filter-customers .results em',
                }),
                evaluate: async (callback, argument) => callback(argument),
                waitForFunction: async (callback) => {
                    if (!callback()) {
                        throw new Error('empty state was not reached');
                    }
                },
                waitForTimeout: async () => {},
            };

            global.window = {vars: () => undefined};
            global.document = {
                documentElement: {innerHTML: ''},
                querySelectorAll: () => [],
                querySelector: (selector) => (selector === '#filter-customers .results em' ? {} : null),
            };

            const emitted = await smoke(page);
            const prefix = '__CUSTOMERS_UI_SMOKE_GATE__';
            if (typeof emitted !== 'string' || !emitted.startsWith(prefix)) {
                throw new Error('smoke result prefix missing');
            }
            process.stdout.write(
                JSON.stringify({route_actions: routeActions, result: JSON.parse(emitted.slice(prefix.length))}),
            );
        })().catch((error) => {
            process.stderr.write(error instanceof Error ? error.message : 'unknown test harness error');
            process.exit(1);
        });
        JS;
        $process = proc_open(
            [
                'node',
                '-e',
                $nodeScript,
                $snippetPath,
                $initialBody,
                $syntheticBody,
                $mode,
                $initialResponseBody,
                $syntheticResponseBody,
            ],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), is_string($stderr) ? $stderr : '');
        self::assertIsString($stdout);
        $decoded = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function executeActualCustomersSearch(string $keyword, bool $addInheritedKey = false): string
    {
        $clientPath = __DIR__ . '/../../../assets/js/http/customers_http_client.js';
        $nodeScript = <<<'JS'
        const fs = require('fs');

        const serializeScalarForm = (data) => {
            const pairs = [];
            for (const key in data) {
                const value = data[key];
                if (value !== null && !['string', 'number', 'undefined'].includes(typeof value)) {
                    throw new Error('Customers search emitted a non-scalar form value');
                }
                pairs.push(encodeURIComponent(key) + '=' + encodeURIComponent(value == null ? '' : value));
            }
            return pairs.join('&');
        };

        let emitted = null;
        global.App = {Http: {}, Utils: {Url: {siteUrl: (path) => path}}};
        global.vars = (key) => (key === 'csrf_token' ? 'token' : undefined);
        global.$ = {
            post: (url, data) => {
                if (url !== 'customers/search') {
                    throw new Error('unexpected Customers client route');
                }
                const expectedKeys = ['csrf_token', 'keyword', 'limit', 'offset', 'order_by'];
                if (JSON.stringify(Object.keys(data)) !== JSON.stringify(expectedKeys)) {
                    throw new Error('Customers search emitted an unexpected form shape');
                }
                if (
                    data.csrf_token !== 'token' ||
                    data.keyword !== process.argv[2] ||
                    data.limit !== 20 ||
                    data.offset !== null ||
                    data.order_by !== undefined
                ) {
                    throw new Error('Customers search emitted unexpected form values');
                }
                emitted = serializeScalarForm(data);
                return Promise.resolve([]);
            },
        };
        eval(fs.readFileSync(process.argv[1], 'utf8'));
        if (process.argv[3] === 'inherited') {
            Object.prototype.inherited = 'value';
        }
        App.Http.Customers.search(process.argv[2], 20, null, null);
        if (emitted === null) {
            throw new Error('Customers client did not emit a request');
        }
        process.stdout.write(emitted);
        JS;
        $process = proc_open(
            ['node', '-e', $nodeScript, $clientPath, $keyword, $addInheritedKey ? 'inherited' : 'clean'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), is_string($stderr) ? $stderr : '');
        self::assertIsString($stdout);

        return trim($stdout);
    }
}
