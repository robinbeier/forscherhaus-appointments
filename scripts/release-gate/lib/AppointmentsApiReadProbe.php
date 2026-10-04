<?php

declare(strict_types=1);

namespace ReleaseGate;

use RuntimeException;

/** Bounded HTTP proof for the Appointments API v1 read projection. */
final class AppointmentsApiReadProbe
{
    /** @var callable(?string):void */
    private readonly mixed $rememberSession;

    public function __construct(
        private readonly GateHttpClient $client,
        private readonly DefenseVerificationFixture $fixture,
        ?callable $rememberSession = null,
    ) {
        $this->rememberSession = $rememberSession ?? static function (?string $session): void {};
    }

    public static function forApp(
        string $baseUrl,
        string $username,
        string $password,
        DefenseVerificationFixture $fixture,
        string $indexPage = 'index.php',
        ?callable $rememberSession = null,
    ): self {
        if ($username === '' || $password === '') {
            throw new RuntimeException('Appointments API read probe credentials are unavailable.');
        }
        return new self(
            new GateHttpClient(
                $baseUrl,
                indexPage: $indexPage,
                additionalHeaders: [
                    'X-FH-Ordinary-Probe' => '1',
                    'Authorization' => 'Basic ' . base64_encode($username . ':' . $password),
                ],
            ),
            $fixture,
            $rememberSession,
        );
    }

    /** @return array<string,mixed> */
    public function run(?callable $observe = null): array
    {
        $observe ??= static function (string $phase, string $outcome): void {};
        $state = $this->fixture->read();
        if (($state['profile'] ?? null) !== 'calendar_race') {
            throw new RuntimeException('Appointments API read verification requires the active calendar-race graph.');
        }
        $appointmentId = (int) ($state['ids']['appointment'] ?? 0);
        $providerId = (int) ($state['actor_id'] ?? 0);
        $customerId = (int) ($state['ids']['calendar_customer'] ?? 0);
        $serviceId = (int) ($state['ids']['service'] ?? 0);
        if ($appointmentId < 1 || $providerId < 1 || $customerId < 1 || $serviceId < 1) {
            throw new RuntimeException('Appointments API read targets are unavailable.');
        }
        $this->assertSyntheticGraphPrivate($providerId, $serviceId);
        $before = $this->fixture->apiSnapshot();
        if ((int) ($before['sentinel']['id'] ?? 0) !== $appointmentId) {
            throw new RuntimeException('Appointments API read sentinel does not match the owned appointment.');
        }

        $with = 'provider,customer,service';
        $routes = [
            'collection' => ['api/v1/appointments', ['q' => (string) $state['marker'], 'with' => $with]],
            'collection_alias' => [
                'api/v1/appointments_api_v1/index',
                ['q' => (string) $state['marker'], 'with' => $with],
            ],
            'show' => ['api/v1/appointments/' . $appointmentId, ['with' => $with]],
            'show_alias' => ['api/v1/appointments_api_v1/show/' . $appointmentId, ['with' => $with]],
            'fields_show' => ['api/v1/appointments/' . $appointmentId, ['fields' => 'id', 'with' => $with]],
            'fields_collection' => [
                'api/v1/appointments',
                ['q' => (string) $state['marker'], 'fields' => 'id', 'with' => $with],
            ],
        ];
        $statuses = [];
        foreach ($routes as $name => [$path, $query]) {
            $this->phase($observe, 'appointments_api_read_' . $name, function () use (
                $name,
                $path,
                $query,
                $appointmentId,
                $providerId,
                $customerId,
                $serviceId,
                &$statuses,
            ): void {
                $response = $this->client->get($path, $query);
                ($this->rememberSession)($this->client->getCookie('ea_session'));
                if ($response->statusCode !== 200) {
                    throw new RuntimeException('Appointments API read returned an unexpected status.');
                }
                $decoded = $this->decode($response->body);
                $row =
                    $name === 'collection' || $name === 'collection_alias' || $name === 'fields_collection'
                        ? $this->findCollectionRow($decoded, $appointmentId)
                        : $decoded;
                $fieldsOnly = str_starts_with($name, 'fields_');
                $this->assertAppointmentProjection(
                    $row,
                    $appointmentId,
                    $providerId,
                    $customerId,
                    $serviceId,
                    $fieldsOnly,
                );
                $statuses[$name] = $response->statusCode;
            });
        }
        $after = $this->fixture->apiSnapshot();
        if ($after !== $before) {
            throw new RuntimeException('Appointments API read changed an owned fixture row.');
        }
        return [
            'status' => 'verified',
            'coverage' => 'collection_show_alias_relations_fields',
            'statuses' => $statuses,
            'public_booking_option' => 'absent',
            'observed' =>
                'Canonical and direct read aliases returned the owned appointment with bounded relation projections and id-only field selection; the synthetic private service remained excluded from public booking and the fixture snapshot was unchanged.',
        ];
    }

