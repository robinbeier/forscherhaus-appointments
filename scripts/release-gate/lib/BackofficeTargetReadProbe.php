<?php

declare(strict_types=1);

namespace ReleaseGate;

use RuntimeException;
use Throwable;

/** Bounded same-session read and role-revocation proof for one owned target graph. */
final class BackofficeTargetReadProbe
{
    /** @var callable(?string):void */
    private readonly mixed $rememberSession;

    public function __construct(
        private readonly GateHttpClient $client,
        private readonly GateHttpClient $publicClient,
        private readonly OrdinaryLiveFixture $actor,
        private readonly DefenseVerificationFixture $targets,
        private readonly string $expectedRedirectOrigin,
        ?callable $rememberSession = null,
    ) {
        $this->rememberSession = $rememberSession ?? static function (?string $session): void {};
    }

    /** @return array<string,mixed> */
    public function run(string $area): array
    {
        if (!in_array($area, ['secretaries', 'services'], true)) {
            throw new RuntimeException('Unsupported Backoffice read area.');
        }
        $actor = $this->actor->read();
        $target = $this->targets->read();
        $expectedProfile = $area === 'secretaries' ? 'backoffice_secretary_read' : 'backoffice_service_read';
        if (($actor['role_slug'] ?? null) !== 'admin' || ($target['profile'] ?? null) !== $expectedProfile) {
            throw new RuntimeException('Backoffice read fixtures do not match the requested area.');
        }
        $targetKey = $area === 'secretaries' ? 'secretary' : 'service';
        $targetId = (int) ($target['ids'][$targetKey] ?? 0);
        $providerId = (int) ($target['ids']['provider'] ?? 0);
        if ($targetId < 1 || $providerId < 1 || ($area === 'secretaries' && $targetId === (int) $actor['user_id'])) {
            throw new RuntimeException('Backoffice read target identity is invalid.');
        }
        if ($area === 'services') {
            $this->targets->assertBackofficeReadServiceNotPublic();
            $this->assertPublicBookingOptionAbsent($targetId, (string) $target['marker']);
        }

        $db = &\get_instance()->db;
        $beforeActor = $db->get_where('users', ['id' => (int) $actor['user_id']])->row_array();
        $beforeSettings = $db->get_where('user_settings', ['id_users' => (int) $actor['user_id']])->row_array();
        $beforeTarget = $this->snapshotTarget($area, $targetId, $providerId);
        if ($beforeActor === [] || $beforeSettings === []) {
            throw new RuntimeException('Owned Backoffice actor is missing.');
        }

        $loggedIn = false;
        $logoutAttempted = false;
        try {
            $page = $this->client->get('login');
            $this->remember();
            $this->expect($page, 200, 'login page');
            $login = $this->client->post('login/validate', [
                'username' => (string) $actor['username'],
                'password' => (string) $actor['password'],
            ]);
            $this->remember();
            $this->expect($login, 200, 'Backoffice login');
            $loginResult = json_decode($login->body, true);
            if (!is_array($loginResult) || ($loginResult['success'] ?? false) !== true) {
                throw new RuntimeException('Owned Backoffice login did not succeed.');
            }
            $loggedIn = true;

            $before = $this->readRoutes($area, $targetId, $providerId, (string) $target['marker'], $beforeTarget, 200);
            $this->assertTargetUnchanged($area, $targetId, $providerId, $beforeTarget);
            $this->actor->transitionAdminToCustomer();
            $afterActor = $db->get_where('users', ['id' => (int) $actor['user_id']])->row_array();
            $afterSettings = $db->get_where('user_settings', ['id_users' => (int) $actor['user_id']])->row_array();
            $customerRole = $db->get_where('roles', ['slug' => 'customer'])->row_array();
            if ((int) ($afterActor['id_roles'] ?? 0) !== (int) ($customerRole['id'] ?? 0)) {
                throw new RuntimeException('Owned Backoffice actor did not transition to customer.');
            }
            $changed = array_diff_assoc($afterActor, $beforeActor);
            unset($changed['id_roles']);
            if ($changed !== [] || $afterSettings !== $beforeSettings) {
                throw new RuntimeException('Owned Backoffice transition changed another actor field.');
            }

            $after = $this->readRoutes($area, $targetId, $providerId, (string) $target['marker'], $beforeTarget, 403);
            $this->assertTargetUnchanged($area, $targetId, $providerId, $beforeTarget);
            if (
                $db->get_where('users', ['id' => (int) $actor['user_id']])->row_array() !== $afterActor ||
                $db->get_where('user_settings', ['id_users' => (int) $actor['user_id']])->row_array() !== $afterSettings
            ) {
                throw new RuntimeException('Denied Backoffice reads changed the owned actor.');
            }
            if ($area === 'services') {
                $this->targets->assertBackofficeReadServiceNotPublic();
                $this->assertPublicBookingOptionAbsent($targetId, (string) $target['marker']);
            }
            $sessionCheck = $this->client->get('login');
            $this->remember();
            $this->expectRedirectTo($sessionCheck, 'calendar', 'same-session login');
            $calendar = $this->client->get('calendar');
            $this->remember();
            $this->expect($calendar, 403, 'same-session customer calendar');
            $this->assertNoMarker($calendar, (string) $target['marker'], 'same-session customer calendar');
            $logoutAttempted = true;
            $logout = $this->client->get('logout');
            $this->remember();
            $this->expect($logout, 200, 'logout');

            return [
                'status' => 'verified',
                'coverage' => $area === 'secretaries' ? 'bounded_secretary_reads' : 'bounded_service_reads',
                'before_statuses' => $before,
                'after_statuses' => $after,
                'session_status' => $sessionCheck->statusCode,
                'calendar_status' => $calendar->statusCode,
                'logout_status' => $logout->statusCode,
                'public_service_exclusion' => $area === 'services' ? 'verified' : 'not_applicable',
                'public_booking_status' => $area === 'services' ? 200 : null,
                'observed' =>
                    'Owned Backoffice target projection was bounded before the stored actor-role transition; the same session could not read it afterwards. The owned target graph and global role permissions were not changed by this probe.',
            ];
        } catch (Throwable $error) {
            if ($loggedIn && !$logoutAttempted) {
                try {
                    $logout = $this->client->get('logout');
                    $this->remember();
                    $this->expect($logout, 200, 'failure-path logout');
                } catch (Throwable) {
                    // Retain the primary failure; independent session and fixture cleanup still run.
                }
            }
            throw $error;
        }
    }

