<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers;

use PHPUnit\Framework\TestCase;

final class BusinessSettingsWorkingPlanAtomicTest extends TestCase
{
    public function testRealControllerGuardAndHandoffSuccess(): void
    {
        $result = $this->probe('success', 'POST');
        self::assertSame(200, $result['status']);
        self::assertSame(
            [
                'auth:edit:system_settings',
                'dto:working_plan',
                'providers:get',
                'begin',
                'set:1:working_plan:{"2":{"z":1},"1":{"a":2}}',
                'set:2:working_plan:{"2":{"z":1},"1":{"a":2}}',
                'commit',
                'response',
            ],
            $result['events'],
        );
    }

    public function testNonPostStopsBeforeDtoAndProviderHandoff(): void
    {
        foreach (['GET', 'HEAD', 'PUT', 'DELETE'] as $method) {
            $result = $this->probe('success', $method);
            self::assertSame(405, $result['status']);
            self::assertSame(['auth:edit:system_settings', 'abort:405'], $result['events']);
            self::assertSame(['Allow' => 'POST'], $result['headers']);
        }
    }

    public function testFailureUsesJsonExceptionAndOwnedRollback(): void
    {
        $result = $this->probe('failure', 'POST');
        self::assertSame(500, $result['status']);
        self::assertSame(
            [
                'auth:edit:system_settings',
                'dto:working_plan',
                'providers:get',
                'begin',
                'set:1:working_plan:{"2":{"z":1},"1":{"a":2}}',
                'set:2:working_plan:{"2":{"z":1},"1":{"a":2}}',
                'rollback',
                'json_exception',
            ],
            $result['events'],
        );
    }

    public function testDeniedPermissionWinsBeforeDtoAndMethodChecks(): void
    {
        foreach (['POST', 'GET'] as $method) {
            $result = $this->probe('forbidden', $method);
            self::assertSame(500, $result['status']);
            self::assertSame(['auth:edit:system_settings', 'json_exception'], $result['events']);
        }
    }

    public function testJoinedTransactionRetainsCallerOwnership(): void
    {
        $success = $this->probe('joined-success', 'POST');
        self::assertNotContains('begin', $success['events']);
        self::assertNotContains('commit', $success['events']);
        self::assertNotContains('rollback', $success['events']);
        $failure = $this->probe('joined-failure', 'POST');
        self::assertNotContains('rollback', $failure['events']);
        self::assertSame(500, $failure['status']);
    }

    public function testEmptyPlanAndProviderListAreHandled(): void
    {
        $emptyPlan = $this->probe('emptyplan', 'POST');
        self::assertContains('set:1:working_plan:{}', $emptyPlan['events']);
        $emptyProviders = $this->probe('emptyproviders', 'POST');
        self::assertSame(
            ['auth:edit:system_settings', 'dto:working_plan', 'providers:get', 'response'],
            $emptyProviders['events'],
        );
    }

    public function testRepeatedNormalApplicationUsesSameHandoff(): void
    {
        $first = $this->probe('success', 'POST');
        $second = $this->probe('success', 'POST');
        self::assertSame($first, $second);
    }

    /** @return array{events:list<string>,status:int,body:string,headers:array<string,string>} */
    private function probe(string $scenario, string $method): array
    {
        $fixture = dirname(__DIR__, 2) . '/Fixtures/working_plan_atomic_probe.php';
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
        return json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
    }
}
