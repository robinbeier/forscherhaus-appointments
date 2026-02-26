<?php

namespace Tests\Unit\Helper;

use Tests\TestCase;

class DonutHelperTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        get_instance()->load->helper('donut');
    }

    public function testDonutHexToRgbSupportsShortAndLongHex(): void
    {
        $this->assertSame([170, 187, 204], donut_hex_to_rgb('#abc'));
        $this->assertSame([46, 125, 50], donut_hex_to_rgb('2e7d32'));
    }

    public function testDonutHexToRgbPadsIncompleteInput(): void
    {
        $this->assertSame([161, 0, 0], donut_hex_to_rgb('a1'));
    }

    public function testDonutImageDataUrlHandlesFractionalProgressWithoutWarnings(): void
    {
        if (!function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD extension is not available in this environment.');
        }

        $warnings = [];
        set_error_handler(
            static function (int $severity, string $message) use (&$warnings): bool {
                if (in_array($severity, [E_WARNING, E_NOTICE, E_DEPRECATED], true)) {
                    $warnings[] = $message;
                }

                return false;
            },
        );

        try {
            $dataUrl = donut_image_data_url(0.3333333, 96, 14, [
                'background' => '#e9eef5',
                'foreground' => '#2e7d32',
            ]);
        } finally {
            restore_error_handler();
        }

        $this->assertIsString($dataUrl);
        $this->assertStringStartsWith('data:image/png;base64,', $dataUrl);
        $this->assertSame([], $warnings);

        $encoded = substr($dataUrl, strlen('data:image/png;base64,'));
        $binary = base64_decode($encoded, true);

        $this->assertIsString($binary);
        $this->assertStringStartsWith("\x89PNG\r\n\x1a\n", $binary);
    }
}
