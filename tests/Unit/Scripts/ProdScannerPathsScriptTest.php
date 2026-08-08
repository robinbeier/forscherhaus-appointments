<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

final class ProdScannerPathsScriptTest extends TestCase
{
    public function testScannerPathCheckFailsOnPublicHttpSuccessWithoutPrintingPaths(): void
    {
        $workspace = sys_get_temp_dir() . '/prod-scanner-paths-' . bin2hex(random_bytes(8));
        $stubBin = $workspace . '/bin';
        $curlLog = $workspace . '/curl.log';

        mkdir($stubBin, 0777, true);

        try {
            $this->writeCurlStub($stubBin);

            $result = $this->runCommand(
                [
                    'bash',
                    '-c',
                    'source scripts/ops/lib/prod_scanner_paths.sh; prod_scanner_paths_check_all https://example.test; printf "failures=%s\n" "$PROD_SCANNER_PATH_FAILURES"',
                ],
                $this->repoRoot(),
                $this->commandEnv($stubBin, $curlLog, 200),
            );

            self::assertSame(0, $result['exit_code'], $result['stderr']);
            self::assertStringContainsString('scanner_path.root_env=200', $result['stdout']);
            self::assertStringContainsString('scanner_path.root_env_tilde_backup=200', $result['stdout']);
            self::assertStringContainsString('scanner_path.wp_admin=200', $result['stdout']);
            self::assertStringContainsString('scanner_query.phpinfo_page=200', $result['stdout']);
            self::assertStringContainsString('failures=18', $result['stdout']);
            self::assertStringContainsString('FAIL scanner_path.root_env public_http_200', $result['stderr']);
            self::assertStringNotContainsString('/.env', $result['stdout'] . $result['stderr']);
            self::assertStringNotContainsString('/?page=phpinfo', $result['stdout'] . $result['stderr']);
            self::assertStringNotContainsString('scanner body', $result['stdout'] . $result['stderr']);
        } finally {
            $this->removeDirectory($workspace);
        }
    }

    public function testScannerPathCheckPassesOnDeniedResponses(): void
    {
        $workspace = sys_get_temp_dir() . '/prod-scanner-paths-' . bin2hex(random_bytes(8));
        $stubBin = $workspace . '/bin';
        $curlLog = $workspace . '/curl.log';

        mkdir($stubBin, 0777, true);

        try {
            $this->writeCurlStub($stubBin);

            $result = $this->runCommand(
                [
                    'bash',
                    '-c',
                    'source scripts/ops/lib/prod_scanner_paths.sh; prod_scanner_paths_check_all https://example.test; printf "failures=%s\n" "$PROD_SCANNER_PATH_FAILURES"',
                ],
                $this->repoRoot(),
                $this->commandEnv($stubBin, $curlLog, 403),
            );

            self::assertSame(0, $result['exit_code'], $result['stderr']);
            self::assertStringContainsString('scanner_path.root_env=403', $result['stdout']);
            self::assertStringContainsString('scanner_path.root_env_tilde_backup=403', $result['stdout']);
            self::assertStringContainsString('scanner_path.wp_admin=403', $result['stdout']);
            self::assertStringContainsString('scanner_query.phpinfo_page=403', $result['stdout']);
            self::assertStringContainsString('failures=0', $result['stdout']);
            self::assertSame('', $result['stderr']);
        } finally {
            $this->removeDirectory($workspace);
        }
    }

    public function testScannerHostContextCheckRejectsRedirectsAndSuccesses(): void
    {
        $workspace = sys_get_temp_dir() . '/prod-scanner-host-contexts-' . bin2hex(random_bytes(8));
        $stubBin = $workspace . '/bin';
        $curlLog = $workspace . '/curl.log';

        mkdir($stubBin, 0777, true);

        try {
            $this->writeCurlStub($stubBin);
            $this->writeIpStub($stubBin);

            $result = $this->runCommand(
                [
                    'bash',
                    '-c',
                    'source scripts/ops/lib/prod_scanner_paths.sh; prod_scanner_host_contexts_check_all; printf "failures=%s\n" "$PROD_SCANNER_HOST_CONTEXT_FAILURES"',
                ],
                $this->repoRoot(),
                $this->hostContextCommandEnv($stubBin, $curlLog, 403, 301, 200, 403, 404, 301),
            );

            self::assertSame(0, $result['exit_code'], $result['stderr']);
            self::assertStringContainsString('scanner_host.http_default.wp_admin=403', $result['stdout']);
            self::assertStringContainsString('scanner_host.http_unmatched.wp_admin=301', $result['stdout']);
            self::assertStringContainsString('scanner_host.http_ip_literal.wp_admin=200', $result['stdout']);
            self::assertStringContainsString('scanner_host.https_default.wp_admin=403', $result['stdout']);
            self::assertStringContainsString('scanner_host.https_unmatched.wp_admin=404', $result['stdout']);
            self::assertStringContainsString('scanner_host.https_ip_literal.wp_admin=301', $result['stdout']);
            self::assertStringContainsString('failures=12', $result['stdout']);
            self::assertStringContainsString(
                'FAIL scanner_host.http_unmatched.wp_admin unexpected_http_301',
                $result['stderr'],
            );
            self::assertStringContainsString(
                'FAIL scanner_host.https_ip_literal.wp_admin unexpected_http_301',
                $result['stderr'],
            );
            self::assertStringNotContainsString('/wp-admin/', $result['stdout'] . $result['stderr']);
            self::assertStringNotContainsString('rob429-unmatched.invalid', $result['stdout'] . $result['stderr']);
            self::assertDoesNotMatchRegularExpression(
                '/\b(?:[0-9]{1,3}\.){3}[0-9]{1,3}\b/',
                $result['stdout'] . $result['stderr'],
            );
            self::assertStringNotContainsString('scanner body', $result['stdout'] . $result['stderr']);
        } finally {
            $this->removeDirectory($workspace);
        }
    }

