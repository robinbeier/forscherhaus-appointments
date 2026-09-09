<?php

namespace Tests\Unit\Helper;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\TestCase;

require_once APPPATH . 'helpers/html_helper.php';

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class HtmlHelperTest extends TestCase
{
    private string $cachePath;

    protected function setUp(): void
    {
        $this->cachePath = sys_get_temp_dir() . '/html-purifier-' . bin2hex(random_bytes(8));
        mkdir($this->cachePath, 0700, true);
        config(['cache_path' => $this->cachePath]);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->cachePath);
    }

    public function testPureHtmlPurifiesMarkupAndWritesDefinitionsToConfiguredCache(): void
    {
        $result = pure_html('<p>Allowed</p><script>alert("x")</script>');

        $this->assertStringContainsString('<p>Allowed</p>', $result);
        $this->assertStringNotContainsString('<script', $result);
        $this->assertNotEmpty($this->filesIn($this->cachePath));
    }

    private function filesIn(string $directory): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory));

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        foreach (array_diff(scandir($directory), ['.', '..']) as $entry) {
            $path = $directory . DIRECTORY_SEPARATOR . $entry;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($directory);
    }
}
