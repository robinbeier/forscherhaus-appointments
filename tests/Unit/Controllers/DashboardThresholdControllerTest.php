<?php

namespace Tests\Unit\Controllers;

use Dashboard;
use Tests\TestCase;

require_once APPPATH . 'controllers/Dashboard.php';

class DashboardThresholdControllerTest extends TestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();

        $_POST = [];
        get_instance()->output->set_output('');
        session([
            'role_slug' => null,
            'user_id' => null,
        ]);
    }

    public function testThresholdPersistsValidValue(): void
    {
        session([
            'role_slug' => DB_SLUG_ADMIN,
            'user_id' => 1,
        ]);

        $_POST = ['threshold' => '0.82'];

        $controller = $this->createController();

        $controller->threshold();

        $response = json_decode(get_instance()->output->get_output(), true);

        $this->assertTrue($response['success']);
        $this->assertSame(0.82, $response['threshold']);
        $this->assertSame([0.82], $controller->savedThresholds);
    }

    public function testThresholdAcceptsBoundaryValues(): void
    {
        session([
            'role_slug' => DB_SLUG_ADMIN,
            'user_id' => 1,
        ]);

        $controller = $this->createController();

        $_POST = ['threshold' => '0'];
        $controller->threshold();
        $firstResponse = json_decode(get_instance()->output->get_output(), true);

        $this->assertTrue($firstResponse['success']);
        $this->assertEquals(0.0, $firstResponse['threshold']);

        get_instance()->output->set_output('');

        $_POST = ['threshold' => '1'];
        $controller->threshold();
        $secondResponse = json_decode(get_instance()->output->get_output(), true);

        $this->assertTrue($secondResponse['success']);
        $this->assertEquals(1.0, $secondResponse['threshold']);
        $this->assertSame([0.0, 1.0], $controller->savedThresholds);
    }

    public function testThresholdRejectsInvalidValues(): void
    {
        session([
            'role_slug' => DB_SLUG_ADMIN,
            'user_id' => 1,
        ]);

        $invalidValues = ['-0.01', '1.01', 'invalid'];

        foreach ($invalidValues as $invalidValue) {
            $controller = $this->createController();

            $_POST = ['threshold' => $invalidValue];

            $controller->threshold();

            $response = json_decode(get_instance()->output->get_output(), true);

            $this->assertFalse($response['success']);
            $this->assertSame(lang('dashboard_conflict_threshold_invalid'), $response['message']);
            $this->assertSame([], $controller->savedThresholds);

            get_instance()->output->set_output('');
        }
    }

    public function testThresholdRejectsNonAdminAccess(): void
    {
        session([
            'role_slug' => DB_SLUG_PROVIDER,
            'user_id' => 2,
        ]);

        $_POST = ['threshold' => '0.8'];

        $controller = $this->createController();

        $controller->threshold();

        $response = json_decode(get_instance()->output->get_output(), true);

        $this->assertFalse($response['success']);
        $this->assertSame('Forbidden', $response['message']);
        $this->assertSame([], $controller->savedThresholds);
    }

    private function createController(): object
    {
        return new class extends Dashboard {
            public array $savedThresholds = [];

            public function __construct()
            {
            }

            protected function persistThreshold(float $threshold): void
            {
                $this->savedThresholds[] = $threshold;
            }
        };
    }
}
