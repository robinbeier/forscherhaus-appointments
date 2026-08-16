<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

/**
 * ROB-468's intentionally red contract for the future installed helper and wrapper.
 *
 * This test must fail only while the bounded implementation is absent or does not
 * satisfy the closed contract. It must not invoke a host, SSH, SCP, or production.
 */
final class LegacyReleaseProvenanceContractTest extends TestCase
{
    private const HELPER = 'scripts/ops/libexec/legacy_release_provenance_v1.py';
    private const WRAPPER = 'scripts/ops/prod_legacy_release_provenance.sh';
    private const SCHEMA = 'release_build_provenance.v1';
    private const TOKEN = 'ROB-468';

    public function testOperatorWrapperInvokesOnlyTheFixedInstalledHelper(): void
    {
        $helper = $this->helper();
        $wrapper = $this->wrapper();

        self::assertStringContainsString(self::SCHEMA, $helper);
        self::assertStringContainsString('current', $helper);
        self::assertStringContainsString('rollback', $helper);
        self::assertStringContainsString('/root/releases', $helper);
        self::assertStringContainsString('ssh ', $wrapper);
        self::assertStringContainsString(
            '/usr/bin/python3 -I -B /usr/local/libexec/fh-legacy-release-provenance-v1',
            $wrapper,
        );
        self::assertStringNotContainsString('scp ', $wrapper);
        self::assertStringNotContainsString(self::HELPER, $wrapper);
        self::assertStringNotContainsString('/var/www/html/easyappointments/scripts/ops', $wrapper);
    }

    public function testAuthorizationAndInputsAreHostLocalAndNotCallerSupplied(): void
    {
        $helper = $this->helper();
        $wrapper = $this->wrapper();

        foreach (['root', '0600', 'authorization', 'canonical'] as $term) {
            self::assertStringContainsString($term, strtolower($helper));
        }
        foreach (['release-id', 'sha256', 'commit', 'member', 'temp', 'path'] as $term) {
            self::assertStringNotContainsString('--' . $term, $wrapper);
        }
        foreach (['release_id', 'sha256', 'commit', 'member', 'temp', 'path'] as $term) {
            self::assertStringNotContainsString('input_' . $term, $helper);
        }
        self::assertStringContainsString('os.geteuid', $helper);
        self::assertStringContainsString('stat', $helper);
    }

    public function testLocksAndCompleteTwoTargetPreflightPrecedeEveryMutation(): void
    {
        $helper = $this->helper();

        $contextBody = $this->functionBody($helper, 'open_context');
        $global = strpos($contextBody, 'open_existing_lock(locks, GLOBAL_PRODUCTION_LOCK');
        $publication = strpos($contextBody, 'open_existing_lock(releases, PUBLICATION_LOCK');
        self::assertIsInt($global);
        self::assertIsInt($publication);
        self::assertLessThan($publication, $global, 'global production lock must be acquired first');

        $runBody = $this->functionBody($helper, 'run');
        $openContext = strpos($runBody, 'context = open_context()');
        $preflight = strpos($runBody, 'plans = preflight_targets(context)');
        $execute = strpos($runBody, 'execute_plans(context, plans');
        self::assertIsInt($openContext);
        self::assertIsInt($preflight);
        self::assertIsInt($execute);
        self::assertLessThan($preflight, $openContext, 'both locks must be acquired before complete preflight');
        self::assertLessThan($execute, $preflight, 'no mutation path may run before complete preflight');
        self::assertStringContainsString('current', $helper);
        self::assertStringContainsString('rollback', $helper);
    }

    public function testMarkerPathFdAndTarContractsAreStableAndExact(): void
    {
        $helper = $this->helper();

        foreach (
            [
                'O_NOFOLLOW',
                'O_DIRECTORY',
                'fstat',
                'readlink',
                'tarfile',
                'for member in archive',
                'sha256',
                'deploy_ea',
            ]
            as $term
        ) {
            self::assertStringContainsString($term, $helper);
        }
        self::assertStringContainsString('required_members', $helper);
        self::assertStringContainsString('member_hashes', $helper);
        self::assertStringContainsString('exact', strtolower($helper));
    }

