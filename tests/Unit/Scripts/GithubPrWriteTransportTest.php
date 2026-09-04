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
    private string $home;
    private string $runtimeRoot;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 3);
        $this->tmp = sys_get_temp_dir() . '/github-pr-write-' . bin2hex(random_bytes(8));
        $this->bin = $this->tmp . '/bin';
        $this->record = $this->tmp . '/record';
        $this->stdin = $this->tmp . '/stdin';
        $this->head = trim((string) shell_exec('/usr/bin/git -C ' . escapeshellarg($root) . ' rev-parse HEAD'));
        $this->branch = 'test/pr-branch';
        $this->home = $this->tmp . '/home';
        $this->runtimeRoot = $this->tmp . '/runtime';
        self::assertMatchesRegularExpression('/\A[a-f0-9]{40}\z/D', $this->head);
        self::assertTrue(mkdir($this->bin, 0700, true));
        self::assertTrue(mkdir($this->home . '/.config/gh', 0700, true));
        self::assertTrue(mkdir($this->runtimeRoot, 0700, true));
        self::assertNotFalse(file_put_contents($this->home . '/.config/gh/hosts.yml', "github.com:\n  user: test\n"));
        self::assertNotFalse(file_put_contents($this->home . '/.config/gh/config.yml', "aliases:\n  api: '!unsafe'\n"));
        self::assertTrue(chmod($this->home . '/.config/gh/hosts.yml', 0600));
        self::assertTrue(chmod($this->home . '/.config/gh/config.yml', 0600));

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
        post_write_command_fail=__POST_WRITE_COMMAND_FAIL__
        comment_response_invalid=__COMMENT_RESPONSE_INVALID__
        comment_wrong_bytes=__COMMENT_WRONG_BYTES__
        comment_missing=__COMMENT_MISSING__
        comment_wrong_target=__COMMENT_WRONG_TARGET__
        large_pr_body=__LARGE_PR_BODY__
        get_count=__GET_COUNT__

        printf 'argv0:%s\nargv:' "$0" >> "$record"
        for arg in "$@"; do printf '\n%s' "$arg" >> "$record"; done
        printf '\n--env--\n' >> "$record"
        /usr/bin/env | LC_ALL=C /usr/bin/sort >> "$record"
        printf '\n--gh-config--\n' >> "$record"
        for entry in "$GH_CONFIG_DIR"/*; do
          if [[ -L "$entry" ]]; then
            printf 'entry:%s:symlink\n' "${entry##*/}" >> "$record"
          elif [[ -e "$entry" ]]; then
            printf 'entry:%s:file\n' "${entry##*/}" >> "$record"
          fi
        done
        printf '\n--call-end--\n' >> "$record"

        if [[ "${1:-}" == "auth" && "${2:-}" == "status" ]]; then
          if [[ -f "$auth_fail" ]]; then printf 'authentication token=do-not-leak\n' >&2; exit 1; fi
          exit 0
        fi

        method=''
        endpoint=''
        jq_expression=''
        previous=''
        for arg in "$@"; do
          if [[ "$previous" == '--method' ]]; then method="$arg"; fi
          if [[ "$previous" == '--jq' ]]; then jq_expression="$arg"; fi
          case "$arg" in repos/*) endpoint="$arg" ;; esac
          previous="$arg"
        done

        if [[ "$method" == 'GET' ]]; then
          count=0
          if [[ -f "$get_count" ]]; then count="$(/bin/cat "$get_count")"; fi
          count=$((count + 1))
          printf '%s' "$count" > "$get_count"
          if [[ -f "$post_write_api_fail" && "$count" -ge 2 ]]; then printf 'API token=do-not-leak\n' >&2; exit 1; fi
          if [[ "$endpoint" == */issues/comments/456 ]]; then
            if [[ -f "$comment_missing" ]]; then printf 'API token=do-not-leak\n' >&2; exit 1; fi
            comment_body_json="$(/usr/bin/sed -n 's/.*\"body\":\"\([^\"]*\)\".*/\1/p' "$stdin_record")"
            comment_repo='https://api.github.com/repos/robinbeier/forscherhaus-appointments'
            comment_issue="$comment_repo/issues/123"
            comment_url="$comment_repo/issues/comments/456"
            if [[ -f "$comment_wrong_bytes" ]]; then comment_body_json='Concurrent body'; fi
            if [[ -f "$comment_wrong_target" ]]; then comment_issue='https://api.github.com/repos/other/repository/issues/999'; comment_url='https://api.github.com/repos/other/repository/issues/comments/456'; fi
            printf '{"id":456,"body":"%s","url":"%s","issue_url":"%s"}' "$comment_body_json" "$comment_url" "$comment_issue"
            exit 0
          fi
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
          if [[ -f "$large_pr_body" && -n "$jq_expression" ]]; then
            if [[ "$jq_expression" == *title* && "$jq_expression" == *body* ]]; then
              printf '{"number":123,"state":"open","title":"%s","body":"%s","base":{"ref":"main","repo":{"full_name":"%s"}},"head":{"ref":"%s","sha":"%s","repo":{"full_name":"%s"}}}' "$title" "$body" "$repo" "$head_ref" "$head_sha" "$repo"
            elif [[ "$jq_expression" == *title* ]]; then
              printf '{"number":123,"state":"open","title":"%s","base":{"ref":"main","repo":{"full_name":"%s"}},"head":{"ref":"%s","sha":"%s","repo":{"full_name":"%s"}}}' "$title" "$repo" "$head_ref" "$head_sha" "$repo"
            else
              printf '{"number":123,"state":"open","base":{"ref":"main","repo":{"full_name":"%s"}},"head":{"ref":"%s","sha":"%s","repo":{"full_name":"%s"}}}' "$repo" "$head_ref" "$head_sha" "$repo"
            fi
            exit 0
          fi
          if [[ -f "$large_pr_body" ]]; then body="$(/usr/bin/head -c 135000 /dev/zero | /usr/bin/tr '\0' x)"; fi
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
        if [[ -f "$post_write_command_fail" ]]; then
          printf 'remote mutation may have completed\n' >&2
          exit 7
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
                '__POST_WRITE_COMMAND_FAIL__',
                '__COMMENT_RESPONSE_INVALID__',
                '__COMMENT_WRONG_BYTES__',
                '__COMMENT_MISSING__',
                '__COMMENT_WRONG_TARGET__',
                '__LARGE_PR_BODY__',
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
                $this->shellQuote($this->tmp . '/post-write-command-fail'),
                $this->shellQuote($this->tmp . '/comment-response-invalid'),
                $this->shellQuote($this->tmp . '/comment-wrong-bytes'),
                $this->shellQuote($this->tmp . '/comment-missing'),
                $this->shellQuote($this->tmp . '/comment-wrong-target'),
                $this->shellQuote($this->tmp . '/large-pr-body'),
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
        self::assertMatchesRegularExpression(
            '/\nGH_CONFIG_DIR=' .
                preg_quote((string) realpath($this->runtimeRoot), '/') .
                '\/github-pr-write-gh-[a-f0-9]{32}\n/',
            $record,
        );
        self::assertMatchesRegularExpression(
            '/\Aargv0:' .
                preg_quote((string) realpath($this->runtimeRoot), '/') .
                '\/github-pr-write-gh-[a-f0-9]{32}\/gh\n/',
            $record,
        );
        self::assertStringContainsString("\n--gh-config--\nentry:gh:file\nentry:hosts.yml:symlink\n", $record);
        self::assertStringNotContainsString('entry:config.yml', $record);
        self::assertSame(['.', '..'], scandir($this->runtimeRoot));
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
        self::assertSame(3, substr_count($record, "\n--method\nGET"));
        self::assertStringContainsString('repos/' . GITHUB_PR_WRITE_REPOSITORY . '/issues/comments/456', $record);
    }

    public function testLargeExistingPullRequestBodyUsesBoundedTargetProjection(): void
    {
        file_put_contents($this->tmp . '/large-pr-body', '1');

        [$exit, $out, $err] = $this->runTransport(
            ['create-comment', '--repo', GITHUB_PR_WRITE_REPOSITORY, '--number', '123'],
            '{"body":"safe"}',
        );

        self::assertSame(0, $exit, $err);
        self::assertSame('', $err);
        self::assertSame(456, json_decode($out, true, 8, JSON_THROW_ON_ERROR)['comment_id'] ?? null);
        self::assertStringNotContainsString(str_repeat('x', 1024), $out . $err);
        $record = (string) file_get_contents($this->record);
        $projection = GITHUB_PR_WRITE_TARGET_PROJECTION;
        self::assertSame(2, substr_count($record, "\n--jq\n" . $projection));
        self::assertStringContainsString("\n--method\nGET", $record);
        self::assertStringContainsString("\n--method\nPOST", $record);
        self::assertSame(3, substr_count($record, "\n--method\nGET"));
        self::assertStringNotContainsString('safe', $record);
    }

    public function testLargeExistingPullRequestBodyDoesNotBreakTitleOnlyUpdate(): void
    {
        file_put_contents($this->tmp . '/large-pr-body', '1');

        [$exit, $out, $err] = $this->runTransport(
            ['update-pr', '--repo', GITHUB_PR_WRITE_REPOSITORY, '--number', '123'],
            '{"title":"New title"}',
        );

        self::assertSame(0, $exit, $err);
        self::assertSame('', $err);
        self::assertSame('ok', json_decode($out, true, 8, JSON_THROW_ON_ERROR)['status'] ?? null);
        self::assertStringNotContainsString(str_repeat('x', 1024), $out . $err);
        $record = (string) file_get_contents($this->record);
        $projection = GITHUB_PR_WRITE_TARGET_PROJECTION;
        $postflightProjection = GITHUB_PR_WRITE_TARGET_PROJECTION . ' + {title: .title}';
        self::assertSame(1, substr_count($record, "\n--jq\n" . $projection . "\n--env--"));
        self::assertSame(1, substr_count($record, "\n--jq\n" . $postflightProjection . "\n--env--"));
        self::assertSame(2, substr_count($record, "\n--method\nGET"));
        self::assertSame(1, substr_count($record, "\n--method\nPATCH"));
        self::assertSame(1, substr_count($record, "\n--silent\n--env--"));
        self::assertStringNotContainsString('New title', $record);
    }

    public function testCreateCommentReconciliationFailsClosedForWrongBytesMissingOrWrongTarget(): void
    {
        foreach (['comment-wrong-bytes', 'comment-missing', 'comment-wrong-target'] as $marker) {
            file_put_contents($this->tmp . '/' . $marker, '1');

            [$exit, $out, $err] = $this->runTransport(
                ['create-comment', '--repo', GITHUB_PR_WRITE_REPOSITORY, '--number', '123'],
                '{"body":"safe"}',
            );

            self::assertSame(0, $exit, $marker);
            self::assertSame('', $err, $marker);
            $response = json_decode($out, true, 8, JSON_THROW_ON_ERROR);
            self::assertSame('write_completed_target_unverified', $response['status'] ?? null, $marker);
            self::assertSame(456, $response['comment_id'] ?? null, $marker);
            self::assertStringNotContainsString('Concurrent body', $out, $marker);
            self::assertStringNotContainsString('do-not-leak', $out . $err, $marker);
            self::assertSame(1, substr_count((string) file_get_contents($this->record), "\n--method\nPOST"), $marker);
            self::assertSame(3, substr_count((string) file_get_contents($this->record), "\n--method\nGET"), $marker);
            self::assertStringContainsString(
                '/issues/comments/456',
                (string) file_get_contents($this->record),
                $marker,
            );

            foreach ([$marker, 'get-count', 'record', 'stdin'] as $file) {
                unlink($this->tmp . '/' . $file);
            }
        }
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

    public function testNonzeroWriteExitReconcilesWithoutRetryAndKeepsCommentId(): void
    {
        foreach (['update-pr', 'create-comment'] as $operation) {
            file_put_contents($this->tmp . '/post-write-command-fail', '1');
            $request = $operation === 'update-pr' ? '{"title":"New title","body":"Private body"}' : '{"body":"safe"}';

            [$exit, $out, $err] = $this->runTransport(
                [$operation, '--repo', GITHUB_PR_WRITE_REPOSITORY, '--number', '123'],
                $request,
            );

            self::assertSame(0, $exit, $operation);
            self::assertSame('', $err, $operation);
            $response = json_decode($out, true, 8, JSON_THROW_ON_ERROR);
            self::assertSame('write_completed_target_unverified', $response['status'] ?? null, $operation);
            if ($operation === 'create-comment') {
                self::assertSame(456, $response['comment_id'] ?? null, $operation);
            } else {
                self::assertArrayNotHasKey('comment_id', $response, $operation);
            }

            $record = (string) file_get_contents($this->record);
            self::assertSame(
                $operation === 'create-comment' ? 3 : 2,
                substr_count($record, "\n--method\nGET"),
                $operation,
            );
            self::assertSame(
                1,
                substr_count($record, "\n--method\n" . ($operation === 'update-pr' ? 'PATCH' : 'POST')),
                $operation,
            );
            self::assertStringNotContainsString('remote mutation may have completed', $out . $err, $operation);

            foreach (['post-write-command-fail', 'get-count', 'record', 'stdin'] as $file) {
                unlink($this->tmp . '/' . $file);
            }
        }
    }

    public function testLocalTargetIsResolvedAgainAndDriftFailsClosed(): void
    {
        $targets = [
            ['sha' => $this->head, 'branch' => $this->branch],
            ['sha' => str_repeat('0', 40), 'branch' => $this->branch],
        ];

        [$exit, $out, $err] = $this->runTransport(
            ['create-comment', '--repo', GITHUB_PR_WRITE_REPOSITORY, '--number', '123'],
            '{"body":"safe"}',
            $targets,
        );

        self::assertSame(0, $exit);
        self::assertSame('', $err);
        self::assertSame(
            'write_completed_target_unverified',
            json_decode($out, true, 8, JSON_THROW_ON_ERROR)['status'] ?? null,
        );
        self::assertSame(456, json_decode($out, true, 8, JSON_THROW_ON_ERROR)['comment_id'] ?? null);
        $record = (string) file_get_contents($this->record);
        self::assertSame(1, substr_count($record, "\n--method\nPOST"));
        self::assertSame(2, substr_count($record, "\n--method\nGET"));

        foreach (['get-count', 'record', 'stdin'] as $file) {
            unlink($this->tmp . '/' . $file);
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

    public function testAuthFailureIsFailClosedAndRedacted(): void
    {
        file_put_contents($this->tmp . '/auth-fail', '1');
        [$exit, , $err] = $this->runTransport(
            ['create-comment', '--repo', GITHUB_PR_WRITE_REPOSITORY, '--number', '123'],
            '{"body":"safe"}',
        );

        self::assertSame(3, $exit);
        self::assertStringNotContainsString('do-not-leak', $err);
        self::assertStringNotContainsString('token=', $err);
    }

    public function testNonzeroWriteExitWithoutResponseIsUnverifiedAndRedacted(): void
    {
        file_put_contents($this->tmp . '/api-fail', '1');
        [$exit, $out, $err] = $this->runTransport(
            ['create-comment', '--repo', GITHUB_PR_WRITE_REPOSITORY, '--number', '123'],
            '{"body":"safe"}',
        );

        self::assertSame(0, $exit);
        self::assertSame('', $err);
        $response = json_decode($out, true, 8, JSON_THROW_ON_ERROR);
        self::assertSame('write_completed_target_unverified', $response['status'] ?? null);
        self::assertArrayNotHasKey('comment_id', $response);
        self::assertStringNotContainsString('do-not-leak', $out . $err);
        self::assertStringNotContainsString('token=', $out . $err);
        $record = (string) file_get_contents($this->record);
        self::assertSame(2, substr_count($record, "\n--method\nGET"));
        self::assertSame(1, substr_count($record, "\n--method\nPOST"));
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
                'expectedGhDigest',
                'validateGhBinary',
                'createGhRuntime',
                'removeGhRuntime',
                'materializeGhBinary',
                'resolveLocalTarget',
                'verifyTarget',
                'verifyUpdatedFields',
                'extractCommentId',
                'verifyCreatedComment',
                'execute',
                'executeWithEnvironment',
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

    public function testLocalTargetPairsHeadAndBranchFromOneGitSnapshot(): void
    {
        $method = new \ReflectionMethod(\GithubPrWriteTransport::class, 'resolveLocalTarget');
        $lines = file($method->getFileName());
        self::assertIsArray($lines);
        $source = implode(
            '',
            array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1),
        );

        self::assertSame(1, substr_count($source, "'status',"));
        self::assertStringContainsString("'--porcelain=v2'", $source);
        self::assertStringContainsString("'# branch.oid '", $source);
        self::assertStringContainsString("'# branch.head '", $source);
        self::assertStringContainsString("\$branchName === '(detached)'", $source);
        self::assertStringNotContainsString("'rev-parse', '--verify', 'HEAD^{commit}'", $source);
        self::assertStringNotContainsString("'symbolic-ref'", $source);
    }

    public function testGhBinaryMustBeASafeAbsoluteExecutable(): void
    {
        $trusted = [
            $this->bin . '/gh' => [
                'resolved_path' => realpath($this->bin . '/gh'),
                'sha256' => hash_file('sha256', $this->bin . '/gh'),
            ],
        ];
        self::assertSame(
            realpath($this->bin . '/gh'),
            $this->invokeTransport('validateGhBinary', [$this->bin . '/gh', $trusted]),
        );

        chmod($this->bin . '/gh', 0777);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ownership, mode, or digest is unsafe');
        $this->invokeTransport('validateGhBinary', [$this->bin . '/gh', $trusted]);
    }

    public function testGhBinaryDigestAndResolvedPathArePinned(): void
    {
        $originalResolved = realpath($this->bin . '/gh');
        $trusted = [
            $this->bin . '/gh' => [
                'resolved_path' => $originalResolved,
                'sha256' => hash_file('sha256', $this->bin . '/gh'),
            ],
        ];

        self::assertNotFalse(file_put_contents($this->bin . '/gh', "\n# changed\n", FILE_APPEND));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ownership, mode, or digest is unsafe');
        $this->invokeTransport('validateGhBinary', [$this->bin . '/gh', $trusted]);
    }

    public function testGhBinaryTrustManifestMatchesMachineContract(): void
    {
        $contract = json_decode(
            (string) file_get_contents(dirname(__DIR__, 3) . '/.codex/contracts/agent-workflow.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame(
            GITHUB_PR_WRITE_GH_CANDIDATES,
            $contract['publish']['github_pr_write_transport']['gh_executable_manifest'] ?? null,
        );
    }

    public function testGhRuntimeExecutesAnAttestedPrivateCopyIndependentOfTheSourcePath(): void
    {
        $source = $this->bin . '/gh';
        $digest = hash_file('sha256', $source);
        self::assertIsString($digest);
        $runtime = $this->invokeTransport('createGhRuntime', [
            $source,
            $digest,
            [
                'name' => 'test-user',
                'dir' => $this->home,
                'uid' => posix_geteuid(),
            ],
            $this->runtimeRoot,
        ]);

        try {
            self::assertNotSame(realpath($source), $runtime['gh_binary']);
            self::assertSame($runtime['config_dir'] . '/gh', $runtime['gh_binary']);
            self::assertSame($digest, hash_file('sha256', $runtime['gh_binary']));
            self::assertSame(0500, fileperms($runtime['gh_binary']) & 0777);
            self::assertNotFalse(file_put_contents($source, "\n# replaced after materialization\n", FILE_APPEND));
            self::assertNotSame(hash_file('sha256', $source), hash_file('sha256', $runtime['gh_binary']));
            self::assertSame($digest, hash_file('sha256', $runtime['gh_binary']));
        } finally {
            $this->invokeTransport('removeGhRuntime', [$runtime['config_dir'], $runtime['gh_binary']]);
        }

        self::assertSame(['.', '..'], scandir($this->runtimeRoot));
    }

    public function testGhRuntimeRejectsSourceChangedAfterItsTrustedDigestWasCaptured(): void
    {
        $source = $this->bin . '/gh';
        $digest = hash_file('sha256', $source);
        self::assertIsString($digest);
        self::assertNotFalse(file_put_contents($source, "\n# changed before materialization\n", FILE_APPEND));

        try {
            $this->invokeTransport('createGhRuntime', [
                $source,
                $digest,
                [
                    'name' => 'test-user',
                    'dir' => $this->home,
                    'uid' => posix_geteuid(),
                ],
                $this->runtimeRoot,
            ]);
            self::fail('Changed GitHub CLI source was accepted.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('digest is unsafe', $exception->getMessage());
        }

        self::assertSame(['.', '..'], scandir($this->runtimeRoot));
    }

    private function runTransport(array $args, string $input, ?array $targetSequence = null): array
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
            $targets = json_decode($argv[5], true, 8, JSON_THROW_ON_ERROR);
            $targetIndex = 0;
            $resolver = static function () use (&$targets, &$targetIndex): array {
                $target = $targets[min($targetIndex++, count($targets) - 1)] ?? null;
                if (!is_array($target) || !is_string($target['sha'] ?? null) || !is_string($target['branch'] ?? null)) {
                    throw new RuntimeException('Invalid test target sequence.');
                }
                return ['sha' => $target['sha'], 'branch' => $target['branch']];
            };
            $runtimeFactory = static function (string $source) use ($invoke, $argv): array {
                $digest = hash_file('sha256', $source);
                if (!is_string($digest)) {
                    throw new RuntimeException('Test GitHub CLI digest is unavailable.');
                }
                return $invoke('createGhRuntime', [
                    $source,
                    $digest,
                    [
                        'name' => 'test-user',
                        'dir' => $argv[6],
                        'uid' => posix_geteuid(),
                    ],
                    $argv[7],
                ]);
            };
            $options = $invoke('parseArguments', [array_slice($argv, 8)]);
            $payload = $invoke('parsePayload', [$options['operation'], is_string($input) ? $input : '']);
            $invoke('execute', [
                $options,
                $payload,
                $argv[2],
                $resolver,
                $runtimeFactory,
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
        $targetSequence ??= [['sha' => $this->head, 'branch' => $this->branch]];
        $targetSequenceJson = json_encode($targetSequence, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $process = proc_open(
            [
                PHP_BINARY,
                '-r',
                $bootstrap,
                $root . '/scripts/agent/github_pr_write_transport.php',
                $this->bin . '/gh',
                $this->head,
                $this->branch,
                $targetSequenceJson,
                $this->home,
                $this->runtimeRoot,
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
