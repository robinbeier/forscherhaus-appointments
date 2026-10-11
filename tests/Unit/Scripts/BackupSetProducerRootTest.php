<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use Ops\DeploymentEvidenceAuthorityV1;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\RootHostTestPrerequisites;

require_once __DIR__ . '/../../../scripts/ops/lib/DeploymentEvidenceAuthorityV1.php';

#[Group('root-deployment')]
final class BackupSetProducerRootTest extends TestCase
{
    private const PASSWORD = 'Rob466_Backup_Only_0123456789abcdef';
    private string $root;
    private string $helper;
    private string $runner;

    protected function setUp(): void
    {
        if (PHP_OS_FAMILY !== 'Linux' || posix_geteuid() !== 0) {
            $this->markTestSkipped('Linux root is required for the backup-set producer filesystem contract.');
        }
        // The production helper rejects world-writable ancestors. Keep the
        // Linux-root fixture beneath the same trusted /var/lib boundary.
        $this->root = '/var/lib/fh-backup-set-producer-root-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
        mkdir($this->root . '/backups', 0700);
        mkdir($this->root . '/orchestrator', 0700);
        mkdir($this->root . '/orchestrator/locks', 0700);
        touch($this->root . '/orchestrator/locks/fh-production-change.lock');
        chmod($this->root . '/orchestrator/locks/fh-production-change.lock', 0600);
        file_put_contents(
            $this->root . '/credentials.cnf',
            "[client]\nuser=fh_backup\npassword=" . self::PASSWORD . "\nprotocol=tcp\nhost=127.0.0.1\nport=3306\n",
        );
        chmod($this->root . '/credentials.cnf', 0600);

        $this->helper = dirname(__DIR__, 3) . '/scripts/ops/libexec/backup_set_producer_v1.py';
        $this->runner = $this->root . '/run.py';
        $dump = $this->root . '/mariadb-dump';
        file_put_contents(
            $dump,
            str_replace(
                '__ROOT__',
                $this->root,
                <<<'SH'
                #!/bin/sh
                case "$*" in *Rob466_Backup_Only_*) exit 88 ;; esac
                env | grep -q 'Rob466_Backup_Only_' && exit 89
                if [ -f '__ROOT__/hold-dump' ]; then
                    exec /usr/bin/python3 -c 'import os,signal,sys,time; root=sys.argv[1]; stopped=os.path.exists(root+"/hold-dump-no-pid"); stopped and open(root+"/before-stop","w").close(); stopped and os.kill(os.getpid(),signal.SIGSTOP); temporary=root+"/dump-child.pid.tmp"; stream=open(temporary,"w",encoding="ascii"); stream.write(str(os.getpid())); stream.flush(); os.fsync(stream.fileno()); stream.close(); os.replace(temporary,root+"/dump-child.pid"); time.sleep(3600)' '__ROOT__'
                fi
                printf '%s' '/*M!999999\- enable the sandbox mode */
                /*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
                DROP TABLE IF EXISTS `ea_test`;
                /*!40101 SET CHARACTER_SET_CLIENT=utf8mb4 */;
                CREATE TABLE `ea_test` (`id` int) ENGINE=InnoDB;
                LOCK TABLES `ea_test` WRITE;
                ALTER TABLE `ea_test` DISABLE KEYS;
                INSERT INTO `ea_test` VALUES (1);
                ALTER TABLE `ea_test` ENABLE KEYS;
                UNLOCK TABLES;
                /*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
                '
                SH
                ,
            ),
        );
        chmod($dump, 0555);
        file_put_contents(
            $this->runner,
            <<<'PY'
            import importlib.util
            import sys
            spec = importlib.util.spec_from_file_location('rob466', sys.argv[1])
            module = importlib.util.module_from_spec(spec)
            spec.loader.exec_module(module)
            module.BACKUP_ROOT = sys.argv[2] + '/backups'
            module.ORCHESTRATOR_ROOT = sys.argv[2] + '/orchestrator'
            module.CREDENTIALS = sys.argv[2] + '/credentials.cnf'
            module.CONFIG_PATH = module.CREDENTIALS
            module.MARIADB_DUMP = sys.argv[2] + '/mariadb-dump'
            module.DUMP_PATH = module.MARIADB_DUMP
            module.TERMINAL_VALIDATOR = sys.argv[2] + '/unused-validator'
            if len(sys.argv) == 4:
                import datetime
                fixed = datetime.datetime.strptime(sys.argv[3], '%Y-%m-%dT%H:%M:%SZ').replace(
                    tzinfo=datetime.timezone.utc)
                module.utc_now = lambda: fixed
            # The containerized PHPUnit process can be reparented by its runtime shim.
            # Parent-death semantics are covered by the shared ROB-465 primitive; this
            # filesystem suite exercises the producer after that entry guard.
            module.bind_to_parent_death = lambda: None
            sys.argv = [sys.argv[0]]
            try:
                module.main()
            except module.ProducerError as error:
                module.emit('busy' if error.code == 75 else 'rejected')
                raise SystemExit(error.code)
            except (OSError, ValueError, UnicodeError):
                module.emit('rejected')
                raise SystemExit(70)
            PY
            ,
        );
        chmod($this->runner, 0555);
    }

    protected function tearDown(): void
    {
        if (isset($this->root) && is_dir($this->root)) {
            $this->removeTree($this->root);
        }
    }

    public function testRecurringUnitsPassRequiredNativeSystemdVerification(): void
    {
        foreach (['/usr/bin/systemd-analyze', '/usr/bin/bash', '/usr/bin/php', '/usr/bin/python3'] as $binary) {
            RootHostTestPrerequisites::enforce(
                $this,
                RootHostTestPrerequisites::classify(
                    is_file($binary) && is_executable($binary),
                    'systemd_unit_binary_missing',
                    "Native recurring-unit verification requires exact executable $binary.",
                ),
            );
        }

        $process = proc_open(
            [
                '/usr/bin/systemd-analyze',
                'verify',
                dirname(__DIR__, 3) . '/scripts/ops/systemd/fh-backup-set-producer.service',
                dirname(__DIR__, 3) . '/scripts/ops/systemd/fh-backup-set-continuity.timer',
                dirname(__DIR__, 3) . '/scripts/ops/systemd/fh-backup-set-restore-verify.service',
            ],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        self::assertSame(0, proc_close($process), $stdout . $stderr);
    }

    public function testTwoSerialRunsPublishIndependentCompatibleSetsAndAggregateOnlyOutput(): void
    {
        $first = $this->runProducer();
        self::assertSame(0, $first['exit'], $first['stderr']);
        $this->markContinuityVerified();
        sleep(2);
        $second = $this->runProducer();
        self::assertSame(0, $second['exit'], $second['stderr']);
        $this->assertLocksAvailable();

        $sets = array_values(
            array_filter(
                scandir($this->root . '/backups') ?: [],
                static fn(string $leaf): bool => preg_match('/^20[0-9]{6}T[0-9]{6}Z$/D', $leaf) === 1,
            ),
        );
        self::assertCount(2, $sets);
        self::assertNotSame($sets[0], $sets[1]);
        $dumpDigests = [];
        $decompressedSql = [];
        $attestationPaths = [];
        foreach ($sets as $set) {
            $dump = $this->root . '/backups/' . $set . '/db/easyappointments.sql.gz';
            $metadata = $this->root . '/backups/' . $set . '/meta/backup.env';
            self::assertFileExists($dump);
            self::assertFileExists($metadata);
            self::assertSame(0600, fileperms($dump) & 0777);
            self::assertSame(0600, fileperms($metadata) & 0777);
            $bytes = file_get_contents($metadata);
            self::assertIsString($bytes);
            self::assertStringContainsString('schema=production_backup_set.v1', $bytes);
            self::assertStringContainsString('backup_set_id=' . $set, $bytes);
            self::assertStringContainsString('dump_sha256=' . hash_file('sha256', $dump), $bytes);
            $dumpDigest = hash_file('sha256', $dump);
            self::assertIsString($dumpDigest);
            $dumpDigests[] = $dumpDigest;
            $decoded = gzdecode((string) file_get_contents($dump));
            self::assertIsString($decoded);
            $decompressedSql[] = $decoded;
            $attestationPaths[] = DeploymentEvidenceAuthorityV1::dumpAttestationPath($dumpDigest);
            $gzipHeader = file_get_contents($dump, false, null, 0, 10);
            self::assertIsString($gzipHeader);
            self::assertSame("\x1f\x8b\x08", substr($gzipHeader, 0, 3));
            $mtime = unpack('Vmtime', substr($gzipHeader, 4, 4));
            self::assertIsArray($mtime);
            $expectedMtime = \DateTimeImmutable::createFromFormat('!Ymd\\THis\\Z', $set, new \DateTimeZone('UTC'));
            self::assertInstanceOf(\DateTimeImmutable::class, $expectedMtime);
            self::assertSame($expectedMtime->getTimestamp(), $mtime['mtime']);
            $gzip = proc_open(['gzip', '-t', $dump], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
            self::assertIsResource($gzip);
            fclose($pipes[0]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($gzip));
        }
        self::assertCount(2, array_unique($dumpDigests), 'Identical SQL sets must have distinct digest authorities.');
        self::assertCount(1, array_unique($decompressedSql), 'The fixture must prove identical decompressed SQL.');
        self::assertCount(
            2,
            array_unique($attestationPaths),
            'Distinct set digests must select independent attestation leaves.',
        );

        $handoff = $this->root . '/backups/last_backup_set.json';
        self::assertFileExists($handoff);
        self::assertSame(0600, fileperms($handoff) & 0777);
        self::assertSame(1, (int) (lstat($handoff)['nlink'] ?? 0));
        $handoffPayload = json_decode((string) file_get_contents($handoff), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($sets[1], $handoffPayload['backup_set_id']);
        self::assertSame(
            hash_file('sha256', $this->root . '/backups/' . $sets[1] . '/db/easyappointments.sql.gz'),
            $handoffPayload['dump_sha256'],
        );

        foreach ([$first, $second] as $result) {
            $payload = json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('production_backup_set_result.v1', $payload['schema']);
            self::assertSame('published', $payload['status']);
            self::assertSame(1, $payload['backup_sets_published']);
            self::assertArrayNotHasKey('backup_set_id', $payload);
            self::assertArrayNotHasKey('dump_sha256', $payload);
            self::assertArrayNotHasKey('path', $payload);
            self::assertStringNotContainsString(self::PASSWORD, $result['stdout'] . $result['stderr']);
        }
        foreach (glob($this->root . '/backups/20*T*Z/meta/backup.env') ?: [] as $metadata) {
            self::assertStringNotContainsString(self::PASSWORD, (string) file_get_contents($metadata));
        }
        self::assertSame(2, count(glob($this->root . '/backups/20*T*Z') ?: []));
        self::assertSame([], glob($this->root . '/backups/.backup-set-producer-*.tmp') ?: []);
    }

    public function testGlobalLockIsRetryableAndDoesNotPublish(): void
    {
        $lock = fopen($this->root . '/orchestrator/locks/fh-production-change.lock', 'r+');
        self::assertIsResource($lock);
        self::assertTrue(flock($lock, LOCK_EX | LOCK_NB));
        try {
            $result = $this->runProducer();
            self::assertSame(75, $result['exit']);
            self::assertSame(
                ['schema' => 'production_backup_set_result.v1', 'status' => 'busy'],
                json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR),
            );
            self::assertSame([], glob($this->root . '/backups/20*T*Z') ?: []);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function testTrustedDumpChildRetainsBothLocksAfterProducerDeath(): void
    {
        $this->runHeldDumpCase(false);
    }

    public function testStoppedDumpWithoutPidIsTerminatedAndReaped(): void
    {
        $this->runHeldDumpCase(true);
    }

    private function runHeldDumpCase(bool $stopBeforePid): void
    {
        touch($this->root . '/hold-dump');
        if ($stopBeforePid) {
            touch($this->root . '/hold-dump-no-pid');
        }
        $coordinator = <<<'PY'
        import ctypes
        import fcntl
        import json
        import os
        import signal
        import subprocess
        import sys
        import time

        root, runner, helper, mode = sys.argv[1:]
        libc = ctypes.CDLL(None, use_errno=True)
        assert libc.prctl(36, 1, 0, 0, 0) == 0  # PR_SET_CHILD_SUBREAPER
        signal.signal(signal.SIGTERM, lambda *_: sys.exit(76))
        producer = subprocess.Popen(
            ['/usr/bin/python3', '-I', '-B', runner, helper, root],
            start_new_session=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
        )
        locks = [root + '/orchestrator/locks/fh-production-change.lock',
                 root + '/backups/.backup-set-producer.lock']

        def wait_for(predicate, seconds=5.0):
            deadline = time.monotonic() + seconds
            while time.monotonic() < deadline:
                if predicate():
                    return True
                time.sleep(0.01)
            return False

        def producer_is_zombie():
            with open('/proc/%d/stat' % producer.pid, encoding='ascii') as stream:
                return stream.read().rsplit(') ', 1)[1][0] == 'Z'

        def assert_locks(busy):
            for path in locks:
                with open(path, 'rb') as stream:
                    try:
                        fcntl.flock(stream, fcntl.LOCK_EX | fcntl.LOCK_NB)
                        acquired = True
                    except BlockingIOError:
                        acquired = False
                    assert acquired != busy, (path, 'expected busy' if busy else 'expected free')

        try:
            if mode == 'stopped':
                # This marker precedes SIGSTOP and PID publication. The helper must
                # clean up even though its normal readiness PID never becomes known.
                assert wait_for(lambda: os.path.exists(root + '/before-stop'))
                assert not wait_for(lambda: os.path.exists(root + '/dump-child.pid'), 0.25)
            else:
                assert wait_for(lambda: os.path.exists(root + '/dump-child.pid'))
                os.kill(producer.pid, signal.SIGKILL)
                assert wait_for(producer_is_zombie)
                # Do not wait/poll/reap the producer yet: its zombie PID reserves
                # this process-group identity until our last group signal below.
                assert_locks(True)
        finally:
            signal.signal(signal.SIGTERM, signal.SIG_IGN)
            # The group leader is our unreaped child even on readiness failure.
            # Kill once before any reaping; never signal a recycled PID/PGID.
            try:
                os.killpg(producer.pid, signal.SIGKILL)
            except ProcessLookupError:
                pass
            producer.wait(timeout=5.0)
            deadline = time.monotonic() + 5.0
            while True:
                try:
                    waited, _ = os.waitpid(-1, os.WNOHANG)
                except ChildProcessError:
                    break
                if time.monotonic() >= deadline:
                    raise RuntimeError('owned descendants did not terminate and reap')
                if waited == 0:
                    time.sleep(0.01)
        assert_locks(False)
        print(json.dumps({'mode': mode, 'children_reaped': True, 'locks_free': True}))
        PY;
        $process = proc_open(
            [
                '/usr/bin/python3',
                '-I',
                '-B',
                '-c',
                $coordinator,
                $this->root,
                $this->runner,
                $this->helper,
                $stopBeforePid ? 'stopped' : 'ready',
            ],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
            null,
            [],
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        try {
            $deadline = microtime(true) + 20.0;
            do {
                $status = proc_get_status($process);
                if (!$status['running']) {
                    break;
                }
                usleep(10_000);
            } while (microtime(true) < $deadline);
            self::assertFalse($status['running'], 'dump coordinator exceeded its bounded lifecycle');
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            self::assertSame(0, $status['exitcode'], $stdout . $stderr);
            self::assertSame(
                ['mode' => $stopBeforePid ? 'stopped' : 'ready', 'children_reaped' => true, 'locks_free' => true],
                json_decode($stdout, true, 512, JSON_THROW_ON_ERROR),
            );
        } finally {
            if (proc_get_status($process)['running']) {
                proc_terminate($process, SIGTERM);
                $deadline = microtime(true) + 12.0;
                while (proc_get_status($process)['running'] && microtime(true) < $deadline) {
                    usleep(10_000);
                }
                if (proc_get_status($process)['running']) {
                    proc_terminate($process, SIGKILL);
                }
            }
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        }
    }

    public function testIncompleteRunnerHistoryIsRetryableAndDoesNotPublish(): void
    {
        $runs = $this->root . '/orchestrator/runs';
        mkdir($runs, 0700);

        foreach (['state.json', 'events.jsonl', 'evidence.json'] as $missing) {
            $run = $runs . '/018f6f52-4c87-4d4e-8b19-6a66e6e1af25';
            mkdir($run, 0700);
            foreach (['state.json', 'events.jsonl', 'evidence.json'] as $leaf) {
                if ($leaf === $missing) {
                    continue;
                }
                file_put_contents($run . '/' . $leaf, "{}\n");
                chmod($run . '/' . $leaf, 0600);
            }

            $result = $this->runProducer();

            self::assertSame(75, $result['exit'], $missing . ': ' . $result['stderr']);
            self::assertSame(
                ['schema' => 'production_backup_set_result.v1', 'status' => 'busy'],
                json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR),
                $missing,
            );
            self::assertSame([], glob($this->root . '/backups/20*T*Z') ?: [], $missing);
            $this->removeTree($run);
        }
    }

    public function testLegacySuccessMarkerWithoutHandoffMigratesOnFirstRun(): void
    {
        file_put_contents($this->root . '/backups/last_backup_success.utc', "2026-01-01T00:00:00Z\n");
        chmod($this->root . '/backups/last_backup_success.utc', 0600);

        $result = $this->runProducer();

        self::assertSame(0, $result['exit'], $result['stderr']);
        self::assertSame('published', json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR)['status']);
        self::assertFileExists($this->root . '/backups/last_backup_set.json');
        self::assertCount(1, glob($this->root . '/backups/20*T*Z') ?: []);
    }

    public function testLegacyMarkerNewerThanProtectedHandoffMigratesOnlyWithoutContinuityState(): void
    {
        $baseline = $this->runProducer('2026-08-13T00:00:00Z');
        self::assertSame(0, $baseline['exit'], $baseline['stderr']);
        unlink($this->root . '/backups/backup_continuity_state.json');
        file_put_contents($this->root . '/backups/last_backup_success.utc', "2026-08-13T00:00:01Z\n");
        chmod($this->root . '/backups/last_backup_success.utc', 0600);

        $migration = $this->runProducer('2026-08-13T00:00:02Z');

        self::assertSame(0, $migration['exit'], $migration['stderr']);
        self::assertSame('published', json_decode($migration['stdout'], true, flags: JSON_THROW_ON_ERROR)['status']);
        self::assertCount(2, glob($this->root . '/backups/20*T*Z') ?: []);
        $state = json_decode(
            (string) file_get_contents($this->root . '/backups/backup_continuity_state.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertSame('pending', $state['status']);
        self::assertSame('20260813T000002Z', $state['handoff']['backup_set_id']);

        file_put_contents($this->root . '/backups/last_backup_success.utc', "2026-08-13T00:00:03Z\n");
        chmod($this->root . '/backups/last_backup_success.utc', 0600);
        self::assertSame(70, $this->runProducer('2026-08-13T00:00:04Z')['exit']);
        self::assertCount(2, glob($this->root . '/backups/20*T*Z') ?: []);
    }

    public function testCrashAfterHandoffBeforeMarkerAttachesWithoutNewDumpOrHandoffRewrite(): void
    {
        $first = $this->runProducer();
        self::assertSame(0, $first['exit'], $first['stderr']);
        $handoff = $this->root . '/backups/last_backup_set.json';
        $before = lstat($handoff);
        self::assertIsArray($before);
        unlink($this->root . '/backups/last_backup_success.utc');

        $replay = $this->runProducer();

        self::assertSame(0, $replay['exit'], $replay['stderr']);
        self::assertSame('attached', json_decode($replay['stdout'], true, 512, JSON_THROW_ON_ERROR)['status']);
        self::assertCount(1, glob($this->root . '/backups/20*T*Z') ?: []);
        $after = lstat($handoff);
        self::assertIsArray($after);
        self::assertSame($before['ino'], $after['ino']);
        self::assertSame($before['mtime'], $after['mtime']);
        self::assertFileExists($this->root . '/backups/last_backup_success.utc');
    }

    public function testCrashAfterFinalSetBeforeHandoffAttachesAndPublishesBothPointers(): void
    {
        $first = $this->runProducer();
        self::assertSame(0, $first['exit'], $first['stderr']);
        unlink($this->root . '/backups/last_backup_success.utc');
        unlink($this->root . '/backups/last_backup_set.json');

        $replay = $this->runProducer();

        self::assertSame(0, $replay['exit'], $replay['stderr']);
        self::assertSame('attached', json_decode($replay['stdout'], true, 512, JSON_THROW_ON_ERROR)['status']);
        self::assertCount(1, glob($this->root . '/backups/20*T*Z') ?: []);
        self::assertFileExists($this->root . '/backups/last_backup_set.json');
        self::assertFileExists($this->root . '/backups/last_backup_success.utc');
    }

    public function testCrashFinalRecoveryAccepts14399SecondsAndRejects14400WithoutPointerMutation(): void
    {
        $created = '2026-08-13T00:00:00Z';
        $first = $this->runProducer($created);
        self::assertSame(0, $first['exit'], $first['stderr']);
        $sets = glob($this->root . '/backups/20*T*Z') ?: [];
        self::assertCount(1, $sets);
        $set = $sets[0];
        $dump = $set . '/db/easyappointments.sql.gz';
        $setIdentity = lstat($set);
        self::assertIsArray($setIdentity);
        $dumpHash = hash_file('sha256', $dump);
        unlink($this->root . '/backups/last_backup_success.utc');
        unlink($this->root . '/backups/last_backup_set.json');

        $freshReplay = $this->runProducer('2026-08-13T03:59:59Z');
        self::assertSame(0, $freshReplay['exit'], $freshReplay['stderr']);
        self::assertSame('attached', json_decode($freshReplay['stdout'], true, 512, JSON_THROW_ON_ERROR)['status']);
        self::assertFileExists($this->root . '/backups/last_backup_success.utc');
        self::assertFileExists($this->root . '/backups/last_backup_set.json');
        unlink($this->root . '/backups/last_backup_success.utc');
        unlink($this->root . '/backups/last_backup_set.json');

        $staleReplay = $this->runProducer('2026-08-13T04:00:00Z');

        self::assertSame(70, $staleReplay['exit'], $staleReplay['stderr']);
        self::assertSame(
            ['schema' => 'production_backup_set_result.v1', 'status' => 'rejected'],
            json_decode($staleReplay['stdout'], true, 512, JSON_THROW_ON_ERROR),
        );
        self::assertFileDoesNotExist($this->root . '/backups/last_backup_success.utc');
        self::assertFileDoesNotExist($this->root . '/backups/last_backup_set.json');
        self::assertCount(1, glob($this->root . '/backups/20*T*Z') ?: []);
        $setAfter = lstat($set);
        self::assertIsArray($setAfter);
        self::assertSame($setIdentity['ino'], $setAfter['ino']);
        self::assertSame($dumpHash, hash_file('sha256', $dump));
    }

    public function testCrashFinalRecoveryRejectsCopiedGzipTimestampBeforePointerMutation(): void
    {
        $created = '2026-08-13T00:00:00Z';
        $first = $this->runProducer($created);
        self::assertSame(0, $first['exit'], $first['stderr']);
        $sets = glob($this->root . '/backups/20*T*Z') ?: [];
        self::assertCount(1, $sets);
        $set = $sets[0];
        $dump = $set . '/db/easyappointments.sql.gz';
        $metadata = $set . '/meta/backup.env';
        $sqlBefore = gzdecode((string) file_get_contents($dump));
        self::assertIsString($sqlBefore);
        unlink($this->root . '/backups/last_backup_success.utc');
        unlink($this->root . '/backups/last_backup_set.json');

        $gzipBytes = file_get_contents($dump);
        self::assertIsString($gzipBytes);
        $header = unpack('Vmtime', substr($gzipBytes, 4, 4));
        self::assertIsArray($header);
        $copiedMtime = $header['mtime'] + 1;
        $gzipBytes = substr($gzipBytes, 0, 4) . pack('V', $copiedMtime) . substr($gzipBytes, 8);
        file_put_contents($dump, $gzipBytes);
        chmod($dump, 0600);
        $mutatedDigest = hash('sha256', $gzipBytes);
        $metadataBytes = file_get_contents($metadata);
        self::assertIsString($metadataBytes);
        $metadataBytes = preg_replace('/^dump_sha256=[0-9a-f]{64}$/m', 'dump_sha256=' . $mutatedDigest, $metadataBytes);
        self::assertIsString($metadataBytes);
        file_put_contents($metadata, $metadataBytes);
        chmod($metadata, 0600);
        self::assertSame($sqlBefore, gzdecode($gzipBytes), 'Changing gzip mtime must leave SQL and CRC valid.');
        $setBefore = lstat($set);
        $dumpBefore = lstat($dump);
        self::assertIsArray($setBefore);
        self::assertIsArray($dumpBefore);

        $replay = $this->runProducer('2026-08-13T00:00:01Z');

        self::assertSame(70, $replay['exit'], $replay['stderr']);
        self::assertSame(
            ['schema' => 'production_backup_set_result.v1', 'status' => 'rejected'],
            json_decode($replay['stdout'], true, 512, JSON_THROW_ON_ERROR),
        );
        self::assertFileDoesNotExist($this->root . '/backups/last_backup_success.utc');
        self::assertFileDoesNotExist($this->root . '/backups/last_backup_set.json');
        self::assertCount(1, glob($this->root . '/backups/20*T*Z') ?: []);
        $setAfter = lstat($set);
        $dumpAfter = lstat($dump);
        self::assertIsArray($setAfter);
        self::assertIsArray($dumpAfter);
        self::assertSame($setBefore['ino'], $setAfter['ino']);
        self::assertSame($dumpBefore['ino'], $dumpAfter['ino']);
        self::assertSame($mutatedDigest, hash_file('sha256', $dump));
    }

    public function testUnsafeCredentialAndTempObjectsRejectWithoutPublication(): void
    {
        chmod($this->root . '/credentials.cnf', 0640);
        $result = $this->runProducer();
        self::assertSame(70, $result['exit']);
        self::assertSame([], glob($this->root . '/backups/20*T*Z') ?: []);
        self::assertStringNotContainsString(self::PASSWORD, $result['stdout'] . $result['stderr']);
        chmod($this->root . '/credentials.cnf', 0600);

        file_put_contents(
            $this->root . '/credentials.cnf',
            "[client]\nuser=fh_backup\npassword=" .
                self::PASSWORD .
                "\nprotocol=tcp\nhost=127.0.0.1\nport=3306\nroutines=true\n",
        );
        chmod($this->root . '/credentials.cnf', 0600);
        $result = $this->runProducer();
        self::assertSame(70, $result['exit']);
        self::assertSame([], glob($this->root . '/backups/20*T*Z') ?: []);
        self::assertStringNotContainsString(self::PASSWORD, $result['stdout'] . $result['stderr']);
        file_put_contents(
            $this->root . '/credentials.cnf',
            "[client]\nuser=fh_backup\npassword=" . self::PASSWORD . "\nprotocol=tcp\nhost=127.0.0.1\nport=3306\n",
        );
        chmod($this->root . '/credentials.cnf', 0600);

        $unsafe = $this->root . '/backups/.backup-set-producer-' . str_repeat('a', 32) . '.tmp';
        symlink('/tmp', $unsafe);
        $result = $this->runProducer();
        self::assertSame(70, $result['exit']);
        self::assertTrue(is_link($unsafe));
        self::assertSame([], glob($this->root . '/backups/20*T*Z') ?: []);
    }

    public function testPendingContinuityStateReplaysTheSameSetUntilVerified(): void
    {
        $first = $this->runProducer('2026-08-13T00:00:00Z');
        self::assertSame(0, $first['exit'], $first['stderr']);
        $statePath = $this->root . '/backups/backup_continuity_state.json';
        $state = json_decode((string) file_get_contents($statePath), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('production_backup_continuity_state.v1', $state['schema']);
        self::assertSame('pending', $state['status']);
        self::assertSame(0600, fileperms($statePath) & 0777);
        self::assertSame(1, (int) (lstat($statePath)['nlink'] ?? 0));

        $replay = $this->runProducer('2026-08-13T00:00:01Z');
        self::assertSame(0, $replay['exit'], $replay['stderr']);
        self::assertSame('attached', json_decode($replay['stdout'], true, flags: JSON_THROW_ON_ERROR)['status']);
        self::assertCount(1, glob($this->root . '/backups/20*T*Z') ?: []);

        $this->markContinuityVerified();
        $next = $this->runProducer('2026-08-13T00:00:02Z');
        self::assertSame(0, $next['exit'], $next['stderr']);
        self::assertSame('published', json_decode($next['stdout'], true, flags: JSON_THROW_ON_ERROR)['status']);
        self::assertCount(2, glob($this->root . '/backups/20*T*Z') ?: []);
        self::assertSame(
            'pending',
            json_decode((string) file_get_contents($statePath), true, flags: JSON_THROW_ON_ERROR)['status'],
        );
    }

    /** @return array{exit:int,stdout:string,stderr:string} */
    private function runProducer(?string $observedAtUtc = null): array
    {
        $command = ['/usr/bin/python3', '-I', '-B', $this->runner, $this->helper, $this->root];
        if ($observedAtUtc !== null) {
            $command[] = $observedAtUtc;
        }
        $process = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, null, []);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['exit' => proc_close($process), 'stdout' => $stdout ?: '', 'stderr' => $stderr ?: ''];
    }

    private function markContinuityVerified(): void
    {
        $path = $this->root . '/backups/backup_continuity_state.json';
        $state = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('pending', $state['status']);
        $state['status'] = 'verified';
        file_put_contents($path, json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n");
        chmod($path, 0600);
    }

    private function assertLocksAvailable(): void
    {
        foreach (['fh-production-change.lock', '.backup-set-producer.lock'] as $leaf) {
            $path =
                $leaf === 'fh-production-change.lock'
                    ? $this->root . '/orchestrator/locks/' . $leaf
                    : $this->root . '/backups/' . $leaf;
            $lock = fopen($path, 'r+');
            self::assertIsResource($lock);
            try {
                self::assertTrue(flock($lock, LOCK_EX | LOCK_NB), $leaf . ' remained locked after successful run');
                flock($lock, LOCK_UN);
            } finally {
                fclose($lock);
            }
        }
    }

    private function removeTree(string $path): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isLink() || $item->isFile() ? unlink($item->getPathname()) : rmdir($item->getPathname());
        }
        rmdir($path);
    }
}
