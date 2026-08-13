<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use Ops\DeploymentEvidenceAuthorityV1;
use Ops\DumpObservationV1;
use Ops\HostRunnerDumpHelper;
use Ops\HostRunnerStorage;
use Ops\ProtectedHostDumpCollector;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__ . '/../../../scripts/ops/lib/DeploymentHostRunnerDumpV1.php';

final class DeploymentHostRunnerDumpV1Test extends TestCase
{
    private const RUN_ID = '018f6f52-4c87-4d4e-8b19-6a66e6e1af25';
    private const SHA = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    public function testCollectorPinsExactDumpBeforeReadingDigestDerivedAttestation(): void
    {
        $attestation = DeploymentEvidenceAuthorityV1::encodeFile([
            'schema' => DeploymentEvidenceAuthorityV1::DUMP_ATTESTATION_SCHEMA,
            'dump' => [
                'sha256' => self::SHA,
                'size_bytes' => 1_000_000,
                'uncompressed_size_bytes' => 4_000_000,
                'created_at_utc' => '2026-08-12T11:30:00Z',
            ],
            'verification' => [
                'method' => 'mariadb_10_11_isolated_restore_v1',
                'image' => DeploymentEvidenceAuthorityV1::DUMP_RESTORE_IMAGE,
                'sha256_verified' => true,
                'gzip_verified' => true,
                'restore_verified' => true,
                'restored_datadir_allocated_bytes' => 8_000_000,
                'restored_datadir_inode_count' => 256,
                'restored_at_utc' => '2026-08-12T11:50:00Z',
            ],
            'attested_at_utc' => '2026-08-12T11:55:00Z',
        ]);
        $storage = new DumpStorageFake();
        $helper = new DumpHelperFake(
            [
                'status' => 'observed',
                'attestation_bytes' => $attestation,
                'attestation_sha256' => hash('sha256', $attestation),
                'dump_sha256' => self::SHA,
                'dump_size_bytes' => 1_000_000,
                'observed_at_utc' => '2026-08-12T12:00:00Z',
            ],
            $storage,
        );

        $result = (new ProtectedHostDumpCollector($storage, $helper))->collect(self::RUN_ID, [
            'path' => '/root/backups/predeploy.sql.gz',
            'sha256' => self::SHA,
        ]);

        self::assertSame(
            [[self::RUN_ID, 'zero-surprise-dump-sql-gz', '/root/backups/predeploy.sql.gz', self::SHA]],
            $storage->referencePins,
        );
        self::assertSame([[self::RUN_ID, 'deploy-ref-zero-surprise-dump.sql.gz', self::SHA]], $helper->calls);
        self::assertSame($attestation, $result->attestationBytes);
        self::assertSame(hash('sha256', $attestation), $result->pinnedAttestationSha256);
        self::assertSame(1_000_000, $result->stableDumpSizeBytes);
    }

    public function testMissingAttestationRetainsOnlyIndependentlyVerifiedDumpDigest(): void
    {
        $storage = new DumpStorageFake();
        $helper = new DumpHelperFake(
            [
                'status' => 'not_observed',
                'attestation_bytes' => null,
                'attestation_sha256' => null,
                'dump_sha256' => self::SHA,
                'dump_size_bytes' => 1_000_000,
                'observed_at_utc' => '2026-08-12T12:00:00Z',
            ],
            $storage,
        );

        $result = (new ProtectedHostDumpCollector($storage, $helper))->collect(self::RUN_ID, [
            'path' => '/root/backups/predeploy.sql',
            'sha256' => self::SHA,
        ]);

        self::assertNull($result->attestationBytes);
        self::assertSame(self::SHA, $result->dumpSha256);
        self::assertTrue($result->sha256Verified);
        self::assertNull($result->gzipVerified);
        self::assertNull($result->restoreVerified);
    }

    public function testPinFailureBecomesInvalidObservationWithoutCallingHelper(): void
    {
        $storage = new DumpStorageFake(new RuntimeException('unavailable'));
        $helper = new DumpHelperFake(null, $storage);

        $result = (new ProtectedHostDumpCollector($storage, $helper))->collect(self::RUN_ID, [
            'path' => '/root/backups/predeploy.sql.gz',
            'sha256' => self::SHA,
        ]);

        self::assertSame(self::SHA, $result->dumpSha256);
        self::assertNull($result->sha256Verified);
        self::assertSame([], $helper->calls);
    }

    public function testCallerCannotSelectAnotherDumpLeafOrMalformedDigest(): void
    {
        $this->expectException(RuntimeException::class);
        (new ProtectedHostDumpCollector(
            new DumpStorageFake(),
            new DumpHelperFake(null, new DumpStorageFake()),
        ))->collect(self::RUN_ID, ['path' => '/tmp/caller.txt', 'sha256' => 'bad']);
    }
}

final class DumpHelperFake implements HostRunnerDumpHelper
{
    /** @var list<array{string,string,string}> */
    public array $calls = [];

    /** @param ?array{status:string,attestation_bytes:?string,attestation_sha256:?string,dump_sha256:string,dump_size_bytes:int,observed_at_utc:string} $result */
    public function __construct(private readonly ?array $result, private readonly DumpStorageFake $storage) {}

    public function observe(string $runId, string $leaf, string $expectedSha256): array
    {
        if ($this->storage->referencePins === []) {
            throw new RuntimeException('helper ran before the exact dump pin');
        }
        $this->calls[] = [$runId, $leaf, $expectedSha256];
        return $this->result ?? throw new RuntimeException('unexpected helper call');
    }
}

final class DumpStorageFake implements HostRunnerStorage
{
    /** @var list<array{string,string,string,string}> */
    public array $referencePins = [];

    public function __construct(private readonly ?RuntimeException $pinFailure = null) {}
    public function prepareRun(string $runId): void {}
    public function pinReference(string $runId, string $field, string $sourcePath, string $sha256): void
    {
        if ($this->pinFailure !== null) {
            throw $this->pinFailure;
        }
        $this->referencePins[] = [$runId, $field, $sourcePath, $sha256];
    }
    public function read(string $relative, int $maxBytes): ?string
    {
        return null;
    }
    public function pin(string $relative, string $bytes, int $maxBytes): string
    {
        throw new RuntimeException('unused');
    }
    public function cow(string $relative, string $bytes, int $maxBytes): void
    {
        throw new RuntimeException('unused');
    }
    public function refreshBinding(string $relative, string $currentBytes, string $candidateBytes): void
    {
        throw new RuntimeException('unused');
    }
    public function refreshActiveClaim(string $currentBytes, string $candidateBytes): void
    {
        throw new RuntimeException('unused');
    }
    public function clearActiveClaim(string $expectedBytes): void
    {
        throw new RuntimeException('unused');
    }
    public function reservedCandidates(): iterable
    {
        return [];
    }
}
