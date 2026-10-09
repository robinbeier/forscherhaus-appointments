<?php

declare(strict_types=1);

namespace ReleaseGate;

use RuntimeException;
use Throwable;

final class CalendarCrossTypeRequestUnconfirmed extends RuntimeException {}

/** One bounded proof that classic calendar writes cannot cross appointment types. */
final class CalendarCrossTypeLiveProbe
{
    public function __construct(
        private readonly GateHttpClient $client,
        private readonly GateHttpClient $publicClient,
        private readonly OrdinaryLiveFixture $actor,
        private readonly DefenseVerificationFixture $fixture,
        private readonly mixed $rememberSession = null,
        private readonly mixed $retainRecovery = null,
    ) {}

    public function run(?callable $observe = null): array
    {
        $observe ??= static function (string $phase, string $outcome): void {};
        $state = $this->fixture->read();
        $actor = $this->actor->read();
        if (($state['profile'] ?? null) !== 'unavailabilities_api' || ($actor['role_slug'] ?? null) !== 'admin') {
            throw new RuntimeException(
                'Calendar cross-type proof requires its owned admin and unavailabilities graph.',
            );
        }
        $rows = $this->fixture->unavailabilitiesApiSnapshot();
        $provider = (int) ($state['ids']['provider_target'] ?? 0);
        $customer = (int) ($state['ids']['calendar_customer'] ?? 0);
        $service = (int) ($state['ids']['service'] ?? 0);
        $manual = $rows['a'] ?? [];
        if (
            $provider < 1 ||
            $customer < 1 ||
            $service < 1 ||
            (int) ($manual['id'] ?? 0) < 1 ||
            (int) ($manual['is_unavailability'] ?? 0) !== 1 ||
            (int) ($rows['ordinary']['is_unavailability'] ?? 1) !== 0
        ) {
            throw new RuntimeException('Owned cross-type targets are unavailable.');
        }
        $this->assertPublicServiceExcluded($service, (string) $state['marker']);
        $before = $this->snapshot($provider);
        $this->authenticate($actor);
        try {
            $start = date('Y-m-d H:i:s', strtotime((string) $rows['ordinary']['start_datetime']) + 7200);
            $end = date('Y-m-d H:i:s', strtotime((string) $rows['ordinary']['end_datetime']) + 7200);
            $this->phase($observe, 'calendar_cross_type_create_denial', function () use (
                $start,
                $end,
                $provider,
                $customer,
                $service,
                $state,
                $before,
            ): void {
                $this->deny(
                    'calendar/save_appointment',
                    [
                        'appointment_data' => [
                            'start_datetime' => $start,
                            'end_datetime' => $end,
                            'notes' => $state['marker'] . ':cross-type:create',
                            'id_users_provider' => $provider,
                            'id_users_customer' => $customer,
                            'id_services' => $service,
                            'is_unavailability' => true,
                        ],
                        'customer_data' => [],
                    ],
                    $before,
                    $provider,
                );
            });
            $this->phase($observe, 'calendar_cross_type_update_denial', function () use (
                $manual,
                $provider,
                $customer,
                $service,
                $state,
                $before,
            ): void {
                $this->deny(
                    'calendar/save_appointment',
                    [
                        'appointment_data' => [
                            'id' => (int) $manual['id'],
                            'start_datetime' => $manual['start_datetime'],
                            'end_datetime' => $manual['end_datetime'],
                            'notes' => $state['marker'] . ':cross-type:update',
                            'id_users_provider' => $provider,
                            'id_users_customer' => $customer,
                            'id_services' => $service,
                            'is_unavailability' => false,
                        ],
                        'customer_data' => [],
                    ],
                    $before,
                    $provider,
                );
            });
            $this->phase($observe, 'calendar_cross_type_delete_denial', function () use (
                $manual,
                $before,
                $provider,
            ): void {
                $this->deny(
                    'calendar/delete_appointment',
                    [
                        'appointment_id' => (int) $manual['id'],
                    ],
                    $before,
                    $provider,
                );
            });
        } finally {
            $logout = $this->client->get('logout');
            $this->remember($this->client);
            if ($logout->statusCode !== 200 || $this->client->get('account')->statusCode !== 307) {
                throw new RuntimeException('Calendar cross-type session did not close.');
            }
            $this->remember($this->client);
        }
        return [
            'status' => 'verified',
            'coverage' => 'calendar_cross_type_denials',
            'statuses' => ['create' => 403, 'update' => 403, 'delete' => 403],
            'public_service_exclusion' => 'absent',
        ];
    }

