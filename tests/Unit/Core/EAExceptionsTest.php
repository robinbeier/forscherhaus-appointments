<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class EAExceptionsTest extends TestCase
{
    #[DataProvider('errorModes')]
    public function testDatabaseErrorOutputRespectsEnvironmentAndTransport(
        string $environment,
        string $mode,
        bool $generic,
    ): void {
        $process = proc_open(
            [PHP_BINARY, '-n', dirname(__DIR__, 2) . '/Fixtures/ea_exceptions_probe.php', $environment, $mode],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $stderr);

        $result = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($result);
        self::assertSame($mode === 'http' ? 418 : null, $result['status']);
        if ($generic) {
            self::assertStringContainsString('An unexpected error occurred.', $result['db']);
            self::assertStringNotContainsString('Sensitive SQL', $result['db']);
        } else {
            self::assertStringContainsString('Sensitive SQL and connection details', $result['db']);
        }
        self::assertStringContainsString('Other message', $result['other']);
    }

    /** @return array<string, array{string, string, bool}> */
    public static function errorModes(): array
    {
        return [
            'production http' => ['production', 'http', true],
            'testing http' => ['testing', 'http', true],
            'development http' => ['development', 'http', false],
            'production cli' => ['production', 'cli', false],
        ];
    }
}
