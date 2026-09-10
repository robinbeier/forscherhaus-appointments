<?php

namespace Tests\Unit\Controllers;

use Account;
use CI_Output;
use Tests\TestCase;

require_once APPPATH . 'controllers/Account.php';

class AccountRequestMethodTest extends TestCase
{
    protected function tearDown(): void
    {
        $_GET = [];
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        get_instance()->output->set_output('');
        session(['role_slug' => null, 'user_id' => null]);

        parent::tearDown();
    }

    public function testSaveRejectsGetBeforeReadingOrChangingAccountData(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET = [
            'account' => [
                'first_name' => 'Untrusted change',
            ],
        ];

        $originalOutput = get_instance()->output;
        $output = $this->createPartialMock(CI_Output::class, ['set_status_header']);
        $output->expects($this->once())->method('set_status_header')->with(405)->willReturnSelf();
        get_instance()->output = $output;

        try {
            $controller = new class extends Account {
                public function __construct() {}
            };

            $controller->save();

            $response = json_decode($output->get_output(), true);
            $this->assertSame(['success' => false, 'message' => 'Method Not Allowed'], $response);
        } finally {
            get_instance()->output = $originalOutput;
        }
    }

    public function testSaveLetsPostReachTheExistingAuthorizationCheck(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        session(['role_slug' => null, 'user_id' => null]);

        $controller = new class extends Account {
            public function __construct() {}
        };

        $controller->save();

        $response = json_decode(get_instance()->output->get_output(), true);
        $this->assertFalse($response['success']);
        $this->assertSame('You do not have the required permissions for this task.', $response['message']);
    }
}
