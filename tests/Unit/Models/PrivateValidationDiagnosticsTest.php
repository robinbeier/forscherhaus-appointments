<?php

namespace Tests\Unit\Models;

use InvalidArgumentException;
use Tests\TestCase;

final class PrivateValidationDiagnosticsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $CI = &get_instance();
        $CI->db->trans_start();
        setting([
            'require_first_name' => '1',
            'require_last_name' => '1',
            'require_email' => '1',
            'require_phone_number' => '0',
            'require_address' => '0',
            'require_city' => '0',
            'require_zip_code' => '0',
        ]);

        foreach (
            [
                'admins',
                'users',
                'secretaries',
                'providers',
                'customers',
                'appointments',
                'blocked_periods',
                'unavailabilities',
                'consents',
                'roles',
                'services',
                'service_categories',
                'settings',
            ]
            as $model
        ) {
            $CI->load->model($model . '_model');
        }
    }

    protected function tearDown(): void
    {
        get_instance()->db->trans_rollback();

        parent::tearDown();
    }

    public function test_user_facing_validation_diagnostics_do_not_include_private_values(): void
    {
        $cases = [
            [
                'admins_model',
                [
                    'first_name' => '',
                    'last_name' => 'Admin',
                    'email' => 'admin-private@example.test',
                    'settings' => ['password' => 'admin-password-sentinel'],
                ],
                'admin record',
            ],
            [
                'users_model',
                [
                    'first_name' => '',
                    'last_name' => 'User',
                    'email' => 'user-private@example.test',
                    'password' => 'user-password-sentinel',
                ],
                'user record',
            ],
            [
                'secretaries_model',
                [
                    'first_name' => '',
                    'last_name' => 'Secretary',
                    'email' => 'secretary-private@example.test',
                    'settings' => ['password' => 'secretary-password-sentinel'],
                ],
                'secretary record',
            ],
            [
                'customers_model',
                ['first_name' => '', 'last_name' => 'Customer', 'email' => '', 'phone_number' => '+49123456789'],
                'customer record',
            ],
            [
                'appointments_model',
                ['start_datetime' => '', 'notes' => 'appointment-private-contact-sentinel'],
                'appointment record',
            ],
            [
                'blocked_periods_model',
                ['name' => '', 'start_datetime' => '', 'end_datetime' => '', 'notes' => 'blocked-private-sentinel'],
                'blocked-period record',
            ],
            [
                'unavailabilities_model',
                [
                    'start_datetime' => '',
                    'end_datetime' => '',
                    'id_users_provider' => '',
                    'notes' => 'unavailability-private-sentinel',
                ],
                'unavailability record',
            ],
            ['consents_model', ['ip' => '', 'type' => '', 'email' => 'consent-private@example.test'], 'consent record'],
            ['roles_model', ['name' => '', 'secret' => 'role-private-sentinel'], 'role record'],
            ['services_model', ['name' => '', 'description' => 'service-private-sentinel'], 'service record'],
            [
                'service_categories_model',
                ['name' => '', 'description' => 'category-private-sentinel'],
                'service-category record',
            ],
            ['settings_model', ['name' => '', 'value' => 'setting-private-sentinel'], 'setting record'],
        ];

        foreach ($cases as [$model, $record, $label]) {
            $message = $this->captureValidationException($model, $record);

            $this->assertStringContainsString($label, $message);
            $this->assertStringNotContainsString('private', strtolower($message));
            $this->assertStringNotContainsString('sentinel', strtolower($message));
            $this->assertStringNotContainsString('customer-phone-sentinel', $message);
            $this->assertStringNotContainsString('admin-password-sentinel', $message);
            $this->assertStringNotContainsString('user-password-sentinel', $message);
            $this->assertStringNotContainsString('secretary-password-sentinel', $message);
        }

        $customerMessage = $this->captureValidationException(
            'customers_model',
            [
                'email' => '',
                'phone_number' => 'customer-phone-sentinel',
            ],
            'find_record_id',
        );
        $this->assertStringContainsString('customer email was not provided', $customerMessage);
        $this->assertStringNotContainsString('sentinel', strtolower($customerMessage));

        foreach (['providers_model', 'secretaries_model'] as $model) {
            $message = $this->captureValidationException($model, [
                'first_name' => 'Example',
                'last_name' => 'Account',
                'email' => 'invalid-email-sentinel',
                'settings' => ['password' => 'password-sentinel'],
            ]);
            $this->assertStringContainsString('Invalid email address', $message);
            $this->assertStringNotContainsString('sentinel', strtolower($message));
        }
    }

    public function test_invalid_email_and_provider_diagnostics_do_not_echo_input(): void
    {
        $secretaryMessage = $this->captureValidationException('secretaries_model', [
            'first_name' => 'Secretary',
            'last_name' => 'Example',
            'email' => 'secretary@example.test',
            'providers' => ['provider-sentinel'],
        ]);
        $this->assertStringContainsString('provider IDs must be numeric', $secretaryMessage);
        $this->assertStringNotContainsString('sentinel', strtolower($secretaryMessage));

        $adminMessage = $this->captureValidationException('admins_model', [
            'first_name' => 'Admin',
            'last_name' => 'Example',
            'email' => 'invalid-admin-email-sentinel',
            'settings' => ['password' => 'admin-password-sentinel'],
        ]);
        $this->assertStringContainsString('Invalid email address', $adminMessage);
        $this->assertStringNotContainsString('sentinel', strtolower($adminMessage));

        $customerMessage = $this->captureValidationException('customers_model', [
            'first_name' => 'Customer',
            'last_name' => 'Example',
            'email' => 'invalid-customer-email-sentinel',
        ]);
        $this->assertStringContainsString('Invalid email address', $customerMessage);
        $this->assertStringNotContainsString('sentinel', strtolower($customerMessage));
    }

    private function captureValidationException(string $model, array $record, string $method = 'validate'): string
    {
        try {
            get_instance()->{$model}->{$method}($record);
        } catch (InvalidArgumentException $exception) {
            return $exception->getMessage();
        }

        $this->fail('Expected ' . $model . ' validation to throw an InvalidArgumentException.');
    }
}
