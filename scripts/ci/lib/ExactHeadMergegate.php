<?php

declare(strict_types=1);

namespace CiContract;

use InvalidArgumentException;

/**
 * Pure, fail-closed evaluation of the evidence required before an exact-head merge.
 */
final class ExactHeadMergegate
{
    public static function parseTarget(string|int $target, string $repository): int
    {
        self::assertRepository($repository);

        if (is_int($target)) {
            if ($target < 1) {
                throw new InvalidArgumentException('Pull request number must be positive.');
            }

            return $target;
        }

        if (preg_match('/^[1-9][0-9]*$/D', $target) === 1) {
            return (int) $target;
        }

        if (
            preg_match('~^https://github\.com/([^/]+)/([^/]+)/pull/([1-9][0-9]*)$~D', $target, $matches) !== 1 ||
            $matches[1] . '/' . $matches[2] !== $repository
        ) {
            throw new InvalidArgumentException(
                'Target must be a pull request number or canonical GitHub URL for this repository.',
            );
        }

        return (int) $matches[3];
    }

    public static function normalizeSha(mixed $sha): string
    {
        if (!is_string($sha) || preg_match('/^[0-9a-fA-F]{40}$/D', $sha) !== 1) {
            throw new InvalidArgumentException('Reviewed SHA must be exactly 40 hexadecimal characters.');
        }

        return strtolower($sha);
    }