    public function testScannerHostContextCheckAcceptsOnlyDeniedResponses(): void
    {
        $workspace = sys_get_temp_dir() . '/prod-scanner-host-contexts-' . bin2hex(random_bytes(8));
        $stubBin = $workspace . '/bin';
        $curlLog = $workspace . '/curl.log';

        mkdir($stubBin, 0777, true);

        try {
            $this->writeCurlStub($stubBin);
            $this->writeIpStub($stubBin);

            $result = $this->runCommand(
                [
                    'bash',
                    '-c',
                    'source scripts/ops/lib/prod_scanner_paths.sh; prod_scanner_host_contexts_check_all; printf "failures=%s\n" "$PROD_SCANNER_HOST_CONTEXT_FAILURES"',
                ],
                $this->repoRoot(),
                $this->hostContextCommandEnv($stubBin, $curlLog, 403, 404, 403, 404, 403, 404),
            );

            self::assertSame(0, $result['exit_code'], $result['stderr']);
            self::assertStringContainsString('scanner_host.http_default.wp_admin=403', $result['stdout']);
            self::assertStringContainsString('scanner_host.http_unmatched.wp_admin=404', $result['stdout']);
            self::assertStringContainsString('scanner_host.http_ip_literal.wp_admin=403', $result['stdout']);
            self::assertStringContainsString('scanner_host.https_default.wp_admin=404', $result['stdout']);
            self::assertStringContainsString('scanner_host.https_unmatched.wp_admin=403', $result['stdout']);
            self::assertStringContainsString('scanner_host.https_ip_literal.wp_admin=404', $result['stdout']);
            self::assertStringContainsString('failures=0', $result['stdout']);
            self::assertSame('', $result['stderr']);
        } finally {
            $this->removeDirectory($workspace);
        }
    }

    public function testProdValidateStreamsScannerPathHelper(): void
    {
        $workspace = sys_get_temp_dir() . '/prod-scanner-paths-' . bin2hex(random_bytes(8));
        $stubBin = $workspace . '/bin';
        $curlLog = $workspace . '/curl.log';

        mkdir($stubBin, 0777, true);

        try {
            $this->writeCurlStub($stubBin);
            $this->writeIpStub($stubBin);
            $this->writeSshStub($stubBin);

            $result = $this->runCommand(
                ['bash', 'scripts/ops/prod_validate_after_change.sh', '--prod-ssh-target', 'prod.example'],
                $this->repoRoot(),
                $this->commandEnv($stubBin, $curlLog, 403),
            );

            self::assertNotSame(127, $result['exit_code'], $result['stderr']);
            self::assertStringContainsString('scanner_path.root_env=403', $result['stdout']);
            self::assertStringContainsString('scanner_path.root_env_tilde_backup=403', $result['stdout']);
            self::assertStringContainsString('scanner_query.phpinfo_page=403', $result['stdout']);
            self::assertStringContainsString('scanner_path_failures=0', $result['stdout']);
            self::assertStringContainsString('scanner_path_monitor_failures=0', $result['stdout']);
            self::assertStringContainsString('scanner_host.http_default.wp_admin=403', $result['stdout']);
            self::assertStringContainsString('scanner_host.http_unmatched.wp_admin=403', $result['stdout']);
            self::assertStringContainsString('scanner_host.http_ip_literal.wp_admin=403', $result['stdout']);
            self::assertStringContainsString('scanner_host.https_default.wp_admin=403', $result['stdout']);
            self::assertStringContainsString('scanner_host.https_unmatched.wp_admin=403', $result['stdout']);
            self::assertStringContainsString('scanner_host.https_ip_literal.wp_admin=403', $result['stdout']);
            self::assertStringContainsString('scanner_host_context_failures=0', $result['stdout']);
            self::assertStringContainsString('app_https=200', $result['stdout']);
            self::assertStringContainsString('www_https=200', $result['stdout']);
            self::assertStringNotContainsString('/.env', $result['stdout'] . $result['stderr']);
            self::assertStringNotContainsString('/?page=phpinfo', $result['stdout'] . $result['stderr']);
            self::assertStringNotContainsString('rob429-unmatched.invalid', $result['stdout'] . $result['stderr']);
            self::assertDoesNotMatchRegularExpression(
                '/\b(?:[0-9]{1,3}\.){3}[0-9]{1,3}\b/',
                $result['stdout'] . $result['stderr'],
            );

            $curlLogContents = file_get_contents($curlLog);
            self::assertIsString($curlLogContents);
            self::assertStringContainsString('https://dasforscherhaus-leg.de/.env~', $curlLogContents);
            self::assertStringContainsString('https://monitor.dasforscherhaus-leg.de/.env~', $curlLogContents);
        } finally {
            $this->removeDirectory($workspace);
        }
    }

