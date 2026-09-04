<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../scripts/agent/github_pr_write_transport.php';

final class GithubPrWriteTransportTest extends TestCase
{
    private string $tmp;
    private string $bin;
    private string $record;
    private string $stdin;
    private string $head;
    private string $branch;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 3);
        $this->tmp = sys_get_temp_dir() . '/github-pr-write-' . bin2hex(random_bytes(8));
        $this->bin = $this->tmp . '/bin';
        $this->record = $this->tmp . '/record';
        $this->stdin = $this->tmp . '/stdin';
        $this->head = trim((string) shell_exec('/usr/bin/git -C ' . escapeshellarg($root) . ' rev-parse HEAD'));
        $this->branch = 'test/pr-branch';
        self::assertMatchesRegularExpression('/\A[a-f0-9]{40}\z/D', $this->head);
        self::assertTrue(mkdir($this->bin, 0700, true));

        $fake = <<<'BASH'
        #!/bin/bash
        set -eu
        record=__RECORD__
        stdin_record=__STDIN__
        auth_fail=__AUTH_FAIL__
        api_fail=__API_FAIL__
        head_mismatch=__HEAD_MISMATCH__
        branch_mismatch=__BRANCH_MISMATCH__
        repo_mismatch=__REPO_MISMATCH__
        post_write_head_mismatch=__POST_WRITE_HEAD_MISMATCH__
        post_write_metadata_mismatch=__POST_WRITE_METADATA_MISMATCH__
        post_write_api_fail=__POST_WRITE_API_FAIL__
        comment_response_invalid=__COMMENT_RESPONSE_INVALID__
        get_count=__GET_COUNT__

        printf 'argv:' >> "$record"
        for arg in "$@"; do printf '\n%s' "$arg" >> "$record"; done
        printf '\n--env--\n' >> "$record"
        /usr/bin/env | LC_ALL=C /usr/bin/sort >> "$record"
        printf '\n--call-end--\n' >> "$record"

        if [[ "${1:-}" == "auth" && "${2:-}" == "status" ]]; then
          if [[ -f "$auth_fail" ]]; then printf 'authentication token=do-not-leak\n' >&2; exit 1; fi
          exit 0
        fi

        method=''
        endpoint=''
        previous=''
        for arg in "$@"; do
          if [[ "$previous" == '--method' ]]; then method="$arg"; fi
          case "$arg" in repos/*) endpoint="$arg" ;; esac
          previous="$arg"
        done

        if [[ "$method" == 'GET' ]]; then
          count=0
          if [[ -f "$get_count" ]]; then count="$(/bin/cat "$get_count")"; fi
          count=$((count + 1))
          printf '%s' "$count" > "$get_count"
          if [[ -f "$post_write_api_fail" && "$count" -ge 2 ]]; then printf 'API token=do-not-leak\n' >&2; exit 1; fi
          head_sha='__HEAD__'
          head_ref='__BRANCH__'
          title='New title'
          body='Private body'
          repo='robinbeier/forscherhaus-appointments'
          if [[ -f "$head_mismatch" ]]; then head_sha='0000000000000000000000000000000000000000'; fi
          if [[ -f "$branch_mismatch" ]]; then head_ref='other/branch'; fi
          if [[ -f "$post_write_head_mismatch" && "$count" -ge 2 ]]; then head_sha='0000000000000000000000000000000000000000'; fi
          if [[ -f "$post_write_metadata_mismatch" && "$count" -ge 2 ]]; then body='Concurrent body'; fi
          if [[ -f "$repo_mismatch" ]]; then repo='other/repository'; fi
          printf '{"number":123,"state":"open","title":"%s","body":"%s","base":{"ref":"main","repo":{"full_name":"%s"}},"head":{"ref":"%s","sha":"%s","repo":{"full_name":"%s"}}}' "$title" "$body" "$repo" "$head_ref" "$head_sha" "$repo"
          exit 0
        fi

        if [[ -f "$api_fail" ]]; then printf 'API token=do-not-leak\n' >&2; exit 1; fi
        /bin/cat > "$stdin_record"
        if [[ "$method" == 'POST' ]]; then
          if [[ -f "$comment_response_invalid" ]]; then printf '{"ok":true}\n'; else printf '{"id":456}\n'; fi
        else
          printf '{"ok":true}\n'
        fi
        BASH;

        $fake = str_replace(
            [
                '__RECORD__',
                '__STDIN__',
                '__AUTH_FAIL__',
                '__API_FAIL__',
                '__HEAD_MISMATCH__',
                '__BRANCH_MISMATCH__',
                '__REPO_MISMATCH__',
                '__POST_WRITE_HEAD_MISMATCH__',
                '__POST_WRITE_METADATA_MISMATCH__',
                '__POST_WRITE_API_FAIL__',
                '__COMMENT_RESPONSE_INVALID__',
                '__GET_COUNT__',
                '__HEAD__',
                '__BRANCH__',
            ],
            [
                $this->shellQuote($this->record),
                $this->shellQuote($this->stdin),
                $this->shellQuote($this->tmp . '/auth-fail'),
                $this->shellQuote($this->tmp . '/api-fail'),
                $this->shellQuote($this->tmp . '/head-mismatch'),
                $this->shellQuote($this->tmp . '/branch-mismatch'),
                $this->shellQuote($this->tmp . '/repo-mismatch'),
                $this->shellQuote($this->tmp . '/post-write-head-mismatch'),
                $this->shellQuote($this->tmp . '/post-write-metadata-mismatch'),
                $this->shellQuote($this->tmp . '/post-write-api-fail'),
                $this->shellQuote($this->tmp . '/comment-response-invalid'),
                $this->shellQuote($this->tmp . '/get-count'),
                $this->head,
                $this->branch,
            ],
            $fake,
        );
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
        $request = json_encode(
            ['title' => 'New title', 'body' => 'Private body'],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );
        [$exit, $out, $err] = $this->runTransport(
            ['update-pr', '--repo', GITHUB_PR_WRITE_REPOSITORY, '--number', '123'],
            $request,
        );

        self::assertSame(0, $exit, $err);
        self::assertSame('', $err);
        self::assertSame('ok', json_decode($out, true, 8, JSON_THROW_ON_ERROR)['status'] ?? null);
        self::assertStringNotContainsString('New title', $out);
        self::assertStringNotContainsString('Private body', $out);
        self::assertSame(
            json_encode(
                ['title' => 'New title', 'body' => 'Private body'],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ),
            file_get_contents($this->stdin),
        );

        $record = (string) file_get_contents($this->record);
        self::assertStringContainsString('repos/' . GITHUB_PR_WRITE_REPOSITORY . '/pulls/123', $record);
        self::assertStringContainsString("\n--method\nGET", $record);
        self::assertSame(2, substr_count($record, "\n--method\nGET"));
        self::assertStringContainsString("\n--method\nPATCH", $record);
        self::assertStringNotContainsString('GH_TOKEN', $record);
        self::assertStringNotContainsString('GITHUB_TOKEN', $record);
        self::assertStringNotContainsString('do-not-leak', $record);
        self::assertStringContainsString('PATH=/usr/bin:/bin:/usr/sbin:/sbin', $record);
        self::assertStringNotContainsString($this->bin . ':', $record);
    }

    public function testCreateCommentPreservesExactBodyBytesWithoutTrailingLf(): void
    {
        $contents = "Exact body\nUTF-8 äöü";
        $request = json_encode(
            ['body' => $contents],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
        [$exit, $out, $err] = $this->runTransport(
            ['create-comment', '--repo', GITHUB_PR_WRITE_REPOSITORY, '--number', '123'],
            $request,
        );

        self::assertSame(0, $exit, $err);
        self::assertSame(456, json_decode($out, true, 8, JSON_THROW_ON_ERROR)['comment_id'] ?? null);
        self::assertStringNotContainsString($contents, $out);
        self::assertSame($request, file_get_contents($this->stdin));
        $record = (string) file_get_contents($this->record);
        self::assertStringContainsString('repos/' . GITHUB_PR_WRITE_REPOSITORY . '/issues/123/comments', $record);
        self::assertStringContainsString("\n--method\nPOST", $record);
        self::assertSame(2, substr_count($record, "\n--method\nGET"));
    }

    public function testNulPayloadIsRejectedBeforeAnyGitHubProcess(): void
    {
        $request = json_encode(['body' => "not\0text"], JSON_THROW_ON_ERROR);
        [$exit, , $err] = $this->runTransport(
            ['create-comment', '--repo', GITHUB_PR_WRITE_REPOSITORY, '--number', '123'],
            $request,
        );

        self::assertNotSame(0, $exit);
        self::assertStringContainsString('UTF-8 text without NUL', $err);
        self::assertFileDoesNotExist($this->record);
    }

    #[DataProvider('invalidArgumentsProvider')]
    public function testRejectsInvalidArgumentsBeforeCallingGh(array $args): void
    {
        [$exit, , $err] = $this->runTransport($args, '{"body":"safe"}');

        self::assertNotSame(0, $exit);
        self::assertStringNotContainsString('do-not-leak', $err);
        self::assertFileDoesNotExist($this->record);
    }

    public static function invalidArgumentsProvider(): array
    {
        return [
            'foreign repository' => [['update-pr', '--repo', 'other/repository', '--number', '123']],
            'invalid number' => [['update-pr', '--repo', GITHUB_PR_WRITE_REPOSITORY, '--number', '0']],
            'non numeric number' => [['update-pr', '--repo', GITHUB_PR_WRITE_REPOSITORY, '--number', 'abc']],
            'unknown operation' => [['delete-pr', '--repo', GITHUB_PR_WRITE_REPOSITORY, '--number', '123']],
            'caller supplied payload path' => [
                ['update-pr', '--repo', GITHUB_PR_WRITE_REPOSITORY, '--number', '123', '--body-file', '/private/file'],
            ],
            'arbitrary endpoint option' => [
                ['update-pr', '--repo', GITHUB_PR_WRITE_REPOSITORY, '--number', '123', '--method', 'DELETE'],
            ],
        ];
    }

    #[DataProvider('invalidPayloadProvider')]
    public function testRejectsInvalidPayloadBeforeCallingGh(string $operation, string $input, string $message): void
    {
        [$exit, , $err] = $this->runTransport(
            [$operation, '--repo', GITHUB_PR_WRITE_REPOSITORY, '--number', '123'],
            $input,
        );

        self::assertNotSame(0, $exit);
        self::assertStringContainsString($message, $err);
        self::assertFileDoesNotExist($this->record);
    }

    public static function invalidPayloadProvider(): array
    {
        return [
            'invalid json' => ['create-comment', '{', 'JSON input is invalid'],
            'list instead of object' => ['create-comment', '["body"]', 'must be an object'],
            'extra field' => ['create-comment', '{"body":"safe","path":"/secret"}', 'fields do not match'],
            'empty comment' => ['create-comment', '{"body":""}', 'must not be empty'],
            'title newline' => ['update-pr', '{"title":"bad\\nline"}', 'one non-empty line'],
            'non string body' => ['create-comment', '{"body":123}', 'body must be a string'],
        ];
    }

    public function testTargetHeadBranchAndRepositoryDriftAreRejectedBeforeWrite(): void
    {
        foreach (['head-mismatch', 'branch-mismatch', 'repo-mismatch'] as $marker) {
            file_put_contents($this->tmp . '/' . $marker, '1');
            [$exit, , $err] = $this->runTransport(
                ['create-comment', '--repo', GITHUB_PR_WRITE_REPOSITORY, '--number', '123'],
                '{"body":"safe"}',
            );
            self::assertSame(3, $exit, $marker);
            self::assertStringContainsString('canonical exact local target', $err, $marker);
            self::assertFileDoesNotExist($this->stdin, $marker);
            unlink($this->tmp . '/' . $marker);
            unlink($this->record);
        }
    }

    public function testPostWriteUncertaintyReportsCompletedWriteWithoutInvitingRetry(): void
    {
        foreach (['post-write-head-mismatch', 'post-write-api-fail'] as $marker) {
            file_put_contents($this->tmp . '/' . $marker, '1');

            [$exit, $out, $err] = $this->runTransport(
                ['create-comment', '--repo', GITHUB_PR_WRITE_REPOSITORY, '--number', '123'],
                '{"body":"safe"}',
            );

            self::assertSame(0, $exit, $marker);
            self::assertSame('', $err, $marker);
            self::assertSame(
                'write_completed_target_unverified',
                json_decode($out, true, 8, JSON_THROW_ON_ERROR)['status'] ?? null,
                $marker,
            );
            self::assertSame(456, json_decode($out, true, 8, JSON_THROW_ON_ERROR)['comment_id'] ?? null, $marker);
            self::assertSame('{"body":"safe"}', file_get_contents($this->stdin), $marker);
            $record = (string) file_get_contents($this->record);
            self::assertSame(2, substr_count($record, "\n--method\nGET"), $marker);
            self::assertStringContainsString("\n--method\nPOST", $record, $marker);
            self::assertStringNotContainsString('do-not-leak', $out . $err, $marker);

            foreach ([$marker, 'get-count', 'record', 'stdin'] as $file) {
                unlink($this->tmp . '/' . $file);
            }
        }
    }

    public function testUpdatePrPostWriteMetadataDriftIsUnverified(): void
    {
        file_put_contents($this->tmp . '/post-write-metadata-mismatch', '1');
        $request = '{"title":"New title","body":"Private body"}';

        [$exit, $out, $err] = $this->runTransport(
            ['update-pr', '--repo', GITHUB_PR_WRITE_REPOSITORY, '--number', '123'],
            $request,
        );

        self::assertSame(0, $exit, $err);
        self::assertSame('', $err);
        self::assertSame(
            'write_completed_target_unverified',
            json_decode($out, true, 8, JSON_THROW_ON_ERROR)['status'] ?? null,
        );
        self::assertStringNotContainsString('Private body', $out);
        self::assertSame($request, file_get_contents($this->stdin));
    }

    public function testCreateCommentWithoutStableResponseIdIsUnverified(): void
    {
        file_put_contents($this->tmp . '/comment-response-invalid', '1');

        [$exit, $out, $err] = $this->runTransport(
            ['create-comment', '--repo', GITHUB_PR_WRITE_REPOSITORY, '--number', '123'],
            '{"body":"safe"}',
        );

        self::assertSame(0, $exit, $err);
        self::assertSame('', $err);
        $response = json_decode($out, true, 8, JSON_THROW_ON_ERROR);
        self::assertSame('write_completed_target_unverified', $response['status'] ?? null);
        self::assertArrayNotHasKey('comment_id', $response);
    }

    public function testAuthAndApiFailuresAreFailClosedAndRedacted(): void
    {
        foreach (['auth-fail' => 3, 'api-fail' => 4] as $marker => $expectedExit) {
            file_put_contents($this->tmp . '/' . $marker, '1');
            [$exit, , $err] = $this->runTransport(
                ['create-comment', '--repo', GITHUB_PR_WRITE_REPOSITORY, '--number', '123'],
                '{"body":"safe"}',
            );
            self::assertSame($expectedExit, $exit, $marker);
            self::assertStringNotContainsString('do-not-leak', $err, $marker);
            self::assertStringNotContainsString('token=', $err, $marker);
            unlink($this->tmp . '/' . $marker);
            unlink($this->record);
        }
    }

    public function testProductionEntrypointCannotInjectBinaryOrExecutionSeams(): void
    {
        $entrypoint = new \ReflectionMethod(\GithubPrWriteTransport::class, 'main');
        self::assertTrue($entrypoint->isPublic());
        self::assertTrue($entrypoint->isStatic());
        self::assertSame(1, $entrypoint->getNumberOfParameters());

        foreach (
            [
                'resolveGhBinary',
                'validateGhBinary',
                'resolveLocalTarget',
                'verifyTarget',
                'verifyUpdatedFields',
                'extractCommentId',
                'execute',
                'runCommand',
            ]
            as $method
        ) {
            self::assertTrue((new \ReflectionMethod(\GithubPrWriteTransport::class, $method))->isPrivate(), $method);
        }
    }

    public function testLocalTargetUsesAttachedBranchAndRejectsDetachedHead(): void
    {
        $root = dirname(__DIR__, 3);
        $branch = trim(
            (string) shell_exec('/usr/bin/git -C ' . escapeshellarg($root) . ' symbolic-ref --quiet --short HEAD'),
        );

        if ($branch === '') {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('branch could not be verified');
            $this->invokeTransport('resolveLocalTarget', []);
            return;
        }

        self::assertSame(['sha' => $this->head, 'branch' => $branch], $this->invokeTransport('resolveLocalTarget', []));
    }

    public function testGhBinaryMustBeASafeAbsoluteExecutable(): void
    {
        self::assertSame(
            realpath($this->bin . '/gh'),
            $this->invokeTransport('validateGhBinary', [$this->bin . '/gh']),
        );

        chmod($this->bin . '/gh', 0777);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ownership or mode is unsafe');
        $this->invokeTransport('validateGhBinary', [$this->bin . '/gh']);
    }

    private function runTransport(array $args, string $input): array
    {
        $root = dirname(__DIR__, 3);
        $bootstrap = <<<'PHP'
        require $argv[1];
        $input = stream_get_contents(STDIN);
        $invoke = static function (string $method, array $arguments): mixed {
            $reflection = new ReflectionMethod(GithubPrWriteTransport::class, $method);
            return $reflection->invoke(null, ...$arguments);
        };
        try {
            $options = $invoke('parseArguments', [array_slice($argv, 5)]);
            $payload = $invoke('parsePayload', [$options['operation'], is_string($input) ? $input : '']);
            $invoke('execute', [
                $options,
                $payload,
                $argv[2],
                ['sha' => $argv[3], 'branch' => $argv[4]],
            ]);
            exit(0);
        } catch (InvalidArgumentException $exception) {
            fwrite(STDERR, 'Input rejected: ' . $exception->getMessage() . PHP_EOL);
            exit(2);
        } catch (UnexpectedValueException $exception) {
            fwrite(STDERR, $exception->getMessage() . PHP_EOL);
            exit(3);
        } catch (Throwable $exception) {
            fwrite(STDERR, $exception->getMessage() . PHP_EOL);
            exit(4);
        }
        PHP;
        $env = array_merge(getenv() ?: [], [
            'PATH' => $this->bin . ':' . (getenv('PATH') ?: ''),
            'GH_TOKEN' => 'do-not-leak',
            'GITHUB_TOKEN' => 'do-not-leak',
        ]);
        $process = proc_open(
            [
                PHP_BINARY,
                '-r',
                $bootstrap,
                $root . '/scripts/agent/github_pr_write_transport.php',
                $this->bin . '/gh',
                $this->head,
                $this->branch,
                ...$args,
            ],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root,
            $env,
        );
        self::assertIsResource($process);
        fwrite($pipes[0], $input);
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), (string) $out, (string) $err];
    }

    private function invokeTransport(string $method, array $arguments): mixed
    {
        $reflection = new \ReflectionMethod(\GithubPrWriteTransport::class, $method);
        return $reflection->invoke(null, ...$arguments);
    }

    private function shellQuote(string $value): string
    {
        return "'" . str_replace("'", "'\"'\"'", $value) . "'";
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
