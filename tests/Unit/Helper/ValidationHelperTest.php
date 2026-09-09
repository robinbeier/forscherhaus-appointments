<?php

namespace Tests\Unit\Helper;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

require_once APPPATH . 'helpers/validation_helper.php';

class ValidationHelperTest extends TestCase
{
    public function testValidateDateTimeReturnsTrueOnValidValue(): void
    {
        $this->assertTrue(validate_datetime(date('Y-m-d H:i:s')));
    }

    public function testValidateDateTimeReturnsFalseOnInvalidValue(): void
    {
        $this->assertFalse(validate_datetime('invalid'));
    }

    #[DataProvider('hexColorProvider')]
    public function testValidateHexColor(mixed $value, bool $expected): void
    {
        $this->assertSame($expected, validate_hex_color($value));
    }

    public static function hexColorProvider(): array
    {
        return [
            'short color' => ['#Ab3', true],
            'long color' => ['#aBc123', true],
            'trailing newline' => ["#abc\n", false],
            'css payload' => ['#abc;background:url(javascript:alert(1))', false],
            'missing hash' => ['abc', false],
            'non string' => [123, false],
        ];
    }
}
