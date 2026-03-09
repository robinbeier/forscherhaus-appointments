<?php

namespace Tests\Unit\Views;

use Tests\TestCase;

class JqueryCompatInlineViewTest extends TestCase
{
    public function testCompatComponentRendersInlineScriptWithLegacyHelpers(): void
    {
        ob_start();
        include APPPATH . 'views/components/jquery_compat_inline.php';
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('<script>', $output);
        $this->assertStringContainsString('$.fn.andSelf', $output);
        $this->assertStringContainsString('$.isNumeric', $output);
        $this->assertStringNotContainsString('assets/js/jquery_compat.js', $output);
    }

    public function testCoreLayoutsUseInlineCompatComponentInsteadOfCompatAsset(): void
    {
        foreach ($this->layoutPaths() as $path) {
            $source = file_get_contents($path);

            $this->assertNotFalse($source);
            $this->assertStringContainsString("component('jquery_compat_inline')", $source, $path);
            $this->assertStringNotContainsString('assets/js/jquery_compat.js', $source, $path);
        }
    }

    /**
     * @return string[]
     */
    private function layoutPaths(): array
    {
        return [
            APPPATH . 'views/layouts/account_layout.php',
            APPPATH . 'views/layouts/backend_layout.php',
            APPPATH . 'views/layouts/booking_layout.php',
            APPPATH . 'views/layouts/message_layout.php',
            APPPATH . 'views/pages/installation.php',
        ];
    }
}
