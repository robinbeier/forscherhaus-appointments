<?php

declare(strict_types=1);

final class GithubPrWriteTarget
{
    public function __construct(
        private readonly GithubPrWriteProcessRunner $processRunner,
        private readonly string $repoRoot,
    ) {}

    /** @return array{sha: string, branch: string} */
    public function resolveLocal(): array
    {
        $resolvedRoot = realpath($this->repoRoot);
        if ($resolvedRoot === false || !is_dir($resolvedRoot)) {
            throw new RuntimeException('Canonical repository checkout is unavailable.');
        }

        $environment = [
            'PATH' => '/usr/bin:/bin:/usr/sbin:/sbin',
            'TMPDIR' => '/tmp',
            'LANG' => 'C',
            'LC_ALL' => 'C',
            'GIT_CONFIG_GLOBAL' => '/dev/null',
            'GIT_CONFIG_SYSTEM' => '/dev/null',
            'GIT_CONFIG_NOSYSTEM' => '1',
            'GIT_NO_LAZY_FETCH' => '1',
            'GIT_NO_REPLACE_OBJECTS' => '1',
            'GIT_OPTIONAL_LOCKS' => '0',
            'GIT_TERMINAL_PROMPT' => '0',
        ];
        $common = [
            '/usr/bin/git',
            '-c',
            'core.fsmonitor=false',
            '-c',
            'core.hooksPath=/dev/null',
            '-c',
            'credential.helper=',
            '-C',
            $resolvedRoot,
        ];

        $topLevel = $this->processRunner->run([...$common, 'rev-parse', '--show-toplevel'], '', $environment);
        if ($topLevel['exit_code'] !== 0 || realpath(trim($topLevel['stdout'])) !== $resolvedRoot) {
            throw new RuntimeException('Canonical repository checkout could not be verified.');
        }

        $snapshot = $this->processRunner->run(
            [
                ...$common,
                'status',
                '--porcelain=v2',
                '--branch',
                '--untracked-files=no',
                '--ignore-submodules=all',
                '--no-renames',
                '-z',
            ],
            '',
            $environment,
        );
        if ($snapshot['exit_code'] !== 0 || !str_ends_with($snapshot['stdout'], "\0")) {
            throw new RuntimeException('Canonical repository target snapshot could not be verified.');
        }

        $headSha = null;
        $branchName = null;
        $records = explode("\0", $snapshot['stdout']);
        array_pop($records);
        foreach ($records as $record) {
            if (str_starts_with($record, '# branch.oid ')) {
                if ($headSha !== null) {
                    throw new RuntimeException('Canonical repository target snapshot is ambiguous.');
                }
                $headSha = substr($record, strlen('# branch.oid '));
            } elseif (str_starts_with($record, '# branch.head ')) {
                if ($branchName !== null) {
                    throw new RuntimeException('Canonical repository target snapshot is ambiguous.');
                }
                $branchName = substr($record, strlen('# branch.head '));
            }
        }
        if (!is_string($headSha) || preg_match('/\A[a-f0-9]{40}\z/D', $headSha) !== 1) {
            throw new RuntimeException('Canonical repository HEAD could not be verified.');
        }
        if (
            !is_string($branchName) ||
            $branchName === '' ||
            $branchName === '(detached)' ||
            strlen($branchName) > 255 ||
            str_contains($branchName, "\0") ||
            str_contains($branchName, "\n") ||
            str_contains($branchName, "\r") ||
            preg_match('//u', $branchName) !== 1
        ) {
            throw new RuntimeException('Canonical repository branch could not be verified.');
        }
        $branchFormat = $this->processRunner->run(
            [...$common, 'check-ref-format', '--branch', $branchName],
            '',
            $environment,
        );
        if ($branchFormat['exit_code'] !== 0 || rtrim($branchFormat['stdout'], "\r\n") !== $branchName) {
            throw new RuntimeException('Canonical repository branch format could not be verified.');
        }

        return ['sha' => $headSha, 'branch' => $branchName];
    }

