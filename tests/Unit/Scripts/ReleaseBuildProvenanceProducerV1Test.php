<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use Ops\DeploymentEvidenceAuthorityV1;
use Ops\ReleaseBuildProvenanceProducerV1;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__ . '/../../../scripts/ops/lib/ReleaseBuildProvenanceProducerV1.php';

final class ReleaseBuildProvenanceProducerV1Test extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/release-provenance-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->root . '/stage/sub', 0700, true));
        $canonical = realpath($this->root);
        self::assertIsString($canonical);
        $this->root = $canonical;
        self::assertSame(3, file_put_contents($this->root . '/stage/a', 'abc'));
        self::assertSame(5, file_put_contents($this->root . '/stage/sub/b', '12345'));
        foreach (['build_release.sh', 'composer.lock', 'package-lock.json', 'deploy_ea.sh'] as $file) {
            self::assertSame(4, file_put_contents($this->root . '/' . $file, 'data'));
        }
        $command = sprintf(
            'COPYFILE_DISABLE=1 tar -czf %s -C %s .',
            escapeshellarg($this->root . '/archive.tar.gz'),
            escapeshellarg($this->root . '/stage'),
        );
        exec($command, $output, $exit);
        self::assertSame(0, $exit);
    }

    protected function tearDown(): void
    {
        $entries = iterator_to_array(
            new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            ),
        );
        foreach ($entries as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->root);
    }

    public function testProducerMeasuresOneExactStageAndFinalArchive(): void
    {
        $record = $this->create();

        self::assertSame(2, $record['capacity_bounds']['stage_file_count']);
        self::assertSame(4, $record['capacity_bounds']['stage_inode_count']);
        self::assertSame(16_384, $record['capacity_bounds']['stage_unpacked_bytes']);
        self::assertSame(hash_file('sha256', $this->root . '/archive.tar.gz'), $record['archive']['sha256']);
        self::assertSame(67_108_864, $record['capacity_bounds']['temp_scratch_bytes']);
        self::assertStringEndsWith("\n", DeploymentEvidenceAuthorityV1::encodeFile($record));
    }

    public function testProducerBoundsEnterCapacityExactlyOnce(): void
    {
        $record = $this->create();
        $provenanceBytes = DeploymentEvidenceAuthorityV1::encodeFile($record);
        $dumpSha = str_repeat('b', 64);
        $attestation = DeploymentEvidenceAuthorityV1::createDumpAttestation(
            [
                'sha256' => $dumpSha,
                'size_bytes' => 1_000,
                'uncompressed_size_bytes' => 4_000,
                'created_at_utc' => '2026-08-12T12:00:00Z',
            ],
            [
                'method' => 'mariadb_10_11_isolated_restore_v1',
                'dump_sha256' => $dumpSha,
                'dump_size_bytes' => 1_000,
                'uncompressed_size_bytes' => 4_000,
                'gzip_exit_code' => 0,
                'restore_exit_code' => 0,
                'restored_datadir_allocated_bytes' => 8_000,
                'restored_datadir_inode_count' => 8,
                'restored_at_utc' => '2026-08-12T12:01:00Z',
            ],
            '2026-08-12T12:01:01Z',
        );
        $attestationBytes = DeploymentEvidenceAuthorityV1::encodeFile($attestation);
        $devices = array_fill_keys(
            ['artifact', 'dump_pin', 'release_root', 'restore_scratch', 'stage', 'state_root', 'temp'],
            1,
        );
        $capacity = DeploymentEvidenceAuthorityV1::capacityFromVerifiedAuthorities(
            1,
            4096,
            1_000_000,
            900_000,
            10_000_000,
            9_000_000,
            $provenanceBytes,
            hash('sha256', $provenanceBytes),
            'ea_20260812_1200',
            str_repeat('a', 40),
            $record['capacity_bounds']['stage_file_count'],
            $record['capacity_bounds']['stage_inode_count'],
            $record['capacity_bounds']['stage_unpacked_bytes'],
            $record['capacity_bounds']['temp_scratch_bytes'],
            $attestationBytes,
            hash('sha256', $attestationBytes),
            '018f6f52-4c87-4d4e-8b19-6a66e6e1af25',
            str_repeat('c', 64),
            $dumpSha,
            1_000,
            '2026-08-12T12:01:01Z',
            $devices,
        );
        $expectedBase = $record['archive']['size_bytes'] + 1_000 + 16_384 + 67_108_864 + 4_000 + 8_000;
        self::assertSame($expectedBase, $capacity['base_required_bytes']);
        self::assertSame(76, $capacity['projected_required_inodes']);
    }

    public function testProducerCanonicalizesTrustedTemporaryStagePath(): void
    {
        $alias = $this->root . '-alias';
        self::assertTrue(symlink($this->root, $alias));
        try {
            $record = ReleaseBuildProvenanceProducerV1::create(
                'ea_20260812_1200',
                str_repeat('a', 40),
                $alias . '/stage/.',
                $this->root . '/archive.tar.gz',
                $this->root . '/build_release.sh',
                $this->root . '/composer.lock',
                $this->root . '/package-lock.json',
                $this->root . '/deploy_ea.sh',
            );
        } finally {
            unlink($alias);
        }

        self::assertSame(4, $record['capacity_bounds']['stage_inode_count']);
    }

    public function testProducerUsesThePublicReleaseIdContract(): void
    {
        foreach (['release_2026', 'r' . str_repeat('a', 127)] as $releaseId) {
            $record = ReleaseBuildProvenanceProducerV1::create(
                $releaseId,
                str_repeat('a', 40),
                $this->root . '/stage',
                $this->root . '/archive.tar.gz',
                $this->root . '/build_release.sh',
                $this->root . '/composer.lock',
                $this->root . '/package-lock.json',
                $this->root . '/deploy_ea.sh',
            );
            self::assertSame($releaseId, $record['release_id']);
            self::assertSame($releaseId . '.tar.gz', $record['archive']['name']);
        }

        foreach (['_leading', 'r' . str_repeat('a', 128)] as $releaseId) {
            try {
                ReleaseBuildProvenanceProducerV1::create(
                    $releaseId,
                    str_repeat('a', 40),
                    $this->root . '/stage',
                    $this->root . '/archive.tar.gz',
                    $this->root . '/build_release.sh',
                    $this->root . '/composer.lock',
                    $this->root . '/package-lock.json',
                    $this->root . '/deploy_ea.sh',
                );
                self::fail('Invalid public release ID was accepted.');
            } catch (RuntimeException $exception) {
                self::assertSame('release_id is invalid', $exception->getMessage());
            }
        }
    }

    public function testProducerRejectsSymlinkOrHardlinkedStageEntries(): void
    {
        self::assertTrue(symlink($this->root . '/stage/a', $this->root . '/stage/link'));
        try {
            $this->create();
            self::fail('Symlinked stage entry was accepted.');
        } catch (RuntimeException) {
            self::addToAssertionCount(1);
        }
        unlink($this->root . '/stage/link');
        self::assertTrue(link($this->root . '/stage/a', $this->root . '/stage/hard'));
        $this->expectException(RuntimeException::class);
        $this->create();
    }

    /** @return array<string,mixed> */
    private function create(): array
    {
        return ReleaseBuildProvenanceProducerV1::create(
            'ea_20260812_1200',
            str_repeat('a', 40),
            $this->root . '/stage',
            $this->root . '/archive.tar.gz',
            $this->root . '/build_release.sh',
            $this->root . '/composer.lock',
            $this->root . '/package-lock.json',
            $this->root . '/deploy_ea.sh',
        );
    }
}
