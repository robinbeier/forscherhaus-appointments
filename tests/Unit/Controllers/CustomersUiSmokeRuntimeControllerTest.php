<?php

namespace Tests\Unit\Controllers;

use Backoffice_request_dto_factory;
use BackofficeSearchRequestDto;
use Customers;
use Customers_ui_smoke_access_policy;
use Login;
use LoginValidateRequestDto;
use Tests\TestCase;

require_once APPPATH . 'controllers/Customers.php';
require_once APPPATH . 'controllers/Login.php';
require_once APPPATH . 'core/Customers_ui_smoke_access_policy.php';
require_once APPPATH . 'libraries/Backoffice_request_dto_factory.php';
require_once APPPATH . 'libraries/Auth_request_dto_factory.php';

final class CustomersUiSmokeRuntimeControllerTest extends TestCase
{
    protected function tearDown(): void
    {
        $_POST = [];
        $_GET = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        get_instance()->output->set_output('');
        session([
            'role_slug' => null,
            'user_id' => null,
            'username' => null,
        ]);

        parent::tearDown();
    }

    public function testReservedCustomersSearchReturnsExactEmptyArrayWithoutTouchingRealSearch(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        session([
            'role_slug' => DB_SLUG_PROVIDER,
            'user_id' => 2,
            'username' => Customers_ui_smoke_access_policy::USERNAMES_BY_ROLE[DB_SLUG_PROVIDER],
        ]);

        $factory = $this->createMock(Backoffice_request_dto_factory::class);
        $factory
            ->expects($this->once())
            ->method('buildSearchRequestDto')
            ->willReturn(new BackofficeSearchRequestDto('', 'update_datetime DESC', 20, 0));

        $customersModel = new class {
            public bool $searchCalled = false;

            public function search(): array
            {
                $this->searchCalled = true;

                return [['id' => 1]];
            }
        };

        $controller = new class ($factory, $customersModel) extends Customers {
            public Backoffice_request_dto_factory $backoffice_request_dto_factory;
            public object $customers_model;
            public object $roles_model;
            public object $users_model;

            public function __construct(Backoffice_request_dto_factory $factory, object $customersModel)
            {
                $this->backoffice_request_dto_factory = $factory;
                $this->customers_model = $customersModel;
                $this->roles_model = new class {
                    public function value(int $roleId, string $field): string
                    {
                        return DB_SLUG_PROVIDER;
                    }
                };
                $this->users_model = new class {
                    public function value(int $userId, string $field): int
                    {
                        return 2;
                    }
                };
            }
        };

        $controller->search();

        $this->assertSame('[]', get_instance()->output->get_output());
        $this->assertFalse($customersModel->searchCalled);
    }

    public function testReservedCustomersUsernameNeverFallsBackToLdap(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';

        $authFactory = $this->createMock(\Auth_request_dto_factory::class);
        $authFactory
            ->expects($this->once())
            ->method('buildLoginValidateRequestDto')
            ->willReturn(
                new LoginValidateRequestDto(
                    Customers_ui_smoke_access_policy::USERNAMES_BY_ROLE[DB_SLUG_PROVIDER],
                    'synthetic-password',
                ),
            );

        $accounts = new class {
            public function check_login(string $username, string $password): array
            {
                return [];
            }
        };

        $ldapClient = new class {
            public bool $called = false;

            public function check_login(string $username, string $password): array
            {
                $this->called = true;

                return ['user_id' => 999];
            }
        };

        $session = new class {
            public bool $regenerated = false;

            public function sess_regenerate(): void
            {
                $this->regenerated = true;
            }
        };

        $controller = new class ($authFactory, $accounts, $ldapClient, $session) extends Login {
            public \Auth_request_dto_factory $auth_request_dto_factory;
            public object $accounts;
            public object $ldap_client;
            public object $session;
            public object $db;

            public function __construct(
                \Auth_request_dto_factory $authFactory,
                object $accounts,
                object $ldapClient,
                object $session,
            ) {
                $this->auth_request_dto_factory = $authFactory;
                $this->accounts = $accounts;
                $this->ldap_client = $ldapClient;
                $this->session = $session;
                $this->db = get_instance()->db;
            }
        };

        $controller->validate();

        $response = json_decode(get_instance()->output->get_output(), true);

        $this->assertFalse($ldapClient->called);
        $this->assertFalse($session->regenerated);
        $this->assertFalse($response['success']);
        $this->assertSame(lang('invalid_credentials_provided'), $response['message']);
    }
}
