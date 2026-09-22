<?php

declare(strict_types=1);

namespace ReleaseGate;

use RuntimeException;

/** Bounded localhost proof for the Appointments API v1 write contract. */
final class AppointmentsApiWriteProbe
{
    public function __construct(
        private readonly GateHttpClient $basic,
        private readonly GateHttpClient $bearer,
        private readonly DefenseVerificationFixture $fixture,
        private readonly string $baseUrl = '',
        private readonly string $concurrentBaseUrl = '',
        private readonly string $indexPage = 'index.php',
        private readonly string $basicAuthorization = '',
        private readonly string $bearerAuthorization = '',
    ) {}

    public static function forApp(
        string $baseUrl,
        string $basicUsername,
        string $basicPassword,
        string $bearerToken,
        DefenseVerificationFixture $fixture,
        string $indexPage = 'index.php',
        string $csrfCookieName = 'csrf_cookie',
        string $csrfTokenName = 'csrf_token',
        ?string $concurrentBaseUrl = null,
    ): self {
        if ($basicUsername === '' || $basicPassword === '' || $bearerToken === '') {
            throw new RuntimeException('Appointments API probe credentials are unavailable.');
        }
        $basicAuthorization = 'Basic ' . base64_encode($basicUsername . ':' . $basicPassword);
        $bearerAuthorization = 'Bearer ' . $bearerToken;
        $client = static fn(string $authorization): GateHttpClient => new GateHttpClient(
            $baseUrl,
            indexPage: $indexPage,
            csrfCookieName: $csrfCookieName,
            csrfTokenName: $csrfTokenName,
            additionalHeaders: ['X-FH-Ordinary-Probe' => '1', 'Authorization' => $authorization],
        );
        return new self(
            $client($basicAuthorization),
            $client($bearerAuthorization),
            $fixture,
            $baseUrl,
            $concurrentBaseUrl ?? $baseUrl,
            $indexPage,
            $basicAuthorization,
            $bearerAuthorization,
        );
    }

