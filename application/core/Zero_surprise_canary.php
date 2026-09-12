<?php defined('BASEPATH') or exit('No direct script access allowed');

/** Restrict the root-provisioned, short-lived release canary to its own resources. */
class Zero_surprise_canary
{
    public const ACTOR = '__ea_zero_surprise_canary_v1';
    public const PROVIDER = '__ea_zero_surprise_provider_v1';
    public const SCHEMA = 'zero_surprise_canary.v1';
    private EA_Controller|CI_Controller $CI;
    /** @var array<string,mixed>|null */
    private ?array $context = null;

    public function __construct()
    {
        $this->CI = &get_instance();
    }

    public static function ownsNotes(mixed $notes, string $run): bool
    {
        return is_string($notes) && ($notes === 'run:' . $run || str_starts_with($notes, 'run:' . $run . ':'));
    }

    /** @return array<string,mixed>|null */
    public static function decodeLease(string $notes, string $token, ?int $now = null): ?array
    {
        $lease = json_decode($notes, true);
        if (
            !is_array($lease) ||
            ($lease['schema'] ?? null) !== self::SCHEMA ||
            !preg_match('/^zs-canary-[a-f0-9]{32}$/D', (string) ($lease['run_id'] ?? '')) ||
            !preg_match('/^[a-f0-9]{64}$/D', $token) ||
            !is_string($lease['token_hash'] ?? null) ||
            !hash_equals($lease['token_hash'], hash('sha256', $token)) ||
            !is_int($lease['expires_at'] ?? null) ||
            $lease['expires_at'] <= ($now ?? time()) ||
            $lease['expires_at'] > ($now ?? time()) + 600
        ) {
            return null;
        }
        foreach (['actor_id', 'provider_id', 'service_id'] as $key) {
            if (!is_int($lease[$key] ?? null) || $lease[$key] < 1) {
                return null;
            }
        }
        return $lease;
    }

    /** The request header is a capability only after current database ownership is verified. */
    public function enforce(): void
    {
        if (is_cli()) {
            return;
        }
        $token = $this->CI->input->get_request_header('X-EA-Canary', false);
        $names = [session('username'), $this->CI->input->post('username'), $this->CI->input->server('PHP_AUTH_USER')];
        $reserved = false;
        foreach ($names as $name) {
            if (!is_string($name) || $name === '') {
                continue;
            }
            if (in_array(rtrim(strtolower($name), ' '), [self::ACTOR, self::PROVIDER], true)) {
                $reserved = true;
            }
            // Resolve the same database collation used by authentication, not a PHP approximation.
            $settings = $this->CI->db->get_where('user_settings', ['username' => $name])->row_array();
            if (
                is_array($settings) &&
                in_array(rtrim(strtolower((string) $settings['username']), ' '), [self::ACTOR, self::PROVIDER], true)
            ) {
                $reserved = true;
            }
        }
        if ($token === null || $token === '') {
            if ($reserved) {
                abort(403, 'Forbidden');
            }
            return;
        }
        if (!is_string($token)) {
            abort(403, 'Forbidden');
        }
        if (!preg_match('/^[a-f0-9]{64}$/D', $token)) {
            abort(403, 'Forbidden');
        }
        // Serialize fixture teardown with an in-flight canary request; never let a timer remove its parents mid-write.
        $lock = $this->CI->db->query("SELECT GET_LOCK('fh-zero-surprise-canary-v1', 0) AS acquired")->row_array();
        if ((int) ($lock['acquired'] ?? 0) !== 1) {
            abort(409, 'Conflict');
        }
        register_shutdown_function(function (): void {
            $this->CI->db->query("SELECT RELEASE_LOCK('fh-zero-surprise-canary-v1')");
        });
        $actor = $this->principal(self::ACTOR);
        $lease = $actor === null ? null : self::decodeLease((string) $actor['notes'], $token);
        $provider = $this->principal(self::PROVIDER);
        if (
            $lease === null ||
            $provider === null ||
            $actor === null ||
            (int) $actor['id'] !== $lease['actor_id'] ||
            (int) $provider['id'] !== $lease['provider_id'] ||
            $actor['role_slug'] !== DB_SLUG_ADMIN ||
            $provider['role_slug'] !== DB_SLUG_PROVIDER ||
            $actor['notes'] !== $provider['notes'] ||
            (int) $provider['is_private'] !== 1 ||
            (int) $provider['notifications'] !== 0 ||
            (int) $provider['google_sync'] !== 0 ||
            (int) $provider['caldav_sync'] !== 0
        ) {
            abort(403, 'Forbidden');
        }
        $service = $this->CI->db->get_where('services', ['id' => $lease['service_id']])->row_array();
        if (
            !is_array($service) ||
            $service['description'] !== $actor['notes'] ||
            (int) $service['is_private'] !== 1 ||
            (int) $service['attendants_number'] !== 1 ||
            $this->CI->db
                ->get_where('services_providers', [
                    'id_users' => $lease['provider_id'],
                    'id_services' => $lease['service_id'],
                ])
                ->num_rows() !== 1
        ) {
            abort(403, 'Forbidden');
        }
        $this->context = $lease;
        $this->enforceRoute();
    }

