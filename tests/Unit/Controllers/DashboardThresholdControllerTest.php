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
        $_GET = [];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        get_instance()->output->set_output('');
        session([
            'role_slug' => null,
            'user_id' => null,
            'dashboard_conflict_threshold' => null,
        ]);
    }

    public function testThresholdPersistsValidValue(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';

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
        $_SERVER['REQUEST_METHOD'] = 'POST';

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

    public function testThresholdPersistsValueInSession(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';

        session([
            'role_slug' => DB_SLUG_ADMIN,
            'user_id' => 1,
        ]);

        $_POST = ['threshold' => '0.72'];

        $controller = $this->createSessionPersistingController();

        $controller->threshold();

        $response = json_decode(get_instance()->output->get_output(), true);

        $this->assertTrue($response['success']);
        $this->assertSame('0.72', session('dashboard_conflict_threshold'));
    }

    public function testThresholdRejectsInvalidValues(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';

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
        $_SERVER['REQUEST_METHOD'] = 'POST';

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

    public function testThresholdRejectsGetRequest(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';

        session([
            'role_slug' => DB_SLUG_ADMIN,
            'user_id' => 1,
        ]);

        $_GET = ['threshold' => '0.2'];

        $controller = $this->createController();

        $controller->threshold();

        $response = json_decode(get_instance()->output->get_output(), true);

        $this->assertFalse($response['success']);
        $this->assertSame('Forbidden', $response['message']);
        $this->assertSame([], $controller->savedThresholds);
    }

    public function testDashboardThresholdUsesSessionValueWhenValid(): void
    {
        session(['dashboard_conflict_threshold' => '0.66']);

        $controller = $this->createThresholdResolverController(0.9);

        $this->assertEquals(0.66, $controller->callGetDashboardThreshold());
    }

    public function testDashboardThresholdFallsBackToConfiguredValueWhenSessionValueIsInvalid(): void
    {
        $invalidValues = ['invalid', '-1', '1.2', []];

        foreach ($invalidValues as $invalidValue) {
            session(['dashboard_conflict_threshold' => $invalidValue]);

            $controller = $this->createThresholdResolverController(0.9);

            $this->assertEquals(0.9, $controller->callGetDashboardThreshold());
        }
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

    private function createSessionPersistingController(): object
    {
        return new class extends Dashboard {
            public function __construct()
            {
            }
        };
    }

    private function createThresholdResolverController(float $configuredThreshold): object
    {
        return new class ($configuredThreshold) extends Dashboard {
            private float $configuredThreshold;

            public function __construct(float $configuredThreshold)
            {
                $this->configuredThreshold = $configuredThreshold;
            }

            public function callGetDashboardThreshold(): float
            {
                return $this->getDashboardThreshold();
            }

            protected function getConfiguredThreshold(): float
            {
                return $this->configuredThreshold;
            }
        };
    }
}
