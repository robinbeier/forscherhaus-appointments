<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

class ParallelWorkContractCliTest extends TestCase
{
    private string $repoRoot;
    private string $trustedValidatorRoot;
    private string $canonicalRemoteRoot;
    private string $baseSha;

    protected function setUp(): void
    {
        parent::setUp();
        $sourceRepoRoot = dirname(__DIR__, 3);
        $this->repoRoot = sys_get_temp_dir() . '/parallel-work-repo-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->repoRoot . '/scripts/agent/lib', 0700, true));
        self::assertTrue(mkdir($this->repoRoot . '/.codex/contracts', 0700, true));
        self::assertTrue(mkdir($this->repoRoot . '/docs/maps', 0700, true));
        self::assertTrue(mkdir($this->repoRoot . '/tests/Fixtures/parallel/lane-a', 0700, true));
        self::assertNotFalse(
            file_put_contents($this->repoRoot . '/tests/Fixtures/parallel/lane-a/tracked.txt', "base\n"),
        );
        $parallelWrapper = (string) file_get_contents(
            $sourceRepoRoot . '/scripts/agent/check_parallel_work_contract.sh',
        );
        $this->canonicalRemoteRoot = sys_get_temp_dir() . '/parallel-work-canonical-' . bin2hex(random_bytes(8));
        self::assertNotFalse(
            file_put_contents(
                $this->repoRoot . '/scripts/agent/check_parallel_work_contract.sh',
                str_replace(
                    'https://github.com/robinbeier/forscherhaus-appointments.git',
                    $this->canonicalRemoteRoot,
                    $parallelWrapper,
                    $replacementCount,
                ),
            ),
        );
        self::assertSame(1, $replacementCount);
        copy(
            $sourceRepoRoot . '/scripts/agent/check_parallel_work_contract.php',
            $this->repoRoot . '/scripts/agent/check_parallel_work_contract.php',
        );
        copy(
            $sourceRepoRoot . '/scripts/agent/verify_trusted_php_runtime.py',
            $this->repoRoot . '/scripts/agent/verify_trusted_php_runtime.py',
        );
        self::assertTrue(chmod($this->repoRoot . '/scripts/agent/check_parallel_work_contract.sh', 0700));
        foreach (
            [
                'RepoPath.php',
                'ParallelWorkContract.php',
                'ParallelWorkOwnershipContract.php',
                'ParallelWorkPolicyContract.php',
            ]
            as $validatorLibrary
        ) {
            copy(
                $sourceRepoRoot . '/scripts/agent/lib/' . $validatorLibrary,
                $this->repoRoot . '/scripts/agent/lib/' . $validatorLibrary,
            );
        }
        copy(
            $sourceRepoRoot . '/.codex/contracts/agent-workflow.json',
            $this->repoRoot . '/.codex/contracts/agent-workflow.json',
        );
        $this->bindFixtureRuntimePin(
            $this->repoRoot . '/scripts/agent/verify_trusted_php_runtime.py',
            $this->repoRoot . '/.codex/contracts/agent-workflow.json',
        );
        copy(
            $sourceRepoRoot . '/docs/maps/component_ownership_map.json',
            $this->repoRoot . '/docs/maps/component_ownership_map.json',
        );
        $this->runGit($this->repoRoot, ['init', '-q']);
        $this->runGit($this->repoRoot, ['config', 'user.name', 'Parallel Work Test']);
        $this->runGit($this->repoRoot, ['config', 'user.email', 'parallel-work-test@example.invalid']);
        $this->runGit($this->repoRoot, ['add', '.']);
        $this->runGit($this->repoRoot, ['commit', '-qm', 'trusted base']);
        $this->baseSha = $this->runGit($this->repoRoot, ['rev-parse', 'HEAD']);
        $this->runGit(sys_get_temp_dir(), ['init', '-q', '--bare', $this->canonicalRemoteRoot]);
        $this->runGit($this->repoRoot, ['remote', 'add', 'origin', $this->canonicalRemoteRoot]);
        $this->runGit($this->repoRoot, ['push', '-q', 'origin', 'HEAD:main']);
        $this->runGit($this->repoRoot, ['fetch', '-q', 'origin', 'main']);
        $this->trustedValidatorRoot = sys_get_temp_dir() . '/parallel-work-validator-' . bin2hex(random_bytes(8));
        $this->runGit(sys_get_temp_dir(), [
            'clone',
            '-q',
            '--no-hardlinks',
            $this->repoRoot,
            $this->trustedValidatorRoot,
        ]);
        $this->runGit($this->trustedValidatorRoot, ['checkout', '-q', '--detach', $this->baseSha]);
        $this->runGit($this->trustedValidatorRoot, ['update-ref', 'refs/remotes/origin/main', $this->baseSha]);
    }

    protected function tearDown(): void
    {
        foreach (['trustedValidatorRoot', 'repoRoot', 'canonicalRemoteRoot'] as $property) {
            if (isset($this->{$property})) {
                $this->removeDirectory($this->{$property});
            }
        }

        parent::tearDown();
    }

