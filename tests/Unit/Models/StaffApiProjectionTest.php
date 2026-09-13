<?php

namespace Tests\Unit\Models;

use Admins_model;
use PHPUnit\Framework\Attributes\DataProvider;
use Secretaries_model;
use Tests\TestCase;

final class StaffApiProjectionTest extends TestCase
{
    private Admins_model $adminsModel;

    private Secretaries_model $secretariesModel;

    protected function setUp(): void
    {
        parent::setUp();

        $CI = &get_instance();
        $CI->load->model('admins_model');
        $CI->load->model('secretaries_model');
        $this->adminsModel = $CI->admins_model;
        $this->secretariesModel = $CI->secretaries_model;
    }

    #[DataProvider('staffModelProvider')]
    public function testStaffApiEncodeExposesOnlyTheSupportedProjection(string $modelProperty): void
    {
        $staff = $this->staffFixture($modelProperty === 'secretariesModel');

        $this->{$modelProperty}->api_encode($staff);
        $normalized = json_encode($staff, JSON_THROW_ON_ERROR);

        $expectedKeys = [
            'id',
            'firstName',
            'lastName',
            'email',
            'mobile',
            'phone',
            'address',
            'city',
            'state',
            'zip',
            'notes',
            'timezone',
            'language',
            'ldapDn',
            'settings',
        ];
        if ($modelProperty === 'secretariesModel') {
            $expectedKeys[] = 'providers';
        }
        $actualKeys = array_keys($staff);
        sort($expectedKeys);
        sort($actualKeys);
        $this->assertSame($expectedKeys, $actualKeys);

        $settingKeys = array_keys($staff['settings']);
        sort($settingKeys);
        $this->assertSame(['calendarView', 'notifications', 'username'], $settingKeys);

        $this->assertSame(42, $staff['id']);
        $this->assertSame('Synthetic', $staff['firstName']);
        $this->assertSame('Staff', $staff['lastName']);
        $this->assertSame('synthetic.staff@example.test', $staff['email']);
        $this->assertSame('synthetic-user', $staff['settings']['username']);
        $this->assertTrue($staff['settings']['notifications']);
        $this->assertSame('default', $staff['settings']['calendarView']);

        if ($modelProperty === 'secretariesModel') {
            $this->assertSame([17, 23], $staff['providers']);
        }

        foreach (['password', 'salt', 'google_token', 'caldav_password', 'internal_only'] as $privateKey) {
            $this->assertArrayNotHasKey($privateKey, $staff['settings']);
            $this->assertArrayNotHasKey($privateKey, $staff);
        }

        foreach (
            [
                'SYNTHETIC_PASSWORD_VALUE',
                'SYNTHETIC_SALT_VALUE',
                'SYNTHETIC_GOOGLE_TOKEN_VALUE',
                'SYNTHETIC_CALDAV_PASSWORD_VALUE',
                'SYNTHETIC_INTERNAL_VALUE',
            ]
            as $privateValue
        ) {
            $this->assertStringNotContainsString($privateValue, $normalized);
        }
    }

    public static function staffModelProvider(): array
    {
        return [
            'admin' => ['adminsModel'],
            'secretary' => ['secretariesModel'],
        ];
    }

    private function staffFixture(bool $withProviders): array
    {
        $staff = [
            'id' => 42,
            'first_name' => 'Synthetic',
            'last_name' => 'Staff',
            'email' => 'synthetic.staff@example.test',
            'mobile_number' => '+491234567890',
            'phone_number' => '+49401234567',
            'address' => 'Synthetic Street 1',
            'city' => 'Synthetic City',
            'state' => 'Synthetic State',
            'zip_code' => '12345',
            'notes' => 'Synthetic notes',
            'timezone' => 'Europe/Berlin',
            'language' => 'english',
            'ldap_dn' => 'uid=synthetic,dc=example,dc=test',
            'settings' => [
                'username' => 'synthetic-user',
                'notifications' => '1',
                'calendar_view' => 'default',
                'password' => 'SYNTHETIC_PASSWORD_VALUE',
                'salt' => 'SYNTHETIC_SALT_VALUE',
                'google_token' => 'SYNTHETIC_GOOGLE_TOKEN_VALUE',
                'caldav_password' => 'SYNTHETIC_CALDAV_PASSWORD_VALUE',
                'internal_only' => 'SYNTHETIC_INTERNAL_VALUE',
            ],
        ];

        if ($withProviders) {
            $staff['providers'] = [17, 23];
        }

        return $staff;
    }
}
