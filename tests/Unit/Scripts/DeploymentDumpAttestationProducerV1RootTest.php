<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\RootHostTestPrerequisites;

final class DeploymentDumpAttestationProducerV1RootTest extends TestCase
{
    private string $root;
    private string $helper;
    private int $sigkill;

    protected function setUp(): void
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            $this->markTestSkipped('Linux root is required for the dump-attestation filesystem contract.');
        }
        RootHostTestPrerequisites::enforce($this, RootHostTestPrerequisites::processRuntimeCheck());
        RootHostTestPrerequisites::enforce($this, RootHostTestPrerequisites::pythonRuntimeCheck());
        if (posix_geteuid() !== 0) {
            RootHostTestPrerequisites::enforce(
                $this,
                RootHostTestPrerequisites::classify(
                    false,
                    'root_required',
                    'Linux root is required for the dump-attestation filesystem contract.',
                ),
            );
        }
        RootHostTestPrerequisites::enforce(
            $this,
            RootHostTestPrerequisites::signalCheck(defined('SIGKILL') ? SIGKILL : null),
        );
        if (in_array('requires-docker-host', $this->groups(), true)) {
            $this->requireDockerHost();
        }
        $this->sigkill = RootHostTestPrerequisites::signalNumber('SIGKILL', defined('SIGKILL') ? SIGKILL : null) ?? 9;
        $this->root = sys_get_temp_dir() . '/fh-dump-attestation-root-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->root, 0700));
        $this->helper = dirname(__DIR__, 3) . '/scripts/ops/libexec/deployment_dump_attestation_v1.py';
    }

    protected function tearDown(): void
    {
        if (isset($this->root) && is_dir($this->root)) {
            $this->removeTree($this->root);
        }
    }

    public function testProtectedBackupHandoffIsStableCanonicalAndClosed(): void
    {
        $record = [
            'backup_set_id' => gmdate('Ymd\\THis\\Z', time() - 60),
            'compressed_size_bytes' => 100,
            'dump_sha256' => str_repeat('a', 64),
            'schema' => 'production_backup_set_handoff.v1',
            'uncompressed_size_bytes' => 200,
        ];
        file_put_contents(
            $this->root . '/last_backup_set.json',
            json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n",
        );
        chmod($this->root . '/last_backup_set.json', 0600);
        $marker =
            substr($record['backup_set_id'], 0, 4) .
            '-' .
            substr($record['backup_set_id'], 4, 2) .
            '-' .
            substr($record['backup_set_id'], 6, 2) .
            'T' .
            substr($record['backup_set_id'], 9, 2) .
            ':' .
            substr($record['backup_set_id'], 11, 2) .
            ':' .
            substr($record['backup_set_id'], 13, 2) .
            "Z\n";
        file_put_contents($this->root . '/last_backup_success.utc', $marker);
        chmod($this->root . '/last_backup_success.utc', 0600);

        $accepted = $this->python(
            <<<'PY'
            import os
            module = load()
            fd = os.open(os.environ['ROB465_TEST_ROOT'], os.O_RDONLY | os.O_DIRECTORY | os.O_CLOEXEC)
            value = module.read_backup_handoff(fd)
            assert module.read_backup_success_marker(fd) == value['backup_set_id']
            module.assert_handoff_matches(value, 'a' * 64, 100, 200)
            print(value['schema'])
            os.close(fd)
            PY
            ,
        );
        self::assertSame(0, $accepted['exit'], $accepted['stderr']);
        self::assertSame("production_backup_set_handoff.v1\n", $accepted['stdout']);

        $mismatch = $this->python(
            <<<'PY'
            import os
            module = load()
            fd = os.open(os.environ['ROB465_TEST_ROOT'], os.O_RDONLY | os.O_DIRECTORY | os.O_CLOEXEC)
            value = module.read_backup_handoff(fd)
            module.assert_handoff_matches(value, 'b' * 64, 100, 200)
            PY
            ,
        );
        self::assertSame(70, $mismatch['exit']);

        file_put_contents($this->root . '/last_backup_success.utc', "2026-01-01T00:00:00Z\n");
        chmod($this->root . '/last_backup_success.utc', 0600);
        $markerMismatch = $this->python(
            <<<'PY'
            import os
            module = load()
            fd = os.open(os.environ['ROB465_TEST_ROOT'], os.O_RDONLY | os.O_DIRECTORY | os.O_CLOEXEC)
            value = module.read_backup_handoff(fd)
            if module.read_backup_success_marker(fd) != value['backup_set_id']:
                module.reject()
            PY
            ,
        );
        self::assertSame(70, $markerMismatch['exit']);
        file_put_contents($this->root . '/last_backup_success.utc', $marker);
        chmod($this->root . '/last_backup_success.utc', 0600);

        $record['future_field'] = true;
        file_put_contents(
            $this->root . '/last_backup_set.json',
            json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n",
        );
        chmod($this->root . '/last_backup_set.json', 0600);
        $rejected = $this->python(
            <<<'PY'
            import os
            module = load()
            fd = os.open(os.environ['ROB465_TEST_ROOT'], os.O_RDONLY | os.O_DIRECTORY | os.O_CLOEXEC)
            module.read_backup_handoff(fd)
            PY
            ,
        );
        self::assertSame(70, $rejected['exit']);
    }

    public function testPendingContinuityStateBindsHandoffAndTransitionsToVerified(): void
    {
        $producerHelper = dirname(__DIR__, 3) . '/scripts/ops/libexec/backup_set_producer_v1.py';
        $handoff = [
            'backup_set_id' => gmdate('Ymd\THis\Z', time() - 60),
            'compressed_size_bytes' => 100,
            'dump_sha256' => str_repeat('a', 64),
            'schema' => 'production_backup_set_handoff.v1',
            'uncompressed_size_bytes' => 200,
        ];
        $producerCanonical = $this->python(
            str_replace(
                ['PRODUCER_HELPER', 'HANDOFF_JSON'],
                [var_export($producerHelper, true), var_export(json_encode($handoff, JSON_THROW_ON_ERROR), true)],
                <<<'PY'
                import importlib.util
                import json
                import os
                spec = importlib.util.spec_from_file_location('rob466_helper', PRODUCER_HELPER)
                producer = importlib.util.module_from_spec(spec)
                spec.loader.exec_module(producer)
                data = producer.continuity_state_bytes('pending', json.loads(HANDOFF_JSON))
                path = os.path.join(os.environ['ROB465_TEST_ROOT'], 'backup_continuity_state.json')
                fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_CLOEXEC | os.O_NOFOLLOW, 0o600)
                os.write(fd, data)
                os.close(fd)
                PY
                ,
            ),
        );
        self::assertSame(0, $producerCanonical['exit'], $producerCanonical['stderr']);

        $accepted = $this->python(
            <<<'PY'
            import os
            module = load()
            fd = os.open(os.environ['ROB465_TEST_ROOT'], os.O_RDONLY | os.O_DIRECTORY | os.O_CLOEXEC)
            state = module.read_continuity_state(fd)
            if state[0]['handoff']['dump_sha256'] != 'a' * 64:
                raise RuntimeError('wrong pending handoff')
            module.mark_continuity_verified(fd, state, 'b' * 32)
            os.close(fd)
            PY
            ,
        );
        self::assertSame(0, $accepted['exit'], $accepted['stderr']);
        $verified = json_decode(
            (string) file_get_contents($this->root . '/backup_continuity_state.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertSame('verified', $verified['status']);
        self::assertSame($handoff, $verified['handoff']);
        self::assertSame(0600, fileperms($this->root . '/backup_continuity_state.json') & 0777);
        self::assertSame([], glob($this->root . '/.backup_continuity_state.json.tmp-*') ?: []);

        $state = [
            'handoff' => array_merge($handoff, ['dump_sha256' => str_repeat('b', 64)]),
            'schema' => 'production_backup_continuity_state.v1',
            'status' => 'pending',
        ];
        file_put_contents(
            $this->root . '/backup_continuity_state.json',
            json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n",
        );
        chmod($this->root . '/backup_continuity_state.json', 0600);
        $mismatch = $this->python(
            <<<'PY'
            import os
            module = load()
            fd = os.open(os.environ['ROB465_TEST_ROOT'], os.O_RDONLY | os.O_DIRECTORY | os.O_CLOEXEC)
            state = module.read_continuity_state(fd)
            if state[0]['handoff']['dump_sha256'] != 'a' * 64:
                module.reject()
            PY
            ,
        );
        self::assertSame(70, $mismatch['exit']);
    }

    public function testPublishIsAtomicNoReplaceAndExactReplayOnly(): void
    {
        $sha = str_repeat('b', 64);
        $first = $this->python(
            <<<'PY'
            import os
            module = load()
            root = os.environ['ROB465_TEST_ROOT']
            fd = os.open(root, os.O_RDONLY | os.O_DIRECTORY | os.O_CLOEXEC)
            data = b'{"schema":"test"}\n'
            print(module.publish(fd, data, 'b' * 64, 'a' * 32))
            print(module.publish(fd, data, 'b' * 64, 'c' * 32))
            os.close(fd)
            PY
            ,
        );
        self::assertSame(0, $first['exit'], $first['stderr']);
        self::assertSame("published\nattached\n", $first['stdout']);
        self::assertSame("{\"schema\":\"test\"}\n", file_get_contents($this->root . '/' . $sha . '.json'));
        self::assertSame(0600, (lstat($this->root . '/' . $sha . '.json')['mode'] ?? 0) & 0777);
        self::assertSame([], glob($this->root . '/.attestation-*'));

        file_put_contents($this->root . '/' . $sha . '.json', "conflict\n");
        chmod($this->root . '/' . $sha . '.json', 0600);
        $conflict = $this->python(
            <<<'PY'
            import os
            module = load()
            fd = os.open(os.environ['ROB465_TEST_ROOT'], os.O_RDONLY | os.O_DIRECTORY | os.O_CLOEXEC)
            module.publish(fd, b'{"schema":"test"}\n', 'b' * 64, 'd' * 32)
            PY
            ,
        );
        self::assertSame(70, $conflict['exit']);
        self::assertSame("conflict\n", file_get_contents($this->root . '/' . $sha . '.json'));
    }

    public function testMarkerReplayIsMonotonicAndReconcilesRecognizedTemps(): void
    {
        file_put_contents($this->root . '/last_verify_success.utc', "2026-08-13T12:00:00Z\n");
        chmod($this->root . '/last_verify_success.utc', 0600);
        file_put_contents($this->root . '/.last_verify_success.utc.tmp-' . str_repeat('a', 32), "partial\n");
        chmod($this->root . '/.last_verify_success.utc.tmp-' . str_repeat('a', 32), 0600);
        $result = $this->python(
            <<<'PY'
            import os
            module = load()
            fd = os.open(os.environ['ROB465_TEST_ROOT'], os.O_RDONLY | os.O_DIRECTORY | os.O_CLOEXEC)
            module.reconcile_files(fd, '.last_verify_success.utc.tmp-', module.MARKER_TEMP_RE, False)
            module.success_marker(fd, '2026-08-13T11:00:00Z', 'b' * 32)
            module.success_marker(fd, '2026-08-13T13:00:00Z', 'c' * 32)
            os.close(fd)
            PY
            ,
        );
        self::assertSame(0, $result['exit'], $result['stderr']);
        self::assertSame("2026-08-13T13:00:00Z\n", file_get_contents($this->root . '/last_verify_success.utc'));
        self::assertSame([], glob($this->root . '/.last_verify_success.utc.tmp-*'));
    }

    public function testFdRelativeCleanupRemovesOnlySafeSameDeviceTreeAndRejectsSymlink(): void
    {
        mkdir($this->root . '/.run-' . str_repeat('a', 32) . '/nested', 0700, true);
        file_put_contents($this->root . '/.run-' . str_repeat('a', 32) . '/nested/file', 'x');
        chmod($this->root . '/.run-' . str_repeat('a', 32) . '/nested/file', 0600);
        $clean = $this->python(
            <<<'PY'
            import os
            module = load()
            fd = os.open(os.environ['ROB465_TEST_ROOT'], os.O_RDONLY | os.O_DIRECTORY | os.O_CLOEXEC)
            module.reconcile_files(fd, '.run-', module.RUN_RE, True)
            os.close(fd)
            PY
            ,
        );
        self::assertSame(0, $clean['exit'], $clean['stderr']);
        self::assertDirectoryDoesNotExist($this->root . '/.run-' . str_repeat('a', 32));

        mkdir($this->root . '/.run-' . str_repeat('b', 32), 0700);
        symlink('/tmp', $this->root . '/.run-' . str_repeat('b', 32) . '/escape');
        $unsafe = $this->python(
            <<<'PY'
            import os
            module = load()
            fd = os.open(os.environ['ROB465_TEST_ROOT'], os.O_RDONLY | os.O_DIRECTORY | os.O_CLOEXEC)
            module.reconcile_files(fd, '.run-', module.RUN_RE, True)
            PY
            ,
        );
        self::assertSame(70, $unsafe['exit']);
        self::assertTrue(is_link($this->root . '/.run-' . str_repeat('b', 32) . '/escape'));
    }

    public function testFdRelativeCleanupAcceptsRootLeaseFileAndDatabaseOwnedTree(): void
    {
        $safe = $this->root . '/.run-' . str_repeat('c', 32);
        mkdir($safe, 0700);
        file_put_contents($safe . '/.container-lease', '');
        chmod($safe . '/.container-lease', 0600);
        mkdir($safe . '/datadir', 0750);
        file_put_contents($safe . '/datadir/ibdata1', 'db');
        chmod($safe . '/datadir/ibdata1', 0660);
        chown($safe . '/datadir', 999);
        chown($safe . '/datadir/ibdata1', 999);
        $result = $this->python(
            <<<'PY'
            import os
            module = load()
            fd = os.open(os.environ['ROB465_TEST_ROOT'], os.O_RDONLY | os.O_DIRECTORY | os.O_CLOEXEC)
            module.reconcile_files(fd, '.run-', module.RUN_RE, True)
            os.close(fd)
            PY
            ,
        );
        self::assertSame(0, $result['exit'], $result['stderr']);
        self::assertDirectoryDoesNotExist($safe);

        $unsafe = $this->root . '/.run-' . str_repeat('d', 32);
        mkdir($unsafe . '/nested', 0700, true);
        self::assertTrue(posix_mkfifo($unsafe . '/nested/special', 0600));
        $rejected = $this->python(
            <<<'PY'
            import os
            module = load()
            fd = os.open(os.environ['ROB465_TEST_ROOT'], os.O_RDONLY | os.O_DIRECTORY | os.O_CLOEXEC)
            module.reconcile_files(fd, '.run-', module.RUN_RE, True)
            PY
            ,
        );
        self::assertSame(70, $rejected['exit']);
        self::assertFileExists($unsafe . '/nested/special');
    }

    public function testUnknownEmptyHistoricalRunFailsClosed(): void
    {
        mkdir($this->root . '/runs', 0700);
        mkdir($this->root . '/runs/018f6f52-4c87-4d4e-8b19-6a66e6e1af25', 0700);
        $result = $this->python(
            <<<'PY'
            import os
            module = load()
            module.activity_count = lambda: 0
            fd = os.open(os.environ['ROB465_TEST_ROOT'], os.O_RDONLY | os.O_DIRECTORY | os.O_CLOEXEC)
            module.assert_no_nonterminal_runs(fd)
            PY
            ,
        );
        self::assertSame(75, $result['exit'], $result['stderr']);
    }

    public function testCapacityOverflowAndRealAttachValidatesBeforeMarkerMutation(): void
    {
        $result = $this->python(
            <<<'PY'
            import datetime
            import os
            module = load()
            try:
                module.require_capacity(os.environ['ROB465_TEST_ROOT'], module.MAX_COMPRESSED + module.MAX_RESTORE_BYTES + module.FIXED_HEADROOM + 1)
            except SystemExit as error:
                if error.code != 70:
                    raise
            else:
                raise RuntimeError('capacity overflow accepted')
            root = os.environ['ROB465_TEST_ROOT']
            os.mkdir(root + '/attestations', 0o700)
            os.mkdir(root + '/backups', 0o700)
            attestations = os.open(root + '/attestations', os.O_RDONLY | os.O_DIRECTORY | os.O_CLOEXEC)
            backups = os.open(root + '/backups', os.O_RDONLY | os.O_DIRECTORY | os.O_CLOEXEC)
            now = datetime.datetime.utcnow().replace(microsecond=0)
            marker = (now - datetime.timedelta(hours=10)).strftime('%Y-%m-%dT%H:%M:%SZ') + '\n'
            marker_fd = os.open('last_verify_success.utc', os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600, dir_fd=backups)
            os.write(marker_fd, marker.encode('ascii')); os.close(marker_fd)
            marker_before = module.identity(os.stat('last_verify_success.utc', dir_fd=backups, follow_symlinks=False))

            def record(created, restored):
                return module.canonical({'schema':'deployment_dump_attestation.v1',
                    'dump':{'sha256':'b'*64,'size_bytes':100,'uncompressed_size_bytes':200,'created_at_utc':created},
                    'verification':{'method':'mariadb_10_11_isolated_restore_v1','image':module.IMAGE,
                                    'sha256_verified':True,'gzip_verified':True,'restore_verified':True,
                                    'restored_datadir_allocated_bytes':4096,'restored_datadir_inode_count':8,
                                    'restored_at_utc':restored},
                    'attested_at_utc':restored})

            created = (now - datetime.timedelta(hours=5)).strftime('%Y-%m-%dT%H:%M:%SZ')
            leaf_fd = os.open('b' * 64 + '.json', os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600, dir_fd=attestations)
            os.write(leaf_fd, record(created, created)); os.close(leaf_fd)
            try:
                module.attach_existing(attestations, backups, 'b'*64, 100, 200, created, 'a'*32)
            except SystemExit as error:
                if error.code != 70:
                    raise
            else:
                raise RuntimeError('stale attestation accepted')
            marker_check = os.open('last_verify_success.utc', os.O_RDONLY, dir_fd=backups)
            marker_bytes = os.read(marker_check, 64); os.close(marker_check)
            if marker_bytes != marker.encode('ascii'):
                raise RuntimeError('stale attach mutated marker bytes')
            if module.identity(os.stat('last_verify_success.utc', dir_fd=backups, follow_symlinks=False)) != marker_before:
                raise RuntimeError('stale attach mutated marker identity')

            os.unlink('b' * 64 + '.json', dir_fd=attestations)
            created = (now - datetime.timedelta(hours=1)).strftime('%Y-%m-%dT%H:%M:%SZ')
            restored = (now - datetime.timedelta(minutes=30)).strftime('%Y-%m-%dT%H:%M:%SZ')
            leaf_fd = os.open('b' * 64 + '.json', os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600, dir_fd=attestations)
            expected = record(created, restored)
            os.write(leaf_fd, expected); os.close(leaf_fd)
            if module.attach_existing(attestations, backups, 'b'*64, 100, 200, created, 'c'*32) != expected:
                raise RuntimeError('exact attach bytes changed')
            marker_fd = os.open('last_verify_success.utc', os.O_RDONLY, dir_fd=backups)
            if os.read(marker_fd, 64) != (restored + '\n').encode('ascii'):
                raise RuntimeError('valid attach did not converge marker')
            os.close(marker_fd); os.close(attestations); os.close(backups)
            PY
            ,
        );
        self::assertSame(0, $result['exit'], $result['stderr']);
        self::assertMatchesRegularExpression(
            '/^20[0-9]{2}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z\n$/',
            (string) file_get_contents($this->root . '/backups/last_verify_success.utc'),
        );
    }

    public function testUnknownOrIncompleteOrchestratorRunBlocksAttestationPath(): void
    {
        mkdir($this->root . '/runs', 0700);
        mkdir($this->root . '/runs/11111111-1111-4111-8111-111111111111', 0700);
        $result = $this->python(
            <<<'PY'
            import os
            module = load()
            fd = os.open(os.environ['ROB465_TEST_ROOT'], os.O_RDONLY | os.O_DIRECTORY | os.O_CLOEXEC)
            module.assert_no_nonterminal_runs(fd)
            PY
            ,
        );
        self::assertSame(75, $result['exit']);
    }

    public function testClosedStreamingDumpGrammarCountsTablesAndRejectsExecutableBypasses(): void
    {
        $result = $this->python(
            <<<'PY'
            module = load()
            valid = b'''/*M!999999\\- enable the sandbox mode */
            -- PREPARE and CREATE TRIGGER in a comment are data, not commands
            /*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
            DROP TABLE IF EXISTS `ea_x`;
            /*!40101 SET CHARACTER_SET_CLIENT=utf8mb4 */;
            CREATE TABLE `ea_x` (`v` text) ENGINE=InnoDB;
            SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
            LOCK TABLES `ea_x` WRITE; ALTER TABLE `ea_x` DISABLE KEYS;
            INSERT INTO `ea_x` VALUES ('PREPARE x','CREATE TRIGGER'),('quote\\'d','x');
            ALTER TABLE `ea_x` ENABLE KEYS; UNLOCK TABLES;
            COMMIT;
            SET AUTOCOMMIT=@OLD_AUTOCOMMIT;'''
            inspector = module.DumpSqlInspector()
            for offset in range(0, len(valid), 3):
                inspector.feed(valid[offset:offset + 3])
            if inspector.finish() != 1:
                raise RuntimeError('wrong table count')
            invalid = (
                b'CREATE TABLE x(id int) ENGINE=CSV;',
                b'/*!50000 CREATE TRIGGER x BEFORE INSERT ON t FOR EACH ROW SET @x=1 */;',
                b'/*M!50000 CREATE EVENT x ON SCHEDULE EVERY 1 DAY DO SELECT 1 */;',
                b'DELIMITER ;;',
                b'INSERT INTO x SELECT * FROM y;',
                b"CREATE TABLE x(id int); PREPARE x FROM 'SELECT 1';",
                b'CREATE TABLE x(id int) PARTITION BY HASH(id);',
                b'CREATE VIEW x AS SELECT 1;',
                b'CREATE TABLE x(id int) DATA DIRECTORY=\'/tmp\';',
                b"INSERT INTO x VALUES ('unterminated);",
            )
            for candidate in invalid:
                probe = module.DumpSqlInspector()
                try:
                    for offset in range(0, len(candidate), 5):
                        probe.feed(candidate[offset:offset + 5])
                    probe.finish()
                except SystemExit as error:
                    if error.code != 70:
                        raise
                else:
                    raise RuntimeError('unsafe SQL accepted')
            PY
            ,
        );
        self::assertSame(0, $result['exit'], $result['stderr']);
    }

    public function testPhpParentDeathKillsBoundPythonChild(): void
    {
        $pidFile = $this->root . '/python.pid';
        $loader =
            "import importlib.util,os,time\n" .
            "spec=importlib.util.spec_from_file_location('rob465_helper', " .
            var_export($this->helper, true) .
            ")\n" .
            "module=importlib.util.module_from_spec(spec); spec.loader.exec_module(module)\n" .
            "module.bind_to_parent_death()\n" .
            'open(' .
            var_export($pidFile, true) .
            ", 'w').write(str(os.getpid()))\n" .
            "time.sleep(120)\n";
        $php =
            'proc_close(proc_open(' .
            var_export(['/usr/bin/python3', '-I', '-B', '-c', $loader], true) .
            ', [["pipe","r"],["file","/dev/null","w"],["file","/dev/null","w"]], $pipes));';
        $wrapper = proc_open([PHP_BINARY, '-r', $php], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        self::assertIsResource($wrapper);
        fclose($pipes[0]);
        $deadline = microtime(true) + 10;
        while (!is_file($pidFile) && microtime(true) < $deadline) {
            usleep(50_000);
        }
        self::assertFileExists($pidFile);
        $childPid = (int) file_get_contents($pidFile);
        self::assertGreaterThan(1, $childPid);
        $child = RootHostTestPrerequisites::linuxProcessObservation($childPid);
        self::assertIsArray($child);
        posix_kill((int) proc_get_status($wrapper)['pid'], $this->sigkill);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($wrapper);
        $deadline = microtime(true) + 5;
        while (
            RootHostTestPrerequisites::originalProcessIsRunning(
                RootHostTestPrerequisites::linuxProcessObservation($childPid),
                $child['start_time'],
            ) &&
            microtime(true) < $deadline
        ) {
            usleep(50_000);
        }
        self::assertFalse(
            RootHostTestPrerequisites::originalProcessIsRunning(
                RootHostTestPrerequisites::linuxProcessObservation($childPid),
                $child['start_time'],
            ),
            'PDEATHSIG did not terminate the original Python helper process.',
        );
    }

    #[Group('requires-docker-host')]
    public function testPinnedContainerLeaseRemovesEarlyAndImportCrashThenAllowsRetry(): void
    {
        if (!$this->exactImageIsLocal()) {
            RootHostTestPrerequisites::enforce(
                $this,
                RootHostTestPrerequisites::classify(
                    false,
                    'docker_image_missing',
                    'The exact ROB-465 MariaDB image must be provisioned before the root suite.',
                ),
            );
        }
        foreach (['early' => '', 'import' => "SELECT SLEEP(30);\n"] as $phase => $delaySql) {
            $nonce = $phase === 'early' ? str_repeat('e', 32) : str_repeat('f', 32);
            $run = $this->root . '/.run-' . $nonce;
            mkdir($run, 0700);
            $dump = $this->writeRestoreFixture($run, $delaySql);
            [$process, $pipes] = $this->startRestore($run, $dump, $nonce);
            $name = 'fh-dump-attestation-' . $nonce;
            self::assertTrue($this->waitForContainer($name, true, 60), $phase . ' container never appeared');
            if ($phase === 'import') {
                self::assertTrue($this->waitForMariaDb($name, 90), 'MariaDB never became ready before import crash');
                usleep(500_000);
            }
            posix_kill((int) proc_get_status($process)['pid'], $this->sigkill);
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_close($process);
            self::assertTrue($this->waitForContainer($name, false, 45), $phase . ' leased container remained orphaned');
            self::assertSame(
                '',
                trim($this->docker(['volume', 'ls', '-q', '--filter', 'label=fh.dump-attestation=v1'])),
            );
        }

        $cleanup = $this->python(
            <<<'PY'
            import os
            module = load()
            fd = os.open(os.environ['ROB465_TEST_ROOT'], os.O_RDONLY | os.O_DIRECTORY | os.O_CLOEXEC)
            module.reconcile_files(fd, '.run-', module.RUN_RE, True)
            os.close(fd)
            PY
            ,
        );
        self::assertSame(0, $cleanup['exit'], $cleanup['stderr']);

        $nonce = str_repeat('1', 32);
        $run = $this->root . '/.run-' . $nonce;
        mkdir($run, 0700);
        $dump = $this->writeRestoreFixture($run, '');
        $retry = $this->python(
            str_replace(
                ['RUN_PATH', 'DUMP_PATH', 'NONCE'],
                [var_export($run, true), var_export($dump, true), var_export($nonce, true)],
                <<<'PY'
                import gzip
                import os
                module = load()
                path = DUMP_PATH
                fd = os.open(path, os.O_RDONLY | os.O_CLOEXEC | os.O_NOFOLLOW)
                with gzip.open(path, 'rb') as source:
                    unpacked = len(source.read())
                print(module.restore(fd, os.fstat(fd), unpacked, 5, RUN_PATH, NONCE))
                os.close(fd)
                PY
                ,
            ),
        );
        self::assertSame(0, $retry['exit'], $retry['stderr']);
        self::assertMatchesRegularExpression('/^\([1-9][0-9]*, [1-9][0-9]*\)\n$/', $retry['stdout']);
        self::assertTrue($this->waitForContainer('fh-dump-attestation-' . $nonce, false, 10));
        self::assertSame('', trim($this->docker(['volume', 'ls', '-q', '--filter', 'label=fh.dump-attestation=v1'])));
    }

    public function testContainerExitRecordIsExactStableAndRootProtected(): void
    {
        $result = $this->python(
            <<<'PY'
            import os
            module = load()
            root = os.environ['ROB465_TEST_ROOT']

            def make_run(nonce, value=b'0\n', mode=0o600, extra=False):
                run = os.path.join(root, '.run-' + nonce)
                os.mkdir(run, 0o700)
                target = os.path.join(run, 'container-exit')
                os.mkdir(target, 0o700)
                descriptor = os.open(os.path.join(target, 'exit'), os.O_WRONLY | os.O_CREAT | os.O_EXCL, mode)
                os.write(descriptor, value)
                os.close(descriptor)
                if extra:
                    descriptor = os.open(os.path.join(target, '.exit.tmp'),
                                         os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
                    os.close(descriptor)
                return run

            module.observe_container_exit(make_run('2' * 32))
            for nonce, value, mode, extra in (
                    ('3' * 32, b'137\n', 0o600, False),
                    ('4' * 32, b'0\n', 0o640, False),
                    ('5' * 32, b'0\n', 0o600, True)):
                try:
                    module.observe_container_exit(make_run(nonce, value, mode, extra))
                except SystemExit as error:
                    if error.code != 70:
                        raise
                else:
                    raise RuntimeError('unsafe container exit record accepted')
            PY
            ,
        );
        self::assertSame(0, $result['exit'], $result['stderr']);
    }

    private function requireDockerHost(): void
    {
        foreach (['dockerBinaryCheck', 'dockerSocketCheck', 'dockerDaemonCheck'] as $check) {
            RootHostTestPrerequisites::enforce($this, RootHostTestPrerequisites::$check());
        }
    }

    /** @return array{exit:int,stdout:string,stderr:string} */
    private function python(string $body): array
    {
        $loader =
            "import importlib.util\n" .
            "def load():\n" .
            "    spec=importlib.util.spec_from_file_location('rob465_helper', " .
            var_export($this->helper, true) .
            ")\n" .
            "    module=importlib.util.module_from_spec(spec)\n" .
            "    spec.loader.exec_module(module)\n" .
            "    return module\n";
        $pipes = [];
        $process = proc_open(
            ['/usr/bin/python3', '-I', '-B', '-c', $loader . $body],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
            null,
            ['ROB465_TEST_ROOT' => $this->root],
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['exit' => proc_close($process), 'stdout' => $stdout ?: '', 'stderr' => $stderr ?: ''];
    }

    private function exactImageIsLocal(): bool
    {
        $result = $this->command([
            '/usr/bin/docker',
            'image',
            'inspect',
            'mariadb@sha256:2f2b6bbcdbaf88afe53b76cb8d73927b623559180c5ab15db2049736f32ec590',
        ]);
        return $result['exit'] === 0;
    }

    private function writeRestoreFixture(string $run, string $delaySql): string
    {
        $sql =
            "USE easyappointments;\n" .
            "CREATE TABLE ea_appointments(id INT PRIMARY KEY);\n" .
            "CREATE TABLE ea_roles(id INT PRIMARY KEY);\n" .
            "CREATE TABLE ea_services(id INT PRIMARY KEY);\n" .
            "CREATE TABLE ea_settings(id INT PRIMARY KEY);\n" .
            "CREATE TABLE ea_users(id INT PRIMARY KEY);\n" .
            "CREATE TABLE engine_probe(id INT) ENGINE=InnoDB;\n" .
            $delaySql;
        $path = $run . '/fixture.sql.gz';
        $encoded = gzencode($sql, 9);
        self::assertIsString($encoded);
        self::assertSame(strlen($encoded), file_put_contents($path, $encoded));
        chmod($path, 0600);
        return $path;
    }

    /** @return array{resource,array<int,resource>} */
    private function startRestore(string $run, string $dump, string $nonce): array
    {
        $body = str_replace(
            ['RUN_PATH', 'DUMP_PATH', 'NONCE'],
            [var_export($run, true), var_export($dump, true), var_export($nonce, true)],
            <<<'PY'
            import gzip
            import os
            module = load()
            fd = os.open(DUMP_PATH, os.O_RDONLY | os.O_CLOEXEC | os.O_NOFOLLOW)
            with gzip.open(DUMP_PATH, 'rb') as source:
                unpacked = len(source.read())
            module.restore(fd, os.fstat(fd), unpacked, 6, RUN_PATH, NONCE)
            PY
            ,
        );
        $loader =
            "import importlib.util\n" .
            "def load():\n" .
            "    spec=importlib.util.spec_from_file_location('rob465_helper', " .
            var_export($this->helper, true) .
            ")\n" .
            "    module=importlib.util.module_from_spec(spec)\n" .
            "    spec.loader.exec_module(module)\n" .
            "    return module\n";
        $process = proc_open(
            ['/usr/bin/python3', '-I', '-B', '-c', $loader . $body],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
            null,
            [],
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        unset($pipes[0]);
        return [$process, $pipes];
    }

    private function waitForContainer(string $name, bool $present, int $seconds): bool
    {
        $deadline = microtime(true) + $seconds;
        do {
            $visible = trim($this->docker(['ps', '-q', '--filter', 'name=^/' . $name . '$'])) !== '';
            if ($visible === $present) {
                return true;
            }
            usleep(200_000);
        } while (microtime(true) < $deadline);
        return false;
    }

    private function waitForMariaDb(string $name, int $seconds): bool
    {
        $deadline = microtime(true) + $seconds;
        do {
            $result = $this->command([
                '/usr/bin/docker',
                'exec',
                $name,
                'mariadb',
                '-uroot',
                '--batch',
                '--skip-column-names',
                '-e',
                'SELECT IF(@@GLOBAL.skip_networking = 0, 1, 0);',
            ]);
            if ($result['exit'] === 0 && $result['stdout'] === "1\n") {
                return true;
            }
            usleep(500_000);
        } while (microtime(true) < $deadline);
        return false;
    }

    private function docker(array $arguments): string
    {
        $result = $this->command(array_merge(['/usr/bin/docker'], $arguments));
        self::assertSame(0, $result['exit'], $result['stderr']);
        return $result['stdout'];
    }

    /** @return array{exit:int,stdout:string,stderr:string} */
    private function command(array $arguments): array
    {
        $process = proc_open($arguments, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, null, []);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['exit' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);
            return;
        }
        foreach (scandir($path) ?: [] as $leaf) {
            if ($leaf !== '.' && $leaf !== '..') {
                $this->removeTree($path . '/' . $leaf);
            }
        }
        rmdir($path);
    }
}