    public function testCliAcceptsValidManifestThroughCanonicalContract(): void
    {
        $manifestPath = $this->writeJsonFixture('manifest', [
            'schema_version' => 1,
            'base_sha' => $this->baseSha,
            'primary_id' => 'primary',
            'primary_approved_component_ids' => ['platform-quality-tooling'],
            'semantic_independence' => [
                'shared_contracts' => [],
                'cross_lane_dependencies' => [],
                'coordination_required' => false,
            ],
            'lanes' => [
                [
                    'id' => 'lane-a',
                    'role' => 'implementation_worker',
                    'base_sha' => $this->baseSha,
                    'ownership' => [['path' => 'scripts/ci/performance', 'match' => 'directory']],
                    'external_mutations' => [],
                ],
                [
                    'id' => 'lane-b',
                    'role' => 'implementation_worker',
                    'base_sha' => $this->baseSha,
                    'ownership' => [['path' => 'tests/Fixtures/parallel/lane-b', 'match' => 'directory']],
                    'external_mutations' => [],
                ],
            ],
        ]);

        [$exitCode, $stdout, $stderr] = $this->runCli([
            '--manifest=' . $manifestPath,
            '--repo-root=' . $this->repoRoot,
        ]);

        self::assertSame(0, $exitCode, $stderr);
        self::assertSame('', $stderr);
        self::assertSame(
            ['schema_version' => 1, 'status' => 'pass', 'errors' => []],
            json_decode($stdout, true, 512, JSON_THROW_ON_ERROR),
        );
    }

    public function testWrapperAttestsExactBasePhpBeforeAnyPhpExecution(): void
    {
        $wrapper = (string) file_get_contents($this->repoRoot . '/scripts/agent/check_parallel_work_contract.sh');

        self::assertStringStartsWith("#!/bin/sh\n", $wrapper);
        self::assertStringContainsString('/usr/bin/python3', $wrapper);
        self::assertStringContainsString('-I -B', $wrapper);
        self::assertStringContainsString('scripts/agent/verify_trusted_php_runtime.py', $wrapper);
        self::assertStringContainsString('.codex/contracts/agent-workflow.json', $wrapper);
        self::assertStringNotContainsString('command -v php', $wrapper);
        self::assertStringNotContainsString('#!/usr/bin/env -S', $wrapper);
        self::assertTrue(
            strpos($wrapper, 'verify_trusted_php_runtime.py') < strrpos($wrapper, 'check_parallel_work_contract.php'),
        );
    }

    public function testAdmissionRejectsARewrittenLocalOriginMain(): void
    {
        self::assertNotFalse(file_put_contents($this->repoRoot . '/lane-only.txt', "lane\n"));
        $this->runGit($this->repoRoot, ['add', 'lane-only.txt']);
        $this->runGit($this->repoRoot, ['commit', '-qm', 'lane-only commit']);
        $rewrittenSha = $this->runGit($this->repoRoot, ['rev-parse', 'HEAD']);
        $this->runGit($this->trustedValidatorRoot, ['fetch', '-q', $this->repoRoot, $rewrittenSha]);
        $this->runGit($this->trustedValidatorRoot, ['update-ref', 'refs/remotes/origin/main', $rewrittenSha]);

        $manifestPath = $this->writeJsonFixture('rewritten-origin-main', $this->manifestForPath('safe/path'));
        [$exitCode, $stdout, $stderr] = $this->runCli(['--manifest=' . $manifestPath]);

        self::assertSame(1, $exitCode, $stderr);
        self::assertSame('', $stderr);
        self::assertSame(
            ['schema_version' => 1, 'status' => 'fail', 'errors' => ['canonical_main_mismatch']],
            json_decode($stdout, true, 512, JSON_THROW_ON_ERROR),
        );
    }

    public function testAdmissionRejectsALaterAncestorThatCouldHideAnEarlierOwnershipViolation(): void
    {
        self::assertNotFalse(
            file_put_contents(
                $this->repoRoot . '/scripts/agent/check_parallel_work_contract.php',
                "\n// primary-owned change that must not be hidden by a later manifest base\n",
                FILE_APPEND,
            ),
        );
        $this->runGit($this->repoRoot, ['add', 'scripts/agent/check_parallel_work_contract.php']);
        $this->runGit($this->repoRoot, ['commit', '-qm', 'later ancestor with ownership violation']);
        $laterSha = $this->runGit($this->repoRoot, ['rev-parse', 'HEAD']);
        $this->runGit($this->trustedValidatorRoot, ['fetch', '-q', $this->repoRoot, $laterSha]);
        $this->runGit($this->trustedValidatorRoot, ['checkout', '-q', '--detach', $laterSha]);

        $manifestPath = $this->writeJsonFixture('later-ancestor', $this->manifestForPath('safe/path', $laterSha));
        [$exitCode, $stdout, $stderr] = $this->runCli(['--manifest=' . $manifestPath]);

        self::assertSame(1, $exitCode, $stderr);
        self::assertSame('', $stderr);
        self::assertSame(
            ['schema_version' => 1, 'status' => 'fail', 'errors' => ['validator_base_mismatch']],
            json_decode($stdout, true, 512, JSON_THROW_ON_ERROR),
        );
    }

