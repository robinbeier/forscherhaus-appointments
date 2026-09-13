<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

final class DefensePhpCacheTest extends TestCase
{
    private array $fixtures = [];

    protected function tearDown(): void
    {
        foreach ($this->fixtures as $path) {
            if (is_file($path)) {
                @unlink($path);
            } elseif (is_dir($path)) {
                $this->remove($path);
            }
        }
    }

    public function testCacheHitUsesExactKeyAndReportsCachedVertices(): void
    {
        $result = $this->runHelper('hit');
        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertSame('hit', $result['report']['cache']);
        $command = $result['commands'][0];
        self::assertContains('--load', $command);
        self::assertMatchesRegularExpression(
            '/^forscherhaus-local\/php-fpm:[0-9a-f]{64}$/',
            $this->after($command, '--tag'),
        );
        self::assertStringContainsString('type=gha,version=2,scope=defense-php-', implode(' ', $command));
    }

    public function testCacheMissBuildsThenExports(): void
    {
        $result = $this->runHelper('miss');
        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertSame('miss', $result['report']['cache']);
        self::assertSame(['cache_build', 'cache_export'], array_column($result['report']['phases'], 'phase'));
        self::assertContains('--output=type=cacheonly', $result['commands'][1]);
    }

    public function testImporterFailureFallsBackButLaterBuildFailureIsFatal(): void
    {
        $result = $this->runHelper('import-error-build-error');
        self::assertSame(23, $result['code']);
        self::assertSame('import_error', $result['report']['cache']);
        self::assertCount(2, $result['commands']);
        self::assertNotContains('--cache-from', $result['commands'][1]);
        self::assertContains('--no-cache', $result['commands'][1]);
    }

    public function testSuccessfulImportAndRunFailureDoesNotFallback(): void
    {
        $result = $this->runHelper('import-success-run-error');
        self::assertSame(23, $result['code']);
        self::assertCount(1, $result['commands']);
    }

    public function testMissingRuntimeUsesOrdinaryBuild(): void
    {
        $result = $this->runHelper('miss', false);
        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertSame('unavailable', $result['report']['cache']);
        self::assertCount(1, $result['commands']);
        self::assertNotContains('--cache-from', $result['commands'][0]);
    }

    public function testChangedBuildInputChangesImageKey(): void
    {
        $first = $this->runHelper('hit');
        $second = $this->runHelper('hit', true, ['args' => ['PHP_FPM_BASE_IMAGE' => 'php:8.4.99-fpm-bookworm']]);
        self::assertNotSame(
            $this->after($first['commands'][0], '--tag'),
            $this->after($second['commands'][0], '--tag'),
        );
    }

    public function testTargetAndTimingFieldsArePreserved(): void
    {
        $result = $this->runHelper('hit', true, ['target' => 'runtime']);
        self::assertSame('runtime', $this->after($result['commands'][0], '--target'));
        self::assertGreaterThanOrEqual(0, $result['report']['total_seconds']);
        foreach ($result['report']['phases'] as $phase) {
            self::assertGreaterThanOrEqual(0, $phase['seconds']);
            self::assertArrayHasKey('exit_code', $phase);
            self::assertArrayHasKey('timed_out', $phase);
        }
    }

    public function testCacheBuildTimeoutFallsBackToOrdinaryBuild(): void
    {
        $result = $this->runHelper('timeout', true, [], 0.3);
        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertSame('timeout', $result['report']['cache']);
        self::assertTrue($result['report']['phases'][0]['timed_out']);
        self::assertCount(3, $result['commands']);
        self::assertNotContains('--cache-from', $result['commands'][1]);
        self::assertContains('--no-cache', $result['commands'][1]);
        self::assertLessThan(2, $result['report']['phases'][0]['seconds']);
    }

    public function testExporterFailureDoesNotMaskSuccessfulImage(): void
    {
        $result = $this->runHelper('export-error');
        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertSame('miss', $result['report']['cache']);
        self::assertSame(2, count($result['commands']));
        self::assertSame(31, $result['report']['phases'][1]['exit_code']);
    }

