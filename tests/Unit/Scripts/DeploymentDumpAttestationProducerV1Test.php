<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use Ops\DeploymentDumpAttestationProducerV1;
use Ops\DeploymentDumpAttestationBusyV1;
use Ops\DeploymentEvidenceAuthorityV1;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__ . '/../../../scripts/ops/lib/DeploymentDumpAttestationProducerV1.php';

final class DeploymentDumpAttestationProducerV1Test extends TestCase
{
    public function testRestoreKeepsMariaDbSystemEngineAvailableWhileForcingApplicationInnoDb(): void
    {
        $helper = file_get_contents(dirname(__DIR__, 3) . '/scripts/ops/libexec/deployment_dump_attestation_v1.py');
        self::assertIsString($helper);
        self::assertStringContainsString('--enforce-storage-engine=InnoDB', $helper);
        self::assertStringContainsString('--sql-mode=NO_ENGINE_SUBSTITUTION', $helper);
        self::assertStringNotContainsString('--disabled-storage-engines=', $helper);
    }

    private string $helper;
    private string $cli;
    private string $helperPath;
    private string $sameServerBackup;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 3);
        $this->helperPath = $root . '/scripts/ops/libexec/deployment_dump_attestation_v1.py';
        $this->helper = file_get_contents($this->helperPath) ?: '';
        $this->cli = file_get_contents($root . '/scripts/ops/verify_deployment_dump_v1.php') ?: '';
        $this->sameServerBackup = file_get_contents($root . '/scripts/ops/prepare_same_server_rebuild_backup.sh') ?: '';
    }

    public function testClosedInputAndPinnedImageContract(): void
    {
        self::assertSame(
            DeploymentEvidenceAuthorityV1::DUMP_RESTORE_IMAGE,
            DeploymentDumpAttestationProducerV1::MARIADB_IMAGE,
        );
        self::assertStringContainsString("if (\$argc !== 2", $this->cli);
        self::assertStringNotContainsString('getopt(', $this->cli);
        self::assertStringContainsString("\$argv[1] === '--latest-handoff'", $this->cli);
        self::assertStringContainsString('produceLatestHandoff()', $this->cli);
        self::assertStringContainsString('deployment_dump_handoff_attestation_result.v1', $this->cli);
        self::assertStringContainsString("BACKUP_ROOT = '/root/backups/easyappointments'", $this->helper);
        self::assertStringContainsString(
            "IMAGE = '" . DeploymentEvidenceAuthorityV1::DUMP_RESTORE_IMAGE . "'",
            $this->helper,
        );
        self::assertStringContainsString("'--pull', 'never'", $this->helper);
        self::assertStringContainsString("'--network', 'none'", $this->helper);
        self::assertStringNotContainsString('os.environ.get', $this->helper);
        self::assertStringContainsString('process, docker_before = docker_popen(', $this->helper);
        self::assertStringContainsString('verify_docker_after(docker_before)', $this->helper);
        self::assertStringContainsString("latest_handoff = sys.argv[1] == '--latest-handoff'", $this->helper);
        self::assertStringContainsString('handoff = read_backup_handoff(backups)', $this->helper);
        self::assertStringContainsString('read_backup_success_marker(backups) != backup_id', $this->helper);
        self::assertStringContainsString('assert_handoff_matches(handoff, digest, size, unpacked)', $this->helper);
    }

    public function testBackupSetGrammarRejectsPathsAndInvalidOrFutureDatesBeforeHelperLaunch(): void
    {
        $method = new \ReflectionMethod(DeploymentDumpAttestationProducerV1::class, 'createdAt');
        $stale = gmdate('Ymd\\THis\\Z', time() - 14_400);
        foreach (['/tmp/dump.sql.gz', '19991231T235959Z', '20260230T120000Z', '29991231T235959Z', $stale] as $value) {
            try {
                $method->invoke(null, $value);
                self::fail('Unsafe backup-set authority was accepted: ' . $value);
            } catch (RuntimeException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testBusyHelperHasDedicatedRetryableExceptionType(): void
    {
        self::assertTrue(is_subclass_of(DeploymentDumpAttestationBusyV1::class, RuntimeException::class));
        self::assertStringContainsString('catch (DeploymentDumpAttestationBusyV1)', $this->cli);
        self::assertStringContainsString('exit(75)', $this->cli);
    }

    public function testHelperContainsStableFdBoundsDurabilityAndSharedLockContracts(): void
    {
        foreach (
            [
                'MAX_COMPRESSED = 16 * 1024 * 1024 * 1024',
                'MAX_UNCOMPRESSED = 64 * 1024 * 1024 * 1024',
                'MAX_RATIO = 100',
                'os.lseek(pinned, 0, os.SEEK_SET)',
                'LIBC.renameat2',
                'RENAME_NOREPLACE',
                'os.fsync(directory)',
                'fh-production-change.lock',
                'ea_appointments',
                'success_marker(backups',
            ]
            as $needle
        ) {
            self::assertStringContainsString($needle, $this->helper);
        }
    }

    public function testLeaseBoundContainerHasNoNamedVolumeAndSelfRemovesOnParentDeath(): void
    {
        self::assertStringContainsString('def reject_docker_orphans():', $this->helper);
        self::assertStringContainsString('label=fh.dump-attestation=v1', $this->helper);
        self::assertStringContainsString('fcntl.flock(lease_fd, fcntl.LOCK_EX | fcntl.LOCK_NB)', $this->helper);
        self::assertStringContainsString("'--rm'", $this->helper);
        self::assertStringContainsString("'--tmpfs', '/run/mysqld:rw,noexec,nosuid,size=16m'", $this->helper);
        self::assertStringContainsString("'--tmpfs', '/tmp:rw,noexec,nosuid,size=16m'", $this->helper);
        self::assertStringContainsString('target=/run/fh-lease,readonly', $this->helper);
        self::assertStringContainsString('flock -s /run/fh-lease', $this->helper);
        self::assertStringNotContainsString("docker(['volume', 'create'", $this->helper);
        self::assertStringNotContainsString("docker(['volume', 'rm'", $this->helper);
        self::assertStringNotContainsString("docker(['rm'", $this->helper);
    }

    public function testStreamingDumpGrammarAcceptsCanonicalDataAndRejectsBypasses(): void
    {
        $program = <<<'PY'
        import importlib.util
        import sys
        spec = importlib.util.spec_from_file_location('rob465_helper', sys.argv[1])
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        valid = b'''/*M!999999\\- enable the sandbox mode */
        -- forbidden words inside comments are inert
        /*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
        /*M!100616 SET @OLD_NOTE_VERBOSITY=@@NOTE_VERBOSITY, NOTE_VERBOSITY=0 */;
        DROP TABLE IF EXISTS `ea_x`;
        /*!40101 SET CHARACTER_SET_CLIENT=utf8mb4 */;
        CREATE TABLE `ea_x` (`v` text) ENGINE=InnoDB;
        /*!40101 SET CHARACTER_SET_CLIENT=utf8mb3 */;
        LOCK TABLES `ea_x` WRITE; ALTER TABLE `ea_x` DISABLE KEYS;
        INSERT INTO `ea_x` VALUES ('PREPARE x','CREATE TRIGGER'),('quote\\'d','x');
        ALTER TABLE `ea_x` ENABLE KEYS; UNLOCK TABLES;
        /*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
        /*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
        /*M!100616 SET NOTE_VERBOSITY=@OLD_NOTE_VERBOSITY */;'''
        inspector = module.DumpSqlInspector()
        for offset in range(0, len(valid), 3):
            inspector.feed(valid[offset:offset + 3])
        assert inspector.finish() == 1
        invalid = (
            b'CREATE TABLE x(id int) ENGINE=CSV;',
            b'/*!50000 CREATE TRIGGER x BEFORE INSERT ON t FOR EACH ROW SET @x=1 */;',
            b'/*M!50000 CREATE EVENT x ON SCHEDULE EVERY 1 DAY DO SELECT 1 */;',
            b'DELIMITER ;;', b'INSERT INTO x SELECT * FROM y;',
            b"CREATE TABLE x(id int); PREPARE x FROM 'SELECT 1';",
            b'CREATE TABLE x(id int) PARTITION BY HASH(id);', b'CREATE VIEW x AS SELECT 1;',
            b'CREATE TABLE x(id int) DATA DIRECTORY=\'/tmp\';', b"INSERT INTO x VALUES ('unterminated);",
            b'SET @anything=(SELECT SLEEP(30));', b'CREATE TABLE x LIKE y;',
            b'CREATE TABLE x(v text, FULLTEXT(v)) ENGINE=InnoDB;',
            b"SET SQL_MODE='', enforce_storage_engine='', default_storage_engine=Aria; CREATE TABLE x(id int);",
            b"SET SQL_MODE='NO_BACKSLASH_ESCAPES'; CREATE TABLE x(v text) ENGINE=InnoDB;",
            b'USE `mysql`; CREATE TABLE x(id int) ENGINE=InnoDB;',
            b'CREATE DATABASE `other`;',
            b'CREATE TABLE x(id int) ENGINE=InnoDB;',
            b'\\-\nCREATE TABLE x(id int) ENGINE=InnoDB;',
            b"/*M!999999\\- enable the sandbox mode */\nSET SQL_MODE='NO_BACKSLASH_ESCAPES'; "
            b"INSERT INTO `x` VALUES ('x\\'); CREATE TABLE `hidden` (`id` int) ENGINE=InnoDB; -- '\n",
        )
        for candidate in invalid:
            probe = module.DumpSqlInspector()
            try:
                if candidate not in (
                    b'CREATE TABLE x(id int) ENGINE=InnoDB;',
                    b'\\-\nCREATE TABLE x(id int) ENGINE=InnoDB;',
                ) and not candidate.startswith(b'/*M!999999'):
                    candidate = module.SANDBOX_PREAMBLES[0] + candidate
                for offset in range(0, len(candidate), 5):
                    probe.feed(candidate[offset:offset + 5])
                probe.finish()
            except SystemExit as error:
                assert error.code == 70
            else:
                raise AssertionError(candidate)
        boundary = module.DumpSqlInspector()
        boundary.feed(module.SANDBOX_PREAMBLES[0])
        for index in range(module.MAX_CREATE_TABLES):
            boundary.feed(('CREATE TABLE `t%d` (id int) ENGINE=InnoDB;' % index).encode('ascii'))
        assert boundary.finish() == module.MAX_CREATE_TABLES
        over = module.DumpSqlInspector()
        over.feed(module.SANDBOX_PREAMBLES[0])
        try:
            for index in range(module.MAX_CREATE_TABLES + 1):
                over.feed(('CREATE TABLE `t%d` (id int) ENGINE=InnoDB;' % index).encode('ascii'))
            over.finish()
        except SystemExit as error:
            assert error.code == 70
        else:
            raise AssertionError('table cap exceeded')
        PY;
        $process = proc_open(
            ['/usr/bin/python3', '-I', '-B', '-c', $program, $this->helperPath],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $stdout . $stderr);
    }

    public function testHistoricalTerminalRunRequiresCanonicalJournalBinding(): void
    {
        $program = <<<'PY'
        import copy, hashlib, importlib.util, sys
        spec = importlib.util.spec_from_file_location('rob465_helper', sys.argv[1])
        module = importlib.util.module_from_spec(spec); spec.loader.exec_module(module)
        run_id = '018f6f52-4c87-4d4e-8b19-6a66e6e1af25'
        fields = {'expected_commit':'a'*40,'release_id':'release-1','traffic_mode':'normal',
                  'dump_policy':'fresh_verified_under_240m','artifact_expectation':'build_from_expected_commit'}
        intent = hashlib.sha256(module.canonical(fields)[:-1]).hexdigest()
        records = [{'schema':'deployment_run.v1','record_type':'intent','run_id':run_id,'sequence':1,
                    'recorded_at_utc':'2026-08-13T10:00:00Z','state':'planned','deploy_invocation_count':0,
                    **fields,'intent_sha256':intent,'exit_code':0,'reason':'ok'}]
        states = module.PROGRESS_STATES[1:]
        previous = 'planned'
        for sequence, lifecycle in enumerate(states, 2):
            records.append({'schema':'deployment_run.v1','record_type':'transition','run_id':run_id,
                            'sequence':sequence,'recorded_at_utc':'2026-08-13T10:00:00Z',
                            'previous_state':previous,'state':lifecycle,
                            'deploy_invocation_count':int(lifecycle in {'deploy_running','post_gates_running','succeeded'}),
                            'intent_sha256':intent,'exit_code':0,'reason':'ok'})
            previous = lifecycle
        events = b''.join(module.canonical(record) for record in records)
        evidence = b'{}\n'
        module.validate_terminal_bundle_authority = lambda journal, payload: {
            'intent_sha256': intent, 'records': len(records), 'run_id': run_id,
            'schema': 'deployment_terminal_bundle_validation.v1', 'state': 'succeeded'}
        deploy = {key: None for key in module.DEPLOY_STATE_KEYS}
        deploy.update(invocation_count=1, unit_state='exited', observed_exit_code=0, receipt_sha256='d'*64,
                      request_sha256='e'*64, execution_input_sha256='f'*64, unit_launch_sha256='1'*64,
                      unit_name='fh-deploy-%s-%s.service' % (run_id, intent[:12]),
                      unit_manager_boot_id='11111111-1111-4111-8111-111111111111',
                      unit_invocation_id='2'*32)
        rollback = {key: None for key in module.ROLLBACK_STATE_KEYS}; rollback.update(invocation_count=0, unit_state='not_created', verdict='not_invoked')
        post = {key: None for key in module.POST_GATE_STATE_KEYS}
        post.update(deploy_submission_count=1, rollback_submission_count=0, deploy_report_sha256='2'*64,
                    deploy_verdict='passed', rollback_verdict='not_submitted')
        state = {'schema':'deployment_host_runner_state.v1','run_id':run_id,'intent_sha256':intent,
                 'state':'succeeded','sequence':len(records),'events_sha256':hashlib.sha256(events).hexdigest(),
                 'active_action':'none','deploy':deploy,'post_gates':post,'rollback':rollback,
                 'evidence_sha256':hashlib.sha256(evidence).hexdigest(),
                 'terminal':{'state':'succeeded','exit_code':0,'reason':'ok'},
                 'updated_at_utc':'2026-08-13T10:00:00Z'}
        module.validate_terminal_run(state, events, evidence, run_id)
        for mutation in ('terminal', 'event_time', 'event_hash', 'invented_rollback'):
            candidate = copy.deepcopy(state); journal = events
            if mutation == 'terminal':
                candidate['terminal'] = {'state':'succeeded','exit_code':99,'reason':'bogus'}
            elif mutation == 'event_time':
                changed = list(records); changed[-1] = dict(changed[-1]); changed[-1]['recorded_at_utc'] = 'not-a-time'
                journal = b''.join(module.canonical(record) for record in changed)
                candidate['events_sha256'] = hashlib.sha256(journal).hexdigest()
            else:
                if mutation == 'event_hash':
                    candidate['events_sha256'] = 'c' * 64
                else:
                    candidate['rollback']['invocation_count'] = 1
                    candidate['rollback']['unit_state'] = 'missing'
                    candidate['rollback']['unit_name'] = 'fh-rollback-%s-%s.service' % (run_id, intent[:12])
                    candidate['rollback']['unit_launch_sha256'] = '3'*64
                    candidate['rollback']['unit_manager_boot_id'] = '11111111-1111-4111-8111-111111111111'
                    candidate['rollback']['unit_missing_observed_boot_id'] = '22222222-2222-4222-8222-222222222222'
                    candidate['rollback']['request_sha256'] = '4'*64
                    candidate['rollback']['execution_input_sha256'] = '5'*64
                    candidate['rollback']['verdict'] = 'unknown'
            try:
                module.validate_terminal_run(candidate, journal, evidence, run_id)
            except SystemExit as error:
                assert error.code == 75
            else:
                raise AssertionError(mutation)
        PY;
        $process = proc_open(
            ['/usr/bin/python3', '-I', '-B', '-c', $program, $this->helperPath],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $stdout . $stderr);
    }

    public function testTerminalBundleValidatorUsesTheCanonicalDeploymentContract(): void
    {
        $root = dirname(__DIR__, 3);
        $validator = $root . '/scripts/ops/libexec/validate_deployment_terminal_bundle_v1.php';
        $events = file_get_contents($root . '/tests/Fixtures/deployment-contract-v1/failed-before-write.jsonl');
        $evidence = file_get_contents(
            $root . '/tests/Fixtures/deployment-contract-v1/failed-before-write-evidence.json',
        );
        self::assertIsString($events);
        self::assertIsString($evidence);
        $run = static function (string $payload) use ($validator): array {
            $process = proc_open([PHP_BINARY, '-n', $validator], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
            self::assertIsResource($process);
            fwrite($pipes[0], $payload);
            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]) ?: '';
            $stderr = stream_get_contents($pipes[2]) ?: '';
            fclose($pipes[1]);
            fclose($pipes[2]);
            return ['exit' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
        };
        $valid = $run(
            json_encode(
                [
                    'events' => base64_encode($events),
                    'evidence' => base64_encode($evidence),
                ],
                JSON_THROW_ON_ERROR,
            ),
        );
        self::assertSame(0, $valid['exit'], $valid['stderr']);
        self::assertStringContainsString('deployment_terminal_bundle_validation.v1', $valid['stdout']);

        $invalid = $run(
            json_encode(
                [
                    'events' => base64_encode($events),
                    'evidence' => base64_encode("{}\n"),
                ],
                JSON_THROW_ON_ERROR,
            ),
        );
        self::assertSame(70, $invalid['exit']);
        self::assertSame('', $invalid['stdout']);
    }

    public function testSameServerBackupDumpSourceMatchesClosedTableDataContract(): void
    {
        self::assertStringContainsString('--skip-triggers', $this->sameServerBackup);
        self::assertStringNotContainsString('--triggers', $this->sameServerBackup);
        self::assertStringNotContainsString('--routines', $this->sameServerBackup);
    }
}
