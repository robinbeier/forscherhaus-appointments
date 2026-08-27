<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

final class BookingWebMcpRuntimeTest extends TestCase
{
    private string $repositoryRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repositoryRoot = dirname(__DIR__, 3);
    }

    public function testJavaScriptRuntimeContract(): void
    {
        if (!is_file($this->repositoryRoot . '/node_modules/moment-timezone/package.json')) {
            $this->markTestSkipped('Frontend dependencies are unavailable in this PHP-only test shard.');
        }

        $process = proc_open(
            ['node', '--test', 'tests/JavaScript/booking_webmcp.test.js'],
            [
                0 => ['file', '/dev/null', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $this->repositoryRoot,
        );

        self::assertIsResource($process);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        self::assertSame(0, $exitCode, trim($stdout . PHP_EOL . $stderr));
        self::assertMatchesRegularExpression('/(?:#|ℹ) pass 29/', $stdout);
        self::assertMatchesRegularExpression('/(?:#|ℹ) fail 0/', $stdout);
    }

    public function testFeatureFlagAndWriteBoundaryAreWiredFailClosed(): void
    {
        $sampleConfig = $this->read('config-sample.php');
        $appConfig = $this->read('application/config/config.php');
        $controller = $this->read('application/controllers/Booking.php');
        $view = $this->read('application/views/pages/booking.php');
        $adapter = $this->read('assets/js/pages/booking_webmcp.js');

        self::assertStringContainsString('const WEBMCP_BOOKING_PILOT_ENABLED = false;', $sampleConfig);
        self::assertStringContainsString("defined('Config::WEBMCP_BOOKING_PILOT_ENABLED')", $appConfig);
        self::assertStringContainsString(
            "'webmcp_booking_pilot_enabled' => \$webmcp_booking_pilot_enabled",
            $controller,
        );
        self::assertStringContainsString("vars('webmcp_booking_pilot_enabled') === '1'", $view);
        self::assertStringContainsString("asset_url('assets/js/pages/booking_webmcp.js')", $view);

        foreach (
            [
                'booking/register',
                'booking_confirmation',
                'booking_cancellation',
                'delete_personal_information',
                'privacy/delete',
            ]
            as $forbiddenPath
        ) {
            self::assertStringNotContainsString($forbiddenPath, $adapter, $forbiddenPath);
        }
    }

    public function testAvailabilityAndPreparationSeamsRemainNarrow(): void
    {
        $httpClient = $this->read('assets/js/http/booking_http_client.js');
        $bookingPage = $this->read('assets/js/pages/booking.js');

        $queryStart = strpos($httpClient, 'function queryAvailableHours');
        $queryEnd = strpos($httpClient, 'function renderAvailableHours', $queryStart);
        self::assertIsInt($queryStart);
        self::assertIsInt($queryEnd);
        $queryFunction = substr($httpClient, $queryStart, $queryEnd - $queryStart);
        self::assertSame(1, substr_count($queryFunction, "siteUrl('booking/get_available_hours')"));
        self::assertStringNotContainsString('booking/register', $queryFunction);

        $prepareStart = strpos($bookingPage, 'async function prepareBookingSelection');
        $prepareEnd = strpos($bookingPage, "document.addEventListener('DOMContentLoaded'", $prepareStart);
        self::assertIsInt($prepareStart);
        self::assertIsInt($prepareEnd);
        $prepareFunction = substr($bookingPage, $prepareStart, $prepareEnd - $prepareStart);

        foreach (
            ['#first-name', '#last-name', '#email', '#phone-number', '#address', '#notes', 'registerAppointment']
            as $forbidden
        ) {
            self::assertStringNotContainsString($forbidden, $prepareFunction, $forbidden);
        }
        self::assertStringContainsString(
            'App.Http.Booking.getAvailableHours(selectedDate, preparationSignal)',
            $prepareFunction,
        );
        self::assertStringContainsString('App.Http.Booking.getUnavailableDates(', $prepareFunction);
        self::assertStringContainsString('{preserveSelection: true, signal: preparationSignal}', $prepareFunction);
        self::assertStringContainsString("$('.wizard-frame').stop(true, true).hide()", $prepareFunction);
        self::assertStringContainsString("$('#wizard-frame-3').show()", $prepareFunction);
        self::assertStringNotContainsString(".trigger('click')", $prepareFunction);

        $clearDefinition = strpos($prepareFunction, 'const clearStaleAvailability');
        $clearBeforeMutation = strpos($prepareFunction, 'clearStaleAvailability(true);', $clearDefinition);
        $serviceMutation = strpos($prepareFunction, '$selectService.val(String(serviceId))');
        $catchBlock = strpos($prepareFunction, '} catch (error) {');
        $clearAfterFailure = strpos($prepareFunction, 'clearStaleAvailability();', $catchBlock);

        self::assertIsInt($clearDefinition);
        self::assertIsInt($clearBeforeMutation);
        self::assertIsInt($serviceMutation);
        self::assertIsInt($catchBlock);
        self::assertIsInt($clearAfterFailure);
        self::assertLessThan($serviceMutation, $clearBeforeMutation);
        self::assertGreaterThan($catchBlock, $clearAfterFailure);
    }

    private function read(string $relativePath): string
    {
        $contents = file_get_contents($this->repositoryRoot . '/' . $relativePath);
        self::assertIsString($contents);
        return $contents;
    }
}