    public function testUnknownCacheOutputDoesNotReportFalseHit(): void
    {
        $result = $this->runHelper('unknown');
        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertSame('unknown', $result['report']['cache']);
    }

    public function testImporterFailureFallsBackSuccessfully(): void
    {
        $result = $this->runHelper('import-error');
        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertSame('import_error', $result['report']['cache']);
        self::assertSame(['cache_build', 'build', 'cache_export'], array_column($result['report']['phases'], 'phase'));
        self::assertNotContains('--cache-from', $result['commands'][1]);
        self::assertContains('--no-cache', $result['commands'][1]);
    }

    public function testUnknownFailureRemainsFatal(): void
    {
        $result = $this->runHelper('unknown-error');
        self::assertSame(24, $result['code']);
        self::assertCount(1, $result['commands']);
        self::assertSame('build_failed', $result['report']['status']);
    }

    public function testExportDeadlinePreservesLoadedImage(): void
    {
        $result = $this->runHelper('export-timeout', true, [], 0.3);
        self::assertSame(0, $result['code'], $result['stderr']);
        self::assertSame('built', $result['report']['status']);
        self::assertTrue($result['report']['phases'][1]['timed_out']);
        self::assertLessThan(2, $result['report']['phases'][1]['seconds']);
    }

    public function testLazyCacheReadsRecoverOnlyWithCompletedCachedBuildAndSpecificReadError(): void
    {
        foreach (['lazy-missing', 'lazy-http'] as $mode) {
            $result = $this->runHelper($mode);
            self::assertSame(0, $result['code'], $result['stderr']);
            self::assertSame('import_error', $result['report']['cache']);
            self::assertCount(3, $result['commands']);
            self::assertNotContains('--cache-from', $result['commands'][1]);
            self::assertContains('--no-cache', $result['commands'][1]);
        }
    }

    public function testLazyReadRecoveryNeverMasksOtherBuildOrLoadErrors(): void
    {
        foreach (
            ['lazy-run', 'lazy-disk', 'lazy-no-import', 'lazy-incomplete', 'lazy-generic', 'lazy-registry']
            as $mode
        ) {
            $result = $this->runHelper($mode);
            self::assertSame(25, $result['code'], $mode);
            self::assertCount(1, $result['commands'], $mode);
            self::assertSame('build_failed', $result['report']['status']);
        }
    }

