<?php

namespace Tests\Unit\Controllers;

use Recovery;
use Tests\TestCase;

require_once APPPATH . 'controllers/Recovery.php';

class RecoveryControllerTest extends TestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();

        $_POST = [];
        $_GET = [];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        http_response_code(200);
        get_instance()->output->set_output('');
    }

    public function testRecoveryRejectsMissingUsernameWithoutException(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'username' => '',
            'email' => 'admin@example.test',
        ];

        $controller = $this->createController();

        $controller->perform();

        $response = json_decode(get_instance()->output->get_output(), true);

        $this->assertSame(400, $controller->callGetContactRobinResponseStatus('', 'admin@example.test'));
        $this->assertFalse($response['success']);
        $this->assertSame(lang('password_recovery_contact_robin'), $response['message']);
        $this->assertFalse($controller->accounts->regeneratePasswordCalled);
        $this->assertFalse($controller->email_messages->sendPasswordCalled);
    }

    public function testRecoveryRejectsMissingEmailWithoutException(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'username' => 'administrator',
            'email' => '',
        ];

        $controller = $this->createController();

        $controller->perform();

        $response = json_decode(get_instance()->output->get_output(), true);

        $this->assertSame(400, $controller->callGetContactRobinResponseStatus('administrator', ''));
        $this->assertFalse($response['success']);
        $this->assertSame(lang('password_recovery_contact_robin'), $response['message']);
        $this->assertFalse($controller->accounts->regeneratePasswordCalled);
        $this->assertFalse($controller->email_messages->sendPasswordCalled);
    }

    public function testRecoveryNeutralizesCompleteRequestWithoutResettingPassword(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'username' => 'administrator',
            'email' => 'admin@example.test',
        ];

        $controller = $this->createController();

        $controller->perform();

        $response = json_decode(get_instance()->output->get_output(), true);

        $this->assertSame(200, $controller->callGetContactRobinResponseStatus('administrator', 'admin@example.test'));
        $this->assertTrue($response['success']);
        $this->assertSame(lang('password_recovery_contact_robin'), $response['message']);
        $this->assertFalse($controller->accounts->regeneratePasswordCalled);
        $this->assertFalse($controller->email_messages->sendPasswordCalled);
    }

    private function createController(): object
    {
        $controller = new class extends Recovery {
            public object $accounts;
            public object $email_messages;

            public function __construct() {}

            public function callGetContactRobinResponseStatus(string $username, string $email): int
            {
                return $this->getContactRobinResponseStatus($username, $email);
            }
        };

        $controller->accounts = new class {
            public bool $regeneratePasswordCalled = false;

            public function regenerate_password(): string
            {
                $this->regeneratePasswordCalled = true;

                return 'generated-password';
            }
        };

        $controller->email_messages = new class {
            public bool $sendPasswordCalled = false;

            public function send_password(): void
            {
                $this->sendPasswordCalled = true;
            }
        };

        return $controller;
    }
}