    /** @return array<string,int> */
    private function readRoutes(
        string $area,
        int $targetId,
        int $providerId,
        string $marker,
        array $targetRow,
        int $expected,
    ): array {
        $idKey = $area === 'secretaries' ? 'secretary_id' : 'service_id';
        $alias = 'backend_api/ajax_filter_' . $area;
        $requests = [
            'GET index' => fn(): GateHttpResponse => $this->client->get($area),
            'GET explicit index' => fn(): GateHttpResponse => $this->client->get($area . '/index'),
            'POST search' => fn(): GateHttpResponse => $this->client->post($area . '/search', ['keyword' => $marker]),
            'GET search' => fn(): GateHttpResponse => $this->client->get($area . '/search', ['keyword' => $marker]),
            // Legacy aliases redirect without forwarding a search keyword.
            'GET alias' => fn(): GateHttpResponse => $this->client->get($alias),
            'POST alias' => fn(): GateHttpResponse => $this->client->post($alias),
            'POST find' => fn(): GateHttpResponse => $this->client->post($area . '/find', [$idKey => $targetId]),
            'GET find' => fn(): GateHttpResponse => $this->client->get($area . '/find', [$idKey => $targetId]),
        ];
        $statuses = [];
        foreach ($requests as $name => $request) {
            $response = $request();
            $this->remember();
            if ($name === 'GET alias') {
                $this->expectRedirectTo($response, $area . '/search', $name, [307]);
                $this->assertNoMarker($response, $marker, $name);
                $statuses[$name] = $response->statusCode;
                $statuses[$name . ' landing'] = $this->followAliasSearch(
                    $response,
                    $area,
                    $targetId,
                    $providerId,
                    $marker,
                    $targetRow,
                    $expected,
                    $name,
                );
                continue;
            } elseif ($name === 'POST alias') {
                $methodStatus = $area === 'secretaries' ? 303 : 405;
                $this->expect($response, $methodStatus, $name);
                $this->assertNoMarker($response, $marker, $name);
                if ($methodStatus === 303) {
                    $this->expectRedirectTo($response, $area . '/search', $name, [303]);
                    $statuses[$name] = $response->statusCode;
                    $statuses[$name . ' landing'] = $this->followAliasSearch(
                        $response,
                        $area,
                        $targetId,
                        $providerId,
                        $marker,
                        $targetRow,
                        $expected,
                        $name,
                    );
                    continue;
                } elseif (!str_contains((string) $response->header('allow'), 'GET')) {
                    throw new RuntimeException('Service alias did not advertise its allowed method.');
                }
            } else {
                $this->expect($response, $expected, $name);
                if ($expected === 403) {
                    $this->assertNoMarker($response, $marker, $name);
                } elseif (str_contains($name, 'index')) {
                    if (!str_contains($response->body, 'id="' . $area . '-page"')) {
                        throw new RuntimeException($name . ' did not render the expected Backoffice page.');
                    }
                } elseif (str_contains($name, 'search')) {
                    $this->assertSearchProjection($response, $area, $targetId, $providerId, $marker, $targetRow, $name);
                } else {
                    $record = json_decode($response->body, true);
                    if (!is_array($record) || array_is_list($record)) {
                        throw new RuntimeException($name . ' did not return a bounded object.');
                    }
                    $this->assertProjection($area, $record, $targetId, $providerId, $marker, $targetRow);
                }
            }
            $statuses[$name] = $response->statusCode;
        }
        return $statuses;
    }