    public function testProdValidateEnforcesHostContextFailuresOnlyWhenRequired(): void
    {
        $workspace = sys_get_temp_dir() . '/prod-scanner-enforcement-' . bin2hex(random_bytes(8));
        $stubBin = $workspace . '/bin';
        $curlLog = $workspace . '/curl.log';

        mkdir($stubBin, 0777, true);

        try {
            $this->writeCurlStub($stubBin);
            $this->writeIpStub($stubBin);
            $this->writeSshStub($stubBin);
            $env = $this->hostContextCommandEnv($stubBin, $curlLog, 403, 301, 403, 403, 403, 403);

            $advisory = $this->runCommand(
                ['bash', 'scripts/ops/prod_validate_after_change.sh', '--prod-ssh-target', 'prod.example'],
                $this->repoRoot(),
                $env,
            );
            $required = $this->runCommand(
                [
                    'bash',
                    'scripts/ops/prod_validate_after_change.sh',
                    '--prod-ssh-target',
                    'prod.example',
                    '--require-scanner-blocking',
                ],
                $this->repoRoot(),
                $env,
            );

            self::assertStringContainsString('scanner_host_context_failures=4', $advisory['stdout']);
            self::assertStringContainsString('scanner_host_context_failures=4', $required['stdout']);
            self::assertSame(
                $this->validationFailureCount($advisory['stdout']) + 4,
                $this->validationFailureCount($required['stdout']),
            );
            self::assertNotSame(0, $required['exit_code']);
        } finally {
            $this->removeDirectory($workspace);
        }
    }

