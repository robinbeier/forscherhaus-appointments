<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

final class ProdPostureScriptTest extends TestCase
{
    public function testPostureHelperReportsHeaderPresenceWithoutValuesOrUrls(): void
    {
        $workspace = sys_get_temp_dir() . '/prod-posture-' . bin2hex(random_bytes(8));
        $stubBin = $workspace . '/bin';
        $curlLog = $workspace . '/curl.log';

        mkdir($stubBin, 0777, true);

        try {
            $this->writeCurlStub($stubBin);

            $result = $this->runCommand(
                ['bash', '-c', 'source scripts/ops/lib/prod_posture.sh; prod_posture_check_headers'],
                $this->repoRoot(),
                [
                    'CURL_LOG' => $curlLog,
                    'CURL_HEADERS' =>
                        "HTTP/2 200\r\nStrict-Transport-Security: max-age=1\r\nX-Frame-Options: SAMEORIGIN\r\n\r\n",
                    'PATH' => $stubBin . PATH_SEPARATOR . (getenv('PATH') ?: ''),
                ],
            );

            self::assertSame(0, $result['exit_code'], $result['stderr']);
            self::assertStringContainsString('posture_header.app_https.hsts=present', $result['stdout']);
            self::assertStringContainsString('posture_header.app_https.x_frame_options=present', $result['stdout']);
            self::assertStringContainsString('posture_header.app_https.csp=missing', $result['stdout']);
            self::assertStringNotContainsString('max-age=1', $result['stdout'] . $result['stderr']);
            self::assertStringNotContainsString(
                'https://dasforscherhaus-leg.de',
                $result['stdout'] . $result['stderr'],
            );
            self::assertStringNotContainsString('Strict-Transport-Security:', $result['stdout'] . $result['stderr']);
        } finally {
            $this->removeDirectory($workspace);
        }
    }

    public function testPostureHelperReportsSshAndPortClassesWithoutRawAddresses(): void
    {
        $workspace = sys_get_temp_dir() . '/prod-posture-' . bin2hex(random_bytes(8));
        $stubBin = $workspace . '/bin';

        mkdir($stubBin, 0777, true);

        try {
            $this->writeSshdStub($stubBin);
            $this->writeUfwStub($stubBin);
            $this->writeSsStub($stubBin);

            $result = $this->runCommand(
                [
                    'bash',
                    '-c',
                    'source scripts/ops/lib/prod_posture.sh; prod_posture_check_ssh; prod_posture_check_firewall_and_ports',
                ],
                $this->repoRoot(),
                [
                    'PATH' => $stubBin . PATH_SEPARATOR . (getenv('PATH') ?: ''),
                ],
            );

            self::assertSame(0, $result['exit_code'], $result['stderr']);
            self::assertStringContainsString('posture_ssh.permitrootlogin=prohibit-password', $result['stdout']);
            self::assertStringContainsString('posture_ssh.passwordauthentication=yes', $result['stdout']);
            self::assertStringContainsString('posture_ssh.x11forwarding=yes', $result['stdout']);
            self::assertStringContainsString('posture_ssh.allowtcpforwarding=yes', $result['stdout']);
            self::assertStringContainsString('posture_ufw.status=inactive', $result['stdout']);
            self::assertStringContainsString('posture_tcp.22.listen_class=wildcard', $result['stdout']);
            self::assertStringContainsString('posture_tcp.80.listen_class=public', $result['stdout']);
            self::assertStringContainsString('posture_tcp.3001.listen_class=loopback', $result['stdout']);
            self::assertStringContainsString('posture_tcp.3306.listen_class=loopback', $result['stdout']);
            self::assertStringContainsString('posture_tcp.expected_public_listener_classes=3', $result['stdout']);
            self::assertStringContainsString('posture_tcp.unexpected_public_listener_count=0', $result['stdout']);
            self::assertStringNotContainsString('0.0.0.0', $result['stdout'] . $result['stderr']);
            self::assertStringNotContainsString('127.0.0.1', $result['stdout'] . $result['stderr']);
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

            header_file=''
            url=''

            while [[ $# -gt 0 ]]; do
                case "$1" in
                    -D)
                        header_file="$2"
                        shift 2
                        ;;
                    -o|--max-time)
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

            printf '%s\n' "$url" >> "${CURL_LOG:?}"
            printf '%b' "${CURL_HEADERS:?}" > "$header_file"
            BASH
            ,
        );
        chmod($stubBin . '/curl', 0755);
    }

    private function writeSshdStub(string $stubBin): void
    {
        file_put_contents(
            $stubBin . '/sshd',
            <<<'BASH'
            #!/usr/bin/env bash
            set -euo pipefail

            if [[ "${1:-}" == "-T" ]]; then
                printf '%s\n' \
                    'permitrootlogin prohibit-password' \
                    'pubkeyauthentication yes' \
                    'passwordauthentication yes' \
                    'x11forwarding yes' \
                    'allowtcpforwarding yes'
                exit 0
            fi

            exit 1
            BASH
            ,
        );
        chmod($stubBin . '/sshd', 0755);
    }

    private function writeUfwStub(string $stubBin): void
    {
        file_put_contents(
            $stubBin . '/ufw',
            <<<'BASH'
            #!/usr/bin/env bash
            set -euo pipefail

            printf 'Status: inactive\n'
            BASH
            ,
        );
        chmod($stubBin . '/ufw', 0755);
    }

    private function writeSsStub(string $stubBin): void
    {
        file_put_contents(
            $stubBin . '/ss',
            <<<'BASH'
            #!/usr/bin/env bash
            set -euo pipefail

            output_all() {
                printf '%s\n' \
                    'LISTEN 0 4096 0.0.0.0:22 0.0.0.0:*' \
                    'LISTEN 0 4096 10.0.0.1:80 0.0.0.0:*' \
                    'LISTEN 0 4096 10.0.0.1:443 0.0.0.0:*' \
                    'LISTEN 0 4096 127.0.0.1:3001 0.0.0.0:*' \
                    'LISTEN 0 4096 127.0.0.1:3003 0.0.0.0:*' \
                    'LISTEN 0 4096 [::1]:3306 [::]:*'
            }

            if [[ "$*" =~ sport[[:space:]]*=[[:space:]]*:([0-9]+) ]]; then
                port="${BASH_REMATCH[1]}"
                output_all | awk -v port=":$port" '$4 ~ port "$" {print}'
                exit 0
            fi

            output_all
            BASH
            ,
        );
        chmod($stubBin . '/ss', 0755);
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
