<?php

declare(strict_types=1);

use Forscherhaus\AgentHarness\ReadonlyReviewerContract;

require_once __DIR__ . '/lib/ReadonlyReviewerContract.php';

$repoRoot = dirname(__DIR__, 2);
$command = $argv[1] ?? '';
$lens = null;
$headSha = null;
foreach (array_slice($argv, 2) as $argument) {
    if (str_starts_with($argument, '--lens=')) {
        $lens = substr($argument, strlen('--lens='));
        continue;
    }
    if (str_starts_with($argument, '--head-sha=')) {
        $headSha = substr($argument, strlen('--head-sha='));
        continue;
    }
    fwrite(STDERR, "Unknown option.\n");
    exit(2);
}

if (!is_string($lens) || $lens === '') {
    fwrite(STDERR, "Missing --lens.\n");
    exit(2);
}

try {
    if ($command === 'resolve') {
        $contract = json_decode(
            (string) file_get_contents($repoRoot . '/.codex/contracts/agent-workflow.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        if (!is_array($contract) || !is_array($contract['authority']['reviewer'] ?? null)) {
            throw new RuntimeException('Reviewer policy is invalid.');
        }
        $invocation = ReadonlyReviewerContract::resolveInvocation($repoRoot, $lens, $contract['authority']['reviewer']);
        fwrite(
            STDOUT,
            implode("\t", [
                $invocation['role_file'],
                $invocation['model'],
                $invocation['reasoning'],
                implode(',', $invocation['disabled_features']),
            ]) . PHP_EOL,
        );
        exit(0);
    }

    if ($command === 'validate') {
        if (!is_string($headSha) || preg_match('/^[a-f0-9]{40}$/D', $headSha) !== 1) {
            throw new InvalidArgumentException('Missing or invalid --head-sha.');
        }
        $review = ReadonlyReviewerContract::validateOutput((string) stream_get_contents(STDIN), $lens, $headSha);
        fwrite(STDOUT, json_encode($review, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL);
        exit(0);
    }

    fwrite(STDERR, "Usage: readonly_reviewer_contract.php <resolve|validate> --lens=<lens> [--head-sha=<sha>]\n");
    exit(2);
} catch (Throwable $throwable) {
    fwrite(STDERR, $throwable->getMessage() . PHP_EOL);
    exit(1);
}
