<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use ReleaseGate\ZeroSurpriseBreakglassValidator;

require_once __DIR__ . '/../../../scripts/release-gate/lib/ZeroSurpriseBreakglassValidator.php';

class ZeroSurpriseBreakglassValidatorTest extends TestCase
{
    public function testValidateFileReturnsOkForScopedValidAck(): void
    {
        $validator = new ZeroSurpriseBreakglassValidator();
        $path = $this->writeTempJson([
            'release_id' => 'ea_20260320_1200',
            'ticket' => 'INC-1234',
            'reason' => 'temporary canary bypass',
            'approved_by' => 'Ops',
            'expires_at_utc' => '2026-03-20T12:30:00Z',
            'allow_disable_predeploy' => false,
            'allow_disable_canary' => true,
        ]);

        try {
            $result = $validator->validateFile(
                $path,
                'ea_20260320_1200',
                false,
                true,
                new DateTimeImmutable('2026-03-20T12:00:00Z'),
            );

            $this->assertTrue($result['ok']);
            $this->assertSame('INC-1234', $result['normalized']['ticket']);
            $this->assertTrue($result['normalized']['allow_disable_canary']);
            $this->assertFalse($result['normalized']['allow_disable_predeploy']);
        } finally {
            @unlink($path);
        }
    }

    public function testValidateFileFailsForReleaseMismatch(): void
    {
        $validator = new ZeroSurpriseBreakglassValidator();
        $path = $this->writeTempJson($this->validAckFixture());

        try {
            $result = $validator->validateFile(
                $path,
                'ea_20260320_1300',
                true,
                false,
                new DateTimeImmutable('2026-03-20T12:00:00Z'),
            );

            $this->assertFalse($result['ok']);
            $this->assertStringContainsString('release_id mismatch', implode("\n", $result['errors']));
        } finally {
            @unlink($path);
        }
    }

    public function testValidateFileFailsWhenAckIsExpired(): void
    {
        $validator = new ZeroSurpriseBreakglassValidator();
        $fixture = $this->validAckFixture();
        $fixture['expires_at_utc'] = '2026-03-20T11:59:59Z';
        $path = $this->writeTempJson($fixture);

        try {
            $result = $validator->validateFile(
                $path,
                'ea_20260320_1200',
                true,
                false,
                new DateTimeImmutable('2026-03-20T12:00:00Z'),
            );

            $this->assertFalse($result['ok']);
            $this->assertStringContainsString('expires_at_utc must be in the future', implode("\n", $result['errors']));
        } finally {
            @unlink($path);
        }
    }

    public function testValidateFileFailsWhenRequestedScopeIsNotAllowed(): void
    {
        $validator = new ZeroSurpriseBreakglassValidator();
        $fixture = $this->validAckFixture();
        $fixture['allow_disable_predeploy'] = false;
        $path = $this->writeTempJson($fixture);

        try {
            $result = $validator->validateFile(
                $path,
                'ea_20260320_1200',
                true,
                false,
                new DateTimeImmutable('2026-03-20T12:00:00Z'),
            );

            $this->assertFalse($result['ok']);
            $this->assertStringContainsString(
                'Breakglass does not allow disabling the predeploy gate',
                implode("\n", $result['errors']),
            );
        } finally {
            @unlink($path);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function validAckFixture(): array
    {
        return [
            'release_id' => 'ea_20260320_1200',
            'ticket' => 'INC-1234',
            'reason' => 'temporary deploy bypass',
            'approved_by' => 'Ops',
            'expires_at_utc' => '2026-03-20T12:30:00Z',
            'allow_disable_predeploy' => true,
            'allow_disable_canary' => false,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function writeTempJson(array $payload): string
    {
        $path = sys_get_temp_dir() . '/zero-surprise-breakglass-' . bin2hex(random_bytes(4)) . '.json';
        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);

        return $path;
    }
}
