<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReleaseGate\OrdinaryProbeEvidence;
use ReleaseGate\OrdinaryProbeSessions;
use Tests\Integration\Support\OrdinaryJournalSyncFault;

require_once __DIR__ . '/Support/OrdinaryJournalSyncFault.php';
require_once dirname(__DIR__, 2) . '/scripts/release-gate/lib/OrdinaryProbeEvidence.php';
require_once dirname(__DIR__, 2) . '/scripts/release-gate/lib/OrdinaryProbeSessions.php';
require_once dirname(__DIR__, 2) . '/scripts/release-gate/lib/GateHttpClient.php';
require_once dirname(__DIR__, 2) . '/scripts/release-gate/lib/CalendarMethodProbe.php';

final class OrdinaryProbeEvidenceTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        if (getenv('FH_DEFENSE_ISOLATED') !== '1' || !is_file('/.dockerenv') || posix_geteuid() !== 0) {
            self::markTestSkipped('Requires explicitly isolated root Docker runner.');
        }
        $this->directory = '/var/lib/fh-evidence-test-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        mkdir($this->directory . '/sessions', 0700);
    }

    protected function tearDown(): void
    {
        OrdinaryJournalSyncFault::disable();
        if (!isset($this->directory)) {
            return;
        }
        foreach (glob($this->directory . '/sessions/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($this->directory . '/sessions');
        foreach (glob($this->directory . '/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($this->directory);
    }

    public function testCalendarMethodProbeFitsTheBoundedPersistentJournal(): void
    {
        $evidence = new OrdinaryProbeEvidence($this->directory);
        $evidence->begin('ea_synthetic');
        foreach (['activate', 'verify', 'supplemental_activate'] as $setup) {
            $evidence->step($setup, 'started');
            $evidence->step($setup, 'passed');
        }
        $requests = 0;
        $remembered = 0;
        $probe = new \ReleaseGate\CalendarMethodProbe(
            static function (string $method, string $path) use (&$requests): \ReleaseGate\GateHttpResponse {
                $requests++;
                $alias = str_starts_with($path, 'backend_api/');
                $target = str_replace('backend_api/ajax_', 'calendar/', explode('?', $path, 2)[0]);
                return new \ReleaseGate\GateHttpResponse(
                    $alias ? ($method === 'POST' ? 303 : 302) : 405,
                    $alias ? ['location' => ['/index.php/' . $target]] : ['allow' => ['POST']],
                    '',
                    0.0,
                    'http://localhost/index.php/' . $path,
                );
            },
            static function (): void {},
            static function (): void {},
            static fn(): array => [
                'appointment_id' => 41,
                'provider_id' => 17,
                'customer_id' => 23,
                'marker' => 'owned',
            ],
            static fn(): array => ['owned' => ['id' => 41]],
            static function () use (&$remembered): void {
                $remembered++;
            },
        );

        self::assertSame('verified', $probe->run($evidence->step(...))['status']);
        $evidence->step('verify', 'started');
        $evidence->step('verify', 'passed');
        $evidence->step('deactivate', 'started');
        $evidence->step('deactivate', 'passed');
        self::assertSame(30, $requests);
        self::assertSame(30, $remembered);
        self::assertCount(34, $evidence->read()['events']);
    }

    public function testCalendarMethodProbeRecordsEveryResponseAndClosesAfterJournalFailure(): void
    {
        $order = [];
        $sessions = new OrdinaryProbeSessions($this->directory, $this->directory . '/sessions');
        $cookie = str_repeat('a', 22);
        $probe = new \ReleaseGate\CalendarMethodProbe(
            function (string $method, string $path) use ($cookie, &$order): \ReleaseGate\GateHttpResponse {
                $order[] = 'request';
                file_put_contents($this->directory . '/sessions/ea_session' . $cookie, 'synthetic');
                return new \ReleaseGate\GateHttpResponse(
                    405,
                    ['allow' => ['POST']],
                    '',
                    0.0,
                    'http://localhost/index.php/' . $path,
                );
            },
            function () use (&$order, $cookie): void {
                $order[] = 'login';
                file_put_contents($this->directory . '/sessions/ea_session' . $cookie, 'authenticated');
            },
            static function () use (&$order): void {
                $order[] = 'close';
            },
            static fn(): array => [
                'appointment_id' => 41,
                'provider_id' => 17,
                'customer_id' => 23,
                'marker' => 'owned',
            ],
            static fn(): array => ['owned' => ['id' => 41]],
            function () use (&$order, $sessions, $cookie): void {
                $order[] = 'remember';
                OrdinaryJournalSyncFault::failFile($this->directory . '/sessions.json.tmp');
                $sessions->remember($cookie);
            },
        );

        try {
            $probe->run();
            self::fail('A journal failure must propagate.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('persist private session journal', strtolower($error->getMessage()));
        }
        self::assertSame(['login', 'request', 'remember', 'close'], $order);
    }

    public function testProductionCloseOrderAttemptsLogoutBeforeItsJournalCanFail(): void
    {
        $sessions = new OrdinaryProbeSessions($this->directory, $this->directory . '/sessions');
        $cookie = str_repeat('b', 22);
        $path = $this->directory . '/sessions/ea_session' . $cookie;
        file_put_contents($path, 'authenticated');
        $sessions->remember($cookie);
        $order = ['login'];
        OrdinaryJournalSyncFault::failFile($this->directory . '/sessions.json.tmp');
        try {
            \ReleaseGate\CalendarMethodProbe::closeSession(
                static function () use (&$order): \ReleaseGate\GateHttpResponse {
                    $order[] = 'logout';
                    return new \ReleaseGate\GateHttpResponse(200, [], '', 0.0, 'http://localhost/logout');
                },
                static function () use (&$order, $sessions, $cookie): void {
                    $order[] = 'close_remember';
                    $sessions->remember($cookie);
                },
                static function () use (&$order): \ReleaseGate\GateHttpResponse {
                    $order[] = 'after_logout';
                    return new \ReleaseGate\GateHttpResponse(307, [], '', 0.0, 'http://localhost/account');
                },
            );
            self::fail('The injected close journal failure must propagate.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('persist private session journal', strtolower($error->getMessage()));
        }
        self::assertSame(['login', 'logout', 'close_remember'], $order);
    }

    public function testProductionAuthenticateOrderJournalsSuccessfulLoginResponse(): void
    {
        $order = [];
        $remembered = 0;
        try {
            \ReleaseGate\CalendarMethodProbe::authenticateSession(
                static function () use (&$order): \ReleaseGate\GateHttpResponse {
                    $order[] = 'login_page';
                    return new \ReleaseGate\GateHttpResponse(200, [], '', 0.0, 'http://localhost/login');
                },
                static function (string $username, string $password) use (&$order): \ReleaseGate\GateHttpResponse {
                    $order[] = 'login';
                    return new \ReleaseGate\GateHttpResponse(
                        200,
                        [],
                        '{"success":true}',
                        0.0,
                        'http://localhost/login/validate',
                    );
                },
                static function () use (&$order, &$remembered): void {
                    $order[] = 'remember';
                    if (++$remembered === 2) {
                        throw new RuntimeException('synthetic login journal failure');
                    }
                },
                ['username' => 'synthetic', 'password' => 'secret'],
            );
            self::fail('The login response journal failure must propagate.');
        } catch (RuntimeException $error) {
            self::assertSame('synthetic login journal failure', $error->getMessage());
        }
        self::assertSame(['login_page', 'remember', 'login', 'remember'], $order);
    }

    public function testCalendarMethodSnapshotUsesOwnedQueriesAndHashesOnlyAppointmentSecret(): void
    {
        $db = new class {
            private string $table = '';
            private string $select = '*';
            private array $rows = [
                'appointments' => [
                    ['id' => 40, 'id_users_provider' => 99, 'hash' => 'unrelated'],
                    ['id' => 41, 'id_users_provider' => 17, 'hash' => 'secret'],
                ],
                'user_settings' => [['id_users' => 17, 'timezone' => 'Europe/Berlin']],
                'users' => [
                    ['id' => 17, 'notes' => 'provider'],
                    ['id' => 23, 'notes' => 'owned'],
                    ['id' => 99, 'notes' => 'unrelated'],
                ],
                'services' => [['id' => 31, 'description' => 'owned'], ['id' => 32, 'description' => 'unrelated']],
                'services_providers' => [
                    ['id_users' => 17, 'id_services' => 31],
                    ['id_users' => 99, 'id_services' => 32],
                ],
            ];
            public function get_where(string $table, array $where): self
            {
                $this->table = $table;
                $this->where = $where;
                return $this;
            }
            public function order_by(string $column, string $direction): self
            {
                return $this;
            }
            public function select(string $columns): self
            {
                $this->select = $columns;
                return $this;
            }
            public function row_array(): array
            {
                return $this->result_array()[0] ?? [];
            }
            public function result_array(): array
            {
                $rows = array_values(
                    array_filter($this->rows[$this->table] ?? [], function (array $row): bool {
                        foreach ($this->where as $key => $value) {
                            if (($row[$key] ?? null) !== $value) {
                                return false;
                            }
                        }
                        return true;
                    }),
                );
                if ($this->select === 'id') {
                    $rows = array_map(static fn(array $row): array => ['id' => $row['id']], $rows);
                }
                $this->select = '*';
                return $rows;
            }
            private array $where = [];
        };
        $result = \ReleaseGate\CalendarMethodProbe::snapshotOwnedRows($db, [
            'actor_id' => 17,
            'marker' => 'owned',
            'ids' => ['appointment' => 41, 'calendar_customer' => 23, 'service' => 31],
        ]);
        self::assertSame(hash('sha256', 'secret'), $result['owned']['appointment']['hash']);
        self::assertSame(
            [['id' => 41, 'id_users_provider' => 17, 'hash' => 'secret']],
            $result['owned']['provider_appointments'],
        );
        self::assertSame([['id' => 23]], $result['owned']['marker_users']);
        self::assertSame([['id' => 31]], $result['owned']['marker_services']);
        self::assertSame([['id_users' => 17, 'id_services' => 31]], $result['owned']['service_relationships']);
    }

    public function testReceiptPrecedesJournalRetirementAndPreservesFailureStep(): void
    {
        $evidence = new OrdinaryProbeEvidence($this->directory);
        $evidence->begin('ea_synthetic');
        $evidence->step('logout', 'started');
        $evidence->step('logout', 'failed');
        $sessions = new OrdinaryProbeSessions($this->directory, $this->directory . '/sessions');
        $cookie = bin2hex(random_bytes(16));
        $path = $this->directory . '/sessions/ea_session' . $cookie;
        file_put_contents($path, 'synthetic');
        $sessions->remember($cookie);
        $sessions->cleanup(function (int $tracked, int $removed, int $absent) use ($evidence): void {
            self::assertFileExists($this->directory . '/sessions.json');
            $evidence->cleaned($tracked, $removed, $absent);
        });
        self::assertFileDoesNotExist($path);
        self::assertFileDoesNotExist($this->directory . '/sessions.json');
        $receipt = $evidence->read();
        self::assertSame('failed', $receipt['events'][1]['outcome']);
        self::assertSame(1, $receipt['cleanup']['tracked']);
        self::assertSame(1, $receipt['cleanup']['removed']);
        self::assertSame(0, $receipt['cleanup']['remaining']);
        self::assertStringNotContainsString($cookie, file_get_contents($this->directory . '/last-evidence.json'));
        $sessions->cleanup($evidence->cleaned(...));
        self::assertSame($receipt, $evidence->read(), 'Idempotent cleanup must retain the original receipt.');
    }

    public function testFailedReceiptPublicationRetainsPrivateJournalForRetry(): void
    {
        $evidence = new OrdinaryProbeEvidence($this->directory);
        $evidence->begin('ea_synthetic');
        $sessions = new OrdinaryProbeSessions($this->directory, $this->directory . '/sessions');
        $cookie = bin2hex(random_bytes(16));
        $path = $this->directory . '/sessions/ea_session' . $cookie;
        file_put_contents($path, 'synthetic');
        $sessions->remember($cookie);
        file_put_contents($this->directory . '/last-evidence.json.tmp', 'incomplete synthetic receipt');
        $failed = false;
        try {
            $sessions->cleanup($evidence->cleaned(...));
        } catch (RuntimeException) {
            $failed = true;
        }
        self::assertTrue($failed);
        self::assertFileDoesNotExist($path);
        self::assertFileExists($this->directory . '/sessions.json');
        self::assertNull($evidence->read()['cleanup']);
        unlink($this->directory . '/last-evidence.json.tmp');
        $sessions->cleanup($evidence->cleaned(...));
        self::assertSame(1, $evidence->read()['cleanup']['already_absent']);
        self::assertFileDoesNotExist($this->directory . '/sessions.json');
    }

    public function testHandledTemporaryWriteFailureAllowsCleanupRetry(): void
    {
        $evidence = new OrdinaryProbeEvidence($this->directory);
        $evidence->begin('ea_synthetic');
        $before = $evidence->read();
        $sessions = new OrdinaryProbeSessions($this->directory, $this->directory . '/sessions');
        $cookie = bin2hex(random_bytes(16));
        $path = $this->directory . '/sessions/ea_session' . $cookie;
        file_put_contents($path, 'synthetic');
        $sessions->remember($cookie);
        OrdinaryJournalSyncFault::failFile($this->directory . '/last-evidence.json.tmp');
        try {
            $sessions->cleanup($evidence->cleaned(...));
            self::fail('Receipt fsync failure must propagate.');
        } catch (RuntimeException) {
            self::assertFileDoesNotExist($path);
            self::assertFileDoesNotExist($this->directory . '/last-evidence.json.tmp');
            self::assertFileExists($this->directory . '/sessions.json');
            self::assertSame($before, $evidence->read());
        } finally {
            OrdinaryJournalSyncFault::disable();
        }
        $sessions->cleanup($evidence->cleaned(...));
        self::assertSame(1, $evidence->read()['cleanup']['already_absent']);
        self::assertFileDoesNotExist($this->directory . '/sessions.json');
    }

    public function testMissingReceiptRetainsPrivateJournal(): void
    {
        $evidence = new OrdinaryProbeEvidence($this->directory);
        $sessions = new OrdinaryProbeSessions($this->directory, $this->directory . '/sessions');
        $cookie = bin2hex(random_bytes(16));
        $path = $this->directory . '/sessions/ea_session' . $cookie;
        file_put_contents($path, 'synthetic');
        $sessions->remember($cookie);
        try {
            $sessions->cleanup($evidence->cleaned(...));
            self::fail('Missing receipt must prevent journal retirement.');
        } catch (RuntimeException) {
            self::assertFileDoesNotExist($path);
            self::assertFileExists($this->directory . '/sessions.json');
            self::assertFileDoesNotExist($this->directory . '/last-evidence.json');
        }
    }

    public static function recordedPhases(): array
    {
        return [['session'], ['activate'], ['deactivate'], ['verify']];
    }

    #[DataProvider('recordedPhases')]
    public function testHandledFailureIsDistinctFromAnInterruptedOperation(string $phase): void
    {
        $evidence = new OrdinaryProbeEvidence($this->directory);
        $evidence->begin('ea_synthetic');
        $error = new RuntimeException('private synthetic failure detail');
        try {
            $evidence->run($phase, static function () use ($evidence, $error): void {
                $evidence->step('waiting', 'started');
                throw $error;
            });
            self::fail('Original operation failure must propagate.');
        } catch (RuntimeException $caught) {
            self::assertSame($error, $caught);
        }
        $receipt = $evidence->read();
        self::assertSame(['started', 'started', 'failed'], array_column($receipt['events'], 'outcome'));
        self::assertSame([$phase, 'waiting', $phase], array_column($receipt['events'], 'phase'));
        self::assertStringNotContainsString($error->getMessage(), json_encode($receipt));
        self::assertSame('synthetic result', $evidence->run('session', static fn() => 'synthetic result'));
        self::assertSame('passed', $evidence->read()['events'][4]['outcome']);
    }

    public function testCleanupAttemptsRevocationDespiteDiagnosticFailure(): void
    {
        $evidence = new OrdinaryProbeEvidence($this->directory);
        $evidence->begin('ea_synthetic');
        file_put_contents($this->directory . '/last-evidence.json.tmp', 'pre-existing recovery evidence');
        $attempted = false;
        $original = new RuntimeException('synthetic ownership ambiguity');
        try {
            $evidence->run(
                'deactivate',
                static function () use (&$attempted, $original): void {
                    $attempted = true;
                    throw $original;
                },
                alwaysAttempt: true,
            );
            self::fail('The revoke failure must be propagated.');
        } catch (RuntimeException $error) {
            self::assertSame($original, $error);
        }
        self::assertTrue($attempted);
        self::assertNull($evidence->read()['cleanup']);
        self::assertSame(
            'pre-existing recovery evidence',
            file_get_contents($this->directory . '/last-evidence.json.tmp'),
        );
    }

    public function testCompletedReceiptSurvivesRetryWithNonemptyJournal(): void
    {
        $evidence = new OrdinaryProbeEvidence($this->directory);
        $evidence->begin('ea_synthetic');
        $sessions = new OrdinaryProbeSessions($this->directory, $this->directory . '/sessions');
        $cookie = bin2hex(random_bytes(16));
        file_put_contents($this->directory . '/sessions/ea_session' . $cookie, 'synthetic');
        $sessions->remember($cookie);
        try {
            $sessions->cleanup(static function (int $tracked, int $removed, int $absent) use ($evidence): void {
                $evidence->cleaned($tracked, $removed, $absent);
                throw new RuntimeException('stop before journal retirement');
            });
            self::fail('The synthetic interruption must retain the journal.');
        } catch (RuntimeException) {
            self::assertFileExists($this->directory . '/sessions.json');
        }
        $first = $evidence->read();
        self::assertSame(1, $first['cleanup']['removed']);
        $sessions->cleanup($evidence->cleaned(...));
        self::assertSame($first, $evidence->read());
        self::assertFileDoesNotExist($this->directory . '/sessions.json');
    }

    public function testSaturatedHistoryDoesNotPreventSuccessfulCleanup(): void
    {
        $evidence = new OrdinaryProbeEvidence($this->directory);
        $evidence->begin('ea_synthetic');
        for ($i = 0; $i < 32; $i++) {
            $evidence->step('deactivate', 'started');
            $evidence->step('deactivate', 'failed');
        }
        $history = $evidence->read()['events'];
        $result = $evidence->run(
            'deactivate',
            static function () use ($evidence): string {
                $evidence->cleaned(0, 0, 0);
                return 'clean';
            },
            alwaysAttempt: true,
        );
        self::assertSame('clean', $result);
        $receipt = $evidence->read();
        self::assertSame($history, $receipt['events']);
        self::assertTrue($receipt['cleanup_events_omitted']);
        self::assertSame(0, $receipt['cleanup']['remaining']);
        self::assertSame('clean', $evidence->run('deactivate', static fn() => 'clean', alwaysAttempt: true));
        self::assertSame($receipt, $evidence->read());
        self::expectException(RuntimeException::class);
        $evidence->step('session', 'started');
    }

    public function testFailedPostconditionRemainsVisibleAfterSuccessfulProbe(): void
    {
        $evidence = new OrdinaryProbeEvidence($this->directory);
        $evidence->begin('ea_synthetic');
        $evidence->step('post_save', 'passed');
        $failure = new RuntimeException('synthetic postcondition mismatch');
        try {
            $evidence->run('verify', static function () use ($failure): void {
                throw $failure;
            });
            self::fail('A failed postcondition must prevent success.');
        } catch (RuntimeException $error) {
            self::assertSame($failure, $error);
        }
        $events = $evidence->read()['events'];
        self::assertSame(['post_save', 'verify', 'verify'], array_column($events, 'phase'));
        self::assertSame(['passed', 'started', 'failed'], array_column($events, 'outcome'));
    }

    public function testOnlyFixedDiagnosticCodesAreAccepted(): void
    {
        $evidence = new OrdinaryProbeEvidence($this->directory);
        $evidence->begin('ea_synthetic');
        try {
            $evidence->step('an arbitrary exception or response', 'failed');
            self::fail('Dynamic diagnostics must not be persisted.');
        } catch (RuntimeException) {
            self::assertSame([], $evidence->read()['events']);
        }
        chmod($this->directory . '/last-evidence.json', 0644);
        self::expectException(RuntimeException::class);
        $evidence->read();
    }
}
