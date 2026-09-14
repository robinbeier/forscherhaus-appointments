<?php
declare(strict_types=1);

namespace Tests\Unit\Controllers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StaffPostGuardTest extends TestCase
{
    #[DataProvider('actionProvider')]
    public function testAuthorizedPostPreservesSyntheticControllerHandoff(string $controller, string $action): void
    {
        $result = $this->probe($controller, 'authorized', 'POST', $action);
        self::assertSame(200, $result['status']);
        $resource = $controller === 'Admins' ? 'admin' : 'secretary';
        $verb = $action === 'store' ? 'add' : ($action === 'update' ? 'edit' : 'delete');
        $expected = ["auth:$verb:users"];
        if ($action === 'destroy') {
            $expected = array_merge($expected, ["dto:{$resource}_id", 'delete:41', 'json_response']);
        } else {
            $expected = array_merge($expected, $this->expectedSaveEvents($controller, $action, $resource));
        }
        self::assertSame($expected, $result['events']);
        $savedPayload = [
            'first_name' => 'Synthetic',
            'last_name' => 'Staff',
            'email' => 'staff@synthetic.invalid',
            'settings' => ['username' => 'synthetic'],
        ];
        if ($action === 'update') {
            $savedPayload = ['id' => 41] + $savedPayload;
        }
        self::assertSame($action === 'destroy' ? [] : [$savedPayload], $result['saved']);
        self::assertSame($action === 'destroy' ? '{"success":true}' : '{"success":true,"id":42}', $result['body']);
    }

    private function expectedSaveEvents(string $controller, string $action, string $resource): array
    {
        $fields =
            $controller === 'Admins'
                ? [
                    'id',
                    'first_name',
                    'last_name',
                    'email',
                    'mobile_number',
                    'phone_number',
                    'address',
                    'city',
                    'state',
                    'zip_code',
                    'notes',
                    'timezone',
                    'language',
                    'ldap_dn',
                    'settings',
                ]
                : [
                    'id',
                    'first_name',
                    'last_name',
                    'email',
                    'alt_number',
                    'phone_number',
                    'address',
                    'city',
                    'state',
                    'zip_code',
                    'notes',
                    'timezone',
                    'language',
                    'is_private',
                    'ldap_dn',
                    'id_roles',
                    'settings',
                    'providers',
                ];
        $events = ["dto:$resource", 'only:' . json_encode($fields, JSON_THROW_ON_ERROR)];
        if ($controller === 'Admins' || $action === 'store') {
            $events[] = $controller === 'Admins' ? 'optional:[]' : 'optional:{"providers":[]}';
        }
        $events[] =
            'only:' . json_encode(['username', 'password', 'notifications', 'calendar_view'], JSON_THROW_ON_ERROR);
        if ($controller === 'Secretaries' && $action === 'update') {
            $events[] = 'optional:{"providers":[]}';
        } else {
            $events[] = 'optional:[]';
        }
        $payload = $action === 'update' ? ['id' => 41] : [];
        $payload += [
            'first_name' => 'Synthetic',
            'last_name' => 'Staff',
            'email' => 'staff@synthetic.invalid',
            'settings' => ['username' => 'synthetic'],
        ];
        $events[] = 'save:' . json_encode($payload, JSON_THROW_ON_ERROR);
        return array_merge($events, ['find:42', 'json_response']);
    }

    #[DataProvider('actionProvider')]
    public function testAuthorizedNonPostStopsBeforeDtoOrMutation(string $controller, string $action): void
    {
        foreach (['GET', 'HEAD', 'PUT', 'DELETE'] as $method) {
            $result = $this->probe($controller, 'authorized', $method, $action);
            self::assertSame(405, $result['status']);
            self::assertSame(
                [
                    'auth:' . ($action === 'store' ? 'add' : ($action === 'update' ? 'edit' : 'delete')) . ':users',
                    'abort:405',
                ],
                $result['events'],
            );
            self::assertSame(['Allow' => 'POST'], $result['headers']);
        }
    }

    #[DataProvider('actionProvider')]
    public function testDeniedPermissionWinsBeforeMethodCheck(string $controller, string $action): void
    {
        $result = $this->probe($controller, 'forbidden', 'GET', $action);
        self::assertSame(403, $result['status']);
        self::assertSame(
            [
                'auth:' . ($action === 'store' ? 'add' : ($action === 'update' ? 'edit' : 'delete')) . ':users',
                'abort:403',
            ],
            $result['events'],
        );
    }

    public static function actionProvider(): array
    {
        return [
            ['Admins', 'store'],
            ['Admins', 'update'],
            ['Admins', 'destroy'],
            ['Secretaries', 'store'],
            ['Secretaries', 'update'],
            ['Secretaries', 'destroy'],
        ];
    }

    private function probe(string $controller, string $scenario, string $method, string $action): array
    {
        $process = proc_open(
            [
                PHP_BINARY,
                '-n',
                dirname(__DIR__, 2) . '/Fixtures/staff_post_guard_probe.php',
                $controller,
                $scenario,
                $method,
                $action,
            ],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $stderr);
        return json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
    }
}
