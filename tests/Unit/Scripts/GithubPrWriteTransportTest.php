<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GithubPrWriteTransportTest extends TestCase
{
    private string $tmp;
    private string $bin;
    private string $record;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/github-pr-write-' . bin2hex(random_bytes(8));
        $this->bin = $this->tmp . '/bin';
        $this->record = $this->tmp . '/record';
        self::assertTrue(mkdir($this->bin, 0700, true));
        $fake = <<<'BASH'
        #!/bin/bash
        set -eu
        record="${TMPDIR:?}/record"
        printf 'argv:' >> "$record"
        for arg in "$@"; do printf '\n%s' "$arg" >> "$record"; done
        printf '\n--env--\n' >> "$record"
        env | LC_ALL=C sort >> "$record"
        if [[ "${1:-}" == "auth" && "${2:-}" == "status" ]]; then
          if [[ -f "$TMPDIR/auth-fail" ]]; then printf 'authentication token=do-not-leak\n' >&2; exit 1; fi
          printf '{"status":"authenticated"}\n'; exit 0
        fi
        if [[ -f "$TMPDIR/api-fail" ]]; then printf 'API token=do-not-leak\n' >&2; exit 1; fi
        cat > "$TMPDIR/stdin"
        printf '{"ok":true}\n'
        BASH;
        self::assertNotFalse(file_put_contents($this->bin . '/gh', $fake));
        self::assertTrue(chmod($this->bin . '/gh', 0700));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tmp);
        parent::tearDown();
    }

    public function testUpdatePrUsesCanonicalPatchAndDoesNotExposeValues(): void
    {
        $title = $this->input('title', 'New title');
        $body = $this->input('body', 'Private body');
        [$exit, $out, $err] = $this->runTransport([
            'update-pr',
            '--repo',
            'owner/repo',
            '--number',
            '123',
            '--title-file',
            $title,
            '--body-file',
            $body,
        ]);
        self::assertSame(0, $exit, $err);
        self::assertSame('', $err);
        self::assertStringNotContainsString('New title', $out);
        self::assertStringNotContainsString('Private body', $out);
        $record = (string) file_get_contents($this->record);
        self::assertStringContainsString('repos/owner/repo/pulls/123', $record);
        self::assertStringContainsString("\n--method\nPATCH", $record);
        self::assertStringNotContainsString('GH_TOKEN', $record);
        self::assertStringNotContainsString('GITHUB_TOKEN', $record);
        self::assertStringNotContainsString('do-not-leak', $record);
    }

    public function testCreateCommentPreservesExactBodyBytesWithoutTrailingLf(): void
    {
        $contents = "Exact body\nUTF-8 äöü";
        $body = $this->input('comment', $contents);
        [$exit, , $err] = $this->runTransport([
            'create-comment',
            '--repo',
            'owner/repo',
            '--number',
            '123',
            '--body-file',
            $body,
        ]);
        self::assertSame(0, $exit, $err);
        $record = (string) file_get_contents($this->record);
        self::assertStringContainsString('repos/owner/repo/issues/123/comments', $record);
        self::assertStringContainsString("\n--method\nPOST", $record);
        $stdin = (string) file_get_contents($this->tmp . '/stdin');
        self::assertStringNotContainsString('do-not-leak', $stdin);
        self::assertSame(
            json_encode(['body' => $contents], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $stdin,
        );
    }

    public function testNulPayloadIsRejectedBeforeAnyWrite(): void
    {
        $body = $this->input('nul', "not\0text");
        [$exit, , $err] = $this->runTransport([
            'create-comment',
            '--repo',
            'owner/repo',
            '--number',
            '123',
            '--body-file',
            $body,
        ]);
        self::assertNotSame(0, $exit);
        self::assertStringContainsString('UTF-8 text without NUL', $err);
        self::assertFileDoesNotExist($this->record);
    }

    #[DataProvider('invalidArgumentsProvider')]
    public function testRejectsInvalidArgumentsBeforeCallingGh(array $args): void
    {
        [$exit, , $err] = $this->runTransport($args);
        self::assertNotSame(0, $exit);
        self::assertStringNotContainsString('do-not-leak', $err);
        self::assertFileDoesNotExist($this->record);
    }

    public static function invalidArgumentsProvider(): array
    {
        return [
            'invalid repo' => [['update-pr', '--repo', '../repo', '--number', '123']],
            'invalid number' => [['update-pr', '--repo', 'owner/repo', '--number', '0']],
            'non numeric number' => [['update-pr', '--repo', 'owner/repo', '--number', 'abc']],
            'unknown operation' => [['delete-pr', '--repo', 'owner/repo', '--number', '123']],
            'arbitrary endpoint option' => [
                ['update-pr', '--repo', 'owner/repo', '--number', '123', '--method', 'DELETE'],
            ],
            'missing comment body' => [['create-comment', '--repo', 'owner/repo', '--number', '123']],
        ];
    }

    public function testAuthAndApiFailuresAreFailClosedAndRedacted(): void
    {
        $body = $this->input('failure', 'body');
        foreach (['FAKE_GH_AUTH_FAIL' => 'auth', 'FAKE_GH_API_FAIL' => 'api'] as $variable => $label) {
            [$exit, , $err] = $this->runTransport(
                ['create-comment', '--repo', 'owner/repo', '--number', '123', '--body-file', $body],
                [$variable => '1'],
            );
            self::assertNotSame(0, $exit, $label);
            self::assertStringNotContainsString('do-not-leak', $err, $label);
            self::assertStringNotContainsString('token=', $err, $label);
        }
    }

    private function input(string $name, string $contents): string
    {
        $path = $this->tmp . '/' . $name;
        self::assertSame(strlen($contents), file_put_contents($path, $contents));
        return $path;
    }

    private function runTransport(array $args, array $extra = []): array
    {
        $root = dirname(__DIR__, 3);
        $env = array_merge(
            getenv() ?: [],
            [
                'PATH' => $this->bin . ':' . (getenv('PATH') ?: ''),
                'TMPDIR' => $this->tmp,
            ],
            $extra,
        );
        foreach ($extra as $variable => $value) {
            if ($value === '1') {
                file_put_contents(
                    $this->tmp . '/' . str_replace('_', '-', strtolower(str_replace('FAKE_GH_', '', $variable))),
                    '1',
                );
            }
        }
        $p = proc_open(
            array_merge([PHP_BINARY, $root . '/scripts/agent/github_pr_write_transport.php'], $args),
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root,
            $env,
        );
        self::assertIsResource($p);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($p), (string) $out, (string) $err];
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}
