<?php

namespace Tests\Unit\Scripts;

use CiContract\BookingEmailFieldPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReleaseGate\GateAssertionException;

require_once __DIR__ . '/../../../scripts/ci/lib/BookingEmailFieldPolicy.php';
require_once __DIR__ . '/../../../scripts/release-gate/lib/GateAssertions.php';

final class BookingEmailFieldPolicyTest extends TestCase
{
    public function testDisplayEmailOneEnablesEmailAndUsesAuthenticatedSettingsRequest(): void
    {
        $calls = [];

        $result = BookingEmailFieldPolicy::resolve(false, static function (...$arguments) use (&$calls): string {
            $calls[] = $arguments;

            return '{"name":"display_email","value":"1"}';
        });

        self::assertTrue($result);
        self::assertSame([['GET', 'api/v1/settings/display_email', true, [200]]], $calls);
    }

    public function testDisplayEmailZeroDisablesEmail(): void
    {
        self::assertFalse(
            BookingEmailFieldPolicy::resolve(
                false,
                static fn(
                    string $method,
                    string $path,
                    bool $authenticated,
                    array $expected,
                ): string => '{"name":"display_email","value":"0"}',
            ),
        );
    }

    public function testSyntheticCanaryReturnsTrueWithoutReadingSettings(): void
    {
        $called = false;

        self::assertTrue(
            BookingEmailFieldPolicy::resolve(true, static function () use (&$called): string {
                $called = true;

                return '';
            }),
        );
        self::assertFalse($called);
    }

    #[DataProvider('malformedSettingProvider')]
    public function testMalformedOrUnsupportedSettingFailsClosed(string $body): void
    {
        $this->expectException(GateAssertionException::class);

        BookingEmailFieldPolicy::resolve(false, static function (
            string $method,
            string $path,
            bool $authenticated,
            array $expected,
        ) use ($body): string {
            return $body;
        });
    }

    /** @return iterable<string, array{string}> */
    public static function malformedSettingProvider(): iterable
    {
        yield 'invalid json' => ['not-json'];
        yield 'wrong setting name' => ['{"name":"require_email","value":"1"}'];
        yield 'unsupported value' => ['{"name":"display_email","value":"true"}'];
        yield 'extra field' => ['{"name":"display_email","value":"1","extra":true}'];
    }
}
