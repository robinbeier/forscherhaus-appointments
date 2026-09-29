<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

require_once __DIR__ . '/../../../scripts/release-gate/lib/ProviderUiSmokeDiagnostics.php';

final class ProviderUiSmokeDiagnosticsTest extends TestCase
{
    /** @param array<string, mixed> $processResult */
    #[DataProvider('runCodeFailureProvider')]
    public function testRunCodeFailuresUseFixedPiiFreeClassAndStage(array $processResult, string $expectedClass): void
    {
        $failure = \ProviderUiSmokeBrowserFlowException::runCodeProcessFailure($processResult);

        self::assertSame($expectedClass, $failure->diagnosticClass);
        self::assertSame('run_code', $failure->stage);
        self::assertSame([], $failure->safeDetails);
        self::assertFalse($failure->assertionFailure);
        self::assertReportFieldsAreSafe($failure);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function runCodeFailureProvider(): iterable
    {
        yield 'non-zero process exit' => [
            ['exit_code' => 7, 'timed_out' => false, 'stdout' => 'sensitive output'],
            'run_code_process_exit',
        ];
        yield 'timeout' => [
            ['exit_code' => 124, 'timed_out' => true, 'stdout' => 'sensitive output'],
            'run_code_timeout',
        ];
        yield 'cli error marker' => [
            ['exit_code' => 0, 'timed_out' => false, 'stdout' => "### Error\nsensitive output"],
            'run_code_cli_error',
        ];
    }

    #[DataProvider('stageFailureProvider')]
    public function testLaterBrowserFlowFailuresUseFixedStages(
        string $factory,
        string $expectedClass,
        string $expectedStage,
        bool $assertionFailure,
    ): void {
        $failure = \ProviderUiSmokeBrowserFlowException::$factory();

        self::assertSame($expectedClass, $failure->diagnosticClass);
        self::assertSame($expectedStage, $failure->stage);
        self::assertSame([], $failure->safeDetails);
        self::assertSame($assertionFailure, $failure->assertionFailure);
        self::assertReportFieldsAreSafe($failure);
    }

    /**
     * @return iterable<string, array{string, string, string, bool}>
     */
    public static function stageFailureProvider(): iterable
    {
        yield 'browser open launch' => ['browserOpenLaunchFailure', 'browser_open_launch', 'browser_open', false];
        yield 'state load launch' => ['stateLoadLaunchFailure', 'state_load_launch', 'state_load', false];
        yield 'state load cleanup' => ['stateLoadCleanupFailure', 'state_load_cleanup', 'state_load', false];
        yield 'structured result parse' => [
            'structuredResultParseFailure',
            'structured_result_parse',
            'structured_result',
            true,
        ];
        yield 'browser close' => ['browserCloseFailure', 'browser_close', 'browser_close', false];
        yield 'pdf permissions' => ['pdfFilePermissionsFailure', 'pdf_file_permissions', 'pdf_permissions', false];
        yield 'run-code launch' => ['runCodeLaunchFailure', 'run_code_launch', 'run_code', false];
    }

    public function testStructuredAssertionPreservesOnlyBooleanAndCountDetails(): void
    {
        $failure = \ProviderUiSmokeBrowserFlowException::structuredResultAssertion([
            'ok' => false,
            'flow_error_count' => 1,
        ]);

        self::assertSame('structured_result_assertion', $failure->diagnosticClass);
        self::assertSame(['ok' => false, 'flow_error_count' => 1], $failure->safeDetails);
        self::assertReportFieldsAreSafe($failure);
    }

    public function testReportMappingContainsOnlyFixedDiagnosticFields(): void
    {
        $failure = \ProviderUiSmokeBrowserFlowException::browserCloseFailure();

        self::assertSame(
            [
                'diagnostic_class' => 'browser_close',
                'stage' => 'browser_close',
            ],
            $failure->reportFields(),
        );
    }

    public function testEveryDiagnosticReportMappingIsExactAndPiiFree(): void
    {
        $cases = [
            [
                \ProviderUiSmokeBrowserFlowException::browserOpenProcessFailure([
                    'exit_code' => 9,
                    'timed_out' => false,
                    'stdout' => 'secret stdout',
                ]),
                'browser_open_process_exit',
                'browser_open',
            ],
            [
                \ProviderUiSmokeBrowserFlowException::stateLoadProcessFailure([
                    'exit_code' => 124,
                    'timed_out' => true,
                    'stdout' => 'secret stdout',
                ]),
                'state_load_timeout',
                'state_load',
            ],
            [\ProviderUiSmokeBrowserFlowException::browserOpenLaunchFailure(), 'browser_open_launch', 'browser_open'],
            [\ProviderUiSmokeBrowserFlowException::stateLoadLaunchFailure(), 'state_load_launch', 'state_load'],
            [\ProviderUiSmokeBrowserFlowException::stateLoadCleanupFailure(), 'state_load_cleanup', 'state_load'],
            [
                \ProviderUiSmokeBrowserFlowException::runCodeProcessFailure([
                    'exit_code' => 9,
                    'timed_out' => false,
                    'stdout' => 'secret stdout',
                ]),
                'run_code_process_exit',
                'run_code',
            ],
            [
                \ProviderUiSmokeBrowserFlowException::runCodeProcessFailure([
                    'exit_code' => 124,
                    'timed_out' => true,
                    'stdout' => 'secret stdout',
                ]),
                'run_code_timeout',
                'run_code',
            ],
            [
                \ProviderUiSmokeBrowserFlowException::runCodeProcessFailure([
                    'exit_code' => 0,
                    'timed_out' => false,
                    'stdout' => "### Error\nsecret stdout",
                ]),
                'run_code_cli_error',
                'run_code',
            ],
            [\ProviderUiSmokeBrowserFlowException::runCodeLaunchFailure(), 'run_code_launch', 'run_code'],
            [
                \ProviderUiSmokeBrowserFlowException::structuredResultParseFailure(),
                'structured_result_parse',
                'structured_result',
            ],
            [
                \ProviderUiSmokeBrowserFlowException::structuredResultAssertion([
                    'ok' => false,
                    'flow_error_count' => 1,
                ]),
                'structured_result_assertion',
                'structured_result',
            ],
            [\ProviderUiSmokeBrowserFlowException::browserCloseFailure(), 'browser_close', 'browser_close'],
            [
                \ProviderUiSmokeBrowserFlowException::pdfFilePermissionsFailure(),
                'pdf_file_permissions',
                'pdf_permissions',
            ],
        ];

        foreach ($cases as [$failure, $diagnosticClass, $stage]) {
            self::assertSame(
                [
                    'diagnostic_class' => $diagnosticClass,
                    'stage' => $stage,
                ],
                $failure->reportFields(),
            );
            self::assertStringNotContainsString('secret', json_encode($failure->reportFields(), JSON_THROW_ON_ERROR));
        }
    }

    private static function assertReportFieldsAreSafe(\ProviderUiSmokeBrowserFlowException $failure): void
    {
        self::assertArrayHasKey('diagnostic_class', [
            'diagnostic_class' => $failure->diagnosticClass,
            'stage' => $failure->stage,
        ]);
        self::assertIsString($failure->diagnosticClass);
        self::assertIsString($failure->stage);
        self::assertStringNotContainsString('sensitive', $failure->diagnosticClass . $failure->stage);
    }
}
