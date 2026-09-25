<?php

declare(strict_types=1);

namespace Tests\Integration\Support;

use RuntimeException;

/** Additional exact synthetic identities for the provider PDF isolation test. */
final class ProviderPdfExportTestSupport
{
    public int $providerId = 0;
    public int $customerId = 0;
    public int $appointmentId = 0;
    public readonly string $providerUsername;
    public readonly string $password;
    public readonly string $run;
    private object $db;
    private int $serviceId = 0;

    public function __construct(DefenseCycleFixtures $fixture)
    {
        $this->db = get_instance()->db;
        $this->run = $fixture->run . '_peer';
        $this->password = $fixture->password;
        $this->providerUsername = $this->run . '_provider';
        $this->serviceId = $fixture->serviceId;
        try {
            $this->createFixture();
        } catch (\Throwable $error) {
            try {
                $this->cleanup();
            } catch (\Throwable $cleanupError) {
                throw new RuntimeException('Peer fixture setup and cleanup both failed.', 0, $cleanupError);
            }
            throw $error;
        }
    }

    private function createFixture(): void
    {
        $providerRole = $this->db->get_where('roles', ['slug' => 'provider'])->row_array();
        $customerRole = $this->db->get_where('roles', ['slug' => 'customer'])->row_array();
        if (!$providerRole || !$customerRole) {
            throw new RuntimeException('Required synthetic roles are missing.');
        }
        $this->providerId = $this->insertUser('provider', 'provider', (int) $providerRole['id']);
        $this->customerId = $this->insertUser('customer', 'customer', (int) $customerRole['id']);
        $salt = generate_salt();
        $plan = array_fill_keys(
            ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'],
            ['start' => '08:00', 'end' => '18:00', 'breaks' => []],
        );
        if (
            !$this->db->insert('user_settings', [
                'id_users' => $this->providerId,
                'username' => $this->providerUsername,
                'password' => hash_password($salt, $this->password),
                'salt' => $salt,
                'working_plan' => json_encode($plan),
                'notifications' => 0,
                'google_sync' => 0,
                'caldav_sync' => 0,
            ])
        ) {
            throw new RuntimeException('Could not create peer provider settings.');
        }
        if (
            !$this->db->insert('services_providers', [
                'id_users' => $this->providerId,
                'id_services' => $this->serviceId,
            ])
        ) {
            throw new RuntimeException('Could not create peer provider service relation.');
        }
        $ci = &get_instance();
        $ci->load->model('appointments_model');
        $monday = new \DateTimeImmutable('next monday');
        $this->appointmentId = (int) $ci->appointments_model->save([
            'start_datetime' => $monday->format('Y-m-d') . ' 10:00:00',
            'end_datetime' => $monday->format('Y-m-d') . ' 10:30:00',
            'notes' => $this->run,
            'status' => 'Booked',
            'is_unavailability' => false,
            'id_users_provider' => $this->providerId,
            'id_users_customer' => $this->customerId,
            'id_services' => $this->serviceId,
        ]);
        if ($this->appointmentId <= 0) {
            throw new RuntimeException('Could not create peer appointment.');
        }
    }

    public function cleanup(): void
    {
        $providerEmail = $this->run . '_provider@synthetic.invalid';
        $customerEmail = $this->run . '_customer@synthetic.invalid';
        $provider =
            $this->providerId > 0 ? $this->db->get_where('users', ['id' => $this->providerId])->row_array() : [];
        $customer =
            $this->customerId > 0 ? $this->db->get_where('users', ['id' => $this->customerId])->row_array() : [];
        if ($provider && ($provider['email'] ?? null) !== $providerEmail) {
            throw new RuntimeException('Peer provider identity changed; refusing cleanup.');
        }
        if ($customer && ($customer['email'] ?? null) !== $customerEmail) {
            throw new RuntimeException('Peer customer identity changed; refusing cleanup.');
        }
        if ($this->appointmentId > 0) {
            $appointment = $this->db->get_where('appointments', ['id' => $this->appointmentId])->row_array();
            if (
                $appointment &&
                ((int) ($appointment['id_users_provider'] ?? 0) !== $this->providerId ||
                    (int) ($appointment['id_users_customer'] ?? 0) !== $this->customerId ||
                    (int) ($appointment['id_services'] ?? 0) !== $this->serviceId)
            ) {
                throw new RuntimeException('Peer appointment identity changed; refusing cleanup.');
            }
            $this->db->delete('reschedule_authorities', ['appointment_id' => $this->appointmentId]);
            $this->db->delete('appointments', [
                'id' => $this->appointmentId,
                'id_users_provider' => $this->providerId,
                'id_users_customer' => $this->customerId,
                'id_services' => $this->serviceId,
            ]);
        }
        if ($this->providerId > 0) {
            $this->db->delete('services_providers', [
                'id_users' => $this->providerId,
                'id_services' => $this->serviceId,
            ]);
            $this->db->delete('user_settings', ['id_users' => $this->providerId]);
            $this->db->delete('users', ['id' => $this->providerId, 'email' => $providerEmail]);
        }
        if ($this->customerId > 0) {
            $this->db->delete('users', ['id' => $this->customerId, 'email' => $customerEmail]);
        }
        $this->assertNoOwnedRows($providerEmail, $customerEmail);
    }

