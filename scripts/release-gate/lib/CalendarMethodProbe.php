<?php

declare(strict_types=1);

namespace ReleaseGate;

use RuntimeException;

/**
 * Read-only method and legacy-alias proof for the six calendar write routes.
 *
 * The callbacks deliberately keep the fixture and database ownership outside
 * this class: the probe only compares the bounded, non-secret snapshot before
 * and after each request.
 */
final class CalendarMethodProbe
{
    /** Keep the authenticated login and its provenance journal in one tested order. */
    public static function authenticateSession(
        callable $loginPage,
        callable $login,
        callable $remember,
        array $context,
    ): void {
        $page = $loginPage();
        $remember();
        if (!$page instanceof GateHttpResponse || $page->statusCode !== 200) {
            throw new RuntimeException('Calendar method probe login page failed.');
        }
        $response = $login((string) $context['username'], (string) $context['password']);
        $data = $response instanceof GateHttpResponse ? json_decode($response->body, true) : null;
        if (
            !$response instanceof GateHttpResponse ||
            $response->statusCode !== 200 ||
            !is_array($data) ||
            ($data['success'] ?? false) !== true
        ) {
            throw new RuntimeException('Calendar method probe login failed.');
        }
        $remember();
    }

    /** Logout must be attempted before its final session provenance journal write. */
    public static function closeSession(callable $logout, callable $remember, callable $afterLogout): void
    {
        $response = $logout();
        $remember();
        if (!$response instanceof GateHttpResponse || $response->statusCode !== 200) {
            throw new RuntimeException('Calendar method probe logout failed.');
        }
        $after = $afterLogout();
        $remember();
        if (!$after instanceof GateHttpResponse || $after->statusCode !== 307) {
            throw new RuntimeException('Calendar method probe session remained authenticated.');
        }
    }

    /** Build the bounded snapshot used by the live probe from the owned fixture rows. */
    public static function snapshotOwnedRows(object $db, array $supplemental): array
    {
        $appointmentId = (int) ($supplemental['ids']['appointment'] ?? 0);
        $actorId = (int) ($supplemental['actor_id'] ?? 0);
        $customerId = (int) ($supplemental['ids']['calendar_customer'] ?? 0);
        $serviceId = (int) ($supplemental['ids']['service'] ?? 0);
        $marker = (string) ($supplemental['marker'] ?? '');
        $appointment = $db->get_where('appointments', ['id' => $appointmentId])->row_array();
        if (!is_array($appointment) || $appointment === []) {
            throw new RuntimeException('Calendar method probe appointment snapshot is unavailable.');
        }
        $appointment['hash'] = hash('sha256', (string) ($appointment['hash'] ?? ''));
        $providerSettings = $db->get_where('user_settings', ['id_users' => $actorId])->row_array();
        if (!is_array($providerSettings) || $providerSettings === []) {
            throw new RuntimeException('Calendar method probe provider settings snapshot is unavailable.');
        }

        return [
            'owned' => [
                'appointment' => $appointment,
                'provider' => $db->get_where('users', ['id' => $actorId])->row_array(),
                'customer' => $db->get_where('users', ['id' => $customerId])->row_array(),
                'service' => $db->get_where('services', ['id' => $serviceId])->row_array(),
                'provider_settings_sha256' => hash('sha256', serialize($providerSettings)),
                'provider_appointments' => $db
                    ->order_by('id', 'asc')
                    ->get_where('appointments', ['id_users_provider' => $actorId])
                    ->result_array(),
                'marker_users' => $db
                    ->select('id')
                    ->order_by('id', 'asc')
                    ->get_where('users', ['notes' => $marker])
                    ->result_array(),
                'marker_services' => $db
                    ->select('id')
                    ->order_by('id', 'asc')
                    ->get_where('services', ['description' => $marker])
                    ->result_array(),
                'service_relationships' => $db
                    ->order_by('id_services', 'asc')
                    ->get_where('services_providers', ['id_users' => $actorId])
                    ->result_array(),
            ],
        ];
    }

    /** @var callable(string,string):GateHttpResponse */
    private $request;
    /** @var callable():void */
    private $authenticate;
    /** @var callable():void */
    private $close;
    /** @var callable():array<string,mixed> */
    private $context;

