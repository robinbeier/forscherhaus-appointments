<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use Ops\HostRunnerTrafficHelper;
use Ops\HostRunnerTrafficMetadata;
use Ops\ProtectedHostTrafficCollector;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__ . '/../../../scripts/ops/lib/DeploymentHostRunnerTrafficV1.php';

final class DeploymentHostRunnerTrafficV1Test extends TestCase
{
    private const RUN_ID = '018f6f52-4c87-4d4e-8b19-6a66e6e1af25';
    private const SHA = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    public function testFreshPinnedReportBindsIndependentMetadataAndHelperWindow(): void
    {
        $bytes = $this->report(100, 190);
        $helper = new TrafficHelperFake('pinned', $bytes, 99, 191);
        $metadata = new TrafficMetadataFake([
            ['producer_sha256' => self::SHA, 'catalog_version' => '2026-08-09.1'],
            ['producer_sha256' => self::SHA, 'catalog_version' => '2026-08-09.1'],
        ]);

        $result = (new ProtectedHostTrafficCollector($helper, $metadata))->collect(self::RUN_ID, 'normal');

        self::assertSame($bytes, $result->pinnedReportBytes);
        self::assertSame(hash('sha256', $bytes), $result->pinnedReportSha256);
        self::assertSame(self::SHA, $result->expectedProducerSha256);
        self::assertSame('2026-08-09.1', $result->expectedCatalogVersion);
        self::assertSame(100, $result->windowStartEpoch);
        self::assertSame(190, $result->windowEndEpoch);
        self::assertSame([[self::RUN_ID, 'normal']], $helper->calls);
    }

    public function testExactAttachedReportMayReplayItsOriginalWindow(): void
    {
        $bytes = $this->report(100, 190);
        $helper = new TrafficHelperFake('attached', $bytes, 1_000, 1_001);
        $result = (new ProtectedHostTrafficCollector($helper, $this->stableMetadata()))
            ->collect(self::RUN_ID, 'normal');

        self::assertSame(100, $result->windowStartEpoch);
        self::assertSame(190, $result->windowEndEpoch);
    }

    public function testFreshReportOutsideHelperBoundaryIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        (new ProtectedHostTrafficCollector(
            new TrafficHelperFake('pinned', $this->report(100, 190), 101, 191),
            $this->stableMetadata(),
        ))->collect(self::RUN_ID, 'normal');
    }

    public function testMetadataDriftRejectsOtherwiseValidReport(): void
    {
        $metadata = new TrafficMetadataFake([
            ['producer_sha256' => self::SHA, 'catalog_version' => '2026-08-09.1'],
            ['producer_sha256' => str_repeat('c', 64), 'catalog_version' => '2026-08-09.1'],
        ]);
        $this->expectException(RuntimeException::class);
        (new ProtectedHostTrafficCollector(
            new TrafficHelperFake('pinned', $this->report(100, 190), 99, 191),
            $metadata,
        ))->collect(self::RUN_ID, 'normal');
    }

    public function testMissingReportRetainsNoInventedBytesOrHash(): void
    {
        $result = (new ProtectedHostTrafficCollector(
            new TrafficHelperFake('not_observed', null, 100, 190),
            $this->stableMetadata(),
        ))->collect(self::RUN_ID, 'normal');

        self::assertNull($result->pinnedReportBytes);
        self::assertNull($result->pinnedReportSha256);
        self::assertSame(100, $result->windowStartEpoch);
        self::assertSame(190, $result->windowEndEpoch);
    }

    private function stableMetadata(): TrafficMetadataFake
    {
        return new TrafficMetadataFake([
            ['producer_sha256' => self::SHA, 'catalog_version' => '2026-08-09.1'],
            ['producer_sha256' => self::SHA, 'catalog_version' => '2026-08-09.1'],
        ]);
    }

    private function report(int $start, int $end): string
    {
        $counts = array_fill_keys([
            'documented_health', 'documented_periodic_ops', 'public_read', 'denied_external',
            'business_or_authenticated', 'unclassified', 'status_5xx', 'write', 'authenticated',
            'customers_or_sensitive', 'scanner_success', 'source_unknown', 'method_unknown',
            'target_unknown', 'pre_window_completion', 'lines_seen', 'lines_in_window',
            'parse_errors', 'rotation_errors', 'total',
        ], 0);
        $counts['documented_health'] = 1;
        $counts['lines_seen'] = 1;
        $counts['lines_in_window'] = 1;
        $counts['total'] = 1;
        return json_encode([
            'schema' => 'traffic_gate.v1',
            'producer_sha256' => self::SHA,
            'policy_version' => 'traffic_gate_policy.v1',
            'catalog_version' => '2026-08-09.1',
            'purpose' => 'deploy',
            'mode' => 'normal',
            'window_start_epoch' => $start,
            'window_end_epoch' => $end,
            'window_seconds' => $end - $start,
            'log_set_sha256' => self::SHA,
            'rotation_complete' => true,
            'parse_complete' => true,
            'evidence_complete' => true,
            'decision' => 'allow',
            'exit_code' => 0,
            'counts' => $counts,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    }
}

final class TrafficHelperFake implements HostRunnerTrafficHelper
{
    /** @var list<array{string,string}> */
    public array $calls = [];

    public function __construct(
        private readonly string $status,
        private readonly ?string $bytes,
        private readonly int $startedEpoch,
        private readonly int $finishedEpoch,
    ) {}

    public function collect(string $runId, string $mode): array
    {
        $this->calls[] = [$runId, $mode];
        return [
            'status' => $this->status,
            'bytes' => $this->bytes,
            'sha256' => $this->bytes === null ? null : hash('sha256', $this->bytes),
            'started_epoch' => $this->startedEpoch,
            'finished_epoch' => $this->finishedEpoch,
        ];
    }
}

final class TrafficMetadataFake implements HostRunnerTrafficMetadata
{
    /** @param list<array{producer_sha256:string,catalog_version:string}> $values */
    public function __construct(private array $values) {}

    public function current(): array
    {
        return array_shift($this->values) ?? throw new RuntimeException('unexpected metadata read');
    }
}