    /** Follow only the already-validated local route, never an arbitrary Location URL. */
    private function followAliasSearch(
        GateHttpResponse $redirect,
        string $area,
        int $targetId,
        int $providerId,
        string $marker,
        array $targetRow,
        int $expected,
        string $operation,
    ): int {
        $queryString = parse_url((string) $redirect->header('location'), PHP_URL_QUERY);
        if ($queryString === false) {
            throw new RuntimeException($operation . ' returned a malformed redirect query.');
        }
        $query = [];
        if (is_string($queryString)) {
            parse_str($queryString, $query);
        }
        if ($query !== []) {
            throw new RuntimeException($operation . ' redirected with an unexpected query.');
        }
        $landing = $this->client->get($area . '/search', $query);
        $this->remember();
        $this->expect($landing, $expected, $operation . ' landing');
        if ($expected === 403) {
            $this->assertNoMarker($landing, $marker, $operation . ' landing');
        } else {
            $this->assertAliasLandingProjection(
                $landing,
                $area,
                $targetId,
                $providerId,
                $marker,
                $targetRow,
                $operation . ' landing',
            );
        }
        return $landing->statusCode;
    }

    private function assertSearchProjection(
        GateHttpResponse $response,
        string $area,
        int $targetId,
        int $providerId,
        string $marker,
        array $targetRow,
        string $operation,
    ): void {
        $rows = json_decode($response->body, true);
        if (!is_array($rows) || !array_is_list($rows) || count($rows) !== 1 || !is_array($rows[0])) {
            throw new RuntimeException($operation . ' did not return exactly one owned target.');
        }
        $this->assertProjection($area, $rows[0], $targetId, $providerId, $marker, $targetRow);
    }

