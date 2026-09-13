<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers;

use PHPUnit\Framework\TestCase;

/**
 * Runs the real Update controller and view with parent, loader, security, instance, and helper doubles.
 * This does not prove real framework CSRF, HTTP, database, or migration behavior.
 */
final class UpdateControllerTest extends TestCase
{
    public function testAnonymousAndForbiddenRequestsNeverInitializeOrMigrate(): void
    {
        $anonymous = $this->probe('anonymous');
        self::assertSame(302, $anonymous['status']);
        self::assertSame('/login', $anonymous['headers']['Location']);
        self::assertContains('auth_check:edit:system_settings', $anonymous['events']);
        self::assertContains('redirect:login', $anonymous['events']);
        self::assertLessThan(
            array_search('redirect:login', $anonymous['events'], true),
            array_search('auth_check:edit:system_settings', $anonymous['events'], true),
        );
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

    public function testAuthorizedGetAndHeadRenderConfirmationWithoutInitialization(): void
    {
        foreach (['GET', 'HEAD'] as $method) {
            $result = $this->probe('authorized', $method);
            self::assertFalse($result['instance']);
            self::assertNotContains('library:instance', $result['events']);
            self::assertNotContains('migrate', $result['events']);
            self::assertSame(
                ['success' => null, 'csrf_token_name' => 'probe_csrf_name', 'csrf_token' => 'probe_csrf_hash'],
                $result['view'],
            );
            self::assertStringContainsString('name="probe_csrf_name"', $result['rendered']);
            self::assertStringContainsString('value="probe_csrf_hash"', $result['rendered']);
            self::assertStringContainsString('method="post"', $result['rendered']);
            self::assertStringContainsString('action="/update"', $result['rendered']);
        }
    }

    public function testAuthorizedPostInitializesAndMigratesExactlyOnce(): void
    {
        $result = $this->probe('authorized', 'POST');
        self::assertSame(1, substr_count(implode('|', $result['events']), 'library:instance'));
        self::assertSame(1, substr_count(implode('|', $result['events']), 'migrate'));
        $authIndex = array_search('auth_check:edit:system_settings', $result['events'], true);
        $libraryIndex = array_search('library:instance', $result['events'], true);
        $migrateIndex = array_search('migrate', $result['events'], true);
        self::assertIsInt($authIndex);
        self::assertIsInt($libraryIndex);
        self::assertIsInt($migrateIndex);
        self::assertLessThan($libraryIndex, $authIndex);
        self::assertLessThan($migrateIndex, $libraryIndex);
        self::assertTrue($result['instance']);
        self::assertSame(['success' => true], $result['view']);
    }

    public function testNonPostMethodsAreRejectedAndMigrationFailureEscapesInView(): void
    {
        foreach (['PUT', 'DELETE'] as $method) {
            $result = $this->probe('authorized', $method);
            self::assertFalse($result['instance']);
            self::assertNotContains('migrate', $result['events']);
            self::assertSame(405, $result['status']);
            self::assertSame('GET, HEAD, POST', $result['headers']['Allow']);
            self::assertSame([], $result['view']);
            self::assertSame('', $result['rendered']);
        }
        $failure = $this->probe('migration_failure', 'POST');
        self::assertTrue($failure['instance']);
        self::assertSame(1, substr_count(implode('|', $failure['events']), 'migrate'));
        self::assertFalse($failure['view']['success']);
        self::assertStringContainsString('&lt;b&gt;synthetic failure &amp; note&lt;/b&gt;', $failure['rendered']);
        self::assertStringNotContainsString('<b>synthetic failure & note</b>', $failure['rendered']);
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
