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

        self::ensureLegacyClass('Appointments_model');
        self::ensureLegacyClass('Services_model');
        self::ensureLegacyClass('Unavailabilities_model');
        self::ensureLegacyClass('Blocked_periods_model');

        self::requireFile(APPPATH . 'libraries/Provider_utilization.php');
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

        $modelFiles = [
            'Appointments_model' => APPPATH . 'models/Appointments_model.php',
            'Services_model' => APPPATH . 'models/Services_model.php',
            'Unavailabilities_model' => APPPATH . 'models/Unavailabilities_model.php',
            'Blocked_periods_model' => APPPATH . 'models/Blocked_periods_model.php',
        ];

        if (isset($modelFiles[$class])) {
            self::requireFile(APPPATH . 'core/EA_Model.php');
            self::requireFile($modelFiles[$class]);
        }

        if (class_exists($class, false)) {
            return;
        }

        $stubs = [
            'Appointments_model' => 'class Appointments_model { public function query() {} }',
            'Services_model' =>
                'class Services_model { public function get($where = null, $limit = null, $offset = null, $orderBy = null) {} }',
            'Unavailabilities_model' =>
                'class Unavailabilities_model { public function get($where = null, $limit = null, $offset = null, $orderBy = null) {} }',
            'Blocked_periods_model' =>
                'class Blocked_periods_model { public function get_for_period($startDate, $endDate) {} }',
        ];

        if (isset($stubs[$class])) {
            eval($stubs[$class]);
            return;
        }

        eval('class ' . $class . ' {}');
    }
}
