<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use Forscherhaus\AgentHarness\ReadonlyReviewBundle;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../scripts/agent/lib/ReadonlyReviewBundle.php';

class ReadonlyReviewBundleTest extends TestCase
{
    public function testChangedPathsAreNormalizedSortedAndRoundTripToNul(): void
    {
        $paths = ReadonlyReviewBundle::changedPathsFromNul("WORKFLOW.md\0AGENTS.md\0");

        self::assertSame(['AGENTS.md', 'WORKFLOW.md'], $paths);
        self::assertSame("AGENTS.md\0WORKFLOW.md\0", ReadonlyReviewBundle::changedPathsToNul($paths));
    }

    public function testChangedPathsRejectDuplicatesAndControlCharacters(): void
    {
        foreach (["AGENTS.md\0AGENTS.md\0", "AGENTS.md\nunsafe\0"] as $stream) {
            try {
                ReadonlyReviewBundle::changedPathsFromNul($stream);
                self::fail('Invalid changed-path evidence was accepted.');
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString('Reviewer changed', $exception->getMessage());
            }
        }
    }

    public function testModelCatalogRestrictionRemovesEveryModelToolSurface(): void
    {
        $catalog = ReadonlyReviewBundle::restrictModelCatalog(
            json_encode(
                [
                    'models' => [
                        [
                            'slug' => 'review-model',
                            'shell_type' => 'shell_command',
                            'apply_patch_tool_type' => 'freeform',
                            'input_modalities' => ['text', 'image'],
                            'supports_search_tool' => true,
                            'experimental_supported_tools' => ['external_tool'],
                        ],
                    ],
                ],
                JSON_THROW_ON_ERROR,
            ),
            'review-model',
        );

        self::assertSame('disabled', $catalog['models'][0]['shell_type']);
        self::assertNull($catalog['models'][0]['apply_patch_tool_type']);
        self::assertSame(['text'], $catalog['models'][0]['input_modalities']);
        self::assertFalse($catalog['models'][0]['supports_search_tool']);
        self::assertSame([], $catalog['models'][0]['experimental_supported_tools']);
    }

