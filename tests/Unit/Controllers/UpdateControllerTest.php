<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers;

use PHPUnit\Framework\TestCase;

/**
 * Runs the real Update controller and view with parent, loader, and helper doubles.
 * This does not prove real HTTP or database behavior.
 */
final class UpdateControllerTest extends TestCase
{
    public function testAnonymousAndForbiddenRequestsNeverInitializeOrMigrate(): void
    {
        $anonymous = $this->probe('anonymous');
        self::assertSame(302, $anonymous['status']);
        self::assertSame('/login', $anonymous['headers']['Location']);
        self::assertContains('redirect:login', $anonymous['events']);
        self::assertNotContains('auth_check:edit:system_settings', $anonymous['events']);
        self::assertFalse($anonymous['instance']);
        self::assertSame([], $anonymous['view']);
        self::assertNotContains('library:instance', $anonymous['events']);
        self::assertNotContains('migrate', $anonymous['events']);

        $anonymousPost = $this->probe('anonymous', 'POST');
        self::assertSame(302, $anonymousPost['status']);
        self::assertFalse($anonymousPost['instance']);
        self::assertNotContains('library:instance', $anonymousPost['events']);
        self::assertNotContains('migrate', $anonymousPost['events']);

        $forbidden = $this->probe('forbidden');
        self::assertSame(403, $forbidden['status']);
        self::assertFalse($forbidden['instance']);
        self::assertSame([], $forbidden['view']);
        self::assertNotContains('library:instance', $forbidden['events']);
        self::assertNotContains('migrate', $forbidden['events']);
        $forbiddenPost = $this->probe('forbidden', 'POST');
        self::assertSame(403, $forbiddenPost['status']);
        self::assertFalse($forbiddenPost['instance']);
        self::assertNotContains('library:instance', $forbiddenPost['events']);
        self::assertNotContains('migrate', $forbiddenPost['events']);
    }

    public function testAuthorizedGetAndHeadExplainOperatorMigrationWithoutInitialization(): void
    {
        foreach (['GET', 'HEAD'] as $method) {
            $result = $this->probe('authorized', $method);
            self::assertFalse($result['instance']);
            self::assertNotContains('library:instance', $result['events']);
            self::assertNotContains('migrate', $result['events']);
            self::assertSame([], $result['view']);
            self::assertStringContainsString('applied by the server operator', $result['rendered']);
            self::assertStringNotContainsString('<form', $result['rendered']);
        }
    }

    public function testAuthorizedPostIsRejectedBeforeMigrationInitialization(): void
    {
        $result = $this->probe('authorized', 'POST');
        self::assertSame(405, $result['status']);
        self::assertSame('GET, HEAD', $result['headers']['Allow']);
        self::assertFalse($result['instance']);
        self::assertContains('auth_check:edit:system_settings', $result['events']);
        self::assertNotContains('library:instance', $result['events']);
        self::assertNotContains('migrate', $result['events']);
    }

    public function testAllOtherMethodsAreRejectedBeforeMigrationInitialization(): void
    {
        foreach (['PUT', 'DELETE', 'PATCH'] as $method) {
            $result = $this->probe('authorized', $method);
            self::assertFalse($result['instance']);
            self::assertNotContains('migrate', $result['events']);
            self::assertSame(405, $result['status']);
            self::assertSame('GET, HEAD', $result['headers']['Allow']);
            self::assertSame([], $result['view']);
            self::assertSame('', $result['rendered']);
        }
    }

    /** @return array{events:list<string>,view:array<string,mixed>,rendered:string,instance:bool,status:int,headers:array<string,string>} */
    private function probe(string $scenario, string $method = 'GET'): array
    {
        $fixture = dirname(__DIR__, 2) . '/Fixtures/update_controller_probe.php';
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
        $exit = proc_close($process);
        self::assertSame(0, $exit, $stderr);
        $result = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($result);
        return $result;
    }
}
