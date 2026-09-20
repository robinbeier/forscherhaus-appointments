<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

final class ProdCommonLoopbackHttpTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/prod-loopback-http-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->directory, 0700));
        $stub = <<<'BASH'
        #!/usr/bin/env bash
        set -euo pipefail
        printf '%s\0' "$@" > "${CURL_ARGUMENTS:?}"
        printf '%b' "${CURL_OBSERVED:?}"
        BASH;
        self::assertSame(strlen($stub), file_put_contents($this->directory . '/curl', $stub));
        self::assertTrue(chmod($this->directory . '/curl', 0700));
    }

    protected function tearDown(): void
    {
        @unlink($this->directory . '/curl.args');
        @unlink($this->directory . '/curl');
        @rmdir($this->directory);
    }

    public function testLoopbackHealthRequestDisablesProxyAndVerifiesPeer(): void
    {
        $result = $this->runProbe("200\\t127.0.0.1", 'http://127.0.0.1/index.php/healthz');

        self::assertSame(0, $result['exit_code'], $result['stderr']);
        self::assertSame('200', $result['stdout']);
        $arguments = explode("\0", rtrim((string) file_get_contents($this->directory . '/curl.args'), "\0"));
        self::assertContains('--proxy', $arguments);
        self::assertContains('--noproxy', $arguments);
        self::assertContains('*', $arguments);
        self::assertContains('http://127.0.0.1/index.php/healthz', $arguments);
        self::assertContains('X-Health-Token: synthetic-token', $arguments);
        $proxy = array_search('--proxy', $arguments, true);
        self::assertIsInt($proxy);
        self::assertSame('', $arguments[$proxy + 1] ?? null);
    }

    public function testLoopbackHealthRequestRejectsNonLoopbackPeerAndUrl(): void
    {
        $peerMismatch = $this->runProbe("200\\t203.0.113.10", 'http://127.0.0.1/index.php/healthz');
        self::assertSame('loopback_peer_mismatch', $peerMismatch['stdout']);

        $urlMismatch = $this->runProbe("200\\t127.0.0.1", 'http://example.test/index.php/healthz');
        self::assertSame('loopback_url_required', $urlMismatch['stdout']);
    }

    /** @return array{exit_code:int,stdout:string,stderr:string} */
    private function runProbe(string $observed, string $url): array
    {
        $process = proc_open(
            [
                '/bin/bash',
                '-c',
                'source scripts/ops/lib/prod_common.sh; prod_loopback_http_code "$1" -H "X-Health-Token: synthetic-token"',
                'bash',
                $url,
            ],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 3),
            array_merge($_ENV, [
                'PATH' => $this->directory . PATH_SEPARATOR . (getenv('PATH') ?: ''),
                'CURL_ARGUMENTS' => $this->directory . '/curl.args',
                'CURL_OBSERVED' => $observed,
            ]),
        );
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [
            'exit_code' => proc_close($process),
            'stdout' => is_string($stdout) ? $stdout : '',
            'stderr' => is_string($stderr) ? $stderr : '',
        ];
    }
}