    /**
     * @param list<string> $requestedFields
     * @param array<string, string> $environment
     * @return array<string, mixed>
     */
    public function verifyRemote(
        string $ghBinary,
        string $repo,
        int $number,
        string $localHead,
        string $localBranch,
        array $requestedFields,
        array $environment,
    ): array {
        sort($requestedFields);
        $projection = match ($requestedFields) {
            [] => GITHUB_PR_WRITE_TARGET_PROJECTION,
            ['body'] => GITHUB_PR_WRITE_TARGET_PROJECTION . ' + {body: .body}',
            ['title'] => GITHUB_PR_WRITE_TARGET_PROJECTION . ' + {title: .title}',
            ['body', 'title'] => GITHUB_PR_WRITE_TARGET_PROJECTION . ' + {body: .body, title: .title}',
            default => throw new LogicException('GitHub pull request projection fields are invalid.'),
        };
        $endpoint = 'repos/' . $repo . '/pulls/' . $number;
        $result = $this->processRunner->run(
            [$ghBinary, 'api', '--hostname', 'github.com', '--method', 'GET', $endpoint, '--jq', $projection],
            '',
            $environment,
        );
        if ($result['exit_code'] !== 0) {
            throw new UnexpectedValueException('GitHub pull request target could not be verified.');
        }

        try {
            $record = json_decode($result['stdout'], true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new UnexpectedValueException('GitHub pull request target returned invalid metadata.');
        }
        if (
            !is_array($record) ||
            ($record['number'] ?? null) !== $number ||
            ($record['state'] ?? null) !== 'open' ||
            ($record['base']['ref'] ?? null) !== GITHUB_PR_WRITE_BASE_REF ||
            ($record['base']['repo']['full_name'] ?? null) !== GITHUB_PR_WRITE_REPOSITORY ||
            ($record['head']['repo']['full_name'] ?? null) !== GITHUB_PR_WRITE_REPOSITORY ||
            ($record['head']['ref'] ?? null) !== $localBranch ||
            ($record['head']['sha'] ?? null) !== $localHead
        ) {
            throw new UnexpectedValueException(
                'GitHub pull request target does not match the canonical exact local target.',
            );
        }

        return $record;
    }

    /**
     * @param array<string, mixed> $record
     * @param array{title?: string, body?: string} $payload
     */
    public function verifyUpdatedFields(array $record, array $payload): void
    {
        foreach ($payload as $field => $expectedValue) {
            if (!array_key_exists($field, $record) || $record[$field] !== $expectedValue) {
                throw new UnexpectedValueException('GitHub pull request update could not be verified.');
            }
        }
    }

    /** @param array<string, string> $environment */
    public function verifyCreatedComment(
        string $ghBinary,
        string $repo,
        int $number,
        int $commentId,
        string $expectedBody,
        array $environment,
    ): void {
        $endpoint = 'repos/' . $repo . '/issues/comments/' . $commentId;
        $result = $this->processRunner->run(
            [
                $ghBinary,
                'api',
                '--hostname',
                'github.com',
                '--method',
                'GET',
                $endpoint,
                '--jq',
                GITHUB_PR_WRITE_COMMENT_PROJECTION,
            ],
            '',
            $environment,
        );
        if ($result['exit_code'] !== 0) {
            throw new UnexpectedValueException('GitHub comment state could not be verified.');
        }

        try {
            $record = json_decode($result['stdout'], true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new UnexpectedValueException('GitHub comment state returned invalid metadata.');
        }

        $expectedCommentUrl = 'https://api.github.com/repos/' . $repo . '/issues/comments/' . $commentId;
        $expectedIssueUrl = 'https://api.github.com/repos/' . $repo . '/issues/' . $number;
        if (
            !is_array($record) ||
            ($record['id'] ?? null) !== $commentId ||
            ($record['url'] ?? null) !== $expectedCommentUrl ||
            ($record['issue_url'] ?? null) !== $expectedIssueUrl ||
            !is_string($record['body'] ?? null) ||
            $record['body'] !== $expectedBody
        ) {
            throw new UnexpectedValueException('GitHub comment state does not match the requested exact target.');
        }
    }
}
