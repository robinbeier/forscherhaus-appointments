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
    ) {}

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
}
