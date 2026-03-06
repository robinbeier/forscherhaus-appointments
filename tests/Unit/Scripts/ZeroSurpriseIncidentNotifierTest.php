<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use ReleaseGate\ZeroSurpriseIncidentNotifier;

require_once __DIR__ . '/../../../scripts/release-gate/lib/ZeroSurpriseIncidentNotifier.php';

class ZeroSurpriseIncidentNotifierTest extends TestCase
{
    public function testBuildPayloadIncludesReportUrlAndFailedInvariants(): void
    {
        $notifier = new ZeroSurpriseIncidentNotifier();
        $reportRoot = sys_get_temp_dir() . '/zero-surprise-app-' . bin2hex(random_bytes(4));
        $reportDirectory = $reportRoot . '/storage/logs/release-gate';
        mkdir($reportDirectory, 0777, true);
        $reportPath = $reportDirectory . '/canary-report.json';
        file_put_contents(
            $reportPath,
            json_encode(
                [
                    'invariants' => [
                        'unexpected_5xx' => ['status' => 'pass'],
                        'overbooking' => ['status' => 'fail'],
                        'fill_rate_math' => ['status' => 'pass'],
                        'pdf_exports' => ['status' => 'fail'],
                    ],
                ],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ) . PHP_EOL,
        );

        try {
            $config = [
                'url' => 'https://example.invalid/zero-surprise',
                'authorization_header' => 'Bearer secret',
                'report_url_template' =>
                    'https://ops.example.invalid/release-gate/{relative_path}?r={release_id}&file={basename}',
                'timeout_seconds' => 10,
            ];

            $payload = $notifier->buildPayload(
                [
                    'event' => 'zero_surprise_canary_failed',
                    'severity' => 'critical',
                    'release_id' => 'ea_20260320_1200',
                    'reason' => 'zero-surprise canary failed',
                    'rollback_result' => 'rollback_succeeded',
                    'report_path' => $reportPath,
                    'report_root' => $reportRoot,
                    'log_path' => '/var/log/deploy_ea_ea_20260320_1200.log',
                    'breakglass_used' => true,
                    'ticket' => 'INC-1234',
                ],
                $config,
                new DateTimeImmutable('2026-03-20T12:15:00Z'),
            );

            $this->assertSame(
                'https://ops.example.invalid/release-gate/storage/logs/release-gate/' .
                    basename($reportPath) .
                    '?r=ea_20260320_1200&file=' .
                    basename($reportPath),
                $payload['report_url'],
            );
            $this->assertSame(['overbooking', 'pdf_exports'], $payload['failed_invariants']);
            $this->assertTrue($payload['breakglass_used']);
            $this->assertSame('INC-1234', $payload['ticket']);
            $this->assertSame('2026-03-20T12:15:00+00:00', $payload['timestamp_utc']);
        } finally {
            @unlink($reportPath);
            @rmdir($reportDirectory);
            @rmdir(dirname($reportDirectory));
            @rmdir(dirname(dirname($reportDirectory)));
            @rmdir($reportRoot);
        }
    }

    public function testExtractFailedInvariantsReturnsEmptyArrayForBrokenReport(): void
    {
        $notifier = new ZeroSurpriseIncidentNotifier();
        $path = sys_get_temp_dir() . '/zero-surprise-broken-report-' . bin2hex(random_bytes(4)) . '.json';
        file_put_contents($path, '{broken-json');

        try {
            $this->assertSame([], $notifier->extractFailedInvariants($path));
        } finally {
            @unlink($path);
        }
    }

    public function testLoadConfigReadsWebhookSettings(): void
    {
        $notifier = new ZeroSurpriseIncidentNotifier();
        $configPath = sys_get_temp_dir() . '/zero-surprise-webhook-' . bin2hex(random_bytes(4)) . '.ini';
        file_put_contents(
            $configPath,
            <<<'INI'
            url = https://example.invalid/hook
            authorization_header = Bearer secret
            report_url_template = https://ops.example.invalid/release-gate/{relative_path}
            timeout_seconds = 15
            INI
            ,
        );

        try {
            $config = $notifier->loadConfig($configPath);

            $this->assertSame('https://example.invalid/hook', $config['url']);
            $this->assertSame('Bearer secret', $config['authorization_header']);
            $this->assertSame(
                'https://ops.example.invalid/release-gate/{relative_path}',
                $config['report_url_template'],
            );
            $this->assertSame(15, $config['timeout_seconds']);
        } finally {
            @unlink($configPath);
        }
    }
}