    private function writeCurlStub(string $stubBin): void
    {
        file_put_contents(
            $stubBin . '/curl',
            <<<'BASH'
            #!/usr/bin/env bash
            set -euo pipefail

            output_file=''
            url=''
            host_header=''
            resolve_spec=''
            hostless='0'

            while [[ $# -gt 0 ]]; do
                case "$1" in
                    -o)
                        output_file="$2"
                        shift 2
                        ;;
                    -H)
                        host_header="$2"
                        shift 2
                        ;;
                    --resolve)
                        resolve_spec="$2"
                        shift 2
                        ;;
                    --http1.0)
                        hostless='1'
                        shift
                        ;;
                    --insecure)
                        shift
                        ;;
                    -w|--max-time)
                        shift 2
                        ;;
                    -sS)
                        shift
                        ;;
                    *)
                        url="$1"
                        shift
                        ;;
                esac
            done

            status="${CURL_STATUS:?}"
            case "$url" in
                https://dasforscherhaus-leg.de/)
                    status="${CURL_STATUS_APP_ROOT:-$status}"
                    ;;
                https://www.dasforscherhaus-leg.de/)
                    status="${CURL_STATUS_WWW_ROOT:-$status}"
                    ;;
                https://monitor.dasforscherhaus-leg.de/)
                    status="${CURL_STATUS_MONITOR_ROOT:-$status}"
                    ;;
            esac

            if [[ "$url" == http://* && "$hostless" == '1' ]]; then
                status="${CURL_STATUS_HTTP_DEFAULT:-$status}"
            elif [[ "$url" == http://* && -n "$resolve_spec" ]]; then
                status="${CURL_STATUS_HTTP_UNMATCHED:-$status}"
            elif [[ "$url" == http://* && "$url" == *"${HOST_CONTEXT_ADDRESS:?}"* ]]; then
                status="${CURL_STATUS_HTTP_IP_LITERAL:-$status}"
            elif [[ "$url" == https://* && "$hostless" == '1' ]]; then
                status="${CURL_STATUS_HTTPS_DEFAULT:-$status}"
            elif [[ "$url" == https://* && -n "$resolve_spec" ]]; then
                status="${CURL_STATUS_HTTPS_UNMATCHED:-$status}"
            elif [[ "$url" == https://* && "$url" == *"${HOST_CONTEXT_ADDRESS:?}"* ]]; then
                status="${CURL_STATUS_HTTPS_IP_LITERAL:-$status}"
            fi

            printf '%s|%s|%s|%s\n' "$hostless" "$resolve_spec" "$host_header" "$url" >> "${CURL_LOG:?}"
            printf 'scanner body' > "$output_file"
            printf '%s' "$status"
            BASH
            ,
        );
        chmod($stubBin . '/curl', 0755);
    }

    private function writeIpStub(string $stubBin): void
    {
        file_put_contents(
            $stubBin . '/ip',
            <<<'BASH'
            #!/usr/bin/env bash
            set -euo pipefail

            if [[ "$*" == '-4 route show default' ]]; then
                printf 'default dev test-public\n'
                exit 0
            fi

            if [[ "$*" == '-o -4 addr show dev test-public scope global' ]]; then
                printf '1: test-public inet %s/32 scope global test-public\n' "${HOST_CONTEXT_ADDRESS:?}"
                exit 0
            fi

            exit 1
            BASH
            ,
        );
        chmod($stubBin . '/ip', 0755);
    }

    private function writeSshStub(string $stubBin): void
    {
        file_put_contents(
            $stubBin . '/ssh',
            <<<'BASH'
            #!/usr/bin/env bash
            set -euo pipefail

            remote_cmd=''
            while [[ $# -gt 0 ]]; do
                case "$1" in
                    -o)
                        shift 2
                        ;;
                    *)
                        remote_cmd="$1"
                        shift
                        ;;
                esac
            done

            if [[ -z "$remote_cmd" ]]; then
                remote_cmd='bash -s'
            fi

            bash -c "$remote_cmd"
            BASH
            ,
        );
        chmod($stubBin . '/ssh', 0755);
    }

    /**
     * @return array<string, string>
     */
    private function commandEnv(string $stubBin, string $curlLog, int $statusCode): array
    {
        return [
            'CURL_LOG' => $curlLog,
            'CURL_STATUS' => (string) $statusCode,
            'CURL_STATUS_APP_ROOT' => '200',
            'CURL_STATUS_WWW_ROOT' => '200',
            'CURL_STATUS_MONITOR_ROOT' => '302',
            'HOST_CONTEXT_ADDRESS' => $this->syntheticIpv4Address(),
            'PATH' => $stubBin . PATH_SEPARATOR . (getenv('PATH') ?: ''),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function hostContextCommandEnv(
        string $stubBin,
        string $curlLog,
        int $defaultStatus,
        int $unmatchedStatus,
        int $ipLiteralStatus,
        int $httpsDefaultStatus,
        int $httpsUnmatchedStatus,
        int $httpsIpLiteralStatus,
    ): array {
        return array_merge($this->commandEnv($stubBin, $curlLog, $defaultStatus), [
            'CURL_STATUS_HTTP_DEFAULT' => (string) $defaultStatus,
            'CURL_STATUS_HTTP_UNMATCHED' => (string) $unmatchedStatus,
            'CURL_STATUS_HTTP_IP_LITERAL' => (string) $ipLiteralStatus,
            'CURL_STATUS_HTTPS_DEFAULT' => (string) $httpsDefaultStatus,
            'CURL_STATUS_HTTPS_UNMATCHED' => (string) $httpsUnmatchedStatus,
            'CURL_STATUS_HTTPS_IP_LITERAL' => (string) $httpsIpLiteralStatus,
        ]);
    }

    private function validationFailureCount(string $stdout): int
    {
        if (preg_match('/validation=failed failures=(\d+)/', $stdout, $matches) === 1) {
            return (int) $matches[1];
        }

        self::assertStringContainsString('validation=passed', $stdout);

        return 0;
    }

    private function syntheticIpv4Address(): string
    {
        return implode('.', ['198', '51', '100', '42']);
    }

    /**
     * @param list<string> $command
     * @param array<string, string> $env
     * @return array{exit_code:int,stdout:string,stderr:string}
     */
    private function runCommand(array $command, string $cwd, array $env = []): array
    {
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptorSpec, $pipes, $cwd, array_merge($_ENV, $env));
        self::assertIsResource($process);

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        return [
            'exit_code' => $exitCode,
            'stdout' => is_string($stdout) ? $stdout : '',
            'stderr' => is_string($stderr) ? $stderr : '',
        ];
    }

    private function repoRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
                continue;
            }

            unlink($item->getPathname());
        }

        rmdir($path);
    }
}
