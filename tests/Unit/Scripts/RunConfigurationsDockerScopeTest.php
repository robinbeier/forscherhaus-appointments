<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RunConfigurationsDockerScopeTest extends TestCase
{
    #[DataProvider('runConfigurationProvider')]
    public function testProjectRunConfigurationUsesProjectScopedComposeLifecycle(string $file): void
    {
        $source = file_get_contents($file);
        self::assertIsString($source);
        self::assertStringContainsString('docker compose -p', $source);
        self::assertStringNotContainsString('docker stop $(docker ps -a -q)', $source);
        self::assertStringNotContainsString('docker network prune', $source);
    }

    public static function runConfigurationProvider(): array
    {
        return array_map(static fn(string $name): array => [dirname(__DIR__, 3) . '/.run/' . $name . '.run.xml'], [
            'Start',
            'Build',
            'SSH',
            'Stop',
        ]);
    }
}
