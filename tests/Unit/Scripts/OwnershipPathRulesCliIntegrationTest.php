<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use Forscherhaus\AgentHarness\ParallelWorkOwnershipContract;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../scripts/agent/lib/ParallelWorkOwnershipContract.php';

final class OwnershipPathRulesCliIntegrationTest extends TestCase
{
    private string|false $previousEngine = false;

    protected function setUp(): void
    {
        $this->previousEngine = getenv('PARALLEL_WORK_OWNERSHIP_ENGINE');
        putenv('PARALLEL_WORK_OWNERSHIP_ENGINE=' . dirname(__DIR__, 3) . '/scripts/ci/ownership_path_rules.py');
    }

    protected function tearDown(): void
    {
        if ($this->previousEngine === false) {
            putenv('PARALLEL_WORK_OWNERSHIP_ENGINE');
        } else {
            putenv('PARALLEL_WORK_OWNERSHIP_ENGINE=' . $this->previousEngine);
        }
    }

    public function testPhpAndPythonExecuteTheSameLanguageNeutralSemanticsContract(): void
    {
        $contractPath = dirname(__DIR__, 3) . '/.codex/contracts/ownership-path-rules.json';
        $contract = json_decode((string) file_get_contents($contractPath), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($contract);
        self::assertSame([], ParallelWorkOwnershipContract::validateSemanticsContract($contract));

        $script = <<<'PYTHON'
        import json
        import pathlib
        import sys

        sys.path.insert(0, "scripts/ci")
        import ownership_path_rules as rules

        print(json.dumps({
            "contract": str(rules.CONTRACT_PATH.relative_to(pathlib.Path.cwd())),
            "match_cases": len(rules.CONTRACT["match_cases"]),
            "invalid_rule_cases": len(rules.CONTRACT["invalid_rule_cases"]),
            "overlap_cases": len(rules.CONTRACT["overlap_cases"]),
        }, sort_keys=True))
        PYTHON;
        $python = json_decode(
            $this->runCommand(['/usr/bin/python3', '-I', '-B', '-c', $script]),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertSame('.codex/contracts/ownership-path-rules.json', $python['contract'] ?? null);
        self::assertSame(count($contract['match_cases']), $python['match_cases'] ?? null);
        self::assertSame(count($contract['invalid_rule_cases']), $python['invalid_rule_cases'] ?? null);
        self::assertSame(count($contract['overlap_cases']), $python['overlap_cases'] ?? null);

        $drifted = $contract;
        $drifted['match_cases'][1]['matches'] = true;
        self::assertContains(
            'ownership_path_rule_match_case_failed:directory-self-is-not-a-file-match',
            ParallelWorkOwnershipContract::validateSemanticsContract($drifted),
        );

        $drifted = $contract;
        $drifted['overlap_cases'][0]['overlaps'] = false;
        self::assertContains(
            'ownership_path_rule_overlap_case_failed:nested-directories',
            ParallelWorkOwnershipContract::validateSemanticsContract($drifted),
        );
    }

    public function testCliDistinguishesDirectoryAndExactFileRulesAndRendersCodeownersPatterns(): void
    {
        $root = sys_get_temp_dir() . '/ownership-path-rules-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($root, 0700, true));
        $mapPath = $root . '/ownership.json';
        $codeownersPath = $root . '/CODEOWNERS';
        $changedPath = 'tests/Unit/Scripts/ReadonlyReviewBundleTest.php';

        $directoryMap = [
            'schema_version' => 3,
            'components' => [
                [
                    'component_id' => 'test-directory',
                    'primary_handle' => '@directory-owner',
                    'secondary_handle' => '@directory-reviewer',
                    'path_rules' => [['path' => 'tests/Unit/Scripts', 'match' => 'directory']],
                    'depends_on' => [],
                ],
            ],
        ];
        $exactMap = [
            'schema_version' => 3,
            'components' => [
                [
                    'component_id' => 'test-exact',
                    'primary_handle' => '@exact-owner',
                    'secondary_handle' => '@exact-reviewer',
                    'path_rules' => [['path' => $changedPath, 'match' => 'exact_file']],
                    'depends_on' => [],
                ],
                [
                    'component_id' => 'test-neighbor',
                    'primary_handle' => '@neighbor-owner',
                    'secondary_handle' => '@neighbor-reviewer',
                    'path_rules' => [
                        ['path' => 'tests/Unit/Scripts/ReadonlyReviewBundleTest.php.bak', 'match' => 'exact_file'],
                    ],
                    'depends_on' => [],
                ],
            ],
        ];

        try {
            file_put_contents($mapPath, json_encode($directoryMap, JSON_THROW_ON_ERROR));
            self::assertSame(['test-directory'], $this->matchComponents($mapPath, $changedPath));
            self::assertSame([], $this->matchComponents($mapPath, 'tests/Unit/Scripts'));
            self::assertSame([], $this->matchComponents($mapPath, 'tests/Unit/ScriptSibling/example.php'));

            file_put_contents($mapPath, json_encode($exactMap, JSON_THROW_ON_ERROR));
            self::assertSame(['test-exact'], $this->matchComponents($mapPath, $changedPath));
            self::assertSame(['test-neighbor'], $this->matchComponents($mapPath, $changedPath . '.bak'));
            self::assertSame([], $this->matchComponents($mapPath, $changedPath . '.other'));

            file_put_contents($mapPath, json_encode($exactMap, JSON_THROW_ON_ERROR));
            $this->runCommand([
                '/usr/bin/python3',
                'scripts/docs/generate_codeowners_from_map.py',
                '--map',
                $mapPath,
                '--output',
                $codeownersPath,
            ]);
            $codeowners = file_get_contents($codeownersPath);
            self::assertIsString($codeowners);
            self::assertStringContainsString(
                '/tests/Unit/Scripts/ReadonlyReviewBundleTest.php @exact-owner @exact-reviewer',
                $codeowners,
            );
            self::assertStringNotContainsString('/tests/Unit/Scripts/ReadonlyReviewBundleTest.php/**', $codeowners);

            file_put_contents($mapPath, json_encode($directoryMap, JSON_THROW_ON_ERROR));
            $this->runCommand([
                '/usr/bin/python3',
                'scripts/docs/generate_codeowners_from_map.py',
                '--map',
                $mapPath,
                '--output',
                $codeownersPath,
            ]);
            $codeowners = file_get_contents($codeownersPath);
            self::assertIsString($codeowners);
            self::assertStringContainsString(
                '/tests/Unit/Scripts/** @directory-owner @directory-reviewer',
                $codeowners,
            );
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testPhpFailsClosedWhenCanonicalEngineIsUnavailable(): void
    {
        $previous = getenv('PARALLEL_WORK_OWNERSHIP_ENGINE');
        putenv('PARALLEL_WORK_OWNERSHIP_ENGINE=/tmp/ownership-engine-does-not-exist');
        try {
            $this->expectException(\RuntimeException::class);
            ParallelWorkOwnershipContract::pathRuleCoversChangedPath(
                ['path' => 'tests/Unit/Scripts', 'match' => 'directory'],
                'tests/Unit/Scripts/example.php',
            );
        } finally {
            if ($previous === false) {
                putenv('PARALLEL_WORK_OWNERSHIP_ENGINE');
            } else {
                putenv('PARALLEL_WORK_OWNERSHIP_ENGINE=' . $previous);
            }
        }
    }

    public function testPhpRejectsSymlinkedEnginePath(): void
    {
        $root = sys_get_temp_dir() . '/ownership-engine-link-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($root, 0700, true));
        $target = $root . '/engine.py';
        $link = $root . '/engine-link.py';
        self::assertNotFalse(file_put_contents($target, "print('{}')\n"));
        self::assertTrue(symlink($target, $link));
        $previous = getenv('PARALLEL_WORK_OWNERSHIP_ENGINE');
        putenv('PARALLEL_WORK_OWNERSHIP_ENGINE=' . $link);
        try {
            $this->expectException(\RuntimeException::class);
            ParallelWorkOwnershipContract::pathRuleCoversChangedPath(
                ['path' => 'tests/Unit/Scripts', 'match' => 'directory'],
                'tests/Unit/Scripts/example.php',
            );
        } finally {
            if ($previous === false) {
                putenv('PARALLEL_WORK_OWNERSHIP_ENGINE');
            } else {
                putenv('PARALLEL_WORK_OWNERSHIP_ENGINE=' . $previous);
            }
            unlink($link);
            unlink($target);
            rmdir($root);
        }
    }

    public function testPhpFailsClosedOnMalformedCanonicalEngineOutput(): void
    {
        $root = sys_get_temp_dir() . '/ownership-engine-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($root . '/scripts/ci', 0700, true));
        $engine = $root . '/scripts/ci/ownership_path_rules.py';
        self::assertNotFalse(file_put_contents($engine, "#!/usr/bin/python3\nprint('{}')\n"));
        self::assertTrue(chmod($engine, 0700));
        $previous = getenv('PARALLEL_WORK_OWNERSHIP_ENGINE');
        putenv('PARALLEL_WORK_OWNERSHIP_ENGINE=' . $engine);
        try {
            $this->expectException(\RuntimeException::class);
            ParallelWorkOwnershipContract::pathRuleCoversChangedPath(
                ['path' => 'tests/Unit/Scripts', 'match' => 'directory'],
                'tests/Unit/Scripts/example.php',
            );
        } finally {
            if ($previous === false) {
                putenv('PARALLEL_WORK_OWNERSHIP_ENGINE');
            } else {
                putenv('PARALLEL_WORK_OWNERSHIP_ENGINE=' . $previous);
            }
            unlink($engine);
            rmdir($root . '/scripts/ci');
            rmdir($root . '/scripts');
            rmdir($root);
        }
    }

    public function testPhpFailsClosedWhenCanonicalEngineWritesToStderr(): void
    {
        $root = sys_get_temp_dir() . '/ownership-engine-stderr-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($root . '/scripts/ci', 0700, true));
        $engine = $root . '/scripts/ci/ownership_path_rules.py';
        self::assertNotFalse(
            file_put_contents(
                $engine,
                <<<'PYTHON'
                #!/usr/bin/python3
                import json
                import sys

                print(json.dumps({
                    "protocol_version": 1,
                    "operation": "covers",
                    "result": {"matches": True},
                }))
                print("unexpected diagnostic", file=sys.stderr)
                PYTHON. "\n",
            ),
        );
        self::assertTrue(chmod($engine, 0700));
        $previous = getenv('PARALLEL_WORK_OWNERSHIP_ENGINE');
        putenv('PARALLEL_WORK_OWNERSHIP_ENGINE=' . $engine);

        try {
            $this->expectException(\RuntimeException::class);
            ParallelWorkOwnershipContract::pathRuleCoversChangedPath(
                ['path' => 'tests/Unit/Scripts', 'match' => 'directory'],
                'tests/Unit/Scripts/example.php',
            );
        } finally {
            if ($previous === false) {
                putenv('PARALLEL_WORK_OWNERSHIP_ENGINE');
            } else {
                putenv('PARALLEL_WORK_OWNERSHIP_ENGINE=' . $previous);
            }
            unlink($engine);
            rmdir($root . '/scripts/ci');
            rmdir($root . '/scripts');
            rmdir($root);
        }
    }

    public function testPhpRequiresVersionedOperationEnvelopeAndToleratesStructuredExtensions(): void
    {
        $root = sys_get_temp_dir() . '/ownership-engine-envelope-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($root . '/scripts/ci', 0700, true));
        $engine = $root . '/scripts/ci/ownership_path_rules.py';
        $previous = getenv('PARALLEL_WORK_OWNERSHIP_ENGINE');

        try {
            $valid = <<<'PYTHON'
            import json
            print(json.dumps({
                "protocol_version": 1,
                "operation": "covers",
                "result": {"matches": True},
                "extensions": {"diagnostics": {"engine": "test"}},
            }))
            PYTHON;
            self::assertNotFalse(file_put_contents($engine, $valid));
            self::assertTrue(chmod($engine, 0700));
            putenv('PARALLEL_WORK_OWNERSHIP_ENGINE=' . $engine);
            self::assertTrue(
                ParallelWorkOwnershipContract::pathRuleCoversChangedPath(
                    ['path' => 'tests/Unit/Scripts', 'match' => 'directory'],
                    'tests/Unit/Scripts/example.php',
                ),
            );

            foreach (
                [
                    '{"protocol_version":2,"operation":"covers","result":{"matches":true}}',
                    '{"protocol_version":1,"operation":"overlap","result":{"matches":true}}',
                    '{"protocol_version":1,"operation":"covers","result":{}}',
                    '{"protocol_version":1,"operation":"covers","result":{"matches":"true"}}',
                    '{"protocol_version":1,"operation":"covers","result":{"matches":true},"unexpected":true}',
                    '{"protocol_version":1,"operation":"covers","result":{"matches":true},"extensions":null}',
                    '{"protocol_version":1,"operation":"covers","result":{"matches":true},"extensions":["diagnostic"]}',
                ]
                as $malformed
            ) {
                self::assertNotFalse(file_put_contents($engine, 'print(' . var_export($malformed, true) . ")\n"));
                $caught = null;
                try {
                    ParallelWorkOwnershipContract::pathRuleCoversChangedPath(
                        ['path' => 'tests/Unit/Scripts', 'match' => 'directory'],
                        'tests/Unit/Scripts/example.php',
                    );
                } catch (\RuntimeException $exception) {
                    $caught = $exception;
                } finally {
                    self::assertInstanceOf(\RuntimeException::class, $caught);
                }
            }

            foreach (
                [
                    ['operation' => 'overlap', 'result' => ['overlaps' => 'true']],
                    ['operation' => 'validate_contract', 'result' => ['errors' => [123]]],
                    ['operation' => 'parse', 'result' => ['valid' => 'true', 'rule' => null]],
                    ['operation' => 'parse', 'result' => ['valid' => false, 'rule' => []]],
                ]
                as $case
            ) {
                self::assertNotFalse(
                    file_put_contents(
                        $engine,
                        'print(' . var_export(json_encode(['protocol_version' => 1] + $case), true) . ")\n",
                    ),
                );
                if ($case['operation'] === 'parse') {
                    $errors = [];
                    self::assertNull(ParallelWorkOwnershipContract::readPathRule([], $errors, 'invalid'));
                    self::assertSame(['invalid'], $errors);
                    continue;
                }
                $caught = null;
                try {
                    match ($case['operation']) {
                        'overlap' => ParallelWorkOwnershipContract::pathRulesOverlap(
                            'foo',
                            'directory',
                            'bar',
                            'directory',
                        ),
                        'validate_contract' => ParallelWorkOwnershipContract::validateSemanticsContract([]),
                    };
                } catch (\RuntimeException $exception) {
                    $caught = $exception;
                } finally {
                    self::assertInstanceOf(\RuntimeException::class, $caught);
                }
            }
        } finally {
            if ($previous === false) {
                putenv('PARALLEL_WORK_OWNERSHIP_ENGINE');
            } else {
                putenv('PARALLEL_WORK_OWNERSHIP_ENGINE=' . $previous);
            }
            unlink($engine);
            rmdir($root . '/scripts/ci');
            rmdir($root . '/scripts');
            rmdir($root);
        }
    }

    public function testPhpDelegatesMatchAndOverlapToCanonicalEngine(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 3) . '/scripts/agent/lib/ParallelWorkOwnershipContract.php',
        );
        self::assertStringContainsString('OwnershipPathRuleEngineClient::execute', $source);
        self::assertStringNotContainsString('proc_open', $source);
        self::assertStringNotContainsString('runCanonicalEngine', $source);
        self::assertStringNotContainsString('private static function pathRuleCovers', $source);
        self::assertStringNotContainsString('private static function pathRulesOverlap', $source);
    }

    /** @return list<string> */
    private function matchComponents(string $mapPath, string $candidatePath): array
    {
        $script = <<<'PYTHON'
        import json
        import sys

        sys.path.insert(0, "scripts/ci")
        import check_component_boundaries as boundaries

        with open(sys.argv[1], encoding="utf-8") as handle:
            payload = json.load(handle)
        components, _ = boundaries.build_component_index(payload)
        print(json.dumps(boundaries.match_components(sys.argv[2], components)))
        PYTHON;
        $output = $this->runCommand(['/usr/bin/python3', '-c', $script, $mapPath, $candidatePath]);

        $matches = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($matches);

        return $matches;
    }

    /** @param list<string> $command */
    private function runCommand(array $command): string
    {
        $escaped = implode(' ', array_map('escapeshellarg', $command));
        $process = proc_open($escaped, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 3));
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        self::assertSame(0, $exitCode, trim((string) $stdout . "\n" . (string) $stderr));

        return (string) $stdout;
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $items = scandir($directory);
        self::assertNotFalse($items);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $directory . '/' . $item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($directory);
    }
}
