<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

final class OwnershipPathRulesCliIntegrationTest extends TestCase
{
    public function testPythonExecutesTheLanguageNeutralSemanticsContract(): void
    {
        $contractPath = dirname(__DIR__, 3) . '/.codex/contracts/ownership-path-rules.json';
        $contract = json_decode((string) file_get_contents($contractPath), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($contract);
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
            "errors": rules.validate_contract(rules.CONTRACT),
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
        self::assertSame([], $python['errors'] ?? null);
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