    private function assertNoOwnedRows(string $providerEmail, string $customerEmail): void
    {
        if (
            ($this->appointmentId > 0 &&
                $this->db->get_where('appointments', ['id' => $this->appointmentId])->num_rows() !== 0) ||
            ($this->providerId > 0 && $this->db->get_where('users', ['id' => $this->providerId])->num_rows() !== 0) ||
            ($this->customerId > 0 && $this->db->get_where('users', ['id' => $this->customerId])->num_rows() !== 0) ||
            $this->db->get_where('users', ['email' => $providerEmail])->num_rows() !== 0 ||
            $this->db->get_where('users', ['email' => $customerEmail])->num_rows() !== 0 ||
            ($this->providerId > 0 &&
                $this->db->get_where('user_settings', ['id_users' => $this->providerId])->num_rows() !== 0) ||
            ($this->providerId > 0 &&
                $this->db
                    ->get_where('services_providers', [
                        'id_users' => $this->providerId,
                        'id_services' => $this->serviceId,
                    ])
                    ->num_rows() !== 0)
        ) {
            throw new RuntimeException('Peer fixture cleanup was not confirmed.');
        }
    }

    private function insertUser(string $role, string $suffix, int $roleId): int
    {
        $email = $this->run . '_' . $suffix . '@synthetic.invalid';
        if (
            !$this->db->insert('users', [
                'first_name' => 'Synthetic',
                'last_name' => $this->run . '_parent',
                'email' => $email,
                'phone_number' => '000000000',
                'notes' => $this->run,
                'timezone' => 'UTC',
                'language' => 'english',
                'id_roles' => $roleId,
                'is_private' => 0,
            ])
        ) {
            throw new RuntimeException('Could not create peer ' . $role . '.');
        }
        return (int) $this->db->insert_id();
    }
}

/** Loopback recording renderer: proves handoff, not real PDF rendering. */
final class ProviderPdfRecordingRenderer
{
    public readonly string $baseUrl;
    private string $directory = '';
    private mixed $process = null;

    public function __construct()
    {
        $this->directory = sys_get_temp_dir() . '/fh-pdf-' . bin2hex(random_bytes(8));
        if (!mkdir($this->directory, 0700)) {
            throw new RuntimeException('Could not create renderer resources.');
        }
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        if (!$socket) {
            $this->close();
            throw new RuntimeException('Could not reserve renderer port.');
        }
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        if (
            file_put_contents(
                $this->directory . '/router.php',
                "<?php\n\$body = file_get_contents('php://input');\nfile_put_contents(__DIR__ . '/capture.jsonl', (\$body === false ? '' : \$body) . \"\\n\", FILE_APPEND | LOCK_EX);\nheader('Content-Type: application/pdf');\necho '%PDF-FAKE-ROB628\\n';\n",
            ) === false
        ) {
            $this->close();
            throw new RuntimeException('Could not write renderer router.');
        }
        $this->baseUrl = 'http://' . $address;
        $this->process = proc_open(
            [PHP_BINARY, '-S', $address, $this->directory . '/router.php'],
            [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', $this->directory . '/server.log', 'a'],
                2 => ['file', $this->directory . '/server.log', 'a'],
            ],
            $pipes,
            $this->directory,
        );
        if (!is_resource($this->process)) {
            $this->close();
            throw new RuntimeException('Could not start renderer.');
        }
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $connection = @stream_socket_client('tcp://' . $address, $errno, $error, 0.02);
            if ($connection) {
                fclose($connection);
                return;
            }
            usleep(20000);
        }
        $this->close();
        throw new RuntimeException('Renderer startup timed out.');
    }

    public function calls(): array
    {
        $body = is_file($this->directory . '/capture.jsonl')
            ? file_get_contents($this->directory . '/capture.jsonl')
            : '';
        if (!is_string($body) || trim($body) === '') {
            return [];
        }
        return array_map(
            static fn(string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
            array_filter(explode("\n", trim($body))),
        );
    }

    public function close(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
            $this->process = null;
        }
        if ($this->directory === '') {
            return;
        }
        foreach (['router.php', 'capture.jsonl', 'server.log'] as $name) {
            if (is_file($this->directory . '/' . $name)) {
                unlink($this->directory . '/' . $name);
            }
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
        $this->directory = '';
    }
}
