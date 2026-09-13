<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

class PrePrFullLdapGuardrailTest extends TestCase
{
    /** @var list<string> */
    private array $harnesses = [];

    protected function tearDown(): void
    {
        foreach ($this->harnesses as $harness) {
            $directory = dirname($harness);
            if (is_dir($directory . '/scripts/ldap')) {
                unlink($directory . '/scripts/ldap/smoke.sh');
                rmdir($directory . '/scripts/ldap');
                rmdir($directory . '/scripts');
            }
            unlink($harness);
            rmdir($directory);
        }
    }

    /**
     * Execute the production selector and conditional in a shell harness.
     * Docker, the full gate, and LDAP are doubled.
     */
    public function testSelectorFailureIsFatalAndNormalStatusesReachConditionalTail(): void
    {
        $script = $this->prePrFullScript();
        $function = $this->extract(
            $script,
            'pre_pr_full_should_include_ldap_guardrail() {',
            "\n}\n\nbash ./scripts/ci/ensure_local_deps.sh",
        );
        $conditionalStart = strpos($script, 'if pre_pr_full_should_include_ldap_guardrail; then');
        $conditionalEnd = strpos(
            $script,
            'echo "[pre-pr-full] LDAP guardrail checks enabled:',
            $conditionalStart === false ? 0 : $conditionalStart,
        );

        self::assertNotFalse($conditionalStart);
        self::assertNotFalse($conditionalEnd);
        $conditionalBody = substr($script, $conditionalStart, $conditionalEnd - $conditionalStart);
        $conditionalFi = strrpos($conditionalBody, "\nfi\n");
        self::assertNotFalse($conditionalFi);
        $echoEnd = strpos($script, "\n", $conditionalEnd);
        self::assertNotFalse($echoEnd);
        $conditional = substr($script, $conditionalStart, $echoEnd + 1 - $conditionalStart);
        self::assertStringContainsString('ldap_scope_status=$?', $conditional);

        $harness = $this->harness($function, $conditional);
        $failure = $this->runHarness($harness, 'failure');
        self::assertSame(2, $failure['exit_code'], $failure['stderr']);
        self::assertStringNotContainsString('AFTER_CONDITIONAL', $failure['stdout']);

        $docsOnly = $this->runHarness($harness, 'docs');
        self::assertSame(0, $docsOnly['exit_code'], $docsOnly['stderr']);
        self::assertStringContainsString('AFTER_CONDITIONAL', $docsOnly['stdout']);
        self::assertStringContainsString('LDAP guardrail checks enabled: 0', $docsOnly['stdout']);

        $relevant = $this->runHarness($harness, 'relevant');
        self::assertSame(0, $relevant['exit_code'], $relevant['stderr']);
        self::assertStringContainsString('AFTER_CONDITIONAL', $relevant['stdout']);
        self::assertStringContainsString('LDAP guardrail checks enabled: 1', $relevant['stdout']);
    }

    public function testLdapGuardrailMatcherNoLongerIncludesScriptUnitTestFiles(): void
    {
        $script = $this->prePrFullScript();

        self::assertStringNotContainsString('tests/Unit/Scripts/DeepRuntimeSuiteTest.php', $script);
        self::assertStringNotContainsString('tests/Unit/Scripts/CiPathFilterMatrixTest.php', $script);
    }

    public function testLdapGuardrailMatcherKeepsRuntimeRelevantPaths(): void
    {
        $script = $this->prePrFullScript();

        self::assertStringContainsString('application/controllers/Login.php', $script);
        self::assertStringContainsString('scripts/ci/dashboard_integration_smoke.php', $script);
        self::assertStringContainsString('docker-compose.yml', $script);
    }

    public function testLdapGuardrailExplicitOverrideFlagsRemainSupported(): void
    {
        $script = $this->prePrFullScript();

        self::assertStringContainsString('if [[ "${PRE_PR_INCLUDE_LDAP_GUARDRAIL:-}" == "1" ]]; then', $script);
        self::assertStringContainsString('if [[ "${PRE_PR_INCLUDE_LDAP_GUARDRAIL:-}" == "0" ]]; then', $script);
    }

    public function testIntegrationSmokeBrowserOpenTimeoutOverrideIsForwarded(): void
    {
        $script = $this->prePrFullScript();

        self::assertStringContainsString(
            'INTEGRATION_SMOKE_BROWSER_BOOTSTRAP_TIMEOUT="${PRE_PR_INTEGRATION_SMOKE_BROWSER_BOOTSTRAP_TIMEOUT:-180}"',
            $script,
        );
        self::assertStringContainsString(
            'INTEGRATION_SMOKE_BROWSER_OPEN_TIMEOUT="${PRE_PR_INTEGRATION_SMOKE_BROWSER_OPEN_TIMEOUT:-20}"',
            $script,
        );
        self::assertStringContainsString(
            '--integration-smoke-browser-bootstrap-timeout="${INTEGRATION_SMOKE_BROWSER_BOOTSTRAP_TIMEOUT}"',
            $script,
        );
        self::assertStringContainsString(
            '--integration-smoke-browser-open-timeout="${INTEGRATION_SMOKE_BROWSER_OPEN_TIMEOUT}"',
            $script,
        );
    }

    private function prePrFullScript(): string
    {
        $contents = file_get_contents(__DIR__ . '/../../../scripts/ci/pre_pr_full.sh');

        self::assertNotFalse($contents);

        return $contents;
    }

    private function extract(string $script, string $startMarker, string $endMarker): string
    {
        $start = strpos($script, $startMarker);
        $end = strpos($script, $endMarker, $start === false ? 0 : $start);
        self::assertNotFalse($start);
        self::assertNotFalse($end);

        return substr($script, $start, $end - $start) . "\n}";
    }

    private function harness(string $function, string $conditional): string
    {
        $directory = sys_get_temp_dir() . '/pre-pr-full-ldap-' . bin2hex(random_bytes(8));
        mkdir($directory, 0777, true);
        $path = $directory . '/harness.sh';
        $contents = "#!/usr/bin/env bash\nset -u\nBASE_REF=main\nSTACK_SERVICES=()\n";
        $contents .= <<<'BASH'
        git_ci_collect_changed_paths() {
            case "${STUB_MODE}" in
                failure) return 2 ;;
                docs) printf '%s\n' README.md; return 0 ;;
                relevant) printf '%s\n' application/controllers/Login.php; return 0 ;;
            esac
        }
        ci_docker_compose() { :; }
        mkdir -p scripts/ldap
        printf '#!/usr/bin/env bash\nexit 0\n' > scripts/ldap/smoke.sh
        chmod +x scripts/ldap/smoke.sh
        BASH;
        $contents .= "\n" . $function . "\nINTEGRATION_SMOKE_INCLUDE_LDAP=0\n" . $conditional;
        $contents .= "\necho AFTER_CONDITIONAL\n";
        file_put_contents($path, $contents);
        chmod($path, 0755);
        $this->harnesses[] = $path;

        return $path;
    }

    private function runHarness(string $harness, string $mode): array
    {
        $process = proc_open(
            ['bash', $harness],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
            dirname($harness),
            array_merge(getenv(), ['STUB_MODE' => $mode]),
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['exit_code' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
    }
}