    private function authenticate(array $actor): void
    {
        $page = $this->client->get('login');
        $this->remember($this->client);
        if ($page->statusCode !== 200) {
            throw new RuntimeException('Calendar cross-type login page unavailable.');
        }
        $login = $this->client->post('login/validate', [
            'username' => (string) $actor['username'],
            'password' => (string) $actor['password'],
        ]);
        $this->remember($this->client);
        $data = json_decode($login->body, true);
        if ($login->statusCode !== 200 || !is_array($data) || ($data['success'] ?? null) !== true) {
            throw new RuntimeException('Calendar cross-type login failed.');
        }
    }

    private function assertPublicServiceExcluded(int $serviceId, string $marker): void
    {
        $ci = &\get_instance();
        $service = $ci->db->get_where('services', ['id' => $serviceId])->row_array();
        if ((int) ($service['is_private'] ?? 0) !== 1 || !empty($service['id_service_categories'])) {
            throw new RuntimeException('Owned service is not private and uncategorized.');
        }
        $ci->load->model('services_model');
        foreach ($ci->services_model->get_available_services(true) as $publicService) {
            if ((int) ($publicService['id'] ?? 0) === $serviceId) {
                throw new RuntimeException('Owned service appeared in public service selection.');
            }
        }
        $page = $this->publicClient->get('booking');
        $this->remember($this->publicClient);
        if (
            $page->statusCode !== 200 ||
            preg_match(
                '~<select\\b[^>]*\\bid=["\']select-service["\'][^>]*>(.*?)</select>~si',
                $page->body,
                $select,
            ) !== 1
        ) {
            throw new RuntimeException('Anonymous booking selector unavailable for exclusion proof.');
        }
        if (
            preg_match(
                '~<option\\b[^>]*\\bvalue=["\']' . preg_quote((string) $serviceId, '~') . '["\']~i',
                $select[1],
            ) === 1 ||
            str_contains($page->body, $marker)
        ) {
            throw new RuntimeException('Owned service appeared in public booking.');
        }
    }

    private function deny(string $path, array $form, array $before, int $provider): void
    {
        try {
            $response = $this->client->post($path, $form);
        } catch (Throwable $error) {
            if (is_callable($this->retainRecovery)) {
                ($this->retainRecovery)();
            }
            throw new CalendarCrossTypeRequestUnconfirmed('Calendar write response was not confirmed.', 0, $error);
        }
        $this->remember($this->client);
        $body = json_decode($response->body, true);
        if ($response->statusCode !== 403 || !is_array($body) || ($body['success'] ?? null) !== false) {
            throw new RuntimeException('Calendar cross-type write did not return the expected denial.');
        }
        if ($this->snapshot($provider) !== $before) {
            throw new RuntimeException('Calendar cross-type denial changed owned provider rows.');
        }
    }

    private function snapshot(int $provider): array
    {
        $this->fixture->unavailabilitiesApiSnapshot();
        $ci = &\get_instance();
        $rows = $ci->db
            ->order_by('id', 'asc')
            ->get_where('appointments', ['id_users_provider' => $provider])
            ->result_array();
        foreach ($rows as &$row) {
            $row['hash'] = hash('sha256', (string) ($row['hash'] ?? ''));
        }
        unset($row);
        return $rows;
    }

    private function remember(GateHttpClient $client): void
    {
        if (is_callable($this->rememberSession)) {
            ($this->rememberSession)($client->getCookie('ea_session'));
        }
    }

    private function phase(callable $observe, string $phase, callable $operation): void
    {
        $observe($phase, 'started');
        try {
            $operation();
            $observe($phase, 'passed');
        } catch (Throwable $error) {
            $observe($phase, 'failed');
            throw $error;
        }
    }
}
