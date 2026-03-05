<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use ReleaseGate\ZeroSurpriseReportValidator;

require_once __DIR__ . '/../../../scripts/release-gate/lib/ZeroSurpriseReportValidator.php';

class ZeroSurpriseReportValidatorTest extends TestCase
{
    public function testValidateFileReturnsOkForValidReport(): void
    {
        $validator = new ZeroSurpriseReportValidator();
        $path = $this->writeTempReport($this->validReportFixture());

        $result = $validator->validateFile(
            $path,
            'ea_20260312_1200',
            'predeploy',
            240,
            new DateTimeImmutable('2026-03-12T12:00:00Z'),
        );

        $this->assertTrue($result['ok']);
        $this->assertSame([], $result['errors']);
        $this->assertSame(0, $result['normalized']['summary_exit_code']);
        $this->assertSame('predeploy', $result['normalized']['mode']);

        @unlink($path);
    }

    public function testValidateFileFailsWhenReportIsMissing(): void
    {
        $validator = new ZeroSurpriseReportValidator();
        $path = sys_get_temp_dir() . '/zero-surprise-missing-' . bin2hex(random_bytes(4)) . '.json';

        $result = $validator->validateFile($path, 'ea_20260312_1200', 'predeploy', 240);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Report file not found', implode("\n", $result['errors']));
    }

    public function testValidateFileFailsWhenJsonIsInvalid(): void
    {
        $validator = new ZeroSurpriseReportValidator();
        $path = $this->writeTempRaw('{broken-json');

        $result = $validator->validateFile($path, 'ea_20260312_1200', 'predeploy', 240);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Report JSON is invalid', implode("\n", $result['errors']));

        @unlink($path);
    }

    public function testValidateFileFailsWhenSummaryExitCodeIsNonZero(): void
    {
        $validator = new ZeroSurpriseReportValidator();
        $fixture = $this->validReportFixture();
        $fixture['summary']['exit_code'] = 1;
        $path = $this->writeTempReport($fixture);

        $result = $validator->validateFile(
            $path,
            'ea_20260312_1200',
            'predeploy',
            240,
            new DateTimeImmutable('2026-03-12T12:00:00Z'),
        );

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('summary.exit_code must be 0', implode("\n", $result['errors']));

        @unlink($path);
    }

    public function testValidateFileFailsWhenReleaseIdDoesNotMatch(): void
    {
        $validator = new ZeroSurpriseReportValidator();
        $path = $this->writeTempReport($this->validReportFixture());

        $result = $validator->validateFile(
            $path,
            'ea_20260312_9999',
            'predeploy',
            240,
            new DateTimeImmutable('2026-03-12T12:00:00Z'),
        );

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('meta.release_id mismatch', implode("\n", $result['errors']));

        @unlink($path);
    }

    public function testValidateFileFailsWhenModeIsMissingOrMismatched(): void
    {
        $validator = new ZeroSurpriseReportValidator();

        $missingModeFixture = $this->validReportFixture();
        unset($missingModeFixture['meta']['mode']);
        $missingModePath = $this->writeTempReport($missingModeFixture);

        $missingModeResult = $validator->validateFile(
            $missingModePath,
            'ea_20260312_1200',
            'predeploy',
            240,
            new DateTimeImmutable('2026-03-12T12:00:00Z'),
        );

        $this->assertFalse($missingModeResult['ok']);
        $this->assertStringContainsString(
            'meta.mode must be a non-empty string',
            implode("\n", $missingModeResult['errors']),
        );

        @unlink($missingModePath);

        $mismatchModeFixture = $this->validReportFixture();
        $mismatchModeFixture['meta']['mode'] = 'canary';
        $mismatchModePath = $this->writeTempReport($mismatchModeFixture);

        $mismatchModeResult = $validator->validateFile(
            $mismatchModePath,
            'ea_20260312_1200',
            'predeploy',
            240,
            new DateTimeImmutable('2026-03-12T12:00:00Z'),
        );

        $this->assertFalse($mismatchModeResult['ok']);
        $this->assertStringContainsString('meta.mode mismatch', implode("\n", $mismatchModeResult['errors']));

        @unlink($mismatchModePath);
    }

