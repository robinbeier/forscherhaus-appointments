<?php

declare(strict_types=1);

const GITHUB_PR_WRITE_REPOSITORY = 'robinbeier/forscherhaus-appointments';
const GITHUB_PR_WRITE_BASE_REF = 'main';
const GITHUB_PR_WRITE_MAX_BODY_BYTES = 65536;
const GITHUB_PR_WRITE_MAX_TITLE_BYTES = 256;
const GITHUB_PR_WRITE_MAX_INPUT_BYTES = 400000;
const GITHUB_PR_WRITE_MAX_COMMAND_OUTPUT_BYTES = 131072;
const GITHUB_PR_WRITE_TARGET_PROJECTION = '{number: .number, state: .state, base: {ref: .base.ref, repo: {full_name: .base.repo.full_name}}, head: {ref: .head.ref, sha: .head.sha, repo: {full_name: .head.repo.full_name}}}';
const GITHUB_PR_WRITE_COMMENT_ID_PROJECTION = '{id: .id}';
const GITHUB_PR_WRITE_COMMENT_PROJECTION = '{id: .id, url: .url, issue_url: .issue_url, body: .body}';

require_once __DIR__ . '/lib/GithubPrWriteRequest.php';
require_once __DIR__ . '/lib/GithubPrWriteProcessRunner.php';
require_once __DIR__ . '/lib/GithubPrWriteRuntime.php';
require_once __DIR__ . '/lib/GithubPrWriteTarget.php';
require_once __DIR__ . '/lib/GithubPrWriteApplication.php';

final class GithubPrWriteTransport
{
    private static function repoRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function processRunner(): GithubPrWriteProcessRunner
    {
        return new GithubPrWriteProcessRunner(GITHUB_PR_WRITE_MAX_COMMAND_OUTPUT_BYTES);
    }

    private static function runtime(): GithubPrWriteRuntime
    {
        return GithubPrWriteRuntime::fromRepository(self::repoRoot());
    }

    /** @param list<string> $arguments */
    public static function main(array $arguments): int
    {
        $runtime = self::runtime();
        $processRunner = self::processRunner();
        $application = new GithubPrWriteApplication(
            $processRunner,
            new GithubPrWriteTarget($processRunner, self::repoRoot()),
            static fn(): string => $runtime->resolveBinary(),
            static fn(string $source): array => $runtime->create($source),
        );

        return $application->run($arguments, STDIN);
    }
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    exit(GithubPrWriteTransport::main(array_slice($argv, 1)));
}