    public function testCanonicalSidecarsUseFsyncAndNoReplaceExactAttach(): void
    {
        $helper = $this->helper();

        foreach (['release_build_provenance.v1', 'fsync', 'RENAME_NOREPLACE', 'renameat2', '0600'] as $term) {
            self::assertStringContainsString($term, $helper);
        }
        self::assertStringContainsString('sidecar', strtolower($helper));
        self::assertStringContainsString('exact', strtolower($helper));
        self::assertStringNotContainsString('os.rename(', $helper);
    }

    public function testMutationOutcomesAndOutputAreAggregateOnly(): void
    {
        $helper = $this->helper();
        $wrapper = $this->wrapper();

        foreach (['none', 'known', 'unknown', 'mutation_outcome'] as $term) {
            self::assertStringContainsString($term, $helper);
        }
        foreach (['aggregate', 'current', 'rollback'] as $term) {
            self::assertStringContainsString($term, strtolower($helper));
        }
        foreach (['release_id', 'archive', 'member_name', 'sha256'] as $term) {
            self::assertStringNotContainsString("'" . $term . "'", $wrapper);
            self::assertStringNotContainsString('"' . $term . '"', $wrapper);
        }
    }

    public function testInspectIsReadOnlyAndExecuteRequiresExactRobToken(): void
    {
        $helper = $this->helper();
        $wrapper = $this->wrapper();

        self::assertStringContainsString('inspect', $wrapper);
        self::assertStringContainsString('read-only', strtolower($wrapper));
        self::assertStringContainsString('--execute', $wrapper);
        self::assertStringContainsString(self::TOKEN, $wrapper);
        self::assertStringContainsString("['execute', EXECUTE_TOKEN]", $helper);
        self::assertStringContainsString('exact', strtolower($wrapper));
        self::assertStringNotContainsString('ROB-467', $wrapper);
        self::assertStringNotContainsString('ROB-469', $wrapper);
    }

    public function testRunbookDefinesFullMatrixAndProductionBoundary(): void
    {
        $root = dirname(__DIR__, 3);
        $docsPath = $root . '/docs/ops/production-legacy-release-provenance.md';
        self::assertFileExists($docsPath);
        $docs = (string) file_get_contents($docsPath);

        foreach (
            [
                'Metadata',
                'Tar',
                'Authorization',
                'Host-Script',
                'Crash',
                'Concurrency',
                'Redaction',
                'Retention',
                'current',
                'rollback',
                'read-only',
                self::TOKEN,
                'no SSH',
                'no SCP',
                'Merge is not production approval',
            ]
            as $term
        ) {
            self::assertStringContainsString($term, $docs);
        }
        self::assertStringNotContainsString('188.245.244.123', $docs);
        self::assertStringNotContainsString('ea_2026', $docs);
        self::assertStringNotContainsString(str_repeat('a', 40), $docs);
    }

    private function helper(): string
    {
        $path = dirname(__DIR__, 3) . '/' . self::HELPER;
        self::assertFileExists($path, 'Expected future installed-helper source is not present yet (intentional RED).');
        return (string) file_get_contents($path);
    }

    private function wrapper(): string
    {
        $path = dirname(__DIR__, 3) . '/' . self::WRAPPER;
        self::assertFileExists($path, 'Expected future operator wrapper is not present yet (intentional RED).');
        return (string) file_get_contents($path);
    }

    /** @param list<string> $needles */
    private function positionOfAny(string $haystack, array $needles): int
    {
        foreach ($needles as $needle) {
            $position = strpos($haystack, $needle);
            if ($position !== false) {
                return $position;
            }
        }
        self::fail('None of the expected contract markers were found: ' . implode(', ', $needles));
    }

    private function functionBody(string $source, string $name): string
    {
        $start = strpos($source, "def {$name}(");
        self::assertIsInt($start, "Expected function {$name} was not found.");
        $next = strpos($source, "\ndef ", $start + 1);
        return substr($source, $start, $next === false ? null : $next - $start);
    }
}