    public function testValidateFileFailsWhenReportIsTooOld(): void
    {
        $validator = new ZeroSurpriseReportValidator();
        $fixture = $this->validReportFixture();
        $fixture['meta']['finished_at_utc'] = '2026-03-12T07:59:00Z';
        $path = $this->writeTempReport($fixture);

        $result = $validator->validateFile(
            $path,
            'ea_20260312_1200',
            'predeploy',
            240,
            new DateTimeImmutable('2026-03-12T12:00:00Z'),
        );

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Report is too old', implode("\n", $result['errors']));

        @unlink($path);
    }

    public function testValidateFileFailsWhenFinishedAtIsInvalid(): void
    {
        $validator = new ZeroSurpriseReportValidator();
        $fixture = $this->validReportFixture();
        $fixture['meta']['finished_at_utc'] = 'not-a-date';
        $path = $this->writeTempReport($fixture);

        $result = $validator->validateFile(
            $path,
            'ea_20260312_1200',
            'predeploy',
            240,
            new DateTimeImmutable('2026-03-12T12:00:00Z'),
        );

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('meta.finished_at_utc is invalid', implode("\n", $result['errors']));

        @unlink($path);
    }

    public function testValidateFileFailsWhenRequiredInvariantIsMissing(): void
    {
        $validator = new ZeroSurpriseReportValidator();
        $fixture = $this->validReportFixture();
        unset($fixture['invariants']['pdf_exports']);
        $path = $this->writeTempReport($fixture);

        $result = $validator->validateFile(
            $path,
            'ea_20260312_1200',
            'predeploy',
            240,
            new DateTimeImmutable('2026-03-12T12:00:00Z'),
        );

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Required invariant missing: pdf_exports', implode("\n", $result['errors']));

        @unlink($path);
    }

    public function testValidateFileFailsWhenInvariantStatusIsFail(): void
    {
        $validator = new ZeroSurpriseReportValidator();
        $fixture = $this->validReportFixture();
        $fixture['invariants']['pdf_exports']['status'] = 'fail';
        $path = $this->writeTempReport($fixture);

        $result = $validator->validateFile(
            $path,
            'ea_20260312_1200',
            'predeploy',
            240,
            new DateTimeImmutable('2026-03-12T12:00:00Z'),
        );

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Invariant "pdf_exports" must be pass', implode("\n", $result['errors']));

        @unlink($path);
    }

    /**
     * @param array<string, mixed> $report
     */
    private function writeTempReport(array $report): string
    {
        $path = sys_get_temp_dir() . '/zero-surprise-validator-' . bin2hex(random_bytes(4)) . '.json';
        file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);

        return $path;
    }

    private function writeTempRaw(string $raw): string
    {
        $path = sys_get_temp_dir() . '/zero-surprise-validator-raw-' . bin2hex(random_bytes(4)) . '.json';
        file_put_contents($path, $raw);

        return $path;
    }

    /**
     * @return array<string, mixed>
     */
    private function validReportFixture(): array
    {
        return [
            'meta' => [
                'release_id' => 'ea_20260312_1200',
                'mode' => 'predeploy',
                'started_at_utc' => '2026-03-12T10:20:00Z',
                'finished_at_utc' => '2026-03-12T11:30:00Z',
            ],
            'invariants' => [
                'unexpected_5xx' => ['status' => 'pass'],
                'overbooking' => ['status' => 'pass'],
                'fill_rate_math' => ['status' => 'pass'],
                'pdf_exports' => ['status' => 'pass'],
            ],
            'summary' => [
                'exit_code' => 0,
            ],
        ];
    }
}
