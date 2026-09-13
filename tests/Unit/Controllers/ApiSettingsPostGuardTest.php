<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers;

use PHPUnit\Framework\TestCase;

/**
 * Runs the real Api_settings::save method with framework, DTO, settings, and
 * response doubles. This proves method guarding and model handoff only; it does
 * not prove real HTTP, framework CSRF, DB persistence, or response redaction.
 */
final class ApiSettingsPostGuardTest extends TestCase
{
    public function testDeniedPermissionNeverBuildsRequestOrWrites(): void
    {
        $result = $this->probe('forbidden', 'POST');

        self::assertContains('auth_check:edit:system_settings', $result['events']);
        self::assertContains('json_exception', $result['events']);
        self::assertNotContains('dto:build', $result['events']);
        self::assertNotContains('query', $result['events']);
        self::assertNotContains('save_batch', $result['events']);
        self::assertSame([], $result['saved']);
    }

    public function testAuthorizedNonPostMethodsAreRejectedBeforeRequestOrWrite(): void
    {
        foreach (['GET', 'HEAD', 'PUT', 'DELETE'] as $method) {
            $result = $this->probe('authorized', $method);

            self::assertSame(405, $result['status']);
            self::assertSame('POST', $result['headers']['Allow']);
            self::assertContains('auth_check:edit:system_settings', $result['events']);
            self::assertNotContains('dto:build', $result['events']);
            self::assertNotContains('query', $result['events']);
            self::assertNotContains('save_batch', $result['events']);
            self::assertSame([], $result['saved']);
        }
    }

    public function testAuthorizedPostHandsOffSettingsAndDoesNotReturnToken(): void
    {
        $result = $this->probe('authorized', 'POST');

        self::assertSame(200, $result['status']);
        self::assertContains('dto:build', $result['events']);
        self::assertContains('save_batch', $result['events']);
        self::assertContains('response', $result['events']);
        self::assertSame('', $result['body']);
        self::assertSame(
            [
                [
                    ['name' => 'api_token', 'value' => 'synthetic-token'],
                    ['name' => 'synthetic_setting', 'value' => 'second-value'],
                ],
            ],
            $result['saved'],
        );
        self::assertSame(1, substr_count(implode('|', $result['events']), 'save_batch'));
        self::assertStringNotContainsString('synthetic-token', $result['body']);
        self::assertLessThan(
            array_search('dto:build', $result['events'], true),
            array_search('auth_check:edit:system_settings', $result['events'], true),
        );
    }

    public function testAuthorizedPostWriteFailureIsPropagated(): void
    {
        $result = $this->probe('write_failure', 'POST');

        self::assertSame(500, $result['status']);
        self::assertContains('save_batch', $result['events']);
        self::assertContains('json_exception', $result['events']);
        self::assertNotContains('response', $result['events']);
        self::assertSame(
            [
                [
                    ['name' => 'api_token', 'value' => 'synthetic-token'],
                    ['name' => 'synthetic_setting', 'value' => 'second-value'],
                ],
            ],
            $result['saved'],
        );
        self::assertStringContainsString('synthetic settings failure', $result['body']);
        self::assertStringNotContainsString('synthetic-token', $result['body']);
    }

    /** @return array{events:list<string>,status:int,headers:array<string,string>,body:string,saved:list<array<string,mixed>>} */
    private function probe(string $scenario, string $method): array
    {
        $fixture = dirname(__DIR__, 2) . '/Fixtures/api_settings_controller_probe.php';
        $process = proc_open(
            [PHP_BINARY, '-n', $fixture, $scenario, $method],
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