    /** @var callable():array<string,mixed> */
    private $snapshot;

    /** @var callable():void|null */
    private $rememberSession;

    /**
     * @param callable(string,string):GateHttpResponse $request
     * @param callable():void $authenticate
     * @param callable():void $close
     * @param callable():array<string,mixed> $context
     * @param callable():array<string,mixed> $snapshot
     * @param callable():void|null $rememberSession
     */
    public function __construct(
        callable $request,
        callable $authenticate,
        callable $close,
        callable $context,
        callable $snapshot,
        ?callable $rememberSession = null,
    ) {
        $this->request = $request;
        $this->authenticate = $authenticate;
        $this->close = $close;
        $this->context = $context;
        $this->snapshot = $snapshot;
        $this->rememberSession = $rememberSession ?? static function (): void {};
    }

    /**
     * @param callable(string,string):void|null $observe
     * @return array{status:string,coverage:string,method_statuses:array<string,array<string,int>>,alias_statuses:array<string,array<string,int>>,observed:string}
     */
    public function run(?callable $observe = null): array
    {
        $observe ??= static function (string $phase, string $outcome): void {};
        try {
            ($this->authenticate)();
            $context = ($this->context)();
            $this->assertContext($context);
            $before = ($this->snapshot)();
            $methods = $this->routes($context);
            $methodStatuses = [];
            $aliasStatuses = [];

            foreach ($methods as $name => $route) {
                $phase = $name . '_methods';
                $this->phase($observe, $phase, function () use (
                    $route,
                    $name,
                    $before,
                    $phase,
                    &$methodStatuses,
                ): void {
                    foreach (['GET', 'HEAD'] as $method) {
                        $operation = $phase . '_' . strtolower($method);
                        $response = ($this->request)($method, $route['path'] . '?' . $route['query']);
                        ($this->rememberSession)();
                        $this->expectStatus($response, 405, $operation);
                        $this->expectAllow($response, 'POST', $operation);
                        $this->assertSnapshot($before, $operation);
                        $methodStatuses[$name][strtolower($method)] = 405;
                    }
                });
            }

            foreach ($methods as $name => $route) {
                $phase = $name . '_aliases';
                $this->phase($observe, $phase, function () use ($route, $name, $before, $phase, &$aliasStatuses): void {
                    foreach (['GET', 'HEAD', 'POST'] as $method) {
                        $operation = $phase . '_' . strtolower($method);
                        $response = ($this->request)($method, 'backend_api/' . $route['alias'] . '?' . $route['query']);
                        ($this->rememberSession)();
                        $allowedStatuses = $method === 'POST' ? [303] : [301, 302, 303, 307, 308];
                        if (!in_array($response->statusCode, $allowedStatuses, true)) {
                            throw new RuntimeException($operation . ' was not redirect-only.');
                        }
                        $this->expectAliasLocation($response, $route['alias'], $route['path'], $operation);
                        $this->assertSnapshot($before, $operation);
                        $aliasStatuses[$name][strtolower($method)] = $response->statusCode;
                    }
                });
            }

            return [
                'status' => 'verified',
                'coverage' => 'six_calendar_write_methods_and_legacy_aliases',
                'method_statuses' => $methodStatuses,
                'alias_statuses' => $aliasStatuses,
                'observed' =>
                    'Every GET and HEAD request returned 405 with Allow: POST; legacy aliases redirected without changing owned synthetic rows.',
            ];
        } finally {
            // A login can succeed before session journaling fails. Always try
            // logout; the wrapper retains recovery state if closure is unproved.
            ($this->close)();
        }
    }

