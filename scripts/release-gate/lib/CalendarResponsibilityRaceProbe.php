<?php

declare(strict_types=1);

namespace ReleaseGate;

use RuntimeException;
use Throwable;

final class UnconfirmedCalendarRequestTermination extends RuntimeException {}

/** Deterministic ROB-550 request-vs-reassignment schedule for owned synthetic rows. */
final class CalendarResponsibilityRaceProbe
{
    /** @var callable(?string):void */
    private $rememberSession;

    /** @var callable():void */
    private $retainRecovery;

    public function __construct(
        private readonly GateHttpClient $client,
        private readonly object $db,
        private readonly string $baseUrl,
        private readonly string $indexPage = 'index.php',
        private readonly string $csrfCookieName = 'csrf_cookie',
        private readonly string $csrfTokenName = 'csrf_token',
        ?callable $rememberSession = null,
        ?callable $retainRecovery = null,
    ) {
        $this->rememberSession = $rememberSession ?? static function (?string $session): void {};
        $this->retainRecovery =
            $retainRecovery ??
            static function (): void {
                throw new RuntimeException('Calendar race recovery callback is unavailable.');
            };
        $parts = parse_url($this->baseUrl);
        if (
            !is_array($parts) ||
            !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true) ||
            (string) ($parts['host'] ?? '') === '' ||
            str_contains($this->indexPage, '..')
        ) {
            throw new RuntimeException('Calendar race probe requires one fixed HTTP application origin.');
        }
    }

    /**
     * @param array{user_id:int,username:string,password:string,email:string,marker:string} $actor
     * @param array{profile:string,marker:string,appointment_id:int,foreign_provider_id:int,customer_id:int,service_id:int} $fixture
     * @return array{status:string,coverage:string,request_status:int,wait_observed:bool,reassignment_committed:bool,appointment_unchanged_except_provider:bool,observed:string}
     */
    public function run(array $actor, array $fixture, ?callable $observe = null): array
    {
        $this->assertContext($actor, $fixture);
        $observe ??= static function (string $phase, string $outcome): void {};
        $this->login($actor);

        $locker = $this->newConnection();
        $changer = $this->newConnection();
        $multi = null;
        $curl = null;
        $lockerActive = false;
        $changerActive = false;
        $requestRunning = false;
        $requestConnectionId = 0;
        $controlIds = [];
        $before = $this->appointmentSnapshot($fixture);
        $requestStatus = 0;
        $waitObserved = false;
        $reassignmentCommitted = false;
        $probeError = null;
        $cleanupError = null;

        try {
            $observe('race_parent_lock', 'started');
            if (!$locker->trans_begin()) {
                throw new RuntimeException('Calendar race parent-lock transaction could not start.');
            }
            $lockerActive = true;
            $usersTable = $locker->escape_identifiers($locker->dbprefix('users'));
            $locked = $locker
                ->query('SELECT id FROM ' . $usersTable . ' WHERE id = ? FOR UPDATE', [$before['id_users_customer']])
                ->row_array();
            if ((int) ($locked['id'] ?? 0) !== (int) $before['id_users_customer']) {
                throw new RuntimeException('Calendar race did not lock its request-specific customer parent.');
            }
            $observe('race_parent_lock', 'passed');

            $observe('race_request_wait', 'started');
            $preRequestProcessIds = $this->processIds($changer);
            [$multi, $curl] = $this->startAppointmentRequest($before);
            $requestRunning = true;
            $controlIds = [$this->connectionId($this->db), $this->connectionId($locker), $this->connectionId($changer)];
            $requestConnectionId = $this->waitForOwnedParentLock($multi, $changer, $controlIds, $preRequestProcessIds, [
                (int) $before['id_users_customer'],
                (int) $before['id_users_provider'],
            ]);
            $waitObserved = $requestConnectionId > 0;
            $observe('race_request_wait', 'passed');

            $observe('race_reassignment', 'started');
            if (!$changer->trans_begin()) {
                throw new RuntimeException('Calendar race responsibility-change transaction could not start.');
            }
            $changerActive = true;
            $updated = $changer->update(
                'appointments',
                ['id_users_provider' => $fixture['foreign_provider_id']],
                [
                    'id' => $fixture['appointment_id'],
                    'id_users_provider' => $actor['user_id'],
                    'id_users_customer' => $fixture['customer_id'],
                    'id_services' => $fixture['service_id'],
                    'notes' => $fixture['marker'],
                ],
            );
            if (!$updated || $changer->affected_rows() !== 1) {
                throw new RuntimeException(
                    'Owned synthetic responsibility change did not affect exactly one appointment.',
                );
            }
            if (!$changer->trans_commit()) {
                throw new RuntimeException('Calendar race responsibility-change transaction could not commit.');
            }
            $changerActive = false;
            $reassignmentCommitted = true;
            $observe('race_reassignment', 'passed');

            if (!$locker->trans_commit()) {
                throw new RuntimeException('Calendar race parent-lock transaction could not commit.');
            }
            $lockerActive = false;

            $observe('race_response', 'started');
            [$requestStatus, $body] = $this->finishAppointmentRequest($multi, $curl);
            $requestRunning = false;
            $multi = null;
            $curl = null;
            if ($requestStatus !== 403) {
                throw new RuntimeException('Concurrent synthetic appointment edit was not rejected with HTTP 403.');
            }
            $decoded = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($decoded) || ($decoded['success'] ?? null) !== false) {
                throw new RuntimeException('Concurrent synthetic appointment rejection response is invalid.');
            }
            $after = $this->appointmentSnapshot($fixture);
            $expectedAfter = $before;
            $expectedAfter['id_users_provider'] = $fixture['foreign_provider_id'];
            if ($after !== $expectedAfter) {
                $changedFields = [];
                foreach (array_unique([...array_keys($expectedAfter), ...array_keys($after)]) as $field) {
                    if (($expectedAfter[$field] ?? null) !== ($after[$field] ?? null)) {
                        $changedFields[] = (string) $field;
                    }
                }
                throw new RuntimeException(
                    'Concurrent synthetic appointment request produced a partial or unrelated write in fields: ' .
                        implode(', ', $changedFields) .
                        '.',
                );
            }
            $observe('race_response', 'passed');
        } catch (Throwable $error) {
            $probeError = $error;
        } finally {
            $requestTerminationConfirmed = true;
            if ($requestRunning && $multi !== null && $curl !== null) {
                try {
                    $this->terminateOwnedRequest(
                        $multi,
                        $curl,
                        $changer,
                        $controlIds,
                        $preRequestProcessIds,
                        [(int) $before['id_users_customer'], (int) $before['id_users_provider']],
                        $requestConnectionId,
                    );
                } catch (Throwable $error) {
                    $cleanupError ??=
                        $error instanceof UnconfirmedCalendarRequestTermination
                            ? $error
                            : new UnconfirmedCalendarRequestTermination(
                                'Owned calendar request termination failed before confirmation.',
                                previous: $error,
                            );
                    $requestTerminationConfirmed = false;
                    try {
                        ($this->retainRecovery)();
                    } catch (Throwable $recoveryError) {
                        $cleanupError = new UnconfirmedCalendarRequestTermination(
                            'Owned calendar request recovery state could not be fully retained.',
                            previous: $recoveryError,
                        );
                    }
                    try {
                        $this->closeRequestHandles($multi, $curl);
                    } catch (Throwable) {
                        // The durable recovery state, not local handle cleanup, is authoritative now.
                    }
                }
            }
            if ($lockerActive) {
                try {
                    $locker->trans_rollback();
                } catch (Throwable $error) {
                    $cleanupError ??= $error;
                }
            }
            if ($changerActive) {
                try {
                    $changer->trans_rollback();
                } catch (Throwable $error) {
                    $cleanupError ??= $error;
                }
            }
            if ($requestTerminationConfirmed) {
                try {
                    $this->restoreOwnedAppointment($before, $fixture);
                } catch (Throwable $error) {
                    $cleanupError ??= $error;
                }
            }
            foreach ([$locker, $changer] as $connection) {
                try {
                    $connection->close();
                } catch (Throwable $error) {
                    $cleanupError ??= $error;
                }
            }
            if ($requestTerminationConfirmed) {
                try {
                    $this->logout();
                } catch (Throwable $error) {
                    $cleanupError ??= $error;
                }
            }
        }

        if ($cleanupError instanceof UnconfirmedCalendarRequestTermination) {
            throw $cleanupError;
        }
        if ($probeError !== null) {
            throw $probeError;
        }
        if ($cleanupError !== null) {
            throw $cleanupError;
        }

        return [
            'status' => 'verified',
            'coverage' => 'targeted_concurrent_schedule',
            'request_status' => $requestStatus,
            'wait_observed' => $waitObserved,
            'reassignment_committed' => $reassignmentCommitted,
            'appointment_unchanged_except_provider' => true,
            'observed' =>
                'The real owned synthetic calendar request was observed waiting on its request-specific customer-parent lock; a separate connection committed the provider reassignment, after which the request was rejected without a partial appointment write.',
        ];
    }

    /** @param array<string,mixed> $actor @param array<string,mixed> $fixture */
    private function assertContext(array $actor, array $fixture): void
    {
        foreach (['user_id', 'username', 'password', 'email', 'marker'] as $key) {
            if (!isset($actor[$key]) || $actor[$key] === '' || ($key === 'user_id' && (int) $actor[$key] < 1)) {
                throw new RuntimeException('Calendar race actor context is incomplete.');
            }
        }
        if (($fixture['profile'] ?? null) !== 'calendar_race') {
            throw new RuntimeException('Calendar race fixture profile is invalid.');
        }
        foreach (['marker', 'appointment_id', 'foreign_provider_id', 'customer_id', 'service_id'] as $key) {
            if (
                !isset($fixture[$key]) ||
                $fixture[$key] === '' ||
                (str_ends_with($key, '_id') && (int) $fixture[$key] < 1)
            ) {
                throw new RuntimeException('Calendar race fixture context is incomplete.');
            }
        }
        $role = $this->db
            ->select('roles.slug')
            ->from('users')
            ->join('roles', 'roles.id = users.id_roles', 'inner')
            ->where('users.id', (int) $actor['user_id'])
            ->where('users.email', (string) $actor['email'])
            ->where('users.notes', (string) $actor['marker'])
            ->get()
            ->row_array();
        if (($role['slug'] ?? null) !== 'provider') {
            throw new RuntimeException('Calendar race actor is not the owned synthetic provider.');
        }
        $ids = [(int) $actor['user_id'], (int) $fixture['customer_id'], (int) $fixture['foreign_provider_id']];
        if ((int) $actor['user_id'] !== min($ids)) {
            throw new RuntimeException('Calendar race actor is not the deterministic first user-parent lock.');
        }
    }

    /** @param array<string,mixed> $actor */
    private function login(array $actor): void
    {
        $loginPage = $this->client->get('login');
        $this->remember();
        $this->expectStatus($loginPage, 200, 'calendar race login page');
        $login = $this->client->post('login/validate', [
            'username' => $actor['username'],
            'password' => $actor['password'],
        ]);
        $this->remember();
        $this->expectStatus($login, 200, 'calendar race login');
        $data = json_decode($login->body, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($data) || ($data['success'] ?? false) !== true) {
            throw new RuntimeException('Calendar race login failed.');
        }
    }

    private function logout(): void
    {
        $logout = $this->client->get('logout');
        $this->remember();
        $this->expectStatus($logout, 200, 'calendar race logout');
        $after = $this->client->get('calendar');
        $this->remember();
        if ($after->statusCode !== 307) {
            throw new RuntimeException('Calendar race session remained authenticated after logout.');
        }
    }

    /** @param array<string,mixed> $appointment @return array{mixed,mixed} */
    private function startAppointmentRequest(array $appointment): array
    {
        if (!function_exists('curl_multi_init')) {
            throw new RuntimeException('ext-curl multi support is required for the calendar race probe.');
        }
        $csrf = $this->client->getCookie($this->csrfCookieName);
        if (!is_string($csrf) || $csrf === '') {
            throw new RuntimeException('Calendar race login did not produce an owned CSRF cookie.');
        }
        $cookies = $this->client->cookies();
        if (($cookies['ea_session'] ?? '') === '') {
            throw new RuntimeException('Calendar race login did not produce an owned session cookie.');
        }
        $cookieHeader = implode(
            '; ',
            array_map(
                static fn(string $name, string $value): string => $name . '=' . $value,
                array_keys($cookies),
                array_values($cookies),
            ),
        );
        $requested = $appointment;
        $requested['start_datetime'] = date(
            'Y-m-d H:i:s',
            strtotime((string) $appointment['start_datetime'] . ' +1 hour'),
        );
        $requested['end_datetime'] = date('Y-m-d H:i:s', strtotime((string) $appointment['end_datetime'] . ' +1 hour'));
        $form = [
            $this->csrfTokenName => $csrf,
            'appointment_data' => $requested,
            'customer_data' => [],
        ];
        $url = rtrim($this->baseUrl, '/') . '/' . trim($this->indexPage, '/') . '/calendar/save_appointment';
        $curl = curl_init();
        if ($curl === false) {
            throw new RuntimeException('Calendar race request could not initialize cURL.');
        }
        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => http_build_query($form, '', '&', PHP_QUERY_RFC3986),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/x-www-form-urlencoded',
                'Cookie: ' . $cookieHeader,
                'X-FH-Ordinary-Probe: 1',
            ],
            CURLOPT_USERAGENT => 'defense-calendar-race-probe/1.0',
        ]);
        $multi = curl_multi_init();
        if (curl_multi_add_handle($multi, $curl) !== CURLM_OK) {
            curl_close($curl);
            curl_multi_close($multi);
            throw new RuntimeException('Calendar race request could not join its owned multi handle.');
        }
        do {
            $code = curl_multi_exec($multi, $running);
        } while ($code === CURLM_CALL_MULTI_PERFORM);
        if ($code !== CURLM_OK || $running < 1) {
            curl_multi_remove_handle($multi, $curl);
            curl_close($curl);
            curl_multi_close($multi);
            throw new RuntimeException('Calendar race request did not enter an asynchronous running state.');
        }

        return [$multi, $curl];
    }

    /** @param list<int> $controlIds @param list<int> $baselineIds @param list<int> $parentIds */
    private function waitForOwnedParentLock(
        object $multi,
        object $observer,
        array $controlIds,
        array $baselineIds,
        array $parentIds,
    ): int {
        $table = strtolower($observer->dbprefix('users'));
        $deadline = microtime(true) + 8;
        $candidateId = 0;
        $candidateFirstSeen = 0.0;
        do {
            do {
                $code = curl_multi_exec($multi, $running);
            } while ($code === CURLM_CALL_MULTI_PERFORM);
            if ($code !== CURLM_OK || $running < 1) {
                throw new RuntimeException(
                    'Calendar race request completed before the owned parent-lock wait was observed.',
                );
            }
            $id = $this->findOwnedParentLockProcess($observer, $controlIds, $baselineIds, $table, $parentIds);
            if ($id > 0) {
                if ($candidateId !== $id) {
                    $candidateId = $id;
                    $candidateFirstSeen = microtime(true);
                } elseif (microtime(true) - $candidateFirstSeen >= 0.15) {
                    // MariaDB may report an InnoDB row-lock wait as "Executing".
                    // The same exact owned query persisting while our transaction
                    // holds its request-specific customer is the wait signal.
                    return $id;
                }
            } else {
                $candidateId = 0;
                $candidateFirstSeen = 0.0;
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);

        throw new RuntimeException('Calendar race request wait could not be attributed to the owned user-parent lock.');
    }

    /** @param list<int> $controlIds */
    private function terminateOwnedRequest(
        object $multi,
        object $curl,
        object $observer,
        array $controlIds,
        array $baselineIds,
        array $parentIds,
        int $knownConnectionId,
    ): void {
        $table = strtolower($observer->dbprefix('users'));
        $connectionId = $knownConnectionId;
        $attributionDeadline = microtime(true) + 2;
        do {
            do {
                $code = curl_multi_exec($multi, $running);
            } while ($code === CURLM_CALL_MULTI_PERFORM);
            if ($code !== CURLM_OK) {
                throw new UnconfirmedCalendarRequestTermination(
                    'Owned calendar request termination could not be confirmed.',
                );
            }
            if ($connectionId < 1) {
                $connectionId = $this->findOwnedParentLockProcess(
                    $observer,
                    $controlIds,
                    $baselineIds,
                    $table,
                    $parentIds,
                );
            }
            if ($running === 0) {
                $transportError = curl_error($curl);
                $responseStatus = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
                if ($transportError === '' && $responseStatus > 0) {
                    $this->closeRequestHandles($multi, $curl);
                    return;
                }
                if ($connectionId < 1) {
                    throw new UnconfirmedCalendarRequestTermination(
                        'Owned calendar request transport ended without server completion evidence.',
                    );
                }
            }
            if ($connectionId > 0) {
                break;
            }
            usleep(50_000);
        } while (microtime(true) < $attributionDeadline);

        if ($connectionId < 1) {
            throw new UnconfirmedCalendarRequestTermination(
                'Owned calendar request remained active without exact database attribution.',
            );
        }

        $observer->query('KILL CONNECTION ' . $connectionId);
        $terminationDeadline = microtime(true) + 5;
        do {
            do {
                $code = curl_multi_exec($multi, $running);
            } while ($code === CURLM_CALL_MULTI_PERFORM);
            if ($code === CURLM_OK && $running === 0 && !$this->processExists($observer, $connectionId)) {
                $this->closeRequestHandles($multi, $curl);
                return;
            }
            usleep(50_000);
        } while (microtime(true) < $terminationDeadline);

        throw new UnconfirmedCalendarRequestTermination(
            'Owned calendar request did not terminate before the parent lock release boundary.',
        );
    }

    /** @param list<int> $controlIds @param list<int> $baselineIds @param list<int> $parentIds */
    private function findOwnedParentLockProcess(
        object $observer,
        array $controlIds,
        array $baselineIds,
        string $table,
        array $parentIds,
    ): int {
        return self::attributeConnectionId(
            $observer->query('SHOW FULL PROCESSLIST')->result_array(),
            $controlIds,
            $baselineIds,
            $table,
            $parentIds,
        );
    }

    /**
     * Attribute one request-created DB process to this probe's HTTP request.
     *
     * Processlist text has no HTTP request identity. Therefore a process is
     * eligible only when it is new since the request started and its query
     * contains the complete user-parent lock query fingerprint. Multiple eligible processes are
     * deliberately ambiguous and fail closed.
     *
     * @param list<array<string,mixed>> $processes
     * @param list<int> $controlIds
     * @param list<int> $baselineIds
     * @param list<int> $parentIds
     */
    public static function attributeConnectionId(
        array $processes,
        array $controlIds,
        array $baselineIds,
        string $table,
        array $parentIds,
    ): int {
        $expectedIds = array_values(
            array_unique(array_filter(array_map('intval', $parentIds), static fn(int $id): bool => $id > 0)),
        );
        sort($expectedIds, SORT_NUMERIC);
        if ($expectedIds === []) {
            return 0;
        }
        $candidates = [];
        foreach ($processes as $process) {
            $id = (int) ($process['Id'] ?? ($process['ID'] ?? 0));
            if ($id < 1 || in_array($id, $controlIds, true) || in_array($id, $baselineIds, true)) {
                continue;
            }
            $info = strtolower((string) ($process['Info'] ?? ''));
            $info = trim((string) preg_replace('/\s+/', ' ', str_replace('`', '', $info)));
            $expectedQuery = sprintf(
                'select id from %s where id in (%s) order by id asc for update',
                strtolower($table),
                implode(', ', $expectedIds),
            );
            if ($info === $expectedQuery) {
                $candidates[] = $id;
            }
        }

        return count($candidates) === 1 ? $candidates[0] : 0;
    }

    /** @return list<int> */
    private function processIds(object $observer): array
    {
        $ids = [];
        foreach ($observer->query('SHOW FULL PROCESSLIST')->result_array() as $process) {
            $id = (int) ($process['Id'] ?? ($process['ID'] ?? 0));
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    private function processExists(object $observer, int $connectionId): bool
    {
        foreach ($observer->query('SHOW FULL PROCESSLIST')->result_array() as $process) {
            if ((int) ($process['Id'] ?? ($process['ID'] ?? 0)) === $connectionId) {
                return true;
            }
        }
        return false;
    }

    private function closeRequestHandles(object $multi, object $curl): void
    {
        curl_multi_remove_handle($multi, $curl);
        curl_close($curl);
        curl_multi_close($multi);
    }

    /** @return array{int,string} */
    private function finishAppointmentRequest(object $multi, object $curl): array
    {
        $deadline = microtime(true) + 20;
        do {
            do {
                $code = curl_multi_exec($multi, $running);
            } while ($code === CURLM_CALL_MULTI_PERFORM);
            if ($code !== CURLM_OK) {
                throw new RuntimeException('Calendar race request multi execution failed.');
            }
            if ($running === 0) {
                break;
            }
            curl_multi_select($multi, 0.1);
        } while (microtime(true) < $deadline);
        if ($running !== 0) {
            throw new RuntimeException('Calendar race request did not finish after releasing the owned lock.');
        }
        $body = curl_multi_getcontent($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        if ($body === false || $error !== '') {
            throw new RuntimeException('Calendar race HTTP request failed.');
        }
        $this->closeRequestHandles($multi, $curl);

        return [$status, (string) $body];
    }

    /** @param array<string,mixed> $fixture @return array<string,mixed> */
    private function appointmentSnapshot(array $fixture): array
    {
        $row = $this->db
            ->get_where('appointments', [
                'id' => $fixture['appointment_id'],
                'id_users_customer' => $fixture['customer_id'],
                'id_services' => $fixture['service_id'],
                'notes' => $fixture['marker'],
            ])
            ->row_array();
        if (!is_array($row) || $row === []) {
            throw new RuntimeException('Owned calendar race appointment snapshot is unavailable.');
        }
        foreach (['id', 'id_users_provider', 'id_users_customer', 'id_services', 'id_parent_appointment'] as $field) {
            if (array_key_exists($field, $row) && $row[$field] !== null) {
                $row[$field] = (int) $row[$field];
            }
        }

        return $row;
    }

    /** @param array<string,mixed> $before @param array<string,mixed> $fixture */
    private function restoreOwnedAppointment(array $before, array $fixture): void
    {
        if (!$this->db->trans_begin()) {
            throw new RuntimeException('Owned calendar race appointment restoration transaction could not start.');
        }
        try {
            $userIds = array_values(
                array_unique(
                    array_filter(
                        array_map('intval', [
                            $before['id_users_customer'],
                            $before['id_users_provider'],
                            $fixture['foreign_provider_id'],
                        ]),
                        static fn(int $id): bool => $id > 0,
                    ),
                ),
            );
            sort($userIds, SORT_NUMERIC);
            $usersTable = $this->db->escape_identifiers($this->db->dbprefix('users'));
            $placeholders = implode(', ', array_fill(0, count($userIds), '?'));
            $lockedUsers = $this->db
                ->query(
                    'SELECT id FROM ' . $usersTable . ' WHERE id IN (' . $placeholders . ') ORDER BY id ASC FOR UPDATE',
                    $userIds,
                )
                ->result_array();
            if (count($lockedUsers) !== count($userIds)) {
                throw new RuntimeException('Owned calendar race appointment parent lock set is incomplete.');
            }

            $appointmentsTable = $this->db->escape_identifiers($this->db->dbprefix('appointments'));
            $current = $this->db
                ->query('SELECT * FROM ' . $appointmentsTable . ' WHERE id = ? FOR UPDATE', [
                    $fixture['appointment_id'],
                ])
                ->row_array();
            if (!is_array($current) || $current === []) {
                throw new RuntimeException('Owned calendar race appointment restoration row is unavailable.');
            }
            $current = $this->normalizeAppointment($current);
            $before = $this->normalizeAppointment($before);
            $foreignState = $before;
            $foreignState['id_users_provider'] = (int) $fixture['foreign_provider_id'];
            if ($current !== $before && $current !== $foreignState) {
                throw new RuntimeException(
                    'Owned calendar race appointment drifted beyond the permitted provider reassignment; refusing restoration.',
                );
            }
            if ($current === $foreignState) {
                if (
                    !$this->db->update(
                        'appointments',
                        ['id_users_provider' => (int) $before['id_users_provider']],
                        [
                            'id' => $fixture['appointment_id'],
                            'notes' => $fixture['marker'],
                            'id_users_provider' => $fixture['foreign_provider_id'],
                        ],
                    ) ||
                    $this->db->affected_rows() !== 1
                ) {
                    throw new RuntimeException('Owned calendar race appointment restoration failed.');
                }
            }
            $verified = $this->db
                ->query('SELECT * FROM ' . $appointmentsTable . ' WHERE id = ? FOR UPDATE', [
                    $fixture['appointment_id'],
                ])
                ->row_array();
            if (!is_array($verified) || $this->normalizeAppointment($verified) !== $before) {
                throw new RuntimeException('Owned calendar race appointment restoration was not verified.');
            }
            if (!$this->db->trans_commit()) {
                throw new RuntimeException('Owned calendar race appointment restoration transaction could not commit.');
            }
        } catch (Throwable $error) {
            $this->db->trans_rollback();
            throw $error;
        }
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function normalizeAppointment(array $row): array
    {
        foreach (['id', 'id_users_provider', 'id_users_customer', 'id_services', 'id_parent_appointment'] as $field) {
            if (array_key_exists($field, $row) && $row[$field] !== null) {
                $row[$field] = (int) $row[$field];
            }
        }

        return $row;
    }

    private function newConnection(): object
    {
        $ci = &\get_instance();
        $connection = $ci->load->database('', true);
        if (!is_object($connection) || $connection === $this->db) {
            throw new RuntimeException('Calendar race requires independent database connections.');
        }
        $connection->db_debug = false;

        return $connection;
    }

    private function connectionId(object $connection): int
    {
        $row = $connection->query('SELECT CONNECTION_ID() AS id')->row_array();
        $id = (int) ($row['id'] ?? 0);
        if ($id < 1) {
            throw new RuntimeException('Calendar race database connection identity is unavailable.');
        }

        return $id;
    }

    private function remember(): void
    {
        ($this->rememberSession)($this->client->getCookie('ea_session'));
    }

    private function expectStatus(GateHttpResponse $response, int $expected, string $operation): void
    {
        if ($response->statusCode !== $expected) {
            throw new RuntimeException($operation . ' returned an unexpected HTTP status.');
        }
    }
}