    private function assertSyntheticGraphPrivate(int $providerId, int $serviceId): void
    {
        $db = \get_instance()->db;
        $provider = $db->get_where('users', ['id' => $providerId])->row_array();
        $service = $db->get_where('services', ['id' => $serviceId])->row_array();
        if ((int) ($provider['is_private'] ?? 0) !== 1 || (int) ($service['is_private'] ?? 0) !== 1) {
            throw new RuntimeException('Synthetic Appointments API graph is not private.');
        }
        $ci = &\get_instance();
        $ci->load->model('services_model');
        try {
            $publicServices = $ci->services_model->get_available_services(true);
        } catch (\Throwable $error) {
            throw new RuntimeException('Public service selection failed closed.', 0, $error);
        }
        if (!is_array($publicServices)) {
            throw new RuntimeException('Public service selection returned malformed data.');
        }
        foreach ($publicServices as $row) {
            if (!is_array($row) || !isset($row['id'])) {
                throw new RuntimeException('Public service selection returned malformed data.');
            }
            if ((int) $row['id'] === $serviceId) {
                throw new RuntimeException('Synthetic private service appeared in public booking options.');
            }
        }
    }

    private function phase(callable $observe, string $phase, callable $operation): void
    {
        $observe($phase, 'started');
        try {
            $operation();
            $observe($phase, 'passed');
        } catch (\Throwable $error) {
            $observe($phase, 'failed');
            throw $error;
        }
    }

    /** @return array<string,mixed>|list<mixed> */
    private function decode(string $body): array
    {
        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $error) {
            throw new RuntimeException('Appointments API read returned invalid JSON.', 0, $error);
        }
        if (!is_array($decoded)) {
            throw new RuntimeException('Appointments API read returned malformed JSON.');
        }
        return $decoded;
    }

    /** @param array<string,mixed>|list<mixed> $decoded @return array<string,mixed> */
    private function findCollectionRow(array $decoded, int $id): array
    {
        if (!array_is_list($decoded)) {
            throw new RuntimeException('Appointments API collection returned an object.');
        }
        if (count($decoded) !== 1 || !is_array($decoded[0]) || (int) ($decoded[0]['id'] ?? 0) !== $id) {
            throw new RuntimeException('Appointments API collection was not limited to the owned row.');
        }
        return $decoded[0];
    }

    /** @param array<string,mixed> $row */
    private function assertAppointmentProjection(
        array $row,
        int $appointmentId,
        int $providerId,
        int $customerId,
        int $serviceId,
        bool $fieldsOnly,
    ): void {
        if ((int) ($row['id'] ?? 0) !== $appointmentId) {
            throw new RuntimeException('Appointments API read returned an unexpected owned row.');
        }
        if (
            !$fieldsOnly &&
            ((int) ($row['providerId'] ?? 0) !== $providerId ||
                (int) ($row['customerId'] ?? 0) !== $customerId ||
                (int) ($row['serviceId'] ?? 0) !== $serviceId)
        ) {
            throw new RuntimeException('Appointments API read returned an unexpected owned relationship.');
        }
        if (
            $fieldsOnly &&
            (array_diff(array_keys($row), ['id', 'provider', 'customer', 'service']) !== [] ||
                array_diff(['id', 'provider', 'customer', 'service'], array_keys($row)) !== [])
        ) {
            throw new RuntimeException('Appointments API fields=id returned an unexpected top-level field.');
        }
        $relationIds = ['provider' => $providerId, 'customer' => $customerId, 'service' => $serviceId];
        foreach ($relationIds as $relation => $relationId) {
            if (!is_array($row[$relation] ?? null) || !isset($row[$relation]['id'])) {
                throw new RuntimeException('Appointments API read relation projection is missing.');
            }
            if ((int) $row[$relation]['id'] !== $relationId) {
                throw new RuntimeException('Appointments API read relation target is incorrect.');
            }
            $allowed = match ($relation) {
                'provider' => [
                    'address',
                    'city',
                    'email',
                    'firstName',
                    'id',
                    'isPrivate',
                    'language',
                    'lastName',
                    'ldapDn',
                    'mobile',
                    'notes',
                    'phone',
                    'state',
                    'timezone',
                    'zip',
                ],
                'customer' => [
                    'address',
                    'city',
                    'customField1',
                    'customField2',
                    'customField3',
                    'customField4',
                    'customField5',
                    'email',
                    'firstName',
                    'id',
                    'language',
                    'lastName',
                    'ldapDn',
                    'notes',
                    'phone',
                    'timezone',
                    'zip',
                ],
                default => [
                    'attendantsNumber',
                    'availabilitiesType',
                    'bufferAfter',
                    'bufferBefore',
                    'currency',
                    'description',
                    'duration',
                    'id',
                    'isPrivate',
                    'location',
                    'name',
                    'price',
                    'serviceCategoryId',
                ],
            };
            if (
                array_diff(array_keys($row[$relation]), $allowed) !== [] ||
                array_diff($allowed, array_keys($row[$relation])) !== []
            ) {
                throw new RuntimeException('Appointments API read returned an unexpected relation projection.');
            }
        }
    }
}
