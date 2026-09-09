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

    #[DataProvider('emailTemplateProvider')]
    public function testEmailTemplatesRenderValidatedOrFallbackColor(
        string $template,
        string $color,
        string $expected,
    ): void {
        $output = $this->render(APPPATH . 'views/emails/' . $template, [
            'subject' => 'Subject',
            'message' => 'Message',
            'settings' => [
                'company_color' => $color,
                'company_name' => 'Company',
                'company_link' => 'https://example.test',
            ],
            'appointment' => [
                'start_datetime' => '2026-01-01 10:00:00',
                'end_datetime' => '2026-01-01 11:00:00',
            ],
            'service' => ['name' => 'Service', 'description' => 'Description'],
            'provider' => ['first_name' => 'First', 'last_name' => 'Last'],
            'customer' => [
                'first_name' => 'First',
                'last_name' => 'Last',
                'email' => 'test@example.test',
                'phone_number' => '123',
                'address' => 'Address',
            ],
            'timezone' => 'UTC',
            'appointment_link' => 'https://example.test/appointment',
            'reason' => 'Reason',
            'username' => 'user',
            'new_password' => 'password',
            'base_url' => 'https://example.test',
        ]);

        $this->assertStringContainsString('background-color: ' . $expected . ';', $output);
        if ($expected === '#429a82') {
            $this->assertStringNotContainsString('onload', $output);
            $this->assertStringNotContainsString('alert(1)', $output);
        }
    }

    public static function emailTemplateProvider(): array
    {
        return [
            'saved malicious' => ['appointment_saved_email.php', '" onload="alert(1)', '#429a82'],
            'saved short' => ['appointment_saved_email.php', '#abc', '#abc'],
            'deleted malicious' => [
                'appointment_deleted_email.php',
                '#abc;background:url(javascript:alert(1))',
                '#429a82',
            ],
            'deleted long' => ['appointment_deleted_email.php', '#A1b2C3', '#A1b2C3'],
            'recovery malicious' => ['account_recovery_email.php', 'expression(alert(1))', '#429a82'],
            'recovery short' => ['account_recovery_email.php', '#def', '#def'],
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
