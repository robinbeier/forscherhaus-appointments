<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../scripts/release-gate/lib/ProviderUiSmokeCredentials.php';

use function ReleaseGate\parseProviderUiSmokeCredentials;
use function ReleaseGate\providerUiSmokeCredentialStatsMatch;

class ProviderUiSmokeCredentialsTest extends TestCase
{
    public function testParsesExactReservedCredentialContract(): void
    {
        $credentials = parseProviderUiSmokeCredentials(
            "PROVIDER_UI_SMOKE_USERNAME=__ea_provider_ui_smoke_v1\n" .
                'PROVIDER_UI_SMOKE_PASSWORD=' .
                str_repeat('a1', 32) .
                "\n",
        );

        self::assertSame('__ea_provider_ui_smoke_v1', $credentials['username']);
        self::assertSame(str_repeat('a1', 32), $credentials['password']);
    }

    public function testRejectsAdditionalIniKeys(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('exactly the required keys');

        parseProviderUiSmokeCredentials(
            "PROVIDER_UI_SMOKE_USERNAME=__ea_provider_ui_smoke_v1\n" .
                'PROVIDER_UI_SMOKE_PASSWORD=' .
                str_repeat('a1', 32) .
                "\n" .
                "EXTRA=not-allowed\n",
        );
    }

    public function testRejectsNonReservedUsername(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('reserved fixture account');

        parseProviderUiSmokeCredentials(
            "PROVIDER_UI_SMOKE_USERNAME=some-provider\n" . 'PROVIDER_UI_SMOKE_PASSWORD=' . str_repeat('a1', 32) . "\n",
        );
    }

    #[DataProvider('invalidPasswordProvider')]
    public function testRejectsPasswordThatIsNotExactly64LowercaseHex(string $password): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid shape');

        parseProviderUiSmokeCredentials(
            "PROVIDER_UI_SMOKE_USERNAME=__ea_provider_ui_smoke_v1\n" . 'PROVIDER_UI_SMOKE_PASSWORD=' . $password . "\n",
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidPasswordProvider(): array
    {
        return [
            'too short' => [str_repeat('a', 63)],
            'too long' => [str_repeat('a', 65)],
            'uppercase' => [str_repeat('A', 64)],
            'not hexadecimal' => [str_repeat('z', 64)],
        ];
    }

    public function testSecureOpenStatComparisonCoversIdentityPermissionsLinksAndSize(): void
    {
        $expected = [
            'dev' => 1,
            'ino' => 2,
            'uid' => 0,
            'mode' => 0100600,
            'nlink' => 1,
            'size' => 128,
        ];

        self::assertTrue(providerUiSmokeCredentialStatsMatch($expected, $expected));

        foreach (['dev', 'ino', 'uid', 'mode', 'nlink', 'size'] as $field) {
            $changed = $expected;
            $changed[$field] = (int) $changed[$field] + 1;
            self::assertFalse(providerUiSmokeCredentialStatsMatch($expected, $changed), $field);
        }
    }
}
