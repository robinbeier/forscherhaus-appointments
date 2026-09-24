<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('root-deployment')]
final class ReleasePairAdmissionRootTest extends TestCase
{
    private string $helper;
    private string $release = 'ea_admission_test';
    private string $archive;
    private string $provenance;

    protected function setUp(): void
    {
        if (PHP_OS_FAMILY !== 'Linux' || posix_geteuid() !== 0) {
            $this->markTestSkipped('Linux root is required for the production release-root contract.');
        }
        if (file_exists('/root/releases') || is_link('/root/releases')) {
            $this->markTestSkipped('/root/releases already exists; the root test will not mutate it.');
        }
        $this->helper = dirname(__DIR__, 3) . '/scripts/ops/libexec/release_pair_admission_v1.py';
        mkdir('/root/releases', 0700, true);
        $this->archive = '/root/releases/' . $this->release . '.tar.gz';
        $this->provenance = '/root/releases/' . $this->release . '.build-provenance.json';
        file_put_contents($this->archive, 'archive');
        file_put_contents($this->provenance, '{"schema":"test"}');
        chmod($this->archive, 0600);
        chmod($this->provenance, 0600);
    }

    protected function tearDown(): void
    {
        if (is_dir('/root/releases') && !is_link('/root/releases')) {
            foreach (scandir('/root/releases') ?: [] as $leaf) {
                if ($leaf !== '.' && $leaf !== '..') {
                    unlink('/root/releases/' . $leaf);
                }
            }
            rmdir('/root/releases');
        }
    }

    public function testExactPairIsVerifiedWithPayloadHashesAndSizes(): void
    {
        $result = $this->runAdmission();
        self::assertSame(0, $result['exit']);
        $payload = json_decode($result['stdout'], true, 32, JSON_THROW_ON_ERROR);
        self::assertSame('pair_verified', $payload['result_class']);
        self::assertSame(hash_file('sha256', $this->archive), $payload['archive']['sha256']);
        self::assertSame(filesize($this->archive), $payload['archive']['size_bytes']);
        self::assertSame(hash_file('sha256', $this->provenance), $payload['provenance']['sha256']);
    }

    public function testMissingPairFailsClosed(): void
    {
        $provenanceHash = hash_file('sha256', $this->provenance);
        $provenanceSize = (int) filesize($this->provenance);
        unlink($this->provenance);
        $result = $this->runAdmission(null, null, $provenanceHash, $provenanceSize);
        self::assertSame(70, $result['exit']);
        self::assertSame('pair_missing', $this->payload($result)['result_class']);
    }

    public function testWrongHashOrSizeFailsClosed(): void
    {
        $result = $this->runAdmission(str_repeat('0', 64), (int) filesize($this->archive));
        self::assertSame(70, $result['exit']);
        self::assertSame('pair_mismatch', $this->payload($result)['result_class']);
        $result = $this->runAdmission(hash_file('sha256', $this->archive), (int) filesize($this->archive) + 1);
        self::assertSame(70, $result['exit']);
        self::assertSame('pair_mismatch', $this->payload($result)['result_class']);
    }

    public function testSymlinkedLeafIsRejectedAsOccupied(): void
    {
        $real = '/root/releases/real-provenance';
        rename($this->provenance, $real);
        symlink($real, $this->provenance);
        $result = $this->runAdmission();
        self::assertSame(70, $result['exit']);
        self::assertSame('pair_occupied', $this->payload($result)['result_class']);
    }

    /** @return array{exit:int,stdout:string,stderr:string} */
    private function runAdmission(
        ?string $archiveHash = null,
        ?int $archiveSize = null,
        ?string $provenanceHash = null,
        ?int $provenanceSize = null,
    ): array {
        $arguments = [
            '/usr/bin/python3',
            '-I',
            '-B',
            $this->helper,
            $this->release,
            $archiveHash ?? hash_file('sha256', $this->archive),
            (string) ($archiveSize ?? filesize($this->archive)),
            $provenanceHash ?? hash_file('sha256', $this->provenance),
            (string) ($provenanceSize ?? filesize($this->provenance)),
        ];
        $pipes = [];
        $process = proc_open(
            implode(' ', array_map('escapeshellarg', $arguments)),
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['exit' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
    }

    /** @param array{stdout:string} $result */
    private function payload(array $result): array
    {
        return json_decode($result['stdout'], true, 32, JSON_THROW_ON_ERROR);
    }
}