    public function testCliRejectsAContractOverrideAndUsesCanonicalPolicy(): void
    {
        $manifestPath = $this->writeJsonFixture('manifest', $this->manifestForPath('scripts/agent'));

        [$exitCode, $stdout, $stderr] = $this->runCli([
            '--manifest=' . $manifestPath,
            '--contract=/tmp/permissive.json',
        ]);

        self::assertSame(2, $exitCode);
        self::assertSame('', $stdout);
        self::assertStringContainsString('Unknown option', $stderr);
    }

    public function testCliRejectsCanonicalPrimaryOwnedPath(): void
    {
        $manifestPath = $this->writeJsonFixture('manifest', $this->manifestForPath('scripts/agent'));

        [$exitCode, $stdout, $stderr] = $this->runCli(['--manifest=' . $manifestPath]);

        self::assertSame(1, $exitCode);
        self::assertSame('', $stderr);
        $result = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        self::assertContains('primary_owned_path:0:scripts/agent', $result['errors']);
    }

    public function testCliRejectsSharedOwnershipPathMatcherAsPrimaryOwned(): void
    {
        $path = 'scripts/ci/ownership_path_rules.py';
        $manifestPath = $this->writeJsonFixture('shared-path-matcher', $this->manifestForPath($path));

        [$exitCode, $stdout, $stderr] = $this->runCli(['--manifest=' . $manifestPath]);

        self::assertSame(1, $exitCode, $stderr);
        self::assertSame('', $stderr);
        self::assertContains(
            'primary_owned_path:0:' . $path,
            json_decode($stdout, true, 512, JSON_THROW_ON_ERROR)['errors'],
        );
    }

