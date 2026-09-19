<?php

namespace Tests\Unit\Views;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CompanyColorOutputTest extends TestCase
{
    #[DataProvider('webColorProvider')]
    public function testWebComponentRendersOnlyValidatedColor(string $color, string $expected): void
    {
        $output = $this->render(APPPATH . 'views/components/company_color_style.php', [
            'company_color' => $color,
        ]);

        $this->assertStringContainsString($expected, $output);
        if ($expected === '') {
            $this->assertSame('', trim($output));
        }
    }

    public static function webColorProvider(): array
    {
        return [
            'malicious css' => ['#abc; background: url(javascript:alert(1))', ''],
            'three digit color' => ['#abc', 'color: #abc;'],
            'six digit color' => ['#a1b2c3', 'color: #a1b2c3;'],
        ];
    }

    private function render(string $path, array $variables): string
    {
        extract($variables, EXTR_SKIP);
        ob_start();
        include $path;

        return (string) ob_get_clean();
    }
}
