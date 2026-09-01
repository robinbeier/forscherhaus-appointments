<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

final class TrustedBaseBootstrapContractTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repoRoot = dirname(__DIR__, 3);
    }

    public function testCanonicalParserResolvesBothDeclaredPayloadsDeterministically(): void
    {
        $contract = $this->contract();

        foreach (
            [
                'reviewer' => ['scripts/agent/run_readonly_reviewer.sh', '0500', 'reviewer'],
                'parallel' => ['scripts/agent/check_parallel_work_contract.sh', '0500', 'parallel'],
            ]
            as $payload => $expectedTail
        ) {
            [$status, $stdout, $stderr] = $this->runParser($contract, $payload);

            self::assertSame(0, $status, $stderr);
            self::assertSame('', $stderr);
            self::assertSame(
                implode("\n", [
                    '.codex/contracts/agent-workflow.json',
                    'scripts/agent/trusted_base_launcher.sh',
                    '0500',
                    'scripts/agent/lib/trusted_base_bootstrap_contract.py',
                    '0400',
                    'scripts/agent/lib/trusted_base_payload_runtime.sh',
                    '0400',
                    ...$expectedTail,
                ]),
                $stdout,
            );
        }
    }

    public function testCanonicalParserRejectsManifestDriftFailClosed(): void
    {
        $canonical = $this->contract();
        $mutators = [
            static function (array $contract): array {
                $contract['trusted_base_bootstrap']['schema_version'] = 1;
                return $contract;
            },
            static function (array $contract): array {
                $contract['trusted_base_bootstrap']['unexpected'] = true;
                return $contract;
            },
            static function (array $contract): array {
                $contract['trusted_base_bootstrap']['contract_parser']['path'] = '../untrusted.py';
                return $contract;
            },
            static function (array $contract): array {
                $contract['trusted_base_bootstrap']['contract_parser']['mode'] = '0500';
                return $contract;
            },
            static function (array $contract): array {
                $contract['trusted_base_bootstrap']['shared_runtime']['mode'] = '0500';
                return $contract;
            },
            static function (array $contract): array {
                $contract['trusted_base_bootstrap']['payloads']['reviewer']['environment_profile'] = 'parallel';
                return $contract;
            },
        ];

        foreach ($mutators as $mutator) {
            [$status, $stdout, $stderr] = $this->runParser($mutator($canonical), 'reviewer');

            self::assertSame(1, $status, $stdout . $stderr);
            self::assertSame('', $stdout);
            self::assertSame('', $stderr);
        }
    }

    public function testLauncherAndRuntimeInvokeAndReattestTheSameParser(): void
    {
        $launcher = (string) file_get_contents($this->repoRoot . '/scripts/agent/trusted_base_launcher.sh');
        $runtime = (string) file_get_contents($this->repoRoot . '/scripts/agent/lib/trusted_base_payload_runtime.sh');

        self::assertStringContainsString(
            "parser_repository_path='scripts/agent/lib/trusted_base_bootstrap_contract.py'",
            $launcher,
        );
        self::assertStringContainsString('trusted_python "$parser_target" "$payload_name"', $launcher);
        self::assertStringContainsString('TRUSTED_BASE_BOOTSTRAP_PARSER_BLOB="$parser_blob"', $launcher);
        self::assertStringContainsString(
            'trusted_base_assert_materialized_blob "$parser_source" "$parser_path" "$parser_mode" "$parser_blob"',
            $runtime,
        );
        self::assertStringContainsString('trusted_base_python "$parser_source" "$expected_payload_id"', $runtime);
        self::assertLessThan(
            strpos($runtime, 'trusted_base_python "$parser_source" "$expected_payload_id"'),
            strpos(
                $runtime,
                'trusted_base_assert_materialized_blob "$parser_source" "$parser_path" "$parser_mode" "$parser_blob"',
            ),
        );
    }

    /** @return array<string, mixed> */
    private function contract(): array
    {
        $contract = json_decode(
            (string) file_get_contents($this->repoRoot . '/.codex/contracts/agent-workflow.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($contract);

        return $contract;
    }

    /** @param array<string, mixed> $contract @return array{int, string, string} */
    private function runParser(array $contract, string $payload): array
    {
        $process = proc_open(
            [
                '/usr/bin/python3',
                '-I',
                '-B',
                $this->repoRoot . '/scripts/agent/lib/trusted_base_bootstrap_contract.py',
                $payload,
            ],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
            $this->repoRoot,
        );
        self::assertIsResource($process);
        fwrite($pipes[0], json_encode($contract, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }
}
