<?php

namespace Tests\Unit\Helper;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

require_once APPPATH . 'helpers/env_helper.php';

final class EnvHelperTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_ENV['CODEX_TEST_ENV_KEY'], $_SERVER['CODEX_TEST_ENV_KEY'], $_SERVER['REDIRECT_CODEX_TEST_ENV_KEY']);
        putenv('CODEX_TEST_ENV_KEY');

        parent::tearDown();
    }

    public function testEnvPrefersPhpEnvironmentMap(): void
    {
        $_ENV['CODEX_TEST_ENV_KEY'] = 'env-value';
        putenv('CODEX_TEST_ENV_KEY=process-value');
        $_SERVER['CODEX_TEST_ENV_KEY'] = 'server-value';

        $this->assertSame('env-value', env('CODEX_TEST_ENV_KEY', 'default'));
    }

    public function testEnvFallsBackToProcessEnvironment(): void
    {
        putenv('CODEX_TEST_ENV_KEY=process-value');
        $_SERVER['CODEX_TEST_ENV_KEY'] = 'server-value';

        $this->assertSame('process-value', env('CODEX_TEST_ENV_KEY', 'default'));
    }

    public function testEnvFallsBackToApacheStyleServerVariable(): void
    {
        $_SERVER['CODEX_TEST_ENV_KEY'] = 'server-value';

        $this->assertSame('server-value', env('CODEX_TEST_ENV_KEY', 'default'));
    }

    public function testEnvFallsBackToRedirectedApacheVariable(): void
    {
        $_SERVER['REDIRECT_CODEX_TEST_ENV_KEY'] = 'redirected-server-value';

        $this->assertSame('redirected-server-value', env('CODEX_TEST_ENV_KEY', 'default'));
    }

    public function testEnvKeepsExplicitEmptyPhpEnvironmentValue(): void
    {
        $_ENV['CODEX_TEST_ENV_KEY'] = '';
        putenv('CODEX_TEST_ENV_KEY=process-value');
        $_SERVER['CODEX_TEST_ENV_KEY'] = 'server-value';

        $this->assertSame('', env('CODEX_TEST_ENV_KEY', 'default'));
    }

    public function testEnvReturnsDefaultWhenNothingIsConfigured(): void
    {
        $this->assertSame('default', env('CODEX_TEST_ENV_KEY', 'default'));
    }

    public function testEnvRejectsEmptyKey(): void
    {
        $this->expectException(InvalidArgumentException::class);

        env('');
    }
}