    public function run(?callable $observe = null): array
    {
        $observe ??= static function (string $phase, string $outcome): void {};
        $clients = ['basic' => $this->basic, 'bearer' => $this->bearer];
        $created = [];
        $statuses = [];
        $sentinel = $this->fixture->apiSnapshot()['sentinel'];
        if ($sentinel === []) {
            throw new RuntimeException('Appointments API sentinel snapshot is unavailable.');
        }

        foreach ($clients as $case => $client) {
            $phase = 'appointments_api_' . $case . '_create';
            $observe($phase, 'started');
            try {
                $payload = $this->fixture->prepareApiAppointment($case);
                $response = $client->requestJsonApp('POST', 'api/v1/appointments', $payload);
                $this->expectStatus($response, 201, $phase);
                $body = $this->decodeObject($response);
                $id = (int) ($body['id'] ?? 0);
                if ($id < 1) {
                    throw new RuntimeException('Appointments API create returned no ID.');
                }
                $this->assertFields($body, $payload);
                $this->fixture->confirmApiAppointmentCreated($case, $id);
                $this->assertSentinel($sentinel);
                $created[$case] = ['id' => $id, 'payload' => $payload];
                $statuses[$case]['post'] = 201;
                $observe($phase, 'passed');
            } catch (\Throwable $error) {
                $observe($phase, 'failed');
                throw $error;
            }
        }

        $observe('appointments_api_denials', 'started');
        try {
            $denialCases = $this->runDenials($created);
            $observe('appointments_api_denials', 'passed');
        } catch (\Throwable $error) {
            $observe('appointments_api_denials', 'failed');
            throw $error;
        }

        foreach ($clients as $case => $client) {
            $phase = 'appointments_api_' . $case . '_update';
            $observe($phase, 'started');
            try {
                $other = $case === 'basic' ? 'bearer' : 'basic';
                $beforeOther = $this->fixture->apiSnapshot()[$other];
                $payload = $this->fixture->prepareApiAppointmentUpdate($case);
                $response = $client->requestJsonApp('PUT', 'api/v1/appointments/' . $created[$case]['id'], $payload);
                $this->expectStatus($response, 200, $phase);
                $body = $this->decodeObject($response);
                if ((int) ($body['id'] ?? 0) !== $created[$case]['id']) {
                    throw new RuntimeException('Appointments API PUT escaped its URI target.');
                }
                $this->assertFields($body, $payload);
                $this->fixture->confirmApiAppointmentUpdated($case);
                $this->assertSentinel($sentinel);
                if ($this->fixture->apiSnapshot()[$other] !== $beforeOther) {
                    throw new RuntimeException('Appointments API PUT changed the other owned appointment.');
                }
                $statuses[$case]['put'] = 200;
                $observe($phase, 'passed');
            } catch (\Throwable $error) {
                $observe($phase, 'failed');
                throw $error;
            }
        }

        foreach ($clients as $case => $client) {
            $phase = 'appointments_api_' . $case . '_delete';
            $observe($phase, 'started');
            try {
                $other = $case === 'basic' ? 'bearer' : 'basic';
                $beforeOther = $this->fixture->apiSnapshot()[$other];
                $id = $this->fixture->prepareApiAppointmentDelete($case);
                $response = $this->fixture->guardApiAppointmentDelete(
                    $case,
                    static fn(): GateHttpResponse => $client->requestApp('DELETE', 'api/v1/appointments/' . $id),
                );
                $this->expectStatus($response, 204, $phase);
                $this->fixture->confirmApiAppointmentDeleted($case);
                $this->assertSentinel($sentinel);
                $repeat = $client->requestApp('DELETE', 'api/v1/appointments/' . $id);
                $this->expectStatus($repeat, 404, $phase . ' repeat');
                if ($this->fixture->apiSnapshot()[$other] !== $beforeOther) {
                    throw new RuntimeException('Appointments API DELETE changed the other owned appointment.');
                }
                $statuses[$case] += ['delete' => 204, 'delete_repeat' => 404];
                $observe($phase, 'passed');
            } catch (\Throwable $error) {
                $observe($phase, 'failed');
                throw $error;
            }
        }

        $snapshot = $this->fixture->apiSnapshot();
        if ($snapshot['basic'] !== [] || $snapshot['bearer'] !== [] || $snapshot['sentinel'] !== $sentinel) {
            throw new RuntimeException('Appointments API bounded deletion postcondition failed.');
        }

        return [
            'status' => 'verified',
            'coverage' => 'complete',
            'auth_statuses' => $statuses,
            'denial_cases' => $denialCases,
            'observed' =>
                'Basic and Bearer POST, PUT and idempotent DELETE succeeded for exact owned appointments. Direct persistence, URI binding and hash continuity were checked; the complete redacted sentinel snapshot remained exactly equal after every successful mutation; invalid methods, aliases, content types, JSON values and fields remained mutation-free.',
        ];
    }

