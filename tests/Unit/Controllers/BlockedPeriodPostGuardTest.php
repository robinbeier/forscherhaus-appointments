<?php
declare(strict_types=1);

namespace Tests\Unit\Controllers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BlockedPeriodPostGuardTest extends TestCase
{
    private const FIELDS = ['id', 'name', 'start_datetime', 'end_datetime', 'notes'];

    #[DataProvider('actions')]
    public function testAuthorizedPostPreservesControllerHandoff(string $action, string $verb): void
    {
        $result = $this->probe($action, 'authorized', 'POST');
        $expected = [
            "auth:$verb:blocked_periods",
            $action === 'destroy' ? 'dto:blocked_period_id' : 'dto:blocked_period',
        ];
        if ($action === 'destroy') {
            $expected = array_merge($expected, ['find:41', 'delete:41', 'json_response']);
        } else {
            $expected = array_merge($expected, [
                'only:' . json_encode(self::FIELDS, JSON_THROW_ON_ERROR),
                'optional:[]',
                'save:' . json_encode($this->payload($action), JSON_THROW_ON_ERROR),
                $action === 'update' ? 'find:41' : 'find:42',
                'json_response',
            ]);
        }
        self::assertSame($expected, $result['events']);
        self::assertSame(200, $result['status']);
        self::assertSame($action === 'destroy' ? [] : [$this->payload($action)], $result['saved']);
        self::assertSame(
            $action === 'destroy'
                ? '{"success":true}'
                : json_encode(['success' => true, 'id' => $action === 'update' ? 41 : 42], JSON_THROW_ON_ERROR),
            $result['body'],
        );
    }

    #[DataProvider('actions')]
    public function testAuthorizedNonPostStopsBeforeDtoOrMutation(string $action, string $verb): void
    {
        foreach (['GET', 'HEAD', 'PUT', 'DELETE'] as $method) {
            $result = $this->probe($action, 'authorized', $method);
            self::assertSame(["auth:$verb:blocked_periods", 'abort:405'], $result['events']);
            self::assertSame(405, $result['status']);
            self::assertSame(['Allow' => 'POST'], $result['headers']);
            self::assertSame([], $result['saved']);
        }
    }

    #[DataProvider('actions')]
    public function testDeniedCapabilityPrecedesMethod(string $action, string $verb): void
    {
        foreach (['GET', 'POST'] as $method) {
            $result = $this->probe($action, 'forbidden', $method);
            self::assertSame(["auth:$verb:blocked_periods", 'abort:403'], $result['events']);
            self::assertSame(403, $result['status']);
            self::assertSame([], $result['saved']);
        }
    }

    #[DataProvider('actions')]
    public function testModelFailureBecomesJsonExceptionWithoutSuccess(string $action, string $verb): void
    {
        $result = $this->probe($action, 'model-failure', 'POST');
        $expected = [
            "auth:$verb:blocked_periods",
            $action === 'destroy' ? 'dto:blocked_period_id' : 'dto:blocked_period',
        ];
        if ($action === 'destroy') {
            $expected = array_merge($expected, ['find:41', 'delete:41']);
        } else {
            $expected = array_merge($expected, [
                'only:' . json_encode(self::FIELDS, JSON_THROW_ON_ERROR),
                'optional:[]',
                'save:' . json_encode($this->payload($action), JSON_THROW_ON_ERROR),
            ]);
        }
        self::assertSame(array_merge($expected, ['json_exception']), $result['events']);
        self::assertSame(500, $result['status']);
        self::assertSame('synthetic model failure', $result['body']);
        self::assertSame([], $result['saved']);
        self::assertNotContains('json_response', $result['events']);
    }

    public static function actions(): array
    {
        return [['store', 'add'], ['update', 'edit'], ['destroy', 'delete']];
    }

    private function payload(string $action): array
    {
        return ($action === 'update' ? ['id' => 41] : []) + [
            'name' => 'Synthetic Block',
            'start_datetime' => '2035-01-15 09:00:00',
            'end_datetime' => $action === 'update' ? '2035-01-15 11:00:00' : '2035-01-15 10:00:00',
            'notes' => 'synthetic',
        ];
    }

    private function probe(string $action, string $scenario, string $method): array
    {
        $process = proc_open(
            [
                PHP_BINARY,
                '-n',
                dirname(__DIR__, 2) . '/Fixtures/blocked_period_post_guard_probe.php',
                $action,
                $scenario,
                $method,
            ],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $stderr);
        self::assertSame('', $stderr);
        return json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
    }
}
