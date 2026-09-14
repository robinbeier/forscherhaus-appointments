<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CustomerPostGuardTest extends TestCase
{
    private const FIELDS = [
        'id',
        'first_name',
        'last_name',
        'email',
        'phone_number',
        'address',
        'city',
        'state',
        'zip_code',
        'notes',
        'timezone',
        'language',
        'custom_field_1',
        'custom_field_2',
        'custom_field_3',
        'custom_field_4',
        'custom_field_5',
        'ldap_dn',
    ];

    #[DataProvider('actions')]
    public function testAuthorizedPostPreservesControllerHandoff(string $action, string $verb): void
    {
        $result = $this->probe($action, 'authorized', 'POST');
        $expected = ["auth:$verb:customers"];
        if ($action === 'store') {
            $expected = array_merge($expected, ['session:role_slug', 'dto:customer']);
        } else {
            $expected = array_merge($expected, [
                'session:user_id',
                $action === 'update' ? 'dto:customer' : 'dto:customer_id',
                'access:17:41',
            ]);
        }
        $expected = array_merge(
            $expected,
            $action === 'destroy' ? ['find:41', 'delete:41', 'json_response'] : $this->saveEvents($action),
        );
        self::assertSame($expected, $result['events']);
        self::assertSame(200, $result['status']);
        self::assertSame($action === 'destroy' ? [] : [$this->payload($action)], $result['saved']);
        self::assertSame($action === 'destroy' ? '{"success":true}' : '{"success":true,"id":42}', $result['body']);
    }

    #[DataProvider('actions')]
    public function testNonPostStopsBeforeSessionDtoAccessOrModel(string $action, string $verb): void
    {
        foreach (['GET', 'HEAD', 'PUT', 'DELETE'] as $method) {
            $result = $this->probe($action, 'authorized', $method);
            self::assertSame(["auth:$verb:customers", 'abort:405'], $result['events']);
            self::assertSame(405, $result['status']);
            self::assertSame(['Allow' => 'POST'], $result['headers']);
            self::assertSame([], $result['saved']);
        }
    }

    #[DataProvider('actions')]
    public function testDeniedCapabilityPrecedesMethod(string $action, string $verb): void
    {
        foreach (['GET', 'POST'] as $method) {
            $result = $this->probe($action, 'forbidden', $method);
            self::assertSame(["auth:$verb:customers", 'abort:403'], $result['events']);
            self::assertSame(403, $result['status']);
            self::assertSame([], $result['saved']);
        }
    }

    public function testStorePreservesNonAdminVisibilityRestriction(): void
    {
        $prefix = ['auth:add:customers', 'session:role_slug', 'setting:limit_customer_visibility'];
        $denied = $this->probe('store', 'nonadmin-limited', 'POST');
        self::assertSame(array_merge($prefix, ['abort:403']), $denied['events']);
        self::assertSame(403, $denied['status']);
        self::assertSame([], $denied['saved']);
        $allowed = $this->probe('store', 'nonadmin-open', 'POST');
        self::assertSame(array_merge($prefix, ['dto:customer'], $this->saveEvents('store')), $allowed['events']);
        self::assertSame(200, $allowed['status']);
        self::assertSame([$this->payload('store')], $allowed['saved']);
        self::assertSame('{"success":true,"id":42}', $allowed['body']);
    }

    public function testStoreStillRejectsExistingId(): void
    {
        $result = $this->probe('store', 'existing-id', 'POST');
        self::assertSame(
            ['auth:add:customers', 'session:role_slug', 'dto:customer', 'json_response'],
            $result['events'],
        );
        self::assertSame(403, $result['status']);
        self::assertSame([], $result['saved']);
        self::assertSame(
            ['success' => false, 'message' => 'Use the update endpoint to edit an existing record.'],
            json_decode($result['body'], true, 512, JSON_THROW_ON_ERROR),
        );
    }

    public function testDeniedCustomerAccessStopsBeforeSaveFindOrDelete(): void
    {
        foreach (['update' => 'edit', 'destroy' => 'delete'] as $action => $verb) {
            $result = $this->probe($action, 'noaccess', 'POST');
            self::assertSame(
                [
                    "auth:$verb:customers",
                    'session:user_id',
                    $action === 'update' ? 'dto:customer' : 'dto:customer_id',
                    'access:17:41',
                    'abort:403',
                ],
                $result['events'],
            );
            self::assertSame(403, $result['status']);
            self::assertSame([], $result['saved']);
        }
    }

    private function payload(string $action): array
    {
        return ($action === 'update' ? ['id' => 41] : []) + [
            'first_name' => 'Synthetic',
            'last_name' => 'Customer',
            'email' => 'customer@synthetic.invalid',
        ];
    }

    private function saveEvents(string $action): array
    {
        return [
            'only:' . json_encode(self::FIELDS, JSON_THROW_ON_ERROR),
            'optional:[]',
            'save:' . json_encode($this->payload($action), JSON_THROW_ON_ERROR),
            'find:42',
            'json_response',
        ];
    }

    public static function actions(): array
    {
        return [['store', 'add'], ['update', 'edit'], ['destroy', 'delete']];
    }

    private function probe(string $action, string $scenario, string $method): array
    {
        $process = proc_open(
            [
                PHP_BINARY,
                '-n',
                dirname(__DIR__, 2) . '/Fixtures/customer_post_guard_probe.php',
                $action,
                $scenario,
                $method,
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
        self::assertSame('', $stderr);
        return json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
    }
}
