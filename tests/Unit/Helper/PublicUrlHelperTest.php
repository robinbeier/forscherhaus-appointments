<?php

declare(strict_types=1);

namespace Tests\Helper;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PublicUrlHelperTest extends TestCase
{
    #[DataProvider('urlCases')]
    public function testPublicLinksUseConfiguredInstallationWithoutChangingRequestUrls(
        string $publicBase,
        string $requestBase,
        string $indexPage,
        string $suffix,
        bool $queryStrings,
        string $expectedPublic,
        string $expectedRequest,
    ): void {
        $root = dirname(__DIR__, 3);
        $script =
            'define("BASEPATH", ' .
            var_export($root . '/system/', true) .
            ');' .
            'define("APPPATH", ' .
            var_export($root . '/application/', true) .
            ');' .
            'class Config { const BASE_URL = ' .
            var_export($publicBase, true) .
            '; }' .
            'require BASEPATH . "core/Config.php";' .
            '$CI = (object) ["config" => (new ReflectionClass("CI_Config"))->newInstanceWithoutConstructor()];' .
            '$CI->config->config = ' .
            var_export(
                [
                    'base_url' => $requestBase,
                    'index_page' => $indexPage,
                    'url_suffix' => $suffix,
                    'enable_query_strings' => $queryStrings,
                ],
                true,
            ) .
            ';' .
            'function &get_instance() { global $CI; return $CI; }' .
            'require BASEPATH . "helpers/url_helper.php";' .
            'require APPPATH . "helpers/config_helper.php";' .
            'require APPPATH . "helpers/EA_url_helper.php";' .
            '$route = "booking/reschedule/abc123?view=details";' .
            'echo json_encode([public_site_url($route), site_url($route)]);';

        $process = proc_open(
            [PHP_BINARY, '-r', $script],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $errors);
        self::assertSame([$expectedPublic, $expectedRequest], json_decode($output, true, 512, JSON_THROW_ON_ERROR));
    }

    public static function urlCases(): iterable
    {
        yield 'untrusted host' => [
            'https://school.example',
            'http://attacker.example',
            'index.php',
            '',
            false,
            'https://school.example/index.php/booking/reschedule/abc123?view=details',
            'http://attacker.example/index.php/booking/reschedule/abc123?view=details',
        ];
        yield 'configured subdirectory and internal runtime' => [
            'https://school.example:8443/appointments/',
            'http://nginx/internal/',
            '',
            '',
            false,
            'https://school.example:8443/appointments/booking/reschedule/abc123?view=details',
            'http://nginx/internal/booking/reschedule/abc123?view=details',
        ];
        yield 'suffix preserved' => [
            'https://school.example',
            'https://attacker.example/other',
            'index.php',
            '.html',
            false,
            'https://school.example/index.php/booking/reschedule/abc123.html?view=details',
            'https://attacker.example/other/index.php/booking/reschedule/abc123.html?view=details',
        ];
        yield 'query route preserved' => [
            'https://school.example',
            'http://nginx',
            'index.php',
            '',
            true,
            'https://school.example/index.phpbooking/reschedule/abc123?view=details',
            'http://nginx/index.phpbooking/reschedule/abc123?view=details',
        ];
    }
}
