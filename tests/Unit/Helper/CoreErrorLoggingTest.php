<?php

namespace Tests\Unit\Helper;

use PHPUnit\Framework\TestCase;

class CoreErrorLoggingTest extends TestCase
{
    public function testErrorTraceKeepsLocationWithoutArgumentsOrObjectData(): void
    {
        // Isolate the framework's static logger and exercise the actual Common.php function.
        $script = <<<'PHP'
        define('BASEPATH', dirname($argv[1], 2) . '/');
        $GLOBALS['captured_logger'] = new class {
            public array $entries = [];
            public function write_log($level, $message): void {
                $this->entries[] = [$level, $message];
            }
        };
        function &load_class($class, $directory) {
            return $GLOBALS['captured_logger'];
        }
        require $argv[1];
        $probe = new class {
            public string $password = 'private-object-password';
            public function emit(array $record, string $token): void {
                log_message('error', 'Required admin email is missing.');
                log_message('info', 'Diagnostic completed.');
            }
        };
        $probe->emit(['password' => 'private-argument-password', 'email' => 'private@example.invalid'], 'private-token');
        echo json_encode($GLOBALS['captured_logger']->entries, JSON_THROW_ON_ERROR);
        PHP;

        $process = proc_open(
            [PHP_BINARY, '-r', $script, dirname(__DIR__, 3) . '/system/core/Common.php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        $this->assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), $errors);

        $entries = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('error', $entries[0][0]);
        $message = $entries[0][1];
        $this->assertStringContainsString('Required admin email is missing.', $message);
        $this->assertStringContainsString('emit', $message);
        $this->assertStringContainsString("'file'", $message);
        $this->assertStringContainsString("'line'", $message);
        foreach (
            ['private-argument-password', 'private-object-password', 'private@example.invalid', 'private-token']
            as $secret
        ) {
            $this->assertStringNotContainsString($secret, $message);
        }
        $this->assertStringNotContainsString("'args'", $message);
        $this->assertStringNotContainsString("'object'", $message);
        $this->assertSame(['info', 'Diagnostic completed.'], $entries[1]);
    }
}
