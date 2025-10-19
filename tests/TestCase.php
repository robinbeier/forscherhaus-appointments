<?php

namespace Tests;

use CI_Controller;
use EA_Controller;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

/**
 * Parent test case sharing common test functionality.
 */
class TestCase extends PHPUnitTestCase
{
    /**
     * @var EA_Controller|CI_Controller
     */
    private static EA_Controller|CI_Controller $CI;

    /**
     * Load the framework instance.
     *
     * @return void
     */
    public static function setUpBeforeClass(): void
    {
        self::$CI = &get_instance();

        self::requireFile(APPPATH . 'libraries/Provider_utilization.php');

        self::ensureLegacyClass('Appointments_model');
        self::ensureLegacyClass('Services_model');
        self::ensureLegacyClass('Unavailabilities_model');
        self::ensureLegacyClass('Blocked_periods_model');
    }

    /**
     * Require a CodeIgniter resource file if it exists.
     *
     * @param string $path
     *
     * @return void
     */
    protected static function requireFile(string $path): void
    {
        if (is_file($path)) {
            require_once $path;
        }
    }

    /**
     * Define minimal legacy classes so PHPUnit can create mocks.
     *
     * @param string $class
     *
     * @return void
     */
    protected static function ensureLegacyClass(string $class): void
    {
        if (class_exists($class, false)) {
            return;
        }

        switch ($class) {
            case 'Appointments_model':
                eval('class Appointments_model { public function query() {} }');
                break;
            case 'Services_model':
                eval(
                    'class Services_model { public function get($where = null, $limit = null, $offset = null, $orderBy = null) {} }'
                );
                break;
            case 'Unavailabilities_model':
                eval(
                    'class Unavailabilities_model { public function get($where = null, $limit = null, $offset = null, $orderBy = null) {} }'
                );
                break;
            case 'Blocked_periods_model':
                eval('class Blocked_periods_model { public function get_for_period($startDate, $endDate) {} }');
                break;
            default:
                eval('class ' . $class . ' {}');
        }
    }
}
