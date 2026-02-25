<?php

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;
use ReleaseGate\GateCliSupport;

require_once __DIR__ . '/../../../scripts/release-gate/lib/GateCliSupport.php';

class GateCliSupportTest extends TestCase
{
    public function testClassifyAssertionExitCodeReturnsRuntimeForPreflightChecks(): void
    {
        $checks = [
            [
                'name' => 'auth_login_validate',
                'status' => 'fail',
                'error' => 'Credentials rejected',
            ],
        ];

        $actual = GateCliSupport::classifyAssertionExitCode($checks);

        $this->assertSame(GateCliSupport::EXIT_RUNTIME_ERROR, $actual);
    }

    public function testClassifyAssertionExitCodeReturnsRuntimeForHttpStatusMismatch(): void
    {
        $checks = [
            [
                'name' => 'dashboard_metrics',
                'status' => 'fail',
                'error' => 'GET metrics expected HTTP 200, got 500.',
            ],
        ];

        $actual = GateCliSupport::classifyAssertionExitCode($checks);

        $this->assertSame(GateCliSupport::EXIT_RUNTIME_ERROR, $actual);
    }

    public function testClassifyAssertionExitCodeReturnsAssertionForNonRuntimeChecks(): void
    {
        $checks = [
            [
                'name' => 'dashboard_heatmap',
                'status' => 'fail',
                'error' => 'weekday must be in range 1..5',
            ],
        ];

        $actual = GateCliSupport::classifyAssertionExitCode($checks);

        $this->assertSame(GateCliSupport::EXIT_ASSERTION_FAILURE, $actual);
    }

    public function testClassifyAssertionExitCodeReturnsAssertionForMalformedInput(): void
    {
        $actual = GateCliSupport::classifyAssertionExitCode([]);

        $this->assertSame(GateCliSupport::EXIT_ASSERTION_FAILURE, $actual);
    }

    public function testResolveCsrfNamesFromConfigReturnsDefaultsForMissingFile(): void
    {
        $actual = GateCliSupport::resolveCsrfNamesFromConfig('/tmp/does-not-exist-' . uniqid('', true) . '.php');

        $this->assertSame(
            [
                'csrf_token_name' => 'csrf_token',
                'csrf_cookie_name' => 'csrf_cookie',
            ],
            $actual,
        );
    }

    public function testResolveCsrfNamesFromConfigAppliesCookiePrefix(): void
    {
        $configPath = tempnam(sys_get_temp_dir(), 'gate-config-');
        $this->assertIsString($configPath);

        try {
            $configContent = <<<'PHP'
<?php
$config['cookie_prefix'] = 'fh_';
$config['csrf_token_name'] = 'my_csrf_token';
$config['csrf_cookie_name'] = 'my_csrf_cookie';
PHP;

            file_put_contents($configPath, $configContent);

            $actual = GateCliSupport::resolveCsrfNamesFromConfig($configPath);

            $this->assertSame(
                [
                    'csrf_token_name' => 'my_csrf_token',
                    'csrf_cookie_name' => 'fh_my_csrf_cookie',
                ],
                $actual,
            );
        } finally {
            if (is_file($configPath)) {
                unlink($configPath);
            }
        }
    }
}