    private function runHelper(string $mode, bool $runtime = true, array $build = [], float $timeout = 0.0): array
    {
        $fixture = sys_get_temp_dir() . '/defense-php-cache-' . bin2hex(random_bytes(5));
        mkdir($fixture . '/docker/php-fpm', 0700, true);
        $this->fixtures[] = $fixture;
        copy(__DIR__ . '/../../../docker/php-fpm/Dockerfile', $fixture . '/docker/php-fpm/Dockerfile');
        $log = $fixture . '/commands.jsonl';
        $bin = $fixture . '-bin';
        mkdir($bin, 0700, true);
        $this->fixtures[] = $bin;
        $docker = $bin . '/docker';
        $script = <<<'PYTHON'
        #!/usr/bin/env python3
        import json, os, sys, time
        args = sys.argv[1:]
        mode = os.environ['BUILD_MODE']
        if args[:2] == ['image', 'inspect']:
            print('sha256:' + 'a' * 64)
            sys.exit(0)
        with open(os.environ['COMMAND_LOG'], 'a') as stream:
            stream.write(json.dumps(args) + '\n')
        def emit(identity, name, **values):
            vertex = {'digest': identity, 'name': name, **values}
            print(json.dumps({'vertexes': [vertex]}), flush=True)
        if mode.startswith('lazy-') and '--cache-from' in args:
            if mode != 'lazy-no-import':
                emit('cache', 'importing cache manifest from gha', completed='2026-09-13T12:00:00Z')
            emit('run', '[2/2] RUN dependencies', completed='2026-09-13T12:00:00Z', cached=True)
            if mode == 'lazy-incomplete':
                emit('copy', '[3/3] COPY runtime /runtime', started='2026-09-13T12:00:00Z')
            error = 'blob sha256:' + 'a' * 64 + ': not found'
            if mode == 'lazy-http':
                error = 'invalid status response 503 for https://cache.blob.core.windows.net/layer'
            if mode == 'lazy-generic':
                error = 'unexpected EOF'
            if mode == 'lazy-registry':
                error = 'invalid status response 503 for https://registry.example/layer'
            emit('export', '[2/2] RUN dependencies' if mode == 'lazy-run' else 'sending tarball', error=error)
            if mode == 'lazy-disk':
                emit('disk', 'exporting to docker image format', error='no space left on device')
            sys.exit(25)
        if mode == 'timeout' and '--cache-from' in args:
            time.sleep(3)
        if mode in ('import-error', 'import-error-build-error') and '--cache-from' in args:
            emit('cache', 'importing cache manifest from gha', error='cache unavailable')
            sys.exit(17)
        if mode == 'import-error-build-error' and '--cache-from' not in args:
            emit('run', '[2/2] RUN dependencies', error='compiler failed')
            sys.exit(23)
        if mode == 'import-success-run-error' and '--cache-from' in args:
            emit('cache', 'importing cache manifest from gha', completed='2026-09-13T12:00:00Z')
            emit('run', '[2/2] RUN dependencies', error='compiler failed')
            sys.exit(23)
        if mode == 'unknown-error':
            print('unclassified failure', flush=True)
            sys.exit(24)
        if mode == 'export-error' and '--output=type=cacheonly' in args:
            sys.exit(31)
        if mode == 'export-timeout' and '--output=type=cacheonly' in args:
            time.sleep(3)
        if mode != 'unknown':
            emit('run', '[2/2] RUN dependencies', completed='2026-09-13T12:00:00Z', cached=mode == 'hit')
        PYTHON;
        file_put_contents($docker, $script);
        chmod($docker, 0700);
        $config = json_encode(
            [
                'services' => [
                    'php-fpm' => [
                        'platform' => 'linux/amd64',
                        'build' => ['context' => $fixture . '/docker/php-fpm'] + $build,
                    ],
                ],
            ],
            JSON_THROW_ON_ERROR,
        );
        $env = ['PATH' => $bin . ':' . getenv('PATH'), 'BUILD_MODE' => $mode, 'COMMAND_LOG' => $log];
        if ($runtime) {
            $env['ACTIONS_RUNTIME_TOKEN'] = 'synthetic';
            $env['ACTIONS_RESULTS_URL'] = 'https://example.test/';
        }
        $command = ['python3', __DIR__ . '/../../../scripts/ci/defense_php_cache.py', '--platform', 'linux/amd64'];
        if ($timeout > 0) {
            $code =
                'import sys; sys.path.insert(0, sys.argv[1]); import defense_php_cache as h; h.CACHE_BUILD_SECONDS=float(sys.argv[2]); h.CACHE_EXPORT_SECONDS=float(sys.argv[2]); sys.argv=["helper","--platform","linux/amd64"]; raise SystemExit(h.main())';
            $command = ['python3', '-c', $code, dirname(__DIR__, 3) . '/scripts/ci', (string) $timeout];
        }
        $process = proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $fixture,
            $env,
        );
        fwrite($pipes[0], $config);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $code = proc_close($process);
        $report = json_decode(trim($stdout), true) ?: [];
        $commands = array_map(
            static fn(string $line): array => json_decode($line, true),
            file($log, FILE_IGNORE_NEW_LINES) ?: [],
        );
        return compact('code', 'stderr', 'report', 'commands');
    }

    private function after(array $command, string $option): string
    {
        $index = array_search($option, $command, true);
        return (string) ($command[$index + 1] ?? '');
    }

    private function remove(string $path): void
    {
        foreach (array_diff(scandir($path), ['.', '..']) as $name) {
            $child = $path . '/' . $name;
            is_dir($child) ? $this->remove($child) : @unlink($child);
        }
        @rmdir($path);
    }
}