    public function testCliAnchorsPolicyAndOwnershipMapToDeclaredBase(): void
    {
        $workingContract = json_decode(
            (string) file_get_contents($this->repoRoot . '/.codex/contracts/agent-workflow.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($workingContract);
        $workingContract['parallel_work']['primary_owned_path_prefixes'] = [];
        self::assertNotFalse(
            file_put_contents(
                $this->repoRoot . '/.codex/contracts/agent-workflow.json',
                json_encode($workingContract, JSON_THROW_ON_ERROR),
            ),
        );

        $manifestPath = $this->writeJsonFixture('base-anchor', $this->manifestForPath('scripts/agent'));
        [$exitCode, $stdout, $stderr] = $this->runCli([
            '--manifest=' . $manifestPath,
            '--repo-root=' . $this->repoRoot,
        ]);

        self::assertSame(1, $exitCode, $stderr);
        self::assertSame('', $stderr);
        self::assertContains(
            'primary_owned_path:0:scripts/agent',
            json_decode($stdout, true, 512, JSON_THROW_ON_ERROR)['errors'],
        );
    }

    public function testCliIgnoresReplaceRefsAndAnAmbientGitBinary(): void
    {
        $replacementContract = json_decode(
            (string) file_get_contents($this->repoRoot . '/.codex/contracts/agent-workflow.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($replacementContract);
        $replacementContract['parallel_work']['primary_owned_path_prefixes'] = [];
        self::assertNotFalse(
            file_put_contents(
                $this->repoRoot . '/.codex/contracts/agent-workflow.json',
                json_encode($replacementContract, JSON_THROW_ON_ERROR),
            ),
        );
        $this->runGit($this->repoRoot, ['add', '.codex/contracts/agent-workflow.json']);
        $this->runGit($this->repoRoot, ['commit', '-qm', 'replacement policy']);
        $replacementSha = $this->runGit($this->repoRoot, ['rev-parse', 'HEAD']);
        $this->runGit($this->trustedValidatorRoot, ['fetch', '-q', $this->repoRoot, $replacementSha]);
        $this->runGit($this->trustedValidatorRoot, ['replace', $this->baseSha, $replacementSha]);

        $ambientDirectory = sys_get_temp_dir() . '/parallel-work-ambient-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($ambientDirectory, 0700));
        $ambientGitMarker = $ambientDirectory . '/git-ran';
        $ambientGit = $ambientDirectory . '/git';
        self::assertNotFalse(
            file_put_contents($ambientGit, "#!/bin/sh\n: > " . escapeshellarg($ambientGitMarker) . "\nexit 99\n"),
        );
        self::assertTrue(chmod($ambientGit, 0700));

        $manifestPath = $this->writeJsonFixture('replace-ref', $this->manifestForPath('scripts/agent'));
        [$exitCode, $stdout, $stderr] = $this->runCli(
            ['--manifest=' . $manifestPath],
            environment: [
                'GIT_NO_REPLACE_OBJECTS' => '0',
                'PATH' => $ambientDirectory,
            ],
        );

        self::assertSame(1, $exitCode, $stderr);
        self::assertSame('', $stderr);
        self::assertContains(
            'primary_owned_path:0:scripts/agent',
            json_decode($stdout, true, 512, JSON_THROW_ON_ERROR)['errors'],
        );
        self::assertFileDoesNotExist($ambientGitMarker);
        self::assertStringContainsString(
            "'GIT_NO_LAZY_FETCH' => '1'",
            (string) file_get_contents($this->repoRoot . '/scripts/agent/check_parallel_work_contract.php'),
        );
    }

    public function testCliRejectsInvalidManifestJsonAndShape(): void
    {
        $invalidJsonPath = sys_get_temp_dir() . '/parallel-work-invalid-' . bin2hex(random_bytes(8)) . '.json';
        self::assertNotFalse(file_put_contents($invalidJsonPath, '{'));

        [$exitCode, $stdout, $stderr] = $this->runCli(['--manifest=' . $invalidJsonPath]);
        self::assertSame(2, $exitCode);
        self::assertSame('', $stdout);
        self::assertStringContainsString('not valid JSON', $stderr);

        $invalidShapePath = $this->writeJsonFixture('shape', []);
        [$exitCode, $stdout, $stderr] = $this->runCli(['--manifest=' . $invalidShapePath]);
        self::assertSame(2, $exitCode);
        self::assertSame('', $stdout);
        self::assertStringContainsString('invalid shape', $stderr);
    }

    public function testCliIgnoresAmbientPhpStartupConfiguration(): void
    {
        $ambientDirectory = sys_get_temp_dir() . '/parallel-work-php-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($ambientDirectory, 0700));
        $marker = $ambientDirectory . '/auto-prepend-ran';
        $autoPrepend = $ambientDirectory . '/auto-prepend.php';
        self::assertNotFalse(
            file_put_contents($autoPrepend, '<?php file_put_contents(' . var_export($marker, true) . ", 'ran');\n"),
        );
        $phpIni = $ambientDirectory . '/php.ini';
        self::assertNotFalse(file_put_contents($phpIni, 'auto_prepend_file=' . $autoPrepend . "\n"));

        $manifestPath = $this->writeJsonFixture('ambient-php', $this->manifestForPath('scripts/agent'));
        [$exitCode, $stdout, $stderr] = $this->runCli(
            ['--manifest=' . $manifestPath],
            environment: [
                'PHPRC' => $phpIni,
                'PHP_INI_SCAN_DIR' => $ambientDirectory,
            ],
        );

        self::assertSame(1, $exitCode, $stderr);
        self::assertSame('', $stderr);
        self::assertContains(
            'primary_owned_path:0:scripts/agent',
            json_decode($stdout, true, 512, JSON_THROW_ON_ERROR)['errors'],
        );
        self::assertFileDoesNotExist($marker);
    }

    public function testCliDoesNotSourceCallerBashEnvironmentBeforeTrustChecks(): void
    {
        $ambientDirectory = sys_get_temp_dir() . '/parallel-work-bash-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($ambientDirectory, 0700));
        $marker = $ambientDirectory . '/bash-env-ran';
        $bashEnvironment = $ambientDirectory . '/bash-env';
        self::assertNotFalse(file_put_contents($bashEnvironment, ': > ' . escapeshellarg($marker) . "\n"));
        $manifestPath = $this->writeJsonFixture('ambient-bash', $this->manifestForPath('scripts/agent'));

        try {
            [$exitCode, $stdout, $stderr] = $this->runCli(
                ['--manifest=' . $manifestPath],
                environment: [
                    'BASH_ENV' => $bashEnvironment,
                    'CODEX_HOME' => $ambientDirectory . '/codex-home',
                    'HOME' => $ambientDirectory . '/home',
                    'PATH' => '/usr/bin:/bin',
                ],
            );

            self::assertSame(1, $exitCode, $stderr);
            self::assertSame('', $stderr);
            self::assertContains(
                'primary_owned_path:0:scripts/agent',
                json_decode($stdout, true, 512, JSON_THROW_ON_ERROR)['errors'],
            );
            self::assertFileDoesNotExist($marker);
        } finally {
            @unlink($marker);
            @unlink($bashEnvironment);
            @rmdir($ambientDirectory);
        }
    }

    public function testCliNeverExecutesPhpResolvedFromAmbientPathBeforeFailClosedBootstrap(): void
    {
        $ambientDirectory = sys_get_temp_dir() . '/parallel-work-path-bootstrap-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($ambientDirectory, 0700));
        $marker = $ambientDirectory . '/ambient-php-ran';
        $ambientPhp = $ambientDirectory . '/php';
        self::assertNotFalse(
            file_put_contents($ambientPhp, "#!/bin/sh\n: > " . escapeshellarg($marker) . "\nexit 99\n"),
        );
        self::assertTrue(chmod($ambientPhp, 0700));

        try {
            [$exitCode, $stdout, $stderr] = $this->runCli([], environment: ['PATH' => $ambientDirectory]);

            self::assertNotSame(0, $exitCode);
            self::assertSame('', $stdout);
            self::assertFileDoesNotExist($marker, $stderr);
        } finally {
            if (is_file($marker)) {
                unlink($marker);
            }
            unlink($ambientPhp);
            rmdir($ambientDirectory);
        }
    }

    public function testTrustedVerificationBindsActualLaneChangesToDeclaredOwnership(): void
    {
        $manifestPath = $this->writeJsonFixture(
            'lane-verification',
            $this->manifestForPath('tests/Fixtures/parallel/lane-a'),
        );

        $committed = $this->repoRoot . '/tests/Fixtures/parallel/lane-a/committed.txt';
        self::assertNotFalse(file_put_contents($committed, "committed\n"));
        $this->runGit($this->repoRoot, ['add', 'tests/Fixtures/parallel/lane-a/committed.txt']);
        $this->runGit($this->repoRoot, ['commit', '-qm', 'lane change']);
        self::assertNotFalse(
            file_put_contents($this->repoRoot . '/tests/Fixtures/parallel/lane-a/tracked.txt', "unstaged\n"),
        );
        $staged = $this->repoRoot . '/tests/Fixtures/parallel/lane-a/staged.txt';
        self::assertNotFalse(file_put_contents($staged, "staged\n"));
        $this->runGit($this->repoRoot, ['add', 'tests/Fixtures/parallel/lane-a/staged.txt']);
        self::assertNotFalse(
            file_put_contents($this->repoRoot . '/tests/Fixtures/parallel/lane-a/untracked.txt', "untracked\n"),
        );

        [$exitCode, $stdout, $stderr] = $this->runTrustedLaneVerification($manifestPath, 'lane-a');
        self::assertSame(0, $exitCode, $stderr);
        self::assertSame('', $stderr);
        $precommitResult = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('provisional_pass', $precommitResult['status']);
        self::assertSame('pre_commit', $precommitResult['verification']['evidence_level']);
        self::assertFalse($precommitResult['verification']['integration_ready']);

        [$exitCode, $stdout, $stderr] = $this->runTrustedLaneVerification($manifestPath, 'lane-a', true);
        self::assertSame(1, $exitCode, $stderr);
        self::assertContains('lane_worktree_not_clean', json_decode($stdout, true, 512, JSON_THROW_ON_ERROR)['errors']);

        $this->runGit($this->repoRoot, ['add', 'tests/Fixtures/parallel/lane-a']);
        $this->runGit($this->repoRoot, ['commit', '-qm', 'complete lane change']);
        [$exitCode, $stdout, $stderr] = $this->runTrustedLaneVerification($manifestPath, 'lane-a', true);
        self::assertSame(0, $exitCode, $stderr);
        $cleanResult = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('pass', $cleanResult['status']);
        self::assertSame($this->baseSha, $cleanResult['verification']['base_sha']);
        self::assertSame(
            $this->runGit($this->repoRoot, ['rev-parse', 'HEAD']),
            $cleanResult['verification']['head_sha'],
        );
        self::assertTrue($cleanResult['verification']['working_tree_clean']);
        self::assertSame('integration', $cleanResult['verification']['evidence_level']);
        self::assertTrue($cleanResult['verification']['integration_ready']);
        self::assertSame(
            '2bbe628e41adeb51021ac4d7aa895fa5b9d798ba54fa9f2e602f679f576ef984',
            $cleanResult['verification']['changed_paths_sha256'],
        );

        self::assertNotFalse(file_put_contents($this->repoRoot . '/scripts/agent/outside.txt', "outside\n"));
        [$exitCode, $stdout, $stderr] = $this->runTrustedLaneVerification($manifestPath, 'lane-a');
        self::assertSame(1, $exitCode, $stderr);
        self::assertSame('', $stderr);
        self::assertContains(
            'ownership_violation:lane-a:scripts/agent/outside.txt',
            json_decode($stdout, true, 512, JSON_THROW_ON_ERROR)['errors'],
        );

        $this->runGit($this->repoRoot, ['add', 'scripts/agent/outside.txt']);
        [$exitCode, $stdout, $stderr] = $this->runTrustedLaneVerification($manifestPath, 'lane-a');
        self::assertSame(1, $exitCode, $stderr);
        self::assertContains(
            'ownership_violation:lane-a:scripts/agent/outside.txt',
            json_decode($stdout, true, 512, JSON_THROW_ON_ERROR)['errors'],
        );

        self::assertNotFalse(
            file_put_contents(
                $this->repoRoot . '/scripts/agent/check_parallel_work_contract.php',
                "\n// outside-lane unstaged change\n",
                FILE_APPEND,
            ),
        );
        [$exitCode, $stdout, $stderr] = $this->runTrustedLaneVerification($manifestPath, 'lane-a');
        self::assertSame(1, $exitCode, $stderr);
        self::assertContains(
            'ownership_violation:lane-a:scripts/agent/check_parallel_work_contract.php',
            json_decode($stdout, true, 512, JSON_THROW_ON_ERROR)['errors'],
        );
    }

    public function testLaneVerificationRejectsAnInLaneValidator(): void
    {
        $manifestPath = $this->writeJsonFixture(
            'self-verification',
            $this->manifestForPath('tests/Fixtures/parallel/lane-a'),
        );
        [$exitCode, $stdout, $stderr] = $this->runCli(
            [
                '--manifest=' . $manifestPath,
                '--repo-root=' . $this->repoRoot,
                '--verify-lane=lane-a',
                '--allow-dirty-precommit',
            ],
            $this->repoRoot,
        );

        self::assertSame(1, $exitCode, $stderr);
        self::assertSame('', $stderr);
        self::assertContains(
            'validator_must_run_outside_lane',
            json_decode($stdout, true, 512, JSON_THROW_ON_ERROR)['errors'],
        );
    }

    public function testAdmissionRejectsAManifestBaseDifferentFromTheTrustedCheckout(): void
    {
        self::assertNotFalse(file_put_contents($this->repoRoot . '/new-base.txt', "new base\n"));
        $this->runGit($this->repoRoot, ['add', 'new-base.txt']);
        $this->runGit($this->repoRoot, ['commit', '-qm', 'different base']);
        $otherBase = $this->runGit($this->repoRoot, ['rev-parse', 'HEAD']);
        $this->runGit($this->trustedValidatorRoot, ['fetch', '-q', $this->repoRoot, $otherBase]);
        $manifestPath = $this->writeJsonFixture('different-base', $this->manifestForPath('safe/path', $otherBase));

        [$exitCode, $stdout, $stderr] = $this->runCli(['--manifest=' . $manifestPath]);

        self::assertSame(1, $exitCode, $stderr);
        self::assertSame('', $stderr);
        self::assertContains('validator_base_mismatch', json_decode($stdout, true, 512, JSON_THROW_ON_ERROR)['errors']);
    }

    public function testAdmissionRejectsDriftBeforeExecutingNewerValidatorSources(): void
    {
        $marker = sys_get_temp_dir() . '/parallel-work-newer-validator-' . bin2hex(random_bytes(8));
        $sourcePath = $this->trustedValidatorRoot . '/scripts/agent/lib/ParallelWorkContract.php';
        self::assertNotFalse(
            file_put_contents(
                $sourcePath,
                "\nfile_put_contents(" . var_export($marker, true) . ", 'executed');\n",
                FILE_APPEND,
            ),
        );
        $this->runGit($this->trustedValidatorRoot, ['add', 'scripts/agent/lib/ParallelWorkContract.php']);
        $this->runGit($this->trustedValidatorRoot, ['commit', '-qm', 'newer validator source']);
        $manifestPath = $this->writeJsonFixture('validator-drift', $this->manifestForPath('safe/path'));

        [$exitCode, $stdout, $stderr] = $this->runCli(['--manifest=' . $manifestPath]);

        self::assertSame(1, $exitCode, $stderr);
        self::assertSame('', $stderr);
        self::assertContains('validator_base_mismatch', json_decode($stdout, true, 512, JSON_THROW_ON_ERROR)['errors']);
        self::assertFileDoesNotExist($marker);
    }

    public function testAdmissionRejectsADirtyValidatorCheckout(): void
    {
        self::assertNotFalse(
            file_put_contents(
                $this->trustedValidatorRoot . '/scripts/agent/lib/ParallelWorkContract.php',
                "\n// untrusted admission-policy change\n",
                FILE_APPEND,
            ),
        );
        $manifestPath = $this->writeJsonFixture('dirty-validator', $this->manifestForPath('safe/path'));

        [$exitCode, $stdout, $stderr] = $this->runCli(['--manifest=' . $manifestPath]);

        self::assertSame(1, $exitCode, $stderr);
        self::assertSame('', $stderr);
        self::assertContains(
            'validator_worktree_not_clean',
            json_decode($stdout, true, 512, JSON_THROW_ON_ERROR)['errors'],
        );
    }

    public function testAdmissionRejectsAWrapperExecutedInsideTheValidatorCheckout(): void
    {
        $manifestPath = $this->writeJsonFixture('in-checkout-runner', $this->manifestForPath('safe/path'));
        $process = proc_open(
            [
                $this->trustedValidatorRoot . '/scripts/agent/check_parallel_work_contract.sh',
                '--validator-checkout=' . $this->trustedValidatorRoot,
                '--manifest=' . $manifestPath,
            ],
            [['file', '/dev/null', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
            $this->trustedValidatorRoot,
        );
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        self::assertSame(1, proc_close($process));
        self::assertSame('', $stdout);
        self::assertStringContainsString('must be materialized outside the checkout', $stderr);
    }

    public function testAdmissionExecutesBaseBlobsInsteadOfAssumeUnchangedCheckoutSources(): void
    {
        $marker = sys_get_temp_dir() . '/parallel-work-transformed-source-' . bin2hex(random_bytes(8));
        $sourcePath = $this->trustedValidatorRoot . '/scripts/agent/lib/ParallelWorkContract.php';
        $source = (string) file_get_contents($sourcePath);
        $transformed = str_replace(
            "<?php\n",
            "<?php\nfile_put_contents(" . var_export($marker, true) . ", 'executed');\n",
            $source,
        );
        self::assertNotSame($source, $transformed);
        self::assertNotFalse(file_put_contents($sourcePath, $transformed));
        $this->runGit($this->trustedValidatorRoot, [
            'update-index',
            '--assume-unchanged',
            'scripts/agent/lib/ParallelWorkContract.php',
        ]);
        self::assertSame('', $this->runGit($this->trustedValidatorRoot, ['status', '--porcelain']));
        $manifestPath = $this->writeJsonFixture('transformed-source', $this->manifestForPath('scripts/agent'));

        [$exitCode, $stdout, $stderr] = $this->runCli(['--manifest=' . $manifestPath]);

        self::assertSame(1, $exitCode, $stderr);
        self::assertSame('', $stderr);
        self::assertContains(
            'primary_owned_path:0:scripts/agent',
            json_decode($stdout, true, 512, JSON_THROW_ON_ERROR)['errors'],
        );
        self::assertFileDoesNotExist($marker);
    }

    public function testAdmissionNeverExecutesAnAssumeUnchangedRuntimeAttestor(): void
    {
        $marker = sys_get_temp_dir() . '/parallel-work-runtime-attestor-' . bin2hex(random_bytes(8));
        $sourcePath = $this->trustedValidatorRoot . '/scripts/agent/verify_trusted_php_runtime.py';
        self::assertNotFalse(
            file_put_contents(
                $sourcePath,
                "#!/usr/bin/python3\nfrom pathlib import Path\nPath(" . var_export($marker, true) . ").touch()\n",
            ),
        );
        $this->runGit($this->trustedValidatorRoot, [
            'update-index',
            '--assume-unchanged',
            'scripts/agent/verify_trusted_php_runtime.py',
        ]);
        self::assertSame('', $this->runGit($this->trustedValidatorRoot, ['status', '--porcelain']));
        $manifestPath = $this->writeJsonFixture('transformed-attestor', $this->manifestForPath('scripts/agent'));

        [$exitCode, $stdout, $stderr] = $this->runCli(['--manifest=' . $manifestPath]);

        self::assertSame(1, $exitCode, $stderr);
        self::assertSame('', $stderr);
        self::assertContains(
            'primary_owned_path:0:scripts/agent',
            json_decode($stdout, true, 512, JSON_THROW_ON_ERROR)['errors'],
        );
        self::assertFileDoesNotExist($marker);
    }

    public function testLaneVerificationRequiresAnExplicitEvidenceMode(): void
    {
        $manifestPath = $this->writeJsonFixture(
            'missing-evidence-mode',
            $this->manifestForPath('tests/Fixtures/parallel/lane-a'),
        );

        [$exitCode, $stdout, $stderr] = $this->runCli([
            '--manifest=' . $manifestPath,
            '--repo-root=' . $this->repoRoot,
            '--verify-lane=lane-a',
        ]);

        self::assertSame(2, $exitCode);
        self::assertSame('', $stdout);
        self::assertStringContainsString('requires an explicit evidence mode', $stderr);
    }

    public function testCliRejectsUnknownOptionBeforeReadingInputs(): void
    {
        [$exitCode, $stdout, $stderr] = $this->runCli(['--bogus']);

        self::assertSame(2, $exitCode);
        self::assertSame('', $stdout);
        self::assertStringContainsString('Unknown option', $stderr);
    }

    /** @param array<string, mixed> $value */
    private function writeJsonFixture(string $label, array $value): string
    {
        $path = sys_get_temp_dir() . '/parallel-work-' . $label . '-' . bin2hex(random_bytes(8)) . '.json';
        self::assertNotFalse(file_put_contents($path, json_encode($value, JSON_THROW_ON_ERROR)));

        return $path;
    }

    private function bindFixtureRuntimePin(string $verifierPath, string $contractPath): void
    {
        $system = match (PHP_OS_FAMILY) {
            'Darwin' => 'Darwin',
            'Linux' => 'Linux',
            default => null,
        };
        $architecture = match (strtolower(php_uname('m'))) {
            'arm64', 'aarch64' => 'aarch64',
            'amd64', 'x86_64' => 'x86_64',
            default => null,
        };
        if ($system === null || $architecture === null) {
            $this->markTestSkipped('The trusted PHP runtime fixture supports Darwin and Linux on known architectures.');
        }
        if ($system === 'Darwin' && $architecture === 'aarch64') {
            $architecture = 'arm64';
        }

        $candidates = array_values(
            array_unique([PHP_BINARY, '/usr/bin/php8.4', '/usr/bin/php8.3', '/usr/bin/php', '/usr/local/bin/php']),
        );
        $candidate = null;
        foreach ($candidates as $logicalPath) {
            $canonicalPath = realpath($logicalPath);
            if (
                $canonicalPath === false ||
                !is_file($canonicalPath) ||
                !is_executable($canonicalPath) ||
                (fileperms($canonicalPath) & 0o022) !== 0
            ) {
                continue;
            }
            if ($system === 'Linux' && fileowner($canonicalPath) !== 0) {
                continue;
            }
            $candidate = $logicalPath;
            break;
        }
        if ($candidate === null) {
            $this->markTestSkipped('No safely inspectable PHP runtime is available for the wrapper fixture.');
        }

        $probe = <<<'PYTHON'
        import importlib.util
        import sys

        spec = importlib.util.spec_from_file_location("trusted_php_runtime_fixture", sys.argv[1])
        if spec is None or spec.loader is None:
            raise RuntimeError("trusted PHP runtime verifier could not be loaded")
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        paths, sealed = module.dependency_closure(sys.argv[2], sys.argv[3])
        print(module.closure_attestation(sys.argv[2], paths, sealed))
        PYTHON;
        $process = proc_open(
            ['/usr/bin/python3', '-I', '-B', '-c', $probe, $verifierPath, $candidate, $system],
            [['file', '/dev/null', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
            $this->repoRoot,
            ['PATH' => '/usr/bin:/bin', 'LC_ALL' => 'C'],
        );
        self::assertIsResource($process);
        $stdout = trim((string) stream_get_contents($pipes[1]));
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $stderr);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $stdout);

        $contract = json_decode((string) file_get_contents($contractPath), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($contract);
        $platform = $system . '-' . $architecture;
        $contract['authority']['interpreter_trust']['php']['candidate_by_platform'] = [$platform => $candidate];
        $contract['authority']['interpreter_trust']['php']['closure_sha256_by_platform'] = [$platform => $stdout];
        self::assertNotFalse(
            file_put_contents($contractPath, json_encode($contract, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
        );
    }

    /** @return array<string, mixed> */
    private function manifestForPath(string $path, ?string $baseSha = null): array
    {
        $baseSha ??= $this->baseSha;

        return [
            'schema_version' => 1,
            'base_sha' => $baseSha,
            'primary_id' => 'primary',
            'primary_approved_component_ids' => [],
            'semantic_independence' => [
                'shared_contracts' => [],
                'cross_lane_dependencies' => [],
                'coordination_required' => false,
            ],
            'lanes' => [
                [
                    'id' => 'lane-a',
                    'role' => 'implementation_worker',
                    'base_sha' => $baseSha,
                    'ownership' => [['path' => $path, 'match' => 'directory']],
                    'external_mutations' => [],
                ],
            ],
        ];
    }

    /**
     * @param list<string> $arguments
     * @param array<string, string>|null $environment
     * @return array{int, string, string}
     */
    private function runCli(array $arguments, ?string $repoRoot = null, ?array $environment = null): array
    {
        $repoRoot ??= $this->trustedValidatorRoot;
        $trustedRunner = $this->materializeValidatorRunner($repoRoot);
        $process = proc_open(
            [$trustedRunner, '--validator-checkout=' . $repoRoot, ...$arguments],
            [['file', '/dev/null', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
            $repoRoot,
            $environment,
        );
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);
        unlink($trustedRunner);

        return [$exitCode, $stdout, $stderr];
    }

    /** @return array{int, string, string} */
    private function runTrustedLaneVerification(string $manifestPath, string $laneId, bool $requireClean = false): array
    {
        $trustedRunner = $this->materializeValidatorRunner($this->trustedValidatorRoot);
        $arguments = [
            $trustedRunner,
            '--validator-checkout=' . $this->trustedValidatorRoot,
            '--manifest=' . $manifestPath,
            '--repo-root=' . $this->repoRoot,
            '--verify-lane=' . $laneId,
        ];
        if ($requireClean) {
            $arguments[] = '--require-clean';
        } else {
            $arguments[] = '--allow-dirty-precommit';
        }
        $process = proc_open(
            $arguments,
            [['file', '/dev/null', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
            $this->trustedValidatorRoot,
        );
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);
        unlink($trustedRunner);

        return [$exitCode, $stdout, $stderr];
    }

    private function materializeValidatorRunner(string $validatorCheckout): string
    {
        $runner = sys_get_temp_dir() . '/parallel-work-validator-runner-' . bin2hex(random_bytes(8));
        self::assertNotFalse(
            file_put_contents(
                $runner,
                $this->runGitRaw($validatorCheckout, ['show', 'HEAD:scripts/agent/check_parallel_work_contract.sh']),
            ),
        );
        self::assertTrue(chmod($runner, 0700));

        return $runner;
    }

    /** @param list<string> $arguments */
    private function runGit(string $workingDirectory, array $arguments): string
    {
        return trim($this->runGitRaw($workingDirectory, $arguments));
    }

    /** @param list<string> $arguments */
    private function runGitRaw(string $workingDirectory, array $arguments): string
    {
        $process = proc_open(
            ['git', '-C', $workingDirectory, ...$arguments],
            [['file', '/dev/null', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), (string) $stderr);

        return (string) $stdout;
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory) || is_link($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            if ($entry->isDir() && !$entry->isLink()) {
                rmdir($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }
        rmdir($directory);
    }
}
