<?php

declare(strict_types=1);

use Forscherhaus\AgentHarness\ReadonlyReviewerContract;

require_once __DIR__ . '/lib/ReadonlyReviewerContract.php';

$repoRoot = dirname(__DIR__, 2);
$command = $argv[1] ?? '';
$lens = null;
$baseSha = null;
$headSha = null;
$changedPathsJsonPath = null;
$platform = null;
$versionOutput = null;
$expectedVersion = null;
$runtimePath = null;
$expectedOwner = null;
$expectedSha256 = null;
foreach (array_slice($argv, 2) as $argument) {
    if (str_starts_with($argument, '--lens=')) {
        $lens = substr($argument, strlen('--lens='));
        continue;
    }
    if (str_starts_with($argument, '--head-sha=')) {
        $headSha = substr($argument, strlen('--head-sha='));
        continue;
    }
    if (str_starts_with($argument, '--base-sha=')) {
        $baseSha = substr($argument, strlen('--base-sha='));
        continue;
    }
    if (str_starts_with($argument, '--changed-paths-json=')) {
        $changedPathsJsonPath = substr($argument, strlen('--changed-paths-json='));
        continue;
    }
    if (str_starts_with($argument, '--platform=')) {
        $platform = substr($argument, strlen('--platform='));
        continue;
    }
    if (str_starts_with($argument, '--version-output=')) {
        $versionOutput = substr($argument, strlen('--version-output='));
        continue;
    }
    if (str_starts_with($argument, '--expected-version=')) {
        $expectedVersion = substr($argument, strlen('--expected-version='));
        continue;
    }
    if (str_starts_with($argument, '--path=')) {
        $runtimePath = substr($argument, strlen('--path='));
        continue;
    }
    if (str_starts_with($argument, '--expected-owner=')) {
        $expectedOwner = substr($argument, strlen('--expected-owner='));
        continue;
    }
    if (str_starts_with($argument, '--expected-sha256=')) {
        $expectedSha256 = substr($argument, strlen('--expected-sha256='));
        continue;
    }
    fwrite(STDERR, "Unknown option.\n");
    exit(2);
}

if (
    in_array($command, ['resolve', 'trusted-paths', 'instructions', 'validate'], true) &&
    (!is_string($lens) || $lens === '')
) {
    fwrite(STDERR, "Missing --lens.\n");
    exit(2);
}

try {
    if ($command === 'runtime') {
        if (!is_string($platform) || $platform === '') {
            throw new InvalidArgumentException('Missing --platform.');
        }
        $contract = json_decode(
            (string) file_get_contents($repoRoot . '/.codex/contracts/agent-workflow.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        if (!is_array($contract) || !is_array($contract['authority']['reviewer'] ?? null)) {
            throw new RuntimeException('Reviewer policy is invalid.');
        }
        $runtime = ReadonlyReviewerContract::runtimeConfiguration($contract['authority']['reviewer'], $platform);
        fwrite(STDOUT, implode("\t", $runtime) . PHP_EOL);
        exit(0);
    }

    if ($command === 'validate-version') {
        if (!is_string($versionOutput) || $versionOutput === '' || !is_string($expectedVersion)) {
            throw new InvalidArgumentException('Missing --version-output or --expected-version.');
        }
        ReadonlyReviewerContract::assertCodexVersion($versionOutput, $expectedVersion);
        exit(0);
    }

    if ($command === 'validate-codex-source' || $command === 'validate-codex-copy') {
        if (
            !is_string($runtimePath) ||
            !str_starts_with($runtimePath, '/') ||
            !is_string($expectedOwner) ||
            preg_match('/^(?:0|[1-9][0-9]*)$/D', $expectedOwner) !== 1
        ) {
            throw new InvalidArgumentException('Missing or invalid Codex binary validation input.');
        }
        if ($command === 'validate-codex-source') {
            ReadonlyReviewerContract::assertCodexSource($runtimePath, (int) $expectedOwner);
            exit(0);
        }
        if (!is_string($expectedSha256)) {
            throw new InvalidArgumentException('Missing --expected-sha256.');
        }
        ReadonlyReviewerContract::assertMaterializedCodex($runtimePath, (int) $expectedOwner, $expectedSha256);
        exit(0);
    }

    if ($command === 'resolve' || $command === 'trusted-paths' || $command === 'instructions') {
        $contract = json_decode(
            (string) file_get_contents($repoRoot . '/.codex/contracts/agent-workflow.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        if (!is_array($contract) || !is_array($contract['authority']['reviewer'] ?? null)) {
            throw new RuntimeException('Reviewer policy is invalid.');
        }
        if ($command === 'trusted-paths') {
            foreach (ReadonlyReviewerContract::trustedBasePaths($contract['authority']['reviewer']) as $path) {
                fwrite(STDOUT, $path . PHP_EOL);
            }
            exit(0);
        }
        $invocation = ReadonlyReviewerContract::resolveInvocation($repoRoot, $lens, $contract['authority']['reviewer']);
        if ($command === 'instructions') {
            fwrite(STDOUT, $invocation['role_instructions']);
            exit(0);
        }
        fwrite(
            STDOUT,
            implode("\t", [
                $invocation['role_file'],
                $invocation['model'],
                $invocation['reasoning'],
                implode(',', $invocation['disabled_features']),
                $invocation['output_schema_path'],
                $invocation['codex_sandbox_mode'],
                $invocation['codex_approval_policy'],
            ]) . PHP_EOL,
        );
        exit(0);
    }

    if ($command === 'validate') {
        if (!is_string($baseSha) || preg_match('/^[a-f0-9]{40}$/D', $baseSha) !== 1) {
            throw new InvalidArgumentException('Missing or invalid --base-sha.');
        }
        if (!is_string($headSha) || preg_match('/^[a-f0-9]{40}$/D', $headSha) !== 1) {
            throw new InvalidArgumentException('Missing or invalid --head-sha.');
        }
        if (!is_string($changedPathsJsonPath) || !str_starts_with($changedPathsJsonPath, '/')) {
            throw new InvalidArgumentException('Missing or invalid --changed-paths-json.');
        }
        try {
            $changedPaths = json_decode(
                (string) file_get_contents($changedPathsJsonPath),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
        } catch (Throwable) {
            throw new InvalidArgumentException('Reviewer changed-path evidence is invalid.');
        }
        if (!is_array($changedPaths)) {
            throw new InvalidArgumentException('Reviewer changed-path evidence is invalid.');
        }
        $review = ReadonlyReviewerContract::validateOutput(
            (string) stream_get_contents(STDIN),
            $lens,
            $baseSha,
            $headSha,
            $changedPaths,
        );
        fwrite(STDOUT, json_encode($review, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL);
        exit(0);
    }

    fwrite(
        STDERR,
        "Usage: readonly_reviewer_contract.php <resolve|instructions|trusted-paths|runtime|validate-version|validate-codex-source|validate-codex-copy|validate> [--lens=<lens>] [--platform=<platform>] [--version-output=<value>] [--expected-version=<version>] [--path=<absolute-path>] [--expected-owner=<uid>] [--expected-sha256=<sha256>] [--base-sha=<sha>] [--head-sha=<sha>] [--changed-paths-json=<absolute-path>]\n",
    );
    exit(2);
} catch (Throwable $throwable) {
    fwrite(STDERR, $throwable->getMessage() . PHP_EOL);
    exit(1);
}