    private function assertAliasLandingProjection(
        GateHttpResponse $response,
        string $area,
        int $targetId,
        int $providerId,
        string $marker,
        array $targetRow,
        string $operation,
    ): void {
        $rows = json_decode($response->body, true);
        if (!is_array($rows) || !array_is_list($rows) || count($rows) > 1000) {
            throw new RuntimeException($operation . ' did not return a bounded list.');
        }
        $ownedCount = 0;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new RuntimeException($operation . ' returned a malformed projection.');
            }
            $this->assertProjectionShape($area, $row);
            if ((int) ($row['id'] ?? 0) === $targetId) {
                $this->assertProjection($area, $row, $targetId, $providerId, $marker, $targetRow);
                $ownedCount++;
            }
        }
        if ($ownedCount > 1) {
            throw new RuntimeException($operation . ' returned duplicate owned targets.');
        }
    }

    /** @param array<string,mixed> $projection @param array<string,mixed> $row */
    private function assertProjection(
        string $area,
        array $projection,
        int $id,
        int $providerId,
        string $marker,
        array $row,
    ): void {
        $this->assertProjectionShape($area, $projection);
        if ((int) ($projection['id'] ?? 0) !== $id) {
            throw new RuntimeException('Backoffice projection did not match the owned target ID.');
        }
        if ($area === 'secretaries') {
            $settings = $projection['settings'];
            if (
                $projection['providers'] !== [$providerId] ||
                $projection['notes'] !== $marker ||
                $settings['username'] !== ($row['username'] ?? null)
            ) {
                throw new RuntimeException('Secretary projection missed the owned read contract.');
            }
            return;
        }
        if (
            $projection['description'] !== $marker ||
            (int) $projection['is_private'] !== 1 ||
            (int) $projection['attendants_number'] !== 1
        ) {
            throw new RuntimeException('Service projection missed the owned read contract.');
        }
    }

    /** @param array<string,mixed> $projection */
    private function assertProjectionShape(string $area, array $projection): void
    {
        if (array_is_list($projection) || (int) ($projection['id'] ?? 0) < 1) {
            throw new RuntimeException('Backoffice response contained a malformed projection.');
        }
        if ($area === 'secretaries') {
            $keys = [
                'address',
                'city',
                'email',
                'first_name',
                'id',
                'language',
                'ldap_dn',
                'last_name',
                'mobile_number',
                'notes',
                'phone_number',
                'providers',
                'settings',
                'state',
                'timezone',
                'zip_code',
            ];
            $actual = array_keys($projection);
            sort($keys);
            sort($actual);
            $settings = $projection['settings'] ?? null;
            $settingsKeys = is_array($settings) ? array_keys($settings) : [];
            sort($settingsKeys);
            if ($actual !== $keys || $settingsKeys !== ['calendar_view', 'username']) {
                throw new RuntimeException('Secretary projection exceeded the bounded read contract.');
            }
            return;
        }
        $keys = [
            'id',
            'name',
            'duration',
            'price',
            'currency',
            'description',
            'color',
            'location',
            'availabilities_type',
            'attendants_number',
            'buffer_before',
            'buffer_after',
            'is_private',
            'id_service_categories',
        ];
        $actual = array_keys($projection);
        sort($keys);
        sort($actual);
        if ($actual !== $keys) {
            throw new RuntimeException('Service projection exceeded the bounded read contract.');
        }
    }

    /** @return array<string,mixed> */
    private function snapshotTarget(string $area, int $id, int $providerId): array
    {
        $db = &\get_instance()->db;
        if ($area === 'secretaries') {
            $user = $db->get_where('users', ['id' => $id])->row_array();
            $settings = $db->get_where('user_settings', ['id_users' => $id])->row_array();
            $links = $db->get_where('secretaries_providers', ['id_users_secretary' => $id])->result_array();
            if (
                $user === [] ||
                $settings === [] ||
                count($links) !== 1 ||
                (int) ($links[0]['id_users_provider'] ?? 0) !== $providerId
            ) {
                throw new RuntimeException('Owned Secretary target graph is incomplete.');
            }
            return ['user' => $user, 'settings' => $settings, 'links' => $links, 'username' => $settings['username']];
        }
        $service = $db->get_where('services', ['id' => $id])->row_array();
        $links = $db->get_where('services_providers', ['id_services' => $id])->result_array();
        $appointments = $db->get_where('appointments', ['id_services' => $id])->num_rows();
        if (
            $service === [] ||
            count($links) !== 1 ||
            (int) ($links[0]['id_users'] ?? 0) !== $providerId ||
            $appointments !== 0
        ) {
            throw new RuntimeException('Owned Service target graph is incomplete or bookable.');
        }
        return ['service' => $service, 'links' => $links, 'appointments' => 0];
    }

    private function assertTargetUnchanged(string $area, int $id, int $providerId, array $expected): void
    {
        if ($this->snapshotTarget($area, $id, $providerId) !== $expected) {
            throw new RuntimeException('Backoffice read mutated the owned target graph.');
        }
    }

    /** @param list<int> $statuses */
    private function expectRedirectTo(
        GateHttpResponse $response,
        string $route,
        string $operation,
        array $statuses = [302, 307],
    ): void {
        $location = $response->header('location');
        $path = $location !== null ? parse_url($location, PHP_URL_PATH) : null;
        $actualOrigin = $location !== null ? parse_url($location) : false;
        $expectedOrigin = parse_url($this->expectedRedirectOrigin);
        if (
            !in_array($response->statusCode, $statuses, true) ||
            !is_string($path) ||
            preg_match('~/(?:index\\.php/)?' . preg_quote($route, '~') . '/?$~', $path) !== 1 ||
            !is_array($actualOrigin) ||
            !is_array($expectedOrigin) ||
            !is_string($actualOrigin['scheme'] ?? null) ||
            !is_string($actualOrigin['host'] ?? null) ||
            strtolower($actualOrigin['scheme']) !== strtolower((string) ($expectedOrigin['scheme'] ?? '')) ||
            strtolower($actualOrigin['host']) !== strtolower((string) ($expectedOrigin['host'] ?? '')) ||
            ($actualOrigin['port'] ?? null) !== ($expectedOrigin['port'] ?? null)
        ) {
            throw new RuntimeException($operation . ' did not redirect to the expected route.');
        }
    }

    private function assertPublicBookingOptionAbsent(int $serviceId, string $marker): void
    {
        $response = $this->publicClient->get('booking');
        ($this->rememberSession)($this->publicClient->getCookie('ea_session'));
        $this->expect($response, 200, 'anonymous booking page');
        if (
            preg_match(
                '~<select\\b[^>]*\\bid=["\']select-service["\'][^>]*>(.*?)</select>~si',
                $response->body,
                $select,
            ) !== 1
        ) {
            throw new RuntimeException('Anonymous booking page did not expose a service selector for exclusion proof.');
        }
        if (
            preg_match(
                '~<option\\b[^>]*\\bvalue=["\']' . preg_quote((string) $serviceId, '~') . '["\']~i',
                $select[1],
            ) === 1 ||
            str_contains($response->body, $marker)
        ) {
            throw new RuntimeException('Owned private service appeared in the anonymous booking page.');
        }
    }

    private function assertNoMarker(GateHttpResponse $response, string $marker, string $operation): void
    {
        if (str_contains($response->body, $marker)) {
            throw new RuntimeException($operation . ' exposed the owned synthetic marker.');
        }
    }

    private function remember(): void
    {
        ($this->rememberSession)($this->client->getCookie('ea_session'));
    }

    private function expect(GateHttpResponse $response, int $status, string $operation): void
    {
        if ($response->statusCode !== $status) {
            throw new RuntimeException(
                $operation . ' returned HTTP ' . $response->statusCode . ', expected ' . $status . '.',
            );
        }
    }
}
