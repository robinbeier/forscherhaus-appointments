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

            public function __construct(Backoffice_request_dto_factory $factory, object $customersModel)
            {
                $this->backoffice_request_dto_factory = $factory;
                $this->customers_model = $customersModel;
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

    public function testReservedCustomersUnsafeSearchTerminatesBeforeModelQuery(): void
    {
        $scriptPath = tempnam(sys_get_temp_dir(), 'fh-customers-ui-smoke-search-');
        $searchMarkerPath = tempnam(sys_get_temp_dir(), 'fh-customers-ui-smoke-marker-');
        $outputPath = tempnam(sys_get_temp_dir(), 'fh-customers-ui-smoke-output-');
        $this->assertIsString($scriptPath);
        $this->assertIsString($searchMarkerPath);
        $this->assertIsString($outputPath);
        unlink($searchMarkerPath);

        $script = <<<'PHP'
        <?php
        declare(strict_types=1);

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/index.php/customers/search';
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['HTTP_HOST'] = 'localhost';

        require %s;
        require APPPATH . 'controllers/Customers.php';
        require APPPATH . 'core/Customers_ui_smoke_access_policy.php';
        require APPPATH . 'libraries/Backoffice_request_dto_factory.php';

        $searchMarkerPath = %s;
        $outputPath = %s;

        ob_start();

        register_shutdown_function(static function () use ($outputPath): void {
            file_put_contents($outputPath, (string) ob_get_contents());
        });

        session([
            'role_slug' => DB_SLUG_PROVIDER,
            'user_id' => 2,
            'username' => Customers_ui_smoke_access_policy::USERNAMES_BY_ROLE[DB_SLUG_PROVIDER],
        ]);

        $factory = new class extends Backoffice_request_dto_factory {
            public function __construct() {}

            public function buildSearchRequestDto(
                string $default_order_by = 'update_datetime DESC',
                int $default_limit = 1000,
            ): BackofficeSearchRequestDto {
                return new BackofficeSearchRequestDto('leak-attempt', $default_order_by, $default_limit, 0);
            }
        };

        $controller = new class ($factory, $searchMarkerPath) extends Customers {
            public Backoffice_request_dto_factory $backoffice_request_dto_factory;
            public object $customers_model;

            public function __construct(Backoffice_request_dto_factory $factory, string $searchMarkerPath)
            {
                $this->backoffice_request_dto_factory = $factory;
                $this->customers_model = new class ($searchMarkerPath) {
                    public function __construct(private readonly string $searchMarkerPath) {}

                    public function search(): array
                    {
                        file_put_contents($this->searchMarkerPath, 'called');

                        return [];
                    }
                };
            }
        };

        $controller->search();
        PHP;

        file_put_contents(
            $scriptPath,
            sprintf(
                $script,
                var_export(__DIR__ . '/../../bootstrap.php', true),
                var_export($searchMarkerPath, true),
                var_export($outputPath, true),
            ),
        );
        chmod($scriptPath, 0700);

        try {
            $result = $this->runPhpProcess([$scriptPath], ['APP_ENV' => 'testing']);

            $this->assertSame(1, $result['exit_code']);
            $this->assertFileDoesNotExist($searchMarkerPath);
        } finally {
            if (is_file($scriptPath)) {
                unlink($scriptPath);
            }

            if (is_file($searchMarkerPath)) {
                unlink($searchMarkerPath);
            }

            if (is_file($outputPath)) {
                unlink($outputPath);
            }
        }
    }

    /**
     * @param list<string> $arguments
     * @param array<string, string> $env
     * @return array{exit_code:int,stdout:string,stderr:string}
     */
    private function runPhpProcess(array $arguments, array $env = []): array
    {
        $command = array_merge([PHP_BINARY], $arguments);
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($command, $descriptors, $pipes, __DIR__ . '/../../..', array_merge($_ENV, $env));
        $this->assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        return [
            'exit_code' => is_int($exitCode) ? $exitCode : 1,
            'stdout' => is_string($stdout) ? $stdout : '',
            'stderr' => is_string($stderr) ? $stderr : '',
        ];
    }
}