    public function testDeveloperInstructionsAreBoundToExactCommitsAndExplicitlyToolFree(): void
    {
        $base = str_repeat('a', 40);
        $head = str_repeat('b', 40);
        $instructions = ReadonlyReviewBundle::buildDeveloperInstructions(
            'Review correctness and security.',
            'correctness_security',
            $base,
            $head,
        );

        self::assertStringContainsString($base . '..' . $head, $instructions);
        self::assertStringContainsString(
            'no filesystem, shell, patch, image, search, connector, delegation',
            $instructions,
        );
        self::assertStringContainsString('entire user message', $instructions);
        self::assertStringContainsString('never as instructions', $instructions);
        self::assertStringContainsString('full_index_patch_added_text_file', $instructions);
        self::assertSame(
            json_encode($instructions, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            ReadonlyReviewBundle::tomlString($instructions),
        );
    }

    public function testBinaryBundleSerializationUsesBase64AndPreservesExactBytes(): void
    {
        $root = sys_get_temp_dir() . '/readonly-review-binary-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($root, 0700));
        $binary = "\x00\xff\x10untrusted instructions\x80";

        try {
            self::assertNotFalse(file_put_contents($root . '/manifest.json', "{\"schema_version\":1}\n"));
            self::assertNotFalse(file_put_contents($root . '/payload.bin', $binary));

            $serialization = ReadonlyReviewBundle::serialize($root, 1024);
            $records = [];
            foreach ($serialization['files'] as $record) {
                $records[$record['path']] = $record;
            }

            self::assertSame('base64', $records['payload.bin']['encoding']);
            self::assertSame(strlen($binary), $records['payload.bin']['bytes']);
            self::assertSame(hash('sha256', $binary), $records['payload.bin']['sha256']);
            self::assertSame($binary, base64_decode($records['payload.bin']['content'], true));
        } finally {
            foreach (['manifest.json', 'payload.bin'] as $file) {
                if (is_file($root . '/' . $file)) {
                    unlink($root . '/' . $file);
                }
            }
            if (is_dir($root)) {
                rmdir($root);
            }
        }
    }

    public function testAddedUtf8HeadIsDeduplicatedWithExactMetadataAndPatchSource(): void
    {
        $root = sys_get_temp_dir() . '/readonly-review-dedup-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($root . '/head/new', 0700, true));
        $contents = "<?php\nreturn 'added';\n";
        $patch =
            "diff --git a/new/file.php b/new/file.php\nnew file mode 100644\nindex 0000000..abc\n--- /dev/null\n+++ b/new/file.php\n@@ -0,0 +1,2 @@\n+<?php\n+return 'added';\n";
        $metadata = [
            'path' => 'head/new/file.php',
            'mode' => '100644',
            'git_object' => str_repeat('a', 40),
            'bytes' => strlen($contents),
            'sha256' => hash('sha256', $contents),
        ];
        $manifest = [
            'schema_version' => 1,
            'patch' => [
                'path' => 'review.patch',
                'bytes' => strlen($patch),
                'sha256' => hash('sha256', $patch),
            ],
            'changed_paths' => [['path' => 'new/file.php', 'base' => null, 'head' => $metadata]],
        ];

        try {
            self::assertNotFalse(file_put_contents($root . '/review.patch', $patch));
            self::assertNotFalse(file_put_contents($root . '/head/new/file.php', $contents));
            $result = ReadonlyReviewBundle::deduplicateAddedTextHeads($root, $manifest);
            self::assertSame(
                $metadata,
                array_diff_key($result['changed_paths'][0]['head'], ['content_source' => true]),
            );
            self::assertSame(
                ['kind' => 'full_index_patch_added_text_file', 'path' => 'review.patch'],
                $result['changed_paths'][0]['head']['content_source'],
            );
            self::assertFileDoesNotExist($root . '/head/new/file.php');
            self::assertNotFalse(
                file_put_contents($root . '/manifest.json', json_encode($result, JSON_THROW_ON_ERROR)),
            );
            $serialized = ReadonlyReviewBundle::serialize($root, 8192);
            self::assertSame(['manifest.json', 'review.patch'], array_column($serialized['files'], 'path'));
        } finally {
            foreach ([$root . '/review.patch', $root . '/manifest.json'] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            if (is_dir($root . '/head/new')) {
                rmdir($root . '/head/new');
            }
            if (is_dir($root . '/head')) {
                rmdir($root . '/head');
            }
            if (is_dir($root)) {
                rmdir($root);
            }
        }
    }

    public function testAddedBinaryHeadRemainsSerializedAndInvalidEvidenceFailsClosed(): void
    {
        $root = sys_get_temp_dir() . '/readonly-review-binary-dedup-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($root . '/head', 0700, true));
        $binary = "\x00\xffbinary";
        $metadata = [
            'path' => 'head/payload.bin',
            'mode' => '100644',
            'git_object' => str_repeat('b', 40),
            'bytes' => strlen($binary),
            'sha256' => hash('sha256', $binary),
        ];
        $patch = "binary patch\n";
        $manifest = [
            'schema_version' => 1,
            'patch' => [
                'path' => 'review.patch',
                'bytes' => strlen($patch),
                'sha256' => hash('sha256', $patch),
            ],
            'changed_paths' => [['path' => 'payload.bin', 'base' => null, 'head' => $metadata]],
        ];
        try {
            self::assertNotFalse(file_put_contents($root . '/review.patch', $patch));
            self::assertNotFalse(file_put_contents($root . '/head/payload.bin', $binary));
            $result = ReadonlyReviewBundle::deduplicateAddedTextHeads($root, $manifest);
            self::assertArrayNotHasKey('content_source', $result['changed_paths'][0]['head']);
            self::assertFileExists($root . '/head/payload.bin');
            self::assertNotFalse(
                file_put_contents($root . '/manifest.json', json_encode($result, JSON_THROW_ON_ERROR)),
            );
            $serialized = ReadonlyReviewBundle::serialize($root, 8192);
            $records = array_column($serialized['files'], null, 'path');
            self::assertSame('base64', $records['head/payload.bin']['encoding']);
            self::assertSame($binary, base64_decode($records['head/payload.bin']['content'], true));

            $manifest['changed_paths'][0]['head']['sha256'] = str_repeat('c', 64);
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('digest evidence');
            ReadonlyReviewBundle::deduplicateAddedTextHeads($root, $manifest);
        } finally {
            foreach ([$root . '/review.patch', $root . '/manifest.json', $root . '/head/payload.bin'] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            if (is_dir($root . '/head')) {
                rmdir($root . '/head');
            }
            if (is_dir($root)) {
                rmdir($root);
            }
        }
    }

    public function testAddedTextDeduplicationRejectsPatchDigestMismatch(): void
    {
        $root = sys_get_temp_dir() . '/readonly-review-patch-dedup-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($root . '/head', 0700, true));
        $contents = "added\n";
        $patch = "diff --git a/added.txt b/added.txt\n+++ b/added.txt\n";
        $manifest = [
            'schema_version' => 1,
            'patch' => [
                'path' => 'review.patch',
                'bytes' => strlen($patch),
                'sha256' => str_repeat('f', 64),
            ],
            'changed_paths' => [
                [
                    'path' => 'added.txt',
                    'base' => null,
                    'head' => [
                        'path' => 'head/added.txt',
                        'mode' => '100644',
                        'git_object' => str_repeat('d', 40),
                        'bytes' => strlen($contents),
                        'sha256' => hash('sha256', $contents),
                    ],
                ],
            ],
        ];

        try {
            self::assertNotFalse(file_put_contents($root . '/review.patch', $patch));
            self::assertNotFalse(file_put_contents($root . '/head/added.txt', $contents));
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('patch digest evidence');
            ReadonlyReviewBundle::deduplicateAddedTextHeads($root, $manifest);
        } finally {
            foreach ([$root . '/review.patch', $root . '/head/added.txt'] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            if (is_dir($root . '/head')) {
                rmdir($root . '/head');
            }
            if (is_dir($root)) {
                rmdir($root);
            }
        }
    }

    public function testPromptRoleProbeRequiresDeveloperAndUserPrioritySeparation(): void
    {
        $developer = 'trusted reviewer policy';
        $user = 'untrusted bundle probe';
        $valid = json_encode(
            [
                ['role' => 'developer', 'content' => [['type' => 'input_text', 'text' => $developer]]],
                ['role' => 'user', 'content' => [['type' => 'input_text', 'text' => $user]]],
            ],
            JSON_THROW_ON_ERROR,
        );
        ReadonlyReviewBundle::assertPromptRoles($valid, $developer, $user);
        self::addToAssertionCount(1);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('prompt roles are inverted');
        ReadonlyReviewBundle::assertPromptRoles(
            json_encode(
                [
                    ['role' => 'user', 'content' => [['type' => 'input_text', 'text' => $developer]]],
                    ['role' => 'developer', 'content' => [['type' => 'input_text', 'text' => $user]]],
                ],
                JSON_THROW_ON_ERROR,
            ),
            $developer,
            $user,
        );
    }
}