    /**
     * @param array<string, mixed> $policy
     * @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    public static function evaluate(array $policy, array $snapshot, int $prNumber, string $reviewedSha): array
    {
        self::assertPolicy($policy);
        $sha = self::normalizeSha($reviewedSha);
        $gates = [];

        $prHeadRevalidated = ($snapshot['pr_head_revalidated'] ?? null) === true;
        self::addGate(
            $gates,
            $prHeadRevalidated ? 'pass' : 'fail',
            $prHeadRevalidated ? 'pr_head_revalidated' : 'pr_head_drift_during_evaluation',
            $prHeadRevalidated
                ? 'Pull request identity remained stable across evidence collection.'
                : 'Pull request identity changed or was not revalidated after evidence collection.',
        );

        $pr = $snapshot['pr'] ?? null;
        if (!is_array($pr)) {
            self::addGate($gates, 'fail', 'pr_snapshot', 'Pull request snapshot is missing.');
        } else {
            $prExpectations = [
                'number' => [$prNumber, 'pr_number', 'Pull request number does not match.'],
                'state' => ['open', 'pr_state', 'Pull request is not open.'],
                'draft' => [false, 'pr_draft', 'Pull request is a draft.'],
                'base_ref' => [$policy['base_ref'], 'pr_base', 'Pull request base is not approved.'],
                'head_sha' => [$sha, 'pr_head', 'Pull request head does not match reviewed SHA.'],
                'mergeable' => [true, 'pr_mergeable', 'Pull request is not mergeable.'],
                'mergeable_state' => ['clean', 'pr_mergeable_state', 'Pull request mergeability is not clean.'],
            ];
            $prValid = true;

            foreach ($prExpectations as $field => [$expected, $code, $message]) {
                if (!array_key_exists($field, $pr) || $pr[$field] !== $expected) {
                    self::addGate($gates, 'fail', $code, $message);
                    $prValid = false;
                }
            }

            if ($prValid) {
                self::addGate($gates, 'pass', 'pr', 'Pull request identity and mergeability are valid.');
            }
        }

        $associatedPrNumbers = $snapshot['associated_pr_numbers'] ?? null;
        $commitBoundToPr = is_array($associatedPrNumbers) && in_array($prNumber, $associatedPrNumbers, true);
        self::addGate(
            $gates,
            $commitBoundToPr ? 'pass' : 'fail',
            $commitBoundToPr ? 'pr_commit_binding' : 'pr_commit_binding_missing',
            $commitBoundToPr
                ? 'Reviewed commit is associated with this pull request.'
                : 'Reviewed commit is not associated with this pull request.',
        );

        $workflow = self::selectWorkflowRun(
            $snapshot['workflow_runs'] ?? null,
            $policy['workflow_name'],
            $sha,
            $prNumber,
            is_array($pr) ? $pr['head_ref'] ?? null : null,
            is_array($pr) ? $pr['head_repository'] ?? null : null,
        );
        if ($workflow === null) {
            self::addGate(
                $gates,
                'fail',
                'workflow_missing_or_stale',
                'No successful pull-request workflow run is bound to this pull request and SHA.',
            );
        } else {
            self::addGate($gates, 'pass', 'workflow', 'Required workflow run is bound to the reviewed head.');
        }

        $suiteId = $workflow['check_suite_id'] ?? null;
        if ($workflow !== null && (!is_int($suiteId) || $suiteId < 1)) {
            self::addGate($gates, 'fail', 'check_suite_missing', 'Selected workflow has no valid check suite.');
            $suiteId = null;
        }

        self::evaluateCheckRuns($policy, $snapshot['check_runs'] ?? null, $sha, $suiteId, $gates);
        self::evaluateReviewAttestations(
            $policy,
            $snapshot['comments'] ?? null,
            $snapshot['review_activity'] ?? null,
            $sha,
            $gates,
        );

        $passed = array_filter($gates, static fn(array $gate): bool => ($gate['status'] ?? null) === 'fail') === [];

        return [
            'status' => $passed ? 'pass' : 'fail',
            'pr_number' => $prNumber,
            'reviewed_sha' => $sha,
            'workflow_run_id' => self::normalizeReportIdentifier($workflow['id'] ?? null),
            'check_suite_id' => is_int($suiteId) ? $suiteId : null,
            'gates' => $gates,
        ];
    }

    /**
     * @param mixed $workflowRuns
     * @return array<string, mixed>|null
     */
    private static function selectWorkflowRun(
        mixed $workflowRuns,
        string $workflowName,
        string $sha,
        int $prNumber,
        mixed $headRef,
        mixed $headRepository,
    ): ?array {
        if (!is_array($workflowRuns) || !is_string($headRef) || !is_string($headRepository)) {
            return null;
        }

        $matches = [];
        foreach ($workflowRuns as $run) {
            if (
                is_array($run) &&
                ($run['name'] ?? null) === $workflowName &&
                ($run['event'] ?? null) === 'pull_request' &&
                ($run['head_sha'] ?? null) === $sha &&
                ($run['head_branch'] ?? null) === $headRef &&
                ($run['head_repository'] ?? null) === $headRepository &&
                self::workflowPullRequestBindingAllows($run['pr_numbers'] ?? null, $prNumber)
            ) {
                $matches[] = $run;
            }
        }

        usort(
            $matches,
            static fn(array $left, array $right): int => ((int) ($right['id'] ?? 0)) <=> ((int) ($left['id'] ?? 0)),
        );

        $latest = $matches[0] ?? null;
        if (
            !is_array($latest) ||
            ($latest['status'] ?? null) !== 'completed' ||
            ($latest['conclusion'] ?? null) !== 'success'
        ) {
            return null;
        }

        return $latest;
    }

    private static function workflowPullRequestBindingAllows(mixed $prNumbers, int $prNumber): bool
    {
        if (!is_array($prNumbers)) {
            return false;
        }

        return in_array($prNumber, $prNumbers, true);
    }

