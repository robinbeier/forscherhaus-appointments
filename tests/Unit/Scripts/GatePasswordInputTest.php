<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;
use ReleaseGate\GateCliSupport;

require_once __DIR__ . '/../../../scripts/release-gate/lib/GateCliSupport.php';

final class GatePasswordInputTest extends TestCase
{
    public function testExplicitPasswordRemainsAvailableWithoutChangingBytes(): void
    {
        self::assertSame("pass\nwith\r", GateCliSupport::readPassword(['password' => "pass\nwith\r"]));
    }

    public function testPasswordStdinPreservesRawBytes(): void
    {
        $input = "pass\nwith\r\n";
        $result = $this->runReader(['password-stdin' => true], $input);

        self::assertSame(0, $result['exit_code'], $result['stderr']);
        self::assertSame(base64_encode($input), $result['stdout']);
    }

    public function testEmptyAndConflictingPasswordInputsAreRejected(): void
    {
        $empty = $this->runReader(['password-stdin' => true], '');
        self::assertNotSame(0, $empty['exit_code']);
        self::assertStringContainsString('non-empty stdin', $empty['stderr']);

        $conflict = $this->runReader(['password' => 'explicit', 'password-stdin' => true], 'stdin');
        self::assertNotSame(0, $conflict['exit_code']);
        self::assertStringContainsString('either --password or --password-stdin', $conflict['stderr']);
    }

    /** @param array<string,mixed> $options @return array{exit_code:int,stdout:string,stderr:string} */
    private function runReader(array $options, string $input): array
    {
        $encodedOptions = base64_encode(serialize($options));
        $script = <<<'PHP'
        require $argv[1];
        try {
            $options = unserialize(base64_decode($argv[2], true), ['allowed_classes' => false]);
            fwrite(STDOUT, base64_encode(ReleaseGate\GateCliSupport::readPassword($options)));
            exit(0);
        } catch (Throwable $error) {
            fwrite(STDERR, $error->getMessage());
            exit(1);
        }
        PHP;
        $descriptorSpec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open(
            ['php', '-r', $script, __DIR__ . '/../../../scripts/release-gate/lib/GateCliSupport.php', $encodedOptions],
            $descriptorSpec,
            $pipes,
            dirname(__DIR__, 3),
        );
        self::assertIsResource($process);
        fwrite($pipes[0], $input);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['exit_code' => proc_close($process), 'stdout' => (string) $stdout, 'stderr' => (string) $stderr];
    }
}
