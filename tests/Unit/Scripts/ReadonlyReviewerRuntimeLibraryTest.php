<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

class ReadonlyReviewerRuntimeLibraryTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\DataProvider('runtimeOutputScenarios')]
    public function testFixtureBackedRuntimeValidatesModelOutput(string $scenario): void
    {
        $repoRoot = dirname(__DIR__, 3);
        $fixtureRoot = sys_get_temp_dir() . '/readonly-reviewer-runtime-' . bin2hex(random_bytes(8));
        $sealedRoot = $fixtureRoot . '/sealed';
        $controlRoot = $sealedRoot . '/control';
        $reviewRoot = $sealedRoot . '/bundle';
        $reviewedRepo = $fixtureRoot . '/reviewed-repository';
        $reviewerHome = $fixtureRoot . '/reviewer-home';
        foreach ([$controlRoot . '/scripts/agent', $reviewRoot, $reviewedRepo, $reviewerHome] as $directory) {
            self::assertTrue(mkdir($directory, 0700, true));
        }

        $baseSha = str_repeat('b', 40);
        $headSha = str_repeat('a', 40);
        $developerInstructions = "Trusted fixture reviewer policy.\n";
        self::assertNotFalse(file_put_contents($controlRoot . '/developer-instructions.txt', $developerInstructions));
        self::assertNotFalse(file_put_contents($controlRoot . '/review-input.json', "{\"fixture\":true}\n"));
        self::assertNotFalse(file_put_contents($controlRoot . '/changed-paths.json', '["WORKFLOW.md"]'));
        self::assertNotFalse(file_put_contents($controlRoot . '/scripts/agent/readonly-reviewer.sb', "(version 1)\n"));
        self::assertTrue(
            copy(
                $repoRoot . '/scripts/agent/readonly-review-output.schema.json',
                $controlRoot . '/scripts/agent/readonly-review-output.schema.json',
            ),
        );
        self::assertNotFalse(file_put_contents($reviewedRepo . '/AGENTS.md', "fixture\n"));
        $authSource = $reviewerHome . '/auth.json';
        self::assertNotFalse(file_put_contents($authSource, "{}\n"));
        self::assertTrue(chmod($authSource, 0600));

        $modelCatalog = json_encode(
            [
                'models' => [
                    $this->modelCatalogEntry([
                        'shell_type' => 'unified_exec',
                        'apply_patch_tool_type' => 'freeform',
                        'input_modalities' => ['text', 'image'],
                        'supports_search_tool' => true,
                        'experimental_supported_tools' => ['fixture'],
                    ]),
                ],
            ],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );
        $promptRoles = json_encode(
            [
                ['role' => 'developer', 'content' => [['type' => 'input_text', 'text' => $developerInstructions]]],
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'input_text',
                            'text' => 'UNTRUSTED-REVIEW-BUNDLE-PROBE',
                        ],
                    ],
                ],
            ],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );
        $validatedOutput =
            $scenario === 'valid'
                ? json_encode(
                    [
                        'lens' => 'correctness_security',
                        'base_sha' => $baseSha,
                        'head_sha' => $headSha,
                        'verdict' => 'no_findings',
                        'findings' => [],
                    ],
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
                )
                : '{invalid-review-output';

        $codexStub = $fixtureRoot . '/codex';
        self::assertNotFalse(
            file_put_contents(
                $codexStub,
                "#!/bin/bash\n" .
                    "set -euo pipefail\n" .
                    "if [[ \"\${HOME:-}\" != \"\${CODEX_HOME:-}\" ]]; then\n" .
                    "  echo \"Reviewer HOME is not synthetic.\" >&2\n" .
                    "  exit 90\n" .
                    "fi\n" .
                    "arguments=\" \$* \"\n" .
                    "if [[ \"\$arguments\" == *\" debug \"* ]]; then\n" .
                    "  for exec_only_flag in --ignore-user-config --ignore-rules --strict-config; do\n" .
                    "    if [[ \"\$arguments\" == *\" \$exec_only_flag \"* ]]; then\n" .
                    "      echo \"Unsupported debug flag: \$exec_only_flag\" >&2\n" .
                    "      exit 91\n" .
                    "    fi\n" .
                    "  done\n" .
                    "else\n" .
                    "  for required_flag in --ignore-user-config --ignore-rules --strict-config; do\n" .
                    "    if [[ \"\$arguments\" != *\" \$required_flag \"* ]]; then\n" .
                    "      echo \"Missing required exec isolation flag: \$required_flag\" >&2\n" .
                    "      exit 92\n" .
                    "    fi\n" .
                    "  done\n" .
                    "fi\n" .
                    "/usr/bin/printf '%s\\0' \"\$@\" >> \"\$TMPDIR/codex-args.bin\"\n" .
                    "/usr/bin/printf '\\0' >> \"\$TMPDIR/codex-args.bin\"\n" .
                    "if [[ \"\$arguments\" == *\" debug models --bundled \"* ]]; then\n" .
                    '  /usr/bin/printf ' .
                    escapeshellarg("%s\n") .
                    ' ' .
                    escapeshellarg($modelCatalog) .
                    "\n" .
                    "elif [[ \"\$arguments\" == *\" debug prompt-input \"* ]]; then\n" .
                    '  /usr/bin/printf ' .
                    escapeshellarg("%s\n") .
                    ' ' .
                    escapeshellarg($promptRoles) .
                    "\n" .
                    "else\n" .
                    "  /bin/cat >/dev/null\n" .
                    '  /usr/bin/printf ' .
                    escapeshellarg("%s\n") .
                    ' ' .
                    escapeshellarg($validatedOutput) .
                    "\n" .
                    "fi\n",
            ),
        );
        self::assertTrue(chmod($codexStub, 0500));

        $sandboxStub = $fixtureRoot . '/sandbox-exec';
        self::assertNotFalse(
            file_put_contents(
                $sandboxStub,
                <<<'BASH'
                #!/bin/bash
                set -euo pipefail
                sealed_root=""
                while [[ "$#" -gt 0 ]]; do
                    case "$1" in
                        -D)
                            case "$2" in SEALED_ROOT=*) sealed_root="${2#*=}" ;; esac
                            shift 2
                            ;;
                        -f) shift 2 ;;
                        *) break ;;
                    esac
                done
                if [[ "${1:-}" == "/bin/cat" ]]; then
                    case "${2:-}" in
                        "$sealed_root"/*) exec /bin/cat "$2" ;;
                        *) exit 1 ;;
                    esac
                fi
                exec "$@"
                BASH. "\n",
            ),
        );
        self::assertTrue(chmod($sandboxStub, 0500));

        $harness = $fixtureRoot . '/run-runtime-fixture.sh';
        self::assertNotFalse(
            file_put_contents(
                $harness,
                <<<'BASH'
                #!/bin/bash
                set -euo pipefail
                source_repo_root="$1"
                fixture_root="$2"
                php_bin="$3"
                codex_bin="$4"
                sandbox_exec="$5"
                sealed_root="$fixture_root/sealed"
                control_root="$sealed_root/control"
                review_root="$sealed_root/bundle"
                reviewed_repo="$fixture_root/reviewed-repository"
                reviewer_home="$fixture_root/reviewer-home"
                auth_source="$reviewer_home/auth.json"
                base_sha="bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb"
                head_sha="aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"
                PATH=/usr/bin:/bin:/usr/sbin:/sbin
                export PATH

                trusted_php() {
                    local requested="$1"
                    shift
                    case "$(basename -- "$requested")" in
                        readonly_reviewer_contract.php)
                            "$php_bin" -n "$source_repo_root/scripts/agent/readonly_reviewer_contract.php" "$@"
                            ;;
                        readonly_review_bundle.php)
                            "$php_bin" -n "$source_repo_root/scripts/agent/readonly_review_bundle.php" "$@"
                            ;;
                        *)
                            echo "Unexpected trusted PHP fixture command." >&2
                            return 2
                            ;;
                    esac
                }

                source "$source_repo_root/scripts/agent/lib/readonly_reviewer_isolated_runtime.sh"
                readonly_reviewer_execute_isolated \
                    "$control_root" "$review_root" "$sealed_root" "$reviewed_repo" \
                    "$auth_source" "$codex_bin" "$PATH" "$reviewer_home" \
                    "$sandbox_exec" correctness_security "$base_sha" "$head_sha"
                BASH. "\n",
            ),
        );
        self::assertTrue(chmod($harness, 0500));

        try {
            $process = proc_open(
                [
                    '/usr/bin/env',
                    '-i',
                    'PATH=/usr/bin:/bin:/usr/sbin:/sbin',
                    '/bin/bash',
                    '--noprofile',
                    '--norc',
                    $harness,
                    $repoRoot,
                    $fixtureRoot,
                    PHP_BINARY,
                    $codexStub,
                    $sandboxStub,
                ],
                [['file', '/dev/null', 'r'], ['pipe', 'w'], ['pipe', 'w']],
                $pipes,
                $fixtureRoot,
            );
            self::assertIsResource($process);
            $stdout = (string) stream_get_contents($pipes[1]);
            $stderr = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);

            $exitCode = proc_close($process);
            $invocationLog = file_get_contents($controlRoot . '/runtime-tmp/codex-args.bin');
            self::assertIsString($invocationLog);
            $serializedInvocations = array_values(
                array_filter(
                    explode("\0\0", rtrim($invocationLog, "\0")),
                    static fn(string $invocation): bool => $invocation !== '',
                ),
            );
            $invocations = array_map(
                static fn(string $invocation): array => explode("\0", $invocation),
                $serializedInvocations,
            );
            self::assertGreaterThanOrEqual(3, count($invocations));
            self::assertSame(['debug', 'models', '--bundled'], $invocations[0]);
            foreach (['--ignore-user-config', '--ignore-rules', '--strict-config'] as $execOnlyFlag) {
                self::assertNotContains($execOnlyFlag, $invocations[0]);
                self::assertNotContains($execOnlyFlag, $invocations[1]);
            }
            self::assertSame(
                ['debug', 'prompt-input', 'UNTRUSTED-REVIEW-BUNDLE-PROBE'],
                array_slice($invocations[1], -3),
            );
            $execInvocation = $invocations[array_key_last($invocations)];
            self::assertSame(
                [
                    '--ask-for-approval',
                    'never',
                    '--sandbox',
                    'read-only',
                    'exec',
                    '--ignore-user-config',
                    '--ignore-rules',
                    '--strict-config',
                    '--ephemeral',
                    '--skip-git-repo-check',
                    '--color',
                    'never',
                    '--model',
                    'gpt-5.4',
                    '--output-schema',
                    $controlRoot . '/scripts/agent/readonly-review-output.schema.json',
                ],
                array_slice($execInvocation, 0, 16),
            );
            self::assertSame(
                [
                    '-c',
                    'model_catalog_json="' . $controlRoot . '/models.json"',
                    '-c',
                    'model_reasoning_effort="high"',
                    '-c',
                    'log_dir="' . $controlRoot . '/runtime-tmp/log"',
                    '-c',
                    'developer_instructions="Trusted fixture reviewer policy.\\n"',
                    '-c',
                    'project_root_markers=[]',
                    '-c',
                    'web_search="disabled"',
                    '-c',
                    'shell_environment_policy.inherit="none"',
                    '-c',
                    'mcp_servers={}',
                    '-c',
                    'agents.max_threads=1',
                    '-c',
                    'agents.max_depth=0',
                    '-C',
                    $sealedRoot,
                    '-',
                ],
                array_slice($execInvocation, -23),
            );
            if ($scenario === 'invalid') {
                self::assertSame(1, $exitCode);
                self::assertSame('', $stdout);
                self::assertStringContainsString('Reviewer output is not valid JSON.', $stderr);
                self::assertStringContainsString('Reviewer isolated model call or output validation failed.', $stderr);
            } else {
                self::assertSame(0, $exitCode, $stderr);
                self::assertSame('', $stderr);
                $result = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
                self::assertSame('correctness_security', $result['lens'] ?? null);
                self::assertSame($baseSha, $result['base_sha'] ?? null);
                self::assertSame($headSha, $result['head_sha'] ?? null);
                self::assertSame('no_findings', $result['verdict'] ?? null);
                self::assertSame([], $result['findings'] ?? null);
            }
        } finally {
            $this->removeDirectory($fixtureRoot);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function runtimeOutputScenarios(): iterable
    {
        yield 'valid no-findings output' => ['valid'];
        yield 'malformed output fails closed' => ['invalid'];
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function modelCatalogEntry(array $overrides = []): array
    {
        return array_replace(
            [
                'slug' => 'gpt-5.4',
                'display_name' => 'Reviewer model',
                'description' => 'Pinned reviewer model fixture.',
                'default_reasoning_level' => 'medium',
                'supported_reasoning_levels' => [['effort' => 'medium']],
                'shell_type' => 'shell_command',
                'visibility' => 'list',
                'supported_in_api' => true,
                'priority' => 1,
                'additional_speed_tiers' => [],
                'service_tiers' => ['priority'],
                'availability_nux' => null,
                'upgrade' => ['target' => 'gpt-5.4'],
                'base_instructions' => 'Fixture model instructions.',
                'model_messages' => ['notice' => 'Fixture notice.'],
                'include_skills_usage_instructions' => true,
                'default_reasoning_summary' => 'none',
                'support_verbosity' => true,
                'default_verbosity' => 'medium',
                'apply_patch_tool_type' => 'freeform',
                'web_search_tool_type' => 'text_and_image',
                'truncation_policy' => ['mode' => 'bytes'],
                'supports_parallel_tool_calls' => true,
                'supports_image_detail_original' => true,
                'context_window' => 200000,
                'max_context_window' => 200000,
                'comp_hash' => 'fixture-comp-hash',
                'effective_context_window_percent' => 95,
                'experimental_supported_tools' => ['external_tool'],
                'input_modalities' => ['text', 'image'],
                'supports_search_tool' => true,
                'use_responses_lite' => false,
            ],
            $overrides,
        );
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory) || is_link($directory)) {
            return;
        }
        chmod($directory, 0700);
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $path = $entry->getPathname();
            if ($entry->isDir() && !$entry->isLink()) {
                chmod($path, 0700);
                rmdir($path);
            } else {
                chmod(dirname($path), 0700);
                chmod($path, 0600);
                unlink($path);
            }
        }
        rmdir($directory);
    }
}