    /**
     * @param array<string, mixed> $policy
     * @param mixed $checkRuns
     * @param int|null $suiteId
     * @param array<int, array<string, mixed>> $gates
     */
    private static function evaluateCheckRuns(
        array $policy,
        mixed $checkRuns,
        string $sha,
        ?int $suiteId,
        array &$gates,
    ): void {
        $runsByName = [];
        if (is_array($checkRuns) && $suiteId !== null) {
            foreach ($checkRuns as $run) {
                if (
                    is_array($run) &&
                    ($run['check_suite_id'] ?? null) === $suiteId &&
                    ($run['head_sha'] ?? null) === $sha &&
                    is_string($run['name'] ?? null)
                ) {
                    $runsByName[$run['name']][] = $run;
                }
            }
        }

        foreach (['required_checks' => false, 'conditional_checks' => true] as $field => $conditional) {
            foreach ($policy[$field] as $name) {
                $matchingRuns = $runsByName[$name] ?? [];
                if (count($matchingRuns) !== 1) {
                    self::addGate(
                        $gates,
                        'fail',
                        $conditional ? 'conditional_check_missing_or_duplicate' : 'required_check_missing_or_duplicate',
                        'Policy check is missing or duplicated.',
                        $name,
                    );
                    continue;
                }

                $run = $matchingRuns[0];
                $completed = ($run['status'] ?? null) === 'completed';
                $successful = $completed && ($run['conclusion'] ?? null) === 'success';
                $notApplicable = $conditional && $completed && ($run['conclusion'] ?? null) === 'skipped';

                if (!$successful && !$notApplicable) {
                    self::addGate(
                        $gates,
                        'fail',
                        $conditional ? 'conditional_check_invalid' : 'required_check_invalid',
                        'Policy check is not in an allowed terminal state.',
                        $name,
                    );
                    continue;
                }

                self::addGate(
                    $gates,
                    'pass',
                    $notApplicable ? 'conditional_check_skipped' : 'check_success',
                    $notApplicable
                        ? 'Conditional policy check is explicitly not applicable.'
                        : 'Policy check succeeded.',
                    $name,
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $policy
     * @param mixed $comments
     * @param mixed $reviewActivity
     * @param array<int, array<string, mixed>> $gates
     */
    private static function evaluateReviewAttestations(
        array $policy,
        mixed $comments,
        mixed $reviewActivity,
        string $sha,
        array &$gates,
    ): void {
        $candidates = [];
        if (is_array($comments)) {
            foreach ($comments as $comment) {
                $candidate = self::parseReviewAttestation($policy, $comment, $sha);
                if ($candidate !== null) {
                    $candidates[] = $candidate;
                }
            }
        }

        usort($candidates, static function (array $left, array $right): int {
            $timeOrder = strcmp($right['attested_at'], $left['attested_at']);
            if ($timeOrder !== 0) {
                return $timeOrder;
            }

            return ((int) $right['comment_id']) <=> ((int) $left['comment_id']);
        });
        $attestation = $candidates[0] ?? null;
        $validAttestationFound = is_array($attestation);
        $laterFeedback =
            $validAttestationFound &&
            (!is_array($comments) ||
                !is_array($reviewActivity) ||
                self::hasBlockingReviewFeedbackAfterAttestation(
                    $policy,
                    $comments,
                    $reviewActivity,
                    $attestation,
                    $sha,
                ));

        self::addGate(
            $gates,
            $validAttestationFound && !$laterFeedback ? 'pass' : 'fail',
            !$validAttestationFound
                ? 'review_attestation_invalid'
                : ($laterFeedback
                    ? 'review_feedback_not_closed'
                    : 'reviews'),
            $validAttestationFound && !$laterFeedback
                ? 'Required independent review attestation is valid.'
                : (!$validAttestationFound
                    ? 'Required independent review attestation is missing, stale, malformed, or untrusted.'
                    : 'Trusted review feedback is newer than the attestation or remains changes-requested.'),
        );
    }

    /**
     * @param array<string, mixed> $policy
     * @param mixed $comment
     * @return array{
     *     comment_id:int|string,
     *     attested_at:string,
     *     review_watermarks:array{review_id:int,review_comment_id:int},
     *     reviews:array<string, string>
     * }|null
     */
    private static function parseReviewAttestation(array $policy, mixed $comment, string $sha): ?array
    {
        if (
            !is_array($comment) ||
            !in_array($comment['author_association'] ?? null, $policy['trusted_associations'], true) ||
            !is_string($comment['body'] ?? null) ||
            (!is_int($comment['id'] ?? null) && !is_string($comment['id'] ?? null))
        ) {
            return null;
        }
        $createdAt = self::normalizeGitHubTimestamp($comment['created_at'] ?? null);
        $updatedAt = self::normalizeGitHubTimestamp($comment['updated_at'] ?? null);
        if ($createdAt === null || $updatedAt === null || $createdAt !== $updatedAt) {
            return null;
        }

        $prefix = '<!-- ' . $policy['attestation_marker'] . "\n";
        $suffix = "\n-->";
        $body = $comment['body'];
        if (!str_starts_with($body, $prefix) || !str_ends_with($body, $suffix)) {
            return null;
        }

        $json = substr($body, strlen($prefix), -strlen($suffix));
        try {
            $attestation = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }

        if (
            !is_array($attestation) ||
            array_keys($attestation) !== ['head_sha', 'review_activity_watermark', 'reviews'] ||
            ($attestation['head_sha'] ?? null) !== $sha ||
            !is_array($attestation['review_activity_watermark'] ?? null) ||
            array_keys($attestation['review_activity_watermark']) !== ['review_id', 'review_comment_id'] ||
            !is_int($attestation['review_activity_watermark']['review_id'] ?? null) ||
            ($attestation['review_activity_watermark']['review_id'] ?? -1) < 0 ||
            !is_int($attestation['review_activity_watermark']['review_comment_id'] ?? null) ||
            ($attestation['review_activity_watermark']['review_comment_id'] ?? -1) < 0 ||
            !is_array($attestation['reviews'] ?? null)
        ) {
            return null;
        }

        $reviews = [];
        foreach ($attestation['reviews'] as $review) {
            if (
                !is_array($review) ||
                array_keys($review) !== ['lens', 'reviewer_ref', 'verdict'] ||
                !is_string($review['lens'] ?? null) ||
                !in_array($review['lens'], $policy['required_review_lenses'], true) ||
                isset($reviews[$review['lens']]) ||
                !is_string($review['reviewer_ref'] ?? null) ||
                preg_match('/^[0-9a-f]{64}$/D', $review['reviewer_ref']) !== 1 ||
                ($review['verdict'] ?? null) !== $policy['attestation_verdict']
            ) {
                return null;
            }

            $reviews[$review['lens']] = $review['reviewer_ref'];
        }

        $requiredLenses = $policy['required_review_lenses'];
        if (
            count($reviews) !== count($requiredLenses) ||
            array_diff($requiredLenses, array_keys($reviews)) !== [] ||
            count(array_unique($reviews)) !== count($reviews)
        ) {
            return null;
        }

        return [
            'comment_id' => $comment['id'],
            'attested_at' => $createdAt,
            'review_watermarks' => $attestation['review_activity_watermark'],
            'reviews' => $reviews,
        ];
    }

    /**
     * @param array<string, mixed> $policy
     * @param array<int, mixed> $comments
     * @param array<int, mixed> $reviewActivity
     * @param array{
     *     comment_id:int|string,
     *     attested_at:string,
     *     review_watermarks:array{review_id:int,review_comment_id:int},
     *     reviews:array<string, string>
     * } $attestation
     */
    private static function hasBlockingReviewFeedbackAfterAttestation(
        array $policy,
        array $comments,
        array $reviewActivity,
        array $attestation,
        string $sha,
    ): bool {
        foreach ($comments as $comment) {
            if (!is_array($comment) || !is_string($comment['author_association'] ?? null)) {
                return true;
            }
            if (($comment['id'] ?? null) === $attestation['comment_id']) {
                continue;
            }

            $updatedAt = self::normalizeGitHubTimestamp($comment['updated_at'] ?? null);
            if ($updatedAt === null || (!is_int($comment['id'] ?? null) && !is_string($comment['id'] ?? null))) {
                return true;
            }
            if (
                in_array($comment['author_association'], $policy['blocking_feedback_associations'], true) &&
                (strcmp($updatedAt, $attestation['attested_at']) > 0 ||
                    ($updatedAt === $attestation['attested_at'] &&
                        (int) ($comment['id'] ?? 0) > (int) $attestation['comment_id']))
            ) {
                return true;
            }
        }

        $latestReviewByActor = [];
        $observedWatermarks = ['review_id' => 0, 'review_comment_id' => 0];
        foreach ($reviewActivity as $activity) {
            if (
                !is_array($activity) ||
                !is_string($activity['author_association'] ?? null) ||
                !is_string($activity['kind'] ?? null) ||
                !in_array($activity['kind'], ['review', 'review_comment'], true) ||
                !is_int($activity['id'] ?? null) ||
                ($activity['id'] ?? 0) < 1 ||
                !is_string($activity['actor_ref'] ?? null)
            ) {
                return true;
            }

            $occurredAt = self::normalizeGitHubTimestamp($activity['occurred_at'] ?? null);
            if ($occurredAt === null) {
                return true;
            }

            $watermarkKey = $activity['kind'] === 'review' ? 'review_id' : 'review_comment_id';
            $observedWatermarks[$watermarkKey] = max($observedWatermarks[$watermarkKey], $activity['id']);

            if (($activity['kind'] ?? null) === 'review' && ($activity['commit_sha'] ?? null) === $sha) {
                $actor = $activity['actor_ref'];
                $existing = $latestReviewByActor[$actor] ?? null;
                if (
                    !is_array($existing) ||
                    strcmp($occurredAt, $existing['occurred_at']) > 0 ||
                    ($occurredAt === $existing['occurred_at'] &&
                        (int) ($activity['id'] ?? 0) > (int) ($existing['id'] ?? 0))
                ) {
                    $latestReviewByActor[$actor] = [
                        'occurred_at' => $occurredAt,
                        'id' => $activity['id'] ?? null,
                        'state' => $activity['state'] ?? null,
                    ];
                }
            }
        }

        if ($observedWatermarks !== $attestation['review_watermarks']) {
            return true;
        }

        foreach ($latestReviewByActor as $review) {
            if (($review['state'] ?? null) === 'CHANGES_REQUESTED') {
                return true;
            }
        }

        return false;
    }

    private static function normalizeGitHubTimestamp(mixed $timestamp): ?string
    {
        if (
            !is_string($timestamp) ||
            preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$/D', $timestamp) !== 1 ||
            strtotime($timestamp) === false
        ) {
            return null;
        }

        return $timestamp;
    }

    /**
     * @param array<string, mixed> $policy
     */
    private static function assertPolicy(array $policy): void
    {
        foreach (
            [
                'base_ref',
                'workflow_name',
                'required_checks',
                'conditional_checks',
                'required_review_lenses',
                'trusted_associations',
                'blocking_feedback_associations',
                'attestation_marker',
                'attestation_verdict',
            ]
            as $key
        ) {
            if (!array_key_exists($key, $policy)) {
                throw new InvalidArgumentException('Malformed mergegate policy.');
            }
        }

        foreach (['base_ref', 'workflow_name', 'attestation_marker', 'attestation_verdict'] as $key) {
            if (!is_string($policy[$key]) || $policy[$key] === '' || str_contains($policy[$key], "\n")) {
                throw new InvalidArgumentException('Malformed mergegate policy.');
            }
        }

        foreach (
            [
                'required_checks',
                'conditional_checks',
                'required_review_lenses',
                'trusted_associations',
                'blocking_feedback_associations',
            ]
            as $key
        ) {
            if (!is_array($policy[$key]) || $policy[$key] === []) {
                throw new InvalidArgumentException('Malformed mergegate policy.');
            }

            foreach ($policy[$key] as $value) {
                if (!is_string($value) || $value === '') {
                    throw new InvalidArgumentException('Malformed mergegate policy.');
                }
            }

            if (count($policy[$key]) !== count(array_unique($policy[$key]))) {
                throw new InvalidArgumentException('Malformed mergegate policy.');
            }
        }

        if (array_intersect($policy['required_checks'], $policy['conditional_checks']) !== []) {
            throw new InvalidArgumentException('Malformed mergegate policy.');
        }
    }

    private static function assertRepository(string $repository): void
    {
        if (preg_match('~^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$~D', $repository) !== 1) {
            throw new InvalidArgumentException('Expected repository owner/name.');
        }
    }

    /**
     * @param array<int, array<string, mixed>> $gates
     */
    private static function addGate(
        array &$gates,
        string $status,
        string $code,
        string $message,
        ?string $subject = null,
    ): void {
        $gate = [
            'status' => $status,
            'code' => $code,
            'message' => $message,
        ];
        if ($subject !== null) {
            $gate['subject'] = $subject;
        }

        $gates[] = $gate;
    }

    private static function normalizeReportIdentifier(mixed $identifier): int|string|null
    {
        if (is_int($identifier) || is_string($identifier)) {
            return $identifier;
        }

        return null;
    }
}
