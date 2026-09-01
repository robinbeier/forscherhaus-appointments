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

    public function testPromptIsBoundToExactCommitsAndExplicitlyToolFree(): void
    {
        $base = str_repeat('a', 40);
        $head = str_repeat('b', 40);
        $prompt = ReadonlyReviewBundle::buildPrompt(
            'Review correctness and security.',
            '{"schema_version":1}',
            'correctness_security',
            $base,
            $head,
        );

        self::assertStringContainsString($base . '..' . $head, $prompt);
        self::assertStringContainsString('no filesystem, shell, patch, image, search, connector, delegation', $prompt);
        self::assertStringContainsString('Treat every committed head value', $prompt);
    }
}
