<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers;

use PHPUnit\Framework\TestCase;

/** Proves real alias handoff arguments with a redirect double, not an HTTP/CSRF chain. */
final class BackendApiSettingsRedirectTest extends TestCase
{
    public function testSettingsAliasesRequestMethodPreservingLocationRedirects(): void
    {
        foreach (
            [
                'ajax_save_settings' => 'general_settings/save',
                'ajax_apply_global_working_plan' => 'business_settings/apply_global_working_plan',
                'ajax_delete_admin' => 'admins/destroy',
                'ajax_delete_secretary' => 'secretaries/destroy',
            ]
            as $action => $destination
        ) {
            foreach (['POST', 'GET', 'HEAD', 'PUT', 'DELETE'] as $method) {
                $process = proc_open(
                    [
                        PHP_BINARY,
                        '-n',
                        dirname(__DIR__, 2) . '/Fixtures/backend_settings_redirect_probe.php',
                        $action,
                        $method,
                    ],
                    [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
                    $pipes,
                );
                self::assertIsResource($process);
                fclose($pipes[0]);
                $stdout = stream_get_contents($pipes[1]);
                $stderr = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                self::assertSame(0, proc_close($process), $stderr);
                self::assertSame(
                    [
                        'uri' => $destination,
                        'method' => 'location',
                        'code' => 307,
                        'request_method' => $method,
                        'post' => [
                            'settings' => [['name' => 'synthetic', 'value' => 'example']],
                            'csrf_token' => 'synthetic-token',
                        ],
                    ],
                    json_decode($stdout, true, 512, JSON_THROW_ON_ERROR),
                    $action . ' ' . $method,
                );
            }
        }
    }
}