    /** Bounded proof for adjacency, auth parity and a parallel client-dispatch outcome. */
    public function runOverlap(?callable $observe = null): array
    {
        if (
            $this->baseUrl === '' ||
            $this->concurrentBaseUrl === '' ||
            $this->basicAuthorization === '' ||
            $this->bearerAuthorization === ''
        ) {
            throw new RuntimeException('Appointments API overlap probe HTTP context is unavailable.');
        }

        $observe ??= static function (string $phase, string $outcome): void {};
        $sentinel = $this->fixture->apiSnapshot()['sentinel'];
        if ($sentinel === []) {
            throw new RuntimeException('Appointments API sentinel snapshot is unavailable.');
        }

        $observe('appointments_api_overlap_adjacency', 'started');
        try {
            $basicPayload = $this->fixture->prepareApiAppointment('basic');
            $basicResponse = $this->basic->requestJsonApp('POST', 'api/v1/appointments', $basicPayload);
            $this->expectStatus($basicResponse, 201, 'Appointments API overlap baseline');
            $basicBody = $this->decodeObject($basicResponse);
            $basicId = (int) ($basicBody['id'] ?? 0);
            $this->fixture->confirmApiAppointmentCreated('basic', $basicId);

            $adjacentStart = new \DateTimeImmutable($basicPayload['end']);
            $adjacentEnd = $adjacentStart->add(new \DateInterval('PT30M'));
            $bearerPayload = $this->fixture->prepareApiAppointment('bearer', [
                'start' => $adjacentStart->format('Y-m-d H:i:s'),
                'end' => $adjacentEnd->format('Y-m-d H:i:s'),
            ]);
            $bearerResponse = $this->bearer->requestJsonApp('POST', 'api/v1/appointments', $bearerPayload);
            $this->expectStatus($bearerResponse, 201, 'Appointments API adjacent create');
            $bearerBody = $this->decodeObject($bearerResponse);
            $bearerId = (int) ($bearerBody['id'] ?? 0);
            $this->fixture->confirmApiAppointmentCreated('bearer', $bearerId);
            $this->assertSentinel($sentinel);
            $observe('appointments_api_overlap_adjacency', 'passed');
        } catch (\Throwable $error) {
            $observe('appointments_api_overlap_adjacency', 'failed');
            throw $error;
        }

        $observe('appointments_api_overlap_auth_denials', 'started');
        try {
            $basicAttemptsBearer = $this->fixture->prepareApiAppointmentUpdate('bearer', [
                'start' => $basicPayload['start'],
                'end' => $basicPayload['end'],
            ]);
            $response = $this->basic->requestJsonApp('PUT', 'api/v1/appointments/' . $bearerId, $basicAttemptsBearer);
            $this->expectStatus($response, 409, 'Basic overlap update');
            $this->fixture->confirmApiAppointmentUnchanged('bearer');

            $bearerAttemptsBasic = $this->fixture->prepareApiAppointmentUpdate('basic', [
                'start' => $bearerPayload['start'],
                'end' => $bearerPayload['end'],
            ]);
            $response = $this->bearer->requestJsonApp('PUT', 'api/v1/appointments/' . $basicId, $bearerAttemptsBasic);
            $this->expectStatus($response, 409, 'Bearer overlap update');
            $this->fixture->confirmApiAppointmentUnchanged('basic');
            $this->assertSentinel($sentinel);
            $observe('appointments_api_overlap_auth_denials', 'passed');
        } catch (\Throwable $error) {
            $observe('appointments_api_overlap_auth_denials', 'failed');
            throw $error;
        }

        $observe('appointments_api_overlap_parallel_dispatch', 'started');
        try {
            $raceStart = $adjacentStart->add(new \DateInterval('P2D'));
            $raceEnd = $raceStart->add(new \DateInterval('PT30M'));
            $window = [
                'start' => $raceStart->format('Y-m-d H:i:s'),
                'end' => $raceEnd->format('Y-m-d H:i:s'),
            ];
            $racePayloads = [
                'basic' => $this->fixture->prepareApiAppointmentUpdate('basic', $window),
                'bearer' => $this->fixture->prepareApiAppointmentUpdate('bearer', $window),
            ];
            $responses = $this->parallelUpdates([
                [
                    'base_url' => $this->baseUrl,
                    'authorization' => $this->basicAuthorization,
                    'appointment_id' => $basicId,
                    'payload' => $racePayloads['basic'],
                ],
                [
                    'base_url' => $this->concurrentBaseUrl,
                    'authorization' => $this->bearerAuthorization,
                    'appointment_id' => $bearerId,
                    'payload' => $racePayloads['bearer'],
                ],
            ]);
            $statuses = array_column($responses, 'status');
            sort($statuses, SORT_NUMERIC);
            if ($statuses !== [200, 409]) {
                throw new RuntimeException('Parallel Appointments API overlap requests did not produce one commit.');
            }
            foreach (['basic', 'bearer'] as $index => $case) {
                if ($responses[$index]['status'] === 200) {
                    $this->fixture->confirmApiAppointmentUpdated($case);
                } else {
                    $this->fixture->confirmApiAppointmentUnchanged($case);
                }
            }
            $snapshot = $this->fixture->apiSnapshot();
            $raceMatches = 0;
            foreach (['basic', 'bearer'] as $case) {
                if (
                    ($snapshot[$case]['start_datetime'] ?? null) === $window['start'] &&
                    ($snapshot[$case]['end_datetime'] ?? null) === $window['end']
                ) {
                    $raceMatches++;
                }
            }
            if ($raceMatches !== 1 || $snapshot['sentinel'] !== $sentinel) {
                throw new RuntimeException('Parallel Appointments API overlap persistence is invalid.');
            }
            $observe('appointments_api_overlap_parallel_dispatch', 'passed');
        } catch (\Throwable $error) {
            $observe('appointments_api_overlap_parallel_dispatch', 'failed');
            throw $error;
        }

        return [
            'status' => 'verified',
            'coverage' => 'bounded_overlap',
            'auth_statuses' => [
                'basic_overlap' => 409,
                'bearer_overlap' => 409,
                'adjacent_post' => 201,
                'parallel_put' => $statuses,
            ],
            'observed' =>
                'Basic and Bearer overlap updates were rejected, direct adjacency was accepted, and two parallel client-dispatched owned PUT requests produced exactly one commit and one conflict. This production result does not claim observed server-side request overlap. The complete redacted sentinel snapshot remained unchanged.',
        ];
    }

