<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use CiContract\ExactHeadMergegate;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../scripts/ci/lib/ExactHeadMergegate.php';

final class ExactHeadMergegateTest extends TestCase
{
    private const SHA = '0123456789abcdef0123456789abcdef01234567';

    public function testParsesOnlyCanonicalTargetsAndNormalizesUppercaseSha(): void
    {
        self::assertSame(12, ExactHeadMergegate::parseTarget(12, 'acme/app'));
        self::assertSame(12, ExactHeadMergegate::parseTarget('12', 'acme/app'));
        self::assertSame(12, ExactHeadMergegate::parseTarget('https://github.com/acme/app/pull/12', 'acme/app'));
        self::assertSame(self::SHA, ExactHeadMergegate::normalizeSha(strtoupper(self::SHA)));
        foreach (
            [
                '0',
                '012',
                'https://gitlab.com/acme/app/pull/12',
                'https://github.com/acme/app/pull/12?x=1',
                'https://github.com/acme/other/pull/12',
            ]
            as $target
        ) {
            try {
                ExactHeadMergegate::parseTarget($target, 'acme/app');
                self::fail('Target accepted.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        $this->expectException(InvalidArgumentException::class);
        ExactHeadMergegate::normalizeSha('bad');
    }

    public function testValidSnapshotPassesWithThreeIndependentReviewers(): void
    {
        $policy = $this->policy();
        $snapshot = $this->snapshot();
        $snapshot['comments'] = [$this->attestationComment()];
        $report = ExactHeadMergegate::evaluate($policy, $snapshot, 12, self::SHA);
        self::assertSame('pass', $report['status']);
        self::assertSame(101, $report['workflow_run_id']);
        self::assertSame(202, $report['check_suite_id']);
        self::assertStringNotContainsString('login', json_encode($report, JSON_THROW_ON_ERROR));
    }

    public function testMissingWorkflowChecksAndAttestationFailClosed(): void
    {
        $snapshot = $this->snapshot();
        unset($snapshot['workflow_runs']);
        $report = ExactHeadMergegate::evaluate($this->policy(), $snapshot, 12, self::SHA);
        self::assertSame('fail', $report['status']);
        self::assertContains('workflow_missing_or_stale', array_column($report['gates'], 'code'));
        self::assertContains('review_attestation_invalid', array_column($report['gates'], 'code'));
    }

    public function testMissingOrChangedFinalPullRequestObservationFailsClosed(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['comments'] = [$this->attestationComment()];
        unset($snapshot['pr_head_revalidated']);
        $report = ExactHeadMergegate::evaluate($this->policy(), $snapshot, 12, self::SHA);
        self::assertSame('fail', $report['status']);
        self::assertContains('pr_head_drift_during_evaluation', array_column($report['gates'], 'code'));

        $snapshot['pr_head_revalidated'] = false;
        self::assertSame('fail', ExactHeadMergegate::evaluate($this->policy(), $snapshot, 12, self::SHA)['status']);
    }

    public function testMissingOrChangedReviewEvidenceObservationFailsClosed(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['comments'] = [$this->attestationComment()];
        unset($snapshot['review_evidence_revalidated']);
        $report = ExactHeadMergegate::evaluate($this->policy(), $snapshot, 12, self::SHA);

        self::assertSame('fail', $report['status']);
        self::assertContains('review_evidence_drift_during_evaluation', array_column($report['gates'], 'code'));

        $snapshot['review_evidence_revalidated'] = false;
        self::assertSame('fail', ExactHeadMergegate::evaluate($this->policy(), $snapshot, 12, self::SHA)['status']);
    }

    public function testWorkflowMustBindExactPullRequestShaAndCheckSuite(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['workflow_runs'][0]['pr_numbers'] = [99];
        $snapshot['check_runs'][0]['check_suite_id'] = 999;
        $report = ExactHeadMergegate::evaluate($this->policy(), $snapshot, 12, self::SHA);

        self::assertSame('fail', $report['status']);
        self::assertContains('workflow_missing_or_stale', array_column($report['gates'], 'code'));
        self::assertContains('required_check_missing_or_duplicate', array_column($report['gates'], 'code'));
    }

    public function testEachPullRequestInvariantFailsIndependently(): void
    {
        $cases = [
            'number' => [13, 'pr_number'],
            'state' => ['closed', 'pr_state'],
            'draft' => [true, 'pr_draft'],
            'base_ref' => ['release', 'pr_base'],
            'head_sha' => [str_repeat('a', 40), 'pr_head'],
            'mergeable' => [false, 'pr_mergeable'],
            'mergeable_state' => ['blocked', 'pr_mergeable_state'],
        ];

        foreach ($cases as $field => [$invalidValue, $expectedCode]) {
            $snapshot = $this->snapshot();
            $snapshot['comments'] = [$this->attestationComment()];
            $snapshot['pr'][$field] = $invalidValue;
            $report = ExactHeadMergegate::evaluate($this->policy(), $snapshot, 12, self::SHA);

            self::assertSame('fail', $report['status'], $field);
            self::assertContains($expectedCode, array_column($report['gates'], 'code'), $field);
        }
    }

    public function testConditionalSkippedIsAllowedButRequiredSkippedIsNot(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['check_runs'][0]['conclusion'] = 'skipped';
        $snapshot['check_runs'][1]['conclusion'] = 'skipped';
        $report = ExactHeadMergegate::evaluate($this->policy(), $snapshot, 12, self::SHA);
        self::assertSame('fail', $report['status']);
        self::assertContains('required_check_invalid', array_column($report['gates'], 'code'));
        self::assertContains('conditional_check_skipped', array_column($report['gates'], 'code'));
    }

    public function testDuplicateCheckRunFailsClosed(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['check_runs'][] = $snapshot['check_runs'][0];
        $snapshot['comments'] = [$this->attestationComment()];
        $report = ExactHeadMergegate::evaluate($this->policy(), $snapshot, 12, self::SHA);

        self::assertSame('fail', $report['status']);
        self::assertContains('required_check_missing_or_duplicate', array_column($report['gates'], 'code'));
    }

    public function testLatestMatchingWorkflowRunMustBeSuccessful(): void
    {
        foreach (
            [['status' => 'in_progress', 'conclusion' => null], ['status' => 'completed', 'conclusion' => 'failure']]
            as $latestState
        ) {
            $snapshot = $this->snapshot();
            $latest = $snapshot['workflow_runs'][0];
            $latest['id'] = 102;
            $latest['status'] = $latestState['status'];
            $latest['conclusion'] = $latestState['conclusion'];
            $snapshot['workflow_runs'][] = $latest;
            $snapshot['comments'] = [$this->attestationComment()];
            $report = ExactHeadMergegate::evaluate($this->policy(), $snapshot, 12, self::SHA);

            self::assertSame('fail', $report['status']);
            self::assertContains('workflow_missing_or_stale', array_column($report['gates'], 'code'));
        }
    }

    public function testAttestationIsRejectedWhenStaleMalformedUntrustedOrDuplicated(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['comments'] = [$this->attestationComment('NONE')];
        self::assertSame('fail', ExactHeadMergegate::evaluate($this->policy(), $snapshot, 12, self::SHA)['status']);
        $snapshot['comments'] = [
            [
                ...$this->attestationComment(),
                'body' => str_replace(self::SHA, str_repeat('f', 40), $this->attestation()),
            ],
        ];
        self::assertSame('fail', ExactHeadMergegate::evaluate($this->policy(), $snapshot, 12, self::SHA)['status']);

        $duplicateReviewer = str_replace(str_repeat('b', 64), str_repeat('a', 64), $this->attestation());
        $snapshot['comments'] = [[...$this->attestationComment(), 'body' => $duplicateReviewer]];
        self::assertSame('fail', ExactHeadMergegate::evaluate($this->policy(), $snapshot, 12, self::SHA)['status']);

        $duplicateLens = str_replace('"lens":"design"', '"lens":"correctness"', $this->attestation());
        $snapshot['comments'] = [[...$this->attestationComment(), 'body' => $duplicateLens]];
        self::assertSame('fail', ExactHeadMergegate::evaluate($this->policy(), $snapshot, 12, self::SHA)['status']);

        $snapshot['comments'] = [[...$this->attestationComment(), 'updated_at' => '2026-08-30T20:00:01Z']];
        self::assertSame('fail', ExactHeadMergegate::evaluate($this->policy(), $snapshot, 12, self::SHA)['status']);
    }

    public function testLatestOwnerMarkerCommentMustItselfBeAValidAttestation(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['comments'] = [
            $this->attestationComment(),
            [
                'id' => 501,
                'author_association' => 'OWNER',
                'created_at' => '2026-08-30T20:00:01Z',
                'updated_at' => '2026-08-30T20:00:01Z',
                'body' => "<!-- exact-head-review-attestation:v1\n{invalid-json}\n-->",
            ],
        ];

        $report = ExactHeadMergegate::evaluate($this->policy(), $snapshot, 12, self::SHA);

        self::assertSame('fail', $report['status']);
        self::assertContains('review_attestation_invalid', array_column($report['gates'], 'code'));
    }

    public function testNewerTrustedFeedbackAndOutstandingChangesRequestedInvalidateAttestation(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['comments'] = [
            $this->attestationComment(),
            [
                'id' => 501,
                'author_association' => 'COLLABORATOR',
                'updated_at' => '2026-08-30T20:00:01Z',
                'body' => 'A later finding.',
            ],
        ];
        $report = ExactHeadMergegate::evaluate($this->policy(), $snapshot, 12, self::SHA);
        self::assertSame('fail', $report['status']);
        self::assertContains('review_feedback_not_closed', array_column($report['gates'], 'code'));

        $snapshot['comments'] = [$this->attestationComment()];
        $snapshot['review_activity'] = [
            [
                'kind' => 'review_comment',
                'id' => 650,
                'author_association' => 'NONE',
                'actor_ref' => str_repeat('e', 64),
                'state' => null,
                'commit_sha' => self::SHA,
                'occurred_at' => '2026-08-30T20:00:01Z',
            ],
        ];
        $report = ExactHeadMergegate::evaluate($this->policy(), $snapshot, 12, self::SHA);
        self::assertSame('fail', $report['status']);
        self::assertContains('review_feedback_not_closed', array_column($report['gates'], 'code'));
    }

    public function testMatchingWatermarkStillBlocksOutstandingChangesRequested(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['comments'] = [$this->attestationComment('OWNER', 700, 0)];
        $snapshot['review_activity'] = [
            [
                'kind' => 'review',
                'id' => 700,
                'author_association' => 'MEMBER',
                'actor_ref' => str_repeat('d', 64),
                'state' => 'CHANGES_REQUESTED',
                'commit_sha' => self::SHA,
                'occurred_at' => '2026-08-30T19:59:00Z',
            ],
        ];
        $report = ExactHeadMergegate::evaluate($this->policy(), $snapshot, 12, self::SHA);
        self::assertSame('fail', $report['status']);
        self::assertContains('review_feedback_not_closed', array_column($report['gates'], 'code'));
    }

    public function testCommentOnlyReviewDoesNotClearOutstandingChangesRequested(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['comments'] = [$this->attestationComment('OWNER', 701, 0)];
        $snapshot['review_activity'] = [
            [
                'kind' => 'review',
                'id' => 700,
                'author_association' => 'MEMBER',
                'actor_ref' => str_repeat('d', 64),
                'state' => 'CHANGES_REQUESTED',
                'commit_sha' => self::SHA,
                'occurred_at' => '2026-08-30T19:59:00Z',
            ],
            [
                'kind' => 'review',
                'id' => 701,
                'author_association' => 'MEMBER',
                'actor_ref' => str_repeat('d', 64),
                'state' => 'COMMENTED',
                'commit_sha' => self::SHA,
                'occurred_at' => '2026-08-30T19:59:30Z',
            ],
        ];

        $report = ExactHeadMergegate::evaluate($this->policy(), $snapshot, 12, self::SHA);

        self::assertSame('fail', $report['status']);
        self::assertContains('review_feedback_not_closed', array_column($report['gates'], 'code'));
    }

    public function testLaterNonBlockingReviewPassesWithFreshMatchingWatermark(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['review_activity'] = [
            [
                'kind' => 'review',
                'id' => 700,
                'author_association' => 'MEMBER',
                'actor_ref' => str_repeat('d', 64),
                'state' => 'CHANGES_REQUESTED',
                'commit_sha' => self::SHA,
                'occurred_at' => '2026-08-30T19:59:00Z',
            ],
            [
                'kind' => 'review',
                'id' => 701,
                'author_association' => 'MEMBER',
                'actor_ref' => str_repeat('d', 64),
                'state' => 'APPROVED',
                'commit_sha' => self::SHA,
                'occurred_at' => '2026-08-30T19:59:30Z',
            ],
        ];
        $snapshot['comments'] = [$this->attestationComment('OWNER', 701, 0)];
        self::assertSame('pass', ExactHeadMergegate::evaluate($this->policy(), $snapshot, 12, self::SHA)['status']);
    }

    public function testReviewActivityWatermarksOrderSameSecondEvidenceWithoutTimestampGuessing(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['review_activity'] = [
            [
                'kind' => 'review_comment',
                'id' => 650,
                'author_association' => 'NONE',
                'actor_ref' => str_repeat('e', 64),
                'state' => null,
                'commit_sha' => self::SHA,
                'occurred_at' => '2026-08-30T19:59:59Z',
            ],
        ];
        $snapshot['comments'] = [$this->attestationComment('OWNER', 0, 650)];
        self::assertSame('pass', ExactHeadMergegate::evaluate($this->policy(), $snapshot, 12, self::SHA)['status']);

        $snapshot['review_activity'][] = [...$snapshot['review_activity'][0], 'id' => 651];
        $report = ExactHeadMergegate::evaluate($this->policy(), $snapshot, 12, self::SHA);
        self::assertSame('fail', $report['status']);
        self::assertContains('review_feedback_not_closed', array_column($report['gates'], 'code'));
    }

    public function testEditedInlineReviewCommentAtOrAfterAttestationIsBlocking(): void
    {
        foreach (['2026-08-30T20:00:00Z', '2026-08-30T20:00:01Z'] as $updatedAt) {
            $snapshot = $this->snapshot();
            $snapshot['review_activity'] = [
                [
                    'kind' => 'review_comment',
                    'id' => 650,
                    'author_association' => 'MEMBER',
                    'actor_ref' => str_repeat('e', 64),
                    'state' => null,
                    'commit_sha' => self::SHA,
                    'occurred_at' => $updatedAt,
                ],
            ];
            $snapshot['comments'] = [$this->attestationComment('OWNER', 0, 650)];

            $report = ExactHeadMergegate::evaluate($this->policy(), $snapshot, 12, self::SHA);

            self::assertSame('fail', $report['status']);
            self::assertContains('review_feedback_not_closed', array_column($report['gates'], 'code'));
        }
    }

    /** @return array<string,mixed> */
    private function policy(): array
    {
        return [
            'base_ref' => 'main',
            'workflow_name' => 'CI',
            'required_checks' => ['unit'],
            'conditional_checks' => ['integration'],
            'required_review_lenses' => ['correctness', 'design', 'tests'],
            'trusted_associations' => ['OWNER'],
            'blocking_feedback_associations' => ['OWNER', 'MEMBER', 'COLLABORATOR'],
            'attestation_marker' => 'exact-head-review-attestation:v1',
            'attestation_verdict' => 'no_findings',
        ];
    }

    /** @return array<string,mixed> */
    private function snapshot(): array
    {
        return [
            'pr_head_revalidated' => true,
            'review_evidence_revalidated' => true,
            'pr' => [
                'number' => 12,
                'state' => 'open',
                'draft' => false,
                'base_ref' => 'main',
                'head_sha' => self::SHA,
                'mergeable' => true,
                'mergeable_state' => 'clean',
                'head_ref' => 'feature',
                'head_repository' => 'acme/app',
            ],
            'workflow_runs' => [
                [
                    'id' => 101,
                    'name' => 'CI',
                    'event' => 'pull_request',
                    'status' => 'completed',
                    'conclusion' => 'success',
                    'head_sha' => self::SHA,
                    'head_branch' => 'feature',
                    'head_repository' => 'acme/app',
                    'pr_numbers' => [12],
                    'check_suite_id' => 202,
                ],
            ],
            'check_runs' => [
                [
                    'name' => 'unit',
                    'check_suite_id' => 202,
                    'head_sha' => self::SHA,
                    'status' => 'completed',
                    'conclusion' => 'success',
                ],
                [
                    'name' => 'integration',
                    'check_suite_id' => 202,
                    'head_sha' => self::SHA,
                    'status' => 'completed',
                    'conclusion' => 'success',
                ],
            ],
            'associated_pr_numbers' => [12],
            'comments' => [],
            'review_activity' => [],
        ];
    }

    private function attestation(int $reviewId = 0, int $reviewCommentId = 0): string
    {
        $reviews = [];
        foreach (['correctness' => 'a', 'design' => 'b', 'tests' => 'c'] as $lens => $prefix) {
            $reviews[] = [
                'lens' => $lens,
                'reviewer_ref' => str_repeat($prefix, 64),
                'verdict' => 'no_findings',
            ];
        }

        return "<!-- exact-head-review-attestation:v1\n" .
            json_encode(
                [
                    'head_sha' => self::SHA,
                    'review_activity_watermark' => [
                        'review_id' => $reviewId,
                        'review_comment_id' => $reviewCommentId,
                    ],
                    'reviews' => $reviews,
                ],
                JSON_UNESCAPED_SLASHES,
            ) .
            "\n-->";
    }

    /** @return array<string, mixed> */
    private function attestationComment(
        string $association = 'OWNER',
        int $reviewId = 0,
        int $reviewCommentId = 0,
    ): array {
        return [
            'id' => 500,
            'author_association' => $association,
            'created_at' => '2026-08-30T20:00:00Z',
            'updated_at' => '2026-08-30T20:00:00Z',
            'body' => $this->attestation($reviewId, $reviewCommentId),
        ];
    }
}