    /** @return array<string,mixed>|null */
    private function principal(string $username): ?array
    {
        $query = $this->CI->db
            ->select(
                'users.*, user_settings.username, user_settings.notifications, user_settings.google_sync, user_settings.caldav_sync, roles.slug AS role_slug',
            )
            ->from('users')
            ->join('user_settings', 'user_settings.id_users = users.id')
            ->join('roles', 'roles.id = users.id_roles')
            ->where('user_settings.username', $username)
            ->get();
        return $query->num_rows() === 1 ? $query->row_array() : null;
    }

    public function active(): bool
    {
        return $this->context !== null;
    }

    /** @return array<string,mixed> */
    public function context(): array
    {
        if ($this->context === null) {
            throw new RuntimeException('Canary context unavailable.');
        }
        return $this->context;
    }

    public function assertPair(mixed $provider, mixed $service): void
    {
        $c = $this->context();
        if ((int) $provider !== $c['provider_id'] || (int) $service !== $c['service_id']) {
            abort(403, 'Forbidden');
        }
    }

    /** Also contain private fixture IDs on ordinary, non-canary booking requests. */
    public function assertPublicTarget(mixed $provider, mixed $service): void
    {
        if ($this->active()) {
            $this->assertPair($provider, $service);
            return;
        }
        $p = $this->CI->db
            ->get_where('user_settings', ['id_users' => (int) $provider, 'username' => self::PROVIDER])
            ->num_rows();
        $s = $this->CI->db->get_where('services', ['id' => (int) $service])->row_array();
        $marker = is_array($s) ? json_decode((string) $s['description'], true) : null;
        if ($p !== 0 || (is_array($marker) && ($marker['schema'] ?? null) === self::SCHEMA)) {
            abort(404, 'Not Found');
        }
    }

    public function assertCustomer(int $id, bool $allowMissing = false): void
    {
        $row = $this->CI->db
            ->select('users.*, roles.slug AS role_slug')
            ->from('users')
            ->join('roles', 'roles.id = users.id_roles')
            ->where('users.id', $id)
            ->get()
            ->row_array();
        if (!$row && $allowMissing) {
            return;
        }
        if (!$row || $row['role_slug'] !== DB_SLUG_CUSTOMER || $row['notes'] !== 'run:' . $this->context()['run_id']) {
            abort(403, 'Forbidden');
        }
    }

    private function assertCustomerDeletion(int $id): void
    {
        $this->assertCustomer($id, true);
        foreach (
            $this->CI->db->get_where('appointments', ['id_users_customer' => $id])->result_array()
            as $appointment
        ) {
            $this->assertAppointment((int) $appointment['id']);
        }
    }

    public function assertAppointment(int $id, bool $allowMissing = false): void
    {
        $row = $this->CI->db->get_where('appointments', ['id' => $id])->row_array();
        if (!$row && $allowMissing) {
            return;
        }
        if (!$row || !self::ownsNotes($row['notes'], $this->context()['run_id'])) {
            abort(403, 'Forbidden');
        }
        $this->assertPair($row['id_users_provider'], $row['id_services']);
        $this->assertCustomer((int) $row['id_users_customer']);
    }

    /** Verified per-request scope suppresses all notifications, without global setting changes. */
    public function suppressNotifications(array $appointment): bool
    {
        if (!$this->active()) {
            return false;
        }
        $this->assertPair($appointment['id_users_provider'] ?? null, $appointment['id_services'] ?? null);
        if (!self::ownsNotes($appointment['notes'] ?? null, $this->context()['run_id'])) {
            abort(403, 'Forbidden');
        }
        return true;
    }

