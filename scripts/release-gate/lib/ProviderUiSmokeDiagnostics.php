<?php

declare(strict_types=1);

final class ProviderUiSmokeBrowserFlowException extends RuntimeException
{
    /** @var array<string, string> */
    private const DIAGNOSTIC_STAGES = [
        'browser_open_process_exit' => 'browser_open',
        'browser_open_timeout' => 'browser_open',
        'browser_open_cli_error' => 'browser_open',
        'browser_open_launch' => 'browser_open',
        'state_load_process_exit' => 'state_load',
        'state_load_timeout' => 'state_load',
        'state_load_cli_error' => 'state_load',
        'state_load_launch' => 'state_load',
        'state_load_cleanup' => 'state_load',
        'run_code_process_exit' => 'run_code',
        'run_code_timeout' => 'run_code',
        'run_code_cli_error' => 'run_code',
        'run_code_launch' => 'run_code',
        'structured_result_parse' => 'structured_result',
        'structured_result_assertion' => 'structured_result',
        'browser_close' => 'browser_close',
        'pdf_file_permissions' => 'pdf_permissions',
    ];

    /**
     * @param array<string, bool|int|float> $safeDetails
     */
    public function __construct(
        public readonly string $diagnosticClass,
        public readonly string $stage,
        public readonly array $safeDetails = [],
        public readonly bool $assertionFailure = false,
    ) {
        if ((self::DIAGNOSTIC_STAGES[$diagnosticClass] ?? null) !== $stage) {
            throw new InvalidArgumentException('Provider UI smoke diagnostic class is invalid.');
        }

        parent::__construct('Provider UI smoke browser flow failed.');
    }

    /**
     * @param array<string, mixed> $processResult
     */
    public static function runCodeProcessFailure(array $processResult): self
    {
        return self::processFailure('run_code', $processResult);
    }

    /** @param array<string, mixed> $processResult */
    public static function browserOpenProcessFailure(array $processResult): self
    {
        return self::processFailure('browser_open', $processResult);
    }

    /** @param array<string, mixed> $processResult */
    public static function stateLoadProcessFailure(array $processResult): self
    {
        return self::processFailure('state_load', $processResult);
    }

    public static function browserOpenLaunchFailure(): self
    {
        return new self('browser_open_launch', 'browser_open');
    }

    public static function stateLoadLaunchFailure(): self
    {
        return new self('state_load_launch', 'state_load');
    }

    public static function stateLoadCleanupFailure(): self
    {
        return new self('state_load_cleanup', 'state_load');
    }

    public static function runCodeLaunchFailure(): self
    {
        return new self('run_code_launch', 'run_code');
    }

    public static function structuredResultParseFailure(): self
    {
        return new self('structured_result_parse', 'structured_result', [], true);
    }

    /**
     * @param array<string, bool|int> $safeDetails
     */
    public static function structuredResultAssertion(array $safeDetails): self
    {
        return new self('structured_result_assertion', 'structured_result', $safeDetails, true);
    }

    public static function browserCloseFailure(): self
    {
        return new self('browser_close', 'browser_close');
    }

    public static function pdfFilePermissionsFailure(): self
    {
        return new self('pdf_file_permissions', 'pdf_permissions');
    }

    /**
     * @return array{diagnostic_class: string, stage: string}
     */
    public function reportFields(): array
    {
        return [
            'diagnostic_class' => $this->diagnosticClass,
            'stage' => $this->stage,
        ];
    }

    /** @param array<string, mixed> $processResult */
    private static function processFailure(string $stage, array $processResult): self
    {
        $suffix = (bool) ($processResult['timed_out'] ?? false)
            ? 'timeout'
            : (preg_match('/(?:^|\R)### Error(?:\R|\z)/', (string) ($processResult['stdout'] ?? '')) === 1
                ? 'cli_error'
                : 'process_exit');

        return new self($stage . '_' . $suffix, $stage);
    }
}