    private function runDenials(array $created): int
    {
        $baseline = $this->fixture->apiSnapshot();
        $cases = 0;
        $target = $created['basic']['id'];

        $response = $this->basic->requestRawApp(
            'POST',
            'api/v1/appointments/' . $target,
            json_encode($created['basic']['payload'], JSON_THROW_ON_ERROR),
            'application/json',
        );
        $this->expectStatus($response, 404, 'POST appointment detail');
        $this->assertUnchanged($baseline);
        $cases++;

        foreach (
            [
                ['GET', 'api/v1/appointments_api_v1/store', 'POST'],
                ['GET', 'api/v1/appointments_api_v1/update/' . $target, 'PUT'],
                ['GET', 'api/v1/appointments_api_v1/destroy/' . $target, 'DELETE'],
                ['POST', 'api/v1/appointments_api_v1/update/' . $target, 'PUT'],
                ['PUT', 'api/v1/appointments_api_v1/destroy/' . $target, 'DELETE'],
                ['DELETE', 'api/v1/appointments_api_v1/store', 'POST'],
            ]
            as [$method, $path, $allow]
        ) {
            $response = $this->basic->requestApp($method, $path);
            $this->expectStatus($response, 405, 'Appointments API natural route alias');
            if (strtoupper((string) $response->header('allow')) !== $allow) {
                throw new RuntimeException('Appointments API alias returned an invalid Allow header.');
            }
            $this->assertUnchanged($baseline);
            $cases++;
        }

        $validJson = json_encode($created['basic']['payload'], JSON_THROW_ON_ERROR);
        foreach (['text/plain', ''] as $contentType) {
            $response = $this->basic->requestRawApp('PUT', 'api/v1/appointments/' . $target, $validJson, $contentType);
            $this->expectStatus($response, 415, 'Appointments API content type');
            $this->assertUnchanged($baseline);
            $cases++;
        }

        foreach (['', '{bad}', '1', '[1]', '{}'] as $raw) {
            $response = $this->basic->requestRawApp('PUT', 'api/v1/appointments/' . $target, $raw, 'application/json');
            $this->expectStatus($response, 400, 'Appointments API JSON shape');
            $this->assertUnchanged($baseline);
            $cases++;
        }

        foreach (
            [
                'id' => $created['bearer']['id'],
                'hash' => 'caller-controlled',
                'book' => 'caller-controlled',
                'googleCalendarId' => 'caller-controlled',
                'caldavCalendarId' => 'caller-controlled',
                'parentAppointmentId' => $created['bearer']['id'],
                'unexpected' => 'caller-controlled',
            ]
            as $field => $value
        ) {
            $payload = $created['basic']['payload'];
            $payload[$field] = $value;
            $response = $this->basic->requestJsonApp('PUT', 'api/v1/appointments/' . $target, $payload);
            $this->expectStatus($response, 400, 'Appointments API unsupported field');
            $this->assertUnchanged($baseline);
            $cases++;
        }
        return $cases;
    }