    private function enforceRoute(): void
    {
        $c = $this->context();
        if (session('user_id') && (int) session('user_id') !== $c['actor_id']) {
            abort(403, 'Forbidden');
        }
        $controller = strtolower((string) $this->CI->router->class);
        $method = strtolower((string) $this->CI->router->method);
        $verb = strtoupper($this->CI->input->method(true));
        $route = $controller . '::' . $method;
        $segments = $this->CI->uri->segment_array();
        $last = (string) end($segments);
        if (in_array($route, ['login::index', 'logout::index'], true) && $verb === 'GET') {
            return;
        }
        if ($route === 'login::validate' && $verb === 'POST' && $this->CI->input->post('username') === self::ACTOR) {
            return;
        }
        if ($route === 'booking::index' && $verb === 'GET') {
            return;
        }
        if (
            ($route === 'booking::reschedule' && $verb === 'GET') ||
            ($route === 'booking_cancellation::of' && $verb === 'POST')
        ) {
            if ($route === 'booking_cancellation::of' && $last === 'missing-' . $c['run_id']) {
                return;
            }
            $appointment = $this->CI->db->get_where('appointments', ['hash' => $last])->row_array();
            if (!$appointment) {
                abort(403, 'Forbidden');
            }
            $this->assertAppointment((int) $appointment['id']);
            return;
        }
        if (
            $controller === 'booking' &&
            in_array($method, ['get_available_hours', 'get_unavailable_dates'], true) &&
            $verb === 'POST'
        ) {
            $this->assertPair($this->CI->input->post('provider_id'), $this->CI->input->post('service_id'));
            return;
        }
        if ($route === 'booking::register' && $verb === 'POST') {
            $data = $this->CI->input->post('post_data');
            if (!is_array($data) || !is_array($data['appointment'] ?? null) || !is_array($data['customer'] ?? null)) {
                abort(403, 'Forbidden');
            }
            $appointment = $data['appointment'];
            $customer = $data['customer'];
            $this->assertPair($appointment['id_users_provider'] ?? null, $appointment['id_services'] ?? null);
            if (
                !self::ownsNotes($appointment['notes'] ?? null, $c['run_id']) ||
                ($customer['notes'] ?? null) !== 'run:' . $c['run_id'] ||
                !str_ends_with((string) ($customer['email'] ?? ''), '@synthetic.invalid')
            ) {
                abort(403, 'Forbidden');
            }
            if (!empty($appointment['id'])) {
                $this->assertAppointment((int) $appointment['id']);
            }
            if (!empty($customer['id'])) {
                $this->assertCustomer((int) $customer['id']);
            }
            return;
        }
        if (in_array($controller, ['appointments_api_v1', 'customers_api_v1'], true)) {
            if ($this->CI->input->server('PHP_AUTH_USER') !== self::ACTOR) {
                abort(403, 'Forbidden');
            }
            if (
                array_intersect(array_keys($this->CI->input->get() ?: []), [
                    'with',
                    'attach',
                    'aggregates',
                    'includeBufferBlocks',
                ])
            ) {
                abort(403, 'Forbidden');
            }
            if ($method === 'index' && $verb === 'GET') {
                // Collection controllers add mandatory current-lease filters.
                return;
            }
            if (
                in_array($method, ['show', 'destroy'], true) &&
                in_array($verb, ['GET', 'DELETE'], true) &&
                ctype_digit($last)
            ) {
                if ($controller === 'appointments_api_v1') {
                    $this->assertAppointment((int) $last, true);
                } elseif ($method === 'destroy') {
                    $this->assertCustomerDeletion((int) $last);
                } else {
                    $this->assertCustomer((int) $last, true);
                }
                return;
            }
            if ($controller === 'appointments_api_v1' && $method === 'store' && $verb === 'POST') {
                $data = json_decode($this->CI->input->raw_input_stream, true);
                if (!is_array($data) || !self::ownsNotes($data['notes'] ?? null, $c['run_id'])) {
                    abort(403, 'Forbidden');
                }
                $this->assertPair($data['providerId'] ?? null, $data['serviceId'] ?? null);
                $this->assertCustomer((int) ($data['customerId'] ?? 0));
                return;
            }
        }
        if (
            ($controller === 'dashboard' && in_array($method, ['metrics', 'heatmap'], true) && $verb === 'POST') ||
            ($controller === 'dashboard_export' &&
                in_array($method, ['principal_pdf', 'teacher_pdf', 'teacher_zip'], true) &&
                $verb === 'GET')
        ) {
            $data = $verb === 'POST' ? $this->CI->input->post() : $this->CI->input->get();
            if (
                is_array($data) &&
                (int) ($data['service_id'] ?? 0) === $c['service_id'] &&
                is_array($data['provider_ids'] ?? null) &&
                array_map('intval', $data['provider_ids']) === [$c['provider_id']]
            ) {
                return;
            }
        }
        abort(403, 'Forbidden');
    }
}
