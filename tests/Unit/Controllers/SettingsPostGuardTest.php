<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers;

use PHPUnit\Framework\TestCase;

/**
 * Executes the seven real settings save methods with loader, DTO, model, and
 * response doubles. This proves method ordering and handoff only, not HTTP,
 * framework CSRF, or database behavior.
 */
final class SettingsPostGuardTest extends TestCase
{
    /** @var array<string, string> */
    private array $controllers = [
        'General_settings' => 'general_settings',
        'Ldap_settings' => 'ldap_settings',
        'Legal_settings' => 'legal_settings',
        'Business_settings' => 'business_settings',
        'Booking_settings' => 'booking_settings',
        'Matomo_analytics_settings' => 'matomo_analytics_settings',
        'Google_analytics_settings' => 'google_analytics_settings',
    ];

    public function testAuthorizedPostHandsOffOneExistingSettingForEveryController(): void
    {
        foreach ($this->controllers as $controller => $key) {
            $result = $this->probe($controller, 'authorized', 'POST');
            self::assertSame(200, $result['status'], $controller);
            self::assertSame(
                [['name' => 'synthetic_setting', 'value' => 'synthetic-value', 'id' => 42]],
                $result['saved'],
                $controller,
            );
            self::assertSame(
                ['dto:' . $key],
                array_values(
                    array_filter($result['events'], static fn(string $event): bool => str_starts_with($event, 'dto:')),
                ),
                $controller,
            );
            self::assertSame(1, substr_count(implode('|', $result['events']), 'save:synthetic_setting'), $controller);
            self::assertSame(1, substr_count(implode('|', $result['events']), 'response'), $controller);
            self::assertLessThan(
                array_search('dto:' . $key, $result['events'], true),
                array_search('auth:edit:system_settings', $result['events'], true),
            );
            self::assertSame('', $result['body'], $controller);
            self::assertStringNotContainsString('synthetic-value', $result['body'], $controller);
        }
    }

    public function testAuthorizedNonPostMethodsStopBeforeDtoQueryAndWrite(): void
    {
        foreach ($this->controllers as $controller => $key) {
            foreach (['GET', 'HEAD', 'PUT', 'DELETE'] as $method) {
                $result = $this->probe($controller, 'authorized', $method);
                self::assertSame(405, $result['status'], $controller . ' ' . $method);
                self::assertSame('POST', $result['headers']['Allow'], $controller . ' ' . $method);
                self::assertNotContains('dto:' . $key, $result['events'], $controller . ' ' . $method);
                self::assertNotContains('query', $result['events'], $controller . ' ' . $method);
                self::assertSame([], $result['saved'], $controller . ' ' . $method);
            }
        }
    }

    public function testDeniedPermissionStopsBeforeDtoQueryAndWrite(): void
    {
        foreach ($this->controllers as $controller => $key) {
            $result = $this->probe($controller, 'forbidden', 'POST');
            self::assertSame(500, $result['status'], $controller);
            self::assertContains('auth:edit:system_settings', $result['events'], $controller);
            self::assertNotContains('dto:' . $key, $result['events'], $controller);
            self::assertNotContains('query', $result['events'], $controller);
            self::assertSame([], $result['saved'], $controller);
        }
    }

    public function testDeniedPermissionWinsBeforeMethodRejection(): void
    {
        foreach ($this->controllers as $controller => $key) {
            $result = $this->probe($controller, 'forbidden', 'GET');
            self::assertSame(500, $result['status'], $controller);
            self::assertContains('json_exception', $result['events'], $controller);
            self::assertNotContains('abort:405', $result['events'], $controller);
            self::assertNotContains('dto:' . $key, $result['events'], $controller);
        }
    }

    /** @return array{events:list<string>,status:int,headers:array<string,string>,body:string,saved:list<array<string,mixed>>} */
    private function probe(string $controller, string $scenario, string $method): array
    {
        $fixture = dirname(__DIR__, 2) . '/Fixtures/settings_post_guard_probe.php';
        $process = proc_open(
            [PHP_BINARY, '-n', $fixture, $controller, $scenario, $method],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 3),
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $stderr);
        $result = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($result);
        return $result;
    }
}
