<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers;

use AppointmentApiUpdateException;
use Appointments_api_v1;
use ReflectionClass;
use RuntimeException;
use Tests\TestCase;

require_once APPPATH . 'models/Appointments_model.php';
require_once APPPATH . 'controllers/api/v1/Appointments_api_v1.php';

final class AppointmentsApiUpdateStatusTest extends TestCase
{
    private object $originalOutput;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalOutput = get_instance()->output;
        get_instance()->output = new class extends \EA_Output {
            public int $statusCode = 200;

            public function set_status_header($code = 200, $text = '')
            {
                $this->statusCode = (int) $code;
                return parent::set_status_header($code, $text);
            }
        };
    }

    protected function tearDown(): void
    {
        get_instance()->output = $this->originalOutput;
        parent::tearDown();
    }

    public function testExpectedUpdateRaceStatusesUseGenericJsonResponse(): void
    {
        $controller = (new ReflectionClass(Appointments_api_v1::class))->newInstanceWithoutConstructor();
        $method = (new ReflectionClass(Appointments_api_v1::class))->getMethod('respondToWriteValidationError');

        foreach ([404, 409] as $status) {
            get_instance()->output->set_output('');

            self::assertTrue(
                $method->invoke($controller, new AppointmentApiUpdateException('internal concurrency detail', $status)),
            );
            self::assertSame($status, get_instance()->output->statusCode);
            self::assertSame(['success' => false], json_decode(get_instance()->output->get_output(), true));
            self::assertStringNotContainsString('internal concurrency detail', get_instance()->output->get_output());
        }

        get_instance()->output->set_output('');
        self::assertFalse($method->invoke($controller, new RuntimeException('database failure', 409)));
        self::assertSame('', get_instance()->output->get_output());
    }
}
