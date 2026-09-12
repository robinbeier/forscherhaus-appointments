<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ReleaseGate\CustomerRoleBoundaryProbe;
use Tests\Integration\Support\DefenseCycleFixtures;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__, 2) . '/scripts/release-gate/lib/GateHttpClient.php';
require_once dirname(__DIR__, 2) . '/scripts/release-gate/lib/CustomerRoleBoundaryProbe.php';
require_once __DIR__ . '/Support/DefenseCycleFixtures.php';
require_once __DIR__ . '/Support/DefenseCycleHttpServer.php';

final class CustomerRoleBoundaryProbeTest extends TestCase
{
    public function testStaffRowsAreDeniedAcrossCustomerReadUpdateDeleteWithPositiveControls(): void
    {
        if (getenv('FH_DEFENSE_ISOLATED') !== '1') {
            self::markTestSkipped('Run scripts/ci/run_defense_cycle.sh with its fresh synthetic stack.');
        }

        $fixture = new DefenseCycleFixtures();
        $server = null;
        $deleteCustomerId = 0;
        try {
            $fixture->create();
            $db = get_instance()->db;
            $customerRole = $db->get_where('roles', ['slug' => 'customer'])->row_array();
            self::assertNotEmpty($customerRole);
            $db->insert('users', [
                'first_name' => 'Synthetic',
                'last_name' => 'Delete Control',
                'email' => $fixture->run . '_delete@synthetic.invalid',
                'phone_number' => '000000000',
                'notes' => $fixture->run,
                'timezone' => 'UTC',
                'language' => 'english',
                'id_roles' => (int) $customerRole['id'],
                'is_private' => 1,
            ]);
            $deleteCustomerId = (int) $db->insert_id();
            self::assertGreaterThan(0, $deleteCustomerId);

            $server = new DefenseCycleHttpServer();
            $actor = $fixture->row('users', $fixture->actorId);
            $result = (new CustomerRoleBoundaryProbe(
                new \ReleaseGate\GateHttpClient($server->baseUrl, additionalHeaders: ['X-FH-Ordinary-Probe' => '1']),
                $db,
            ))->run(
                [
                    'user_id' => $fixture->actorId,
                    'username' => $fixture->run . '_actor',
                    'password' => $fixture->password,
                    'email' => (string) $actor['email'],
                    'marker' => (string) $actor['notes'],
                ],
                [
                    'profile' => 'customer_boundary',
                    'marker' => $fixture->run,
                    'search_marker' => $fixture->run,
                    'provider_target_id' => $fixture->providerId,
                    'admin_target_id' => $fixture->actorId,
                    'customer_update_id' => $fixture->customerId,
                    'customer_delete_id' => $deleteCustomerId,
                ],
            );

            self::assertSame('verified', $result['status']);
            self::assertSame('complete', $result['coverage']);
            self::assertSame(200, $result['search_status']);
            self::assertSame(
                [
                    'provider' => ['find' => 403, 'update' => 403, 'destroy' => 403],
                    'admin' => ['find' => 403, 'update' => 403, 'destroy' => 403],
                ],
                $result['denial_statuses'],
            );
            self::assertSame(['find' => 200, 'update' => 200, 'destroy' => 200], $result['positive_statuses']);
            self::assertSame(0, $db->get_where('users', ['id' => $deleteCustomerId])->num_rows());
        } finally {
            try {
                $server?->close();
            } finally {
                if ($deleteCustomerId > 0) {
                    get_instance()->db->delete('users', ['id' => $deleteCustomerId, 'notes' => $fixture->run]);
                }
                $fixture->cleanup();
            }
        }
    }
}
