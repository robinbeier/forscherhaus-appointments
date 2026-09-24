<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use Ops\DeploymentEvidenceAuthorityV1;
use Ops\ReleaseBuildProvenanceProducerV1;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use FilesystemIterator;

require_once __DIR__ . '/../../../scripts/ops/lib/ReleaseBuildProvenanceProducerV1.php';

final class VerifyLocalReleasePairTest extends TestCase
{
    private string $root;
    private string $project;
    private string $archive;
    private string $provenance;

    protected function setUp(): void
    {
        $this->project = dirname(__DIR__, 3);
        $this->root = sys_get_temp_dir() . '/verify-release-pair-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->root, 0700));
        $canonical = realpath($this->root);
        self::assertIsString($canonical);
        $this->root = $canonical;
        self::assertTrue(mkdir($this->root . '/stage', 0700));
        self::assertSame(7, file_put_contents($this->root . '/stage/example', 'example'));
        $this->archive = $this->root . '/ea_test.tar.gz';
        $this->provenance = $this->root . '/ea_test.build-provenance.json';
        exec(
            'COPYFILE_DISABLE=1 tar -czf ' .
                escapeshellarg($this->archive) .
                ' -C ' .
                escapeshellarg($this->root . '/stage') .
                ' .',
            $output,
            $exit,
        );
        self::assertSame(0, $exit);
        $record = ReleaseBuildProvenanceProducerV1::create(
            'ea_test',
            str_repeat('a', 40),
            $this->root . '/stage',
            $this->archive,
            $this->project . '/build_release.sh',
            $this->project . '/composer.lock',
            $this->project . '/package-lock.json',
            $this->project . '/deploy_ea.sh',
        );
        file_put_contents($this->provenance, DeploymentEvidenceAuthorityV1::encodeFile($record));
    }

    protected function tearDown(): void
    {
        $entries = iterator_to_array(
            new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            ),
        );
        foreach ($entries as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->root);
    }

    public function testExactExistingPairVerifiesWithoutRebuild(): void
    {
        $result = $this->verify();
        self::assertSame(0, $result['exit']);
        self::assertSame("verified\n", $result['stdout']);
    }

    public function testArchiveChangedOnlyInMetadataIsRejected(): void
    {
        $original = hash_file('sha256', $this->archive);
        self::assertTrue(touch($this->root . '/stage/example', 1_600_000_000));
        exec(
            'COPYFILE_DISABLE=1 tar -czf ' .
                escapeshellarg($this->archive) .
                ' -C ' .
                escapeshellarg($this->root . '/stage') .
                ' .',
            $output,
            $exit,
        );
        self::assertSame(0, $exit);
        self::assertNotSame($original, hash_file('sha256', $this->archive));
        self::assertSame(70, $this->verify()['exit']);
    }

    public function testMismatchedCommitOrSourceHashIsRejected(): void
    {
        self::assertSame(70, $this->verify(str_repeat('b', 40))['exit']);
        $record = json_decode((string) file_get_contents($this->provenance), true, 32, JSON_THROW_ON_ERROR);
        $record['source']['build_script_sha256'] = str_repeat('0', 64);
        file_put_contents($this->provenance, DeploymentEvidenceAuthorityV1::encodeFile($record));
        self::assertSame(70, $this->verify()['exit']);
    }

    public function testSymlinkedProvenanceAndChangedArchiveContentAreRejected(): void
    {
        $real = $this->root . '/real-provenance';
        self::assertTrue(rename($this->provenance, $real));
        self::assertTrue(symlink($real, $this->provenance));
        self::assertSame(70, $this->verify()['exit']);
        unlink($this->provenance);
        self::assertTrue(rename($real, $this->provenance));
        file_put_contents($this->archive, 'changed', FILE_APPEND);
        self::assertSame(70, $this->verify()['exit']);
    }

    /** @return array{exit:int,stdout:string} */
    private function verify(?string $commit = null): array
    {
        $command = [
            PHP_BINARY,
            $this->project . '/scripts/ops/verify_local_release_pair.php',
            '--release=ea_test',
            '--commit=' . ($commit ?? str_repeat('a', 40)),
            '--archive=' . $this->archive,
            '--provenance=' . $this->provenance,
        ];
        $process = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertIsString($stdout);
        return ['exit' => proc_close($process), 'stdout' => $stdout];
    }
}