    private function assertUnchanged(array $before): void
    {
        if ($this->fixture->apiSnapshot() !== $before) {
            throw new RuntimeException('Rejected Appointments API request changed the owned graph.');
        }
    }

    private function assertSentinel(array $expected): void
    {
        if ($this->fixture->apiSnapshot()['sentinel'] !== $expected) {
            throw new RuntimeException('Appointments API mutation changed the complete redacted sentinel snapshot.');
        }
    }

    private function decodeObject(GateHttpResponse $response): array
    {
        $body = json_decode($response->body, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($body) || array_is_list($body)) {
            throw new RuntimeException('Appointments API response is not an object.');
        }
        return $body;
    }

    private function assertFields(array $actual, array $expected): void
    {
        foreach (
            ['start', 'end', 'location', 'color', 'status', 'notes', 'customerId', 'providerId', 'serviceId']
            as $field
        ) {
            if (($actual[$field] ?? null) != $expected[$field]) {
                throw new RuntimeException('Appointments API response did not persist the intended fields.');
            }
        }
    }

    private function expectStatus(GateHttpResponse $response, int $expected, string $operation): void
    {
        if ($response->statusCode !== $expected) {
            throw new RuntimeException($operation . ' returned an unexpected HTTP status.');
        }
    }

    /**
     * @param list<array{base_url:string,authorization:string,appointment_id:int,payload:array<string,mixed>}> $requests
     * @return list<array{status:int,body:string}>
     */
    private function parallelUpdates(array $requests): array
    {
        if (!function_exists('curl_multi_init')) {
            throw new RuntimeException('ext-curl multi support is required for the overlap probe.');
        }
        $multi = curl_multi_init();
        $handles = [];
        $prefix = trim($this->indexPage, '/');
        $prefix = $prefix === '' ? '' : $prefix . '/';

        foreach ($requests as $index => $request) {
            $url =
                rtrim($request['base_url'], '/') . '/' . $prefix . 'api/v1/appointments/' . $request['appointment_id'];
            $handle = curl_init($url);
            if ($handle === false) {
                throw new RuntimeException('Could not initialize parallel Appointments API request.');
            }
            curl_setopt_array($handle, [
                CURLOPT_CUSTOMREQUEST => 'PUT',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json',
                    'Authorization: ' . $request['authorization'],
                    'Content-Type: application/json',
                    'X-FH-Ordinary-Probe: 1',
                ],
                CURLOPT_POSTFIELDS => json_encode($request['payload'], JSON_THROW_ON_ERROR),
                CURLOPT_TIMEOUT => 15,
                CURLOPT_CONNECTTIMEOUT => 5,
            ]);
            curl_multi_add_handle($multi, $handle);
            $handles[$index] = $handle;
        }

        try {
            do {
                $status = curl_multi_exec($multi, $running);
                if ($status !== CURLM_OK) {
                    throw new RuntimeException('Parallel Appointments API execution failed.');
                }
                if ($running > 0) {
                    curl_multi_select($multi, 1.0);
                }
            } while ($running > 0);

            $responses = [];
            foreach ($handles as $handle) {
                $body = curl_multi_getcontent($handle);
                if ($body === false) {
                    throw new RuntimeException('Parallel Appointments API response is unavailable.');
                }
                $responses[] = [
                    'status' => (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
                    'body' => $body,
                ];
            }
            return $responses;
        } finally {
            foreach ($handles as $handle) {
                curl_multi_remove_handle($multi, $handle);
                curl_close($handle);
            }
            curl_multi_close($multi);
        }
    }
}