    /** @param array<string,mixed> $context @return array<string,array{path:string,alias:string,query:string}> */
    private function routes(array $context): array
    {
        $id = (int) $context['appointment_id'];
        $provider = (int) $context['provider_id'];
        $marker = rawurlencode((string) $context['marker']);
        $common = 'appointment_data%5Bid%5D=' . $id . '&appointment_data%5Bid_users_provider%5D=' . $provider;

        return [
            'save_appointment' => [
                'path' => 'calendar/save_appointment',
                'alias' => 'ajax_save_appointment',
                'query' => $common . '&customer_data%5Bid%5D=' . (int) $context['customer_id'],
            ],
            'delete_appointment' => [
                'path' => 'calendar/delete_appointment',
                'alias' => 'ajax_delete_appointment',
                'query' => 'appointment_id=' . $id,
            ],
            'save_unavailability' => [
                'path' => 'calendar/save_unavailability',
                'alias' => 'ajax_save_unavailability',
                'query' =>
                    'unavailability%5Bid%5D=' .
                    $id .
                    '&unavailability%5Bid_users_provider%5D=' .
                    $provider .
                    '&unavailability%5Bnotes%5D=' .
                    $marker,
            ],
            'delete_unavailability' => [
                'path' => 'calendar/delete_unavailability',
                'alias' => 'ajax_delete_unavailability',
                'query' => 'unavailability_id=' . $id,
            ],
            'save_working_plan_exception' => [
                'path' => 'calendar/save_working_plan_exception',
                'alias' => 'ajax_save_working_plan_exception',
                'query' => 'provider_id=' . $provider . '&date=2035-02-14&working_plan_exception%5Bis_working%5D=1',
            ],
            'delete_working_plan_exception' => [
                'path' => 'calendar/delete_working_plan_exception',
                'alias' => 'ajax_delete_working_plan_exception',
                'query' => 'provider_id=' . $provider . '&date=2035-02-14',
            ],
        ];
    }

    /** @param array<string,mixed> $context */
    private function assertContext(array $context): void
    {
        foreach (['appointment_id', 'provider_id', 'customer_id'] as $key) {
            if ((int) ($context[$key] ?? 0) < 1) {
                throw new RuntimeException('Calendar method probe requires owned synthetic IDs.');
            }
        }
        if ((string) ($context['marker'] ?? '') === '') {
            throw new RuntimeException('Calendar method probe requires an owned synthetic marker.');
        }
    }

    /** @param callable(string,string):void $observe */
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

    /** @param array<string,mixed> $expected */
    private function assertSnapshot(array $expected, string $phase): void
    {
        if (($this->snapshot)() !== $expected) {
            throw new RuntimeException($phase . ' changed owned synthetic rows.');
        }
    }

    private function expectStatus(GateHttpResponse $response, int $expected, string $phase): void
    {
        if ($response->statusCode !== $expected) {
            throw new RuntimeException($phase . ' returned an unexpected status.');
        }
    }

    private function expectAllow(GateHttpResponse $response, string $expected, string $phase): void
    {
        if (strtoupper((string) $response->header('allow')) !== $expected) {
            throw new RuntimeException($phase . ' did not advertise Allow: ' . $expected . '.');
        }
    }

    private function expectAliasLocation(GateHttpResponse $response, string $alias, string $target, string $phase): void
    {
        $requested = parse_url($response->url);
        $location = (string) $response->header('location');
        $redirected = parse_url($location);
        $suffix = '/backend_api/' . $alias;
        if (
            !is_array($requested) ||
            !is_array($redirected) ||
            !str_ends_with((string) ($requested['path'] ?? ''), $suffix) ||
            !isset($requested['scheme'], $requested['host'])
        ) {
            throw new RuntimeException($phase . ' has no trustworthy redirect context.');
        }
        $expectedPath = substr((string) $requested['path'], 0, -strlen($suffix)) . '/' . $target;
        if (
            ($redirected['path'] ?? null) !== $expectedPath ||
            isset($redirected['query']) ||
            isset($redirected['fragment']) ||
            isset($redirected['user']) ||
            isset($redirected['pass'])
        ) {
            throw new RuntimeException($phase . ' redirected to an unexpected calendar route.');
        }
        if (isset($redirected['scheme']) || isset($redirected['host']) || isset($redirected['port'])) {
            if (
                strtolower((string) ($redirected['scheme'] ?? '')) !== strtolower((string) $requested['scheme']) ||
                strtolower((string) ($redirected['host'] ?? '')) !== strtolower((string) $requested['host']) ||
                ($redirected['port'] ?? null) !== ($requested['port'] ?? null)
            ) {
                throw new RuntimeException($phase . ' redirected outside the request origin.');
            }
        } elseif (!str_starts_with($location, '/') || str_starts_with($location, '//')) {
            throw new RuntimeException($phase . ' has an ambiguous relative redirect.');
        }
    }
}
