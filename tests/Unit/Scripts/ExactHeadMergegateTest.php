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

    public function testAttestationObjectKeyOrderIsIgnoredButUnknownKeysFailClosed(): void
    {
        $body = $this->attestation();
        $matches = [];
        self::assertSame(1, preg_match('/<!-- exact-head-review-attestation:v2\n(.*)\n-->/s', $body, $matches));
        $payload = json_decode($matches[1], true, 128, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        $reordered = array_reverse($payload, true);
        $reordered['reviews'] = array_map(
            static fn(array $review): array => array_reverse($review, true),
            $payload['reviews'],
        );
        $reordered['review_activity_watermark'] = array_reverse($payload['review_activity_watermark'], true);
        $snapshot = $this->snapshot();
        $snapshot['comments'] = [
            array_merge($this->attestationComment(), [
                'body' =>
                    "<!-- exact-head-review-attestation:v2\n" .
                    json_encode($reordered, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) .
                    "\n-->",
            ]),
        ];
        $reorderedReport = ExactHeadMergegate::evaluate($this->policy(), $snapshot, 12, self::SHA);
        self::assertSame('pass', $reorderedReport['status'], json_encode($reorderedReport, JSON_THROW_ON_ERROR));

        $reordered['unexpected'] = true;
        $snapshot['comments'][0]['body'] =
            "<!-- exact-head-review-attestation:v2\n" .
            json_encode($reordered, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) .
            "\n-->";
        $report = ExactHeadMergegate::evaluate($this->policy(), $snapshot, 12, self::SHA);
        self::assertSame('fail', $report['status']);
        self::assertContains('review_attestation_invalid', array_column($report['gates'], 'code'));
    }

    public function testCompletedWorkflowDoesNotMakeAdvisoryFailuresImplicitlyBlocking(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['comments'] = [$this->attestationComment()];
        $snapshot['workflow_runs'][0]['conclusion'] = 'failure';
        $snapshot['check_runs'][] = [
            'name' => 'heavy-job-duration-trends',
            'check_suite_id' => 202,
            'head_sha' => self::SHA,
            'status' => 'completed',
            'conclusion' => 'failure',
        ];

        $report = ExactHeadMergegate::evaluate($this->policy(), $snapshot, 12, self::SHA);

        self::assertSame('pass', $report['status']);
        self::assertContains('workflow', array_column($report['gates'], 'code'));
    }

    public function testUnverifiedCiExecutionContractFailsClosedIncludingSkippedConditionalChecks(): void
    {
        $policy = $this->policy();
        $policy['ci_execution_contract_verified'] = false;
        $snapshot = $this->snapshot();
        $snapshot['comments'] = [$this->attestationComment()];
        $snapshot['check_runs'][1]['conclusion'] = 'skipped';

        $report = ExactHeadMergegate::evaluate($policy, $snapshot, 12, self::SHA);

        self::assertSame('fail', $report['status']);
        self::assertContains('ci_execution_contract_unverified', array_column($report['gates'], 'code'));
        self::assertContains('conditional_check_unverified', array_column($report['gates'], 'code'));
        self::assertNotContains('conditional_check_skipped', array_column($report['gates'], 'code'));
    }

    public function testReportProvidesOnlyPrivacySafeReviewActivityWatermarks(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['review_activity'] = [
            [
                'kind' => 'review',
                'id' => 700,
                'author_association' => 'MEMBER',
                'actor_ref' => str_repeat('d', 64),
                'state' => 'APPROVED',
                'commit_sha' => self::SHA,
                'occurred_at' => '2026-08-30T19:59:30Z',
                'content_digest' => hash('sha256', 'approved'),
                'edit_count' => 0,
            ],
        ];
        $digest = $this->reviewPayloadDigest($snapshot['review_activity']);
        $snapshot['comments'] = [$this->attestationComment('OWNER', 700, 0, $digest)];

        $report = ExactHeadMergegate::evaluate($this->policy(), $snapshot, 12, self::SHA);

        self::assertSame(
            ['review_id' => 700, 'review_comment_id' => 0, 'review_payload_digest' => $digest],
            $report['review_activity_watermark'],
        );
        self::assertArrayNotHasKey('actor_ref', $report['review_activity_watermark']);
        self::assertArrayNotHasKey('content_digest', $report['review_activity_watermark']);
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

    public function testMissingOrChangedCiEvidenceObservationFailsClosed(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['comments'] = [$this->attestationComment()];
        unset($snapshot['ci_evidence_revalidated']);
        $report = ExactHeadMergegate::evaluate($this->policy(), $snapshot, 12, self::SHA);

        self::assertSame('fail', $report['status']);
        self::assertContains('ci_evidence_drift_during_evaluation', array_column($report['gates'], 'code'));

        $snapshot['ci_evidence_revalidated'] = false;
        self::assertSame('fail', ExactHeadMergegate::evaluate($this->policy(), $snapshot, 12, self::SHA)['status']);
    }

    public function testCiEvidenceObservationCountMustBeExactlyTwo(): void
    {
        foreach ([null, 1, 3] as $observationCount) {
            $snapshot = $this->snapshot();
            $snapshot['comments'] = [$this->attestationComment()];
            if ($observationCount === null) {
                unset($snapshot['ci_evidence_observation_count']);
            } else {
                $snapshot['ci_evidence_observation_count'] = $observationCount;
            }

            $report = ExactHeadMergegate::evaluate($this->policy(), $snapshot, 12, self::SHA);

            self::assertSame('fail', $report['status'], (string) ($observationCount ?? 'missing'));
            self::assertContains(
                'ci_evidence_drift_during_evaluation',
                array_column($report['gates'], 'code'),
                (string) ($observationCount ?? 'missing'),
            );
        }
    }

    public function testCommitPullRequestBindingIsRequiredIndependently(): void
    {
        foreach ([null, [99]] as $associatedPullRequests) {
            $snapshot = $this->snapshot();
            $snapshot['comments'] = [$this->attestationComment()];
            if ($associatedPullRequests === null) {
                unset($snapshot['associated_pr_numbers']);
            } else {
                $snapshot['associated_pr_numbers'] = $associatedPullRequests;
            }

            $report = ExactHeadMergegate::evaluate($this->policy(), $snapshot, 12, self::SHA);

            self::assertSame('fail', $report['status']);
            self::assertContains('pr_commit_binding_missing', array_column($report['gates'], 'code'));
            self::assertContains('workflow', array_column($report['gates'], 'code'));
        }
    }

    public function testSelectedWorkflowRequiresAValidCheckSuiteIndependently(): void
    {
        foreach ([null, 0, '202'] as $checkSuiteId) {
            $snapshot = $this->snapshot();
            $snapshot['comments'] = [$this->attestationComment()];
            if ($checkSuiteId === null) {
                unset($snapshot['workflow_runs'][0]['check_suite_id']);
            } else {
                $snapshot['workflow_runs'][0]['check_suite_id'] = $checkSuiteId;
            }

            $report = ExactHeadMergegate::evaluate($this->policy(), $snapshot, 12, self::SHA);

            self::assertSame('fail', $report['status']);
            self::assertContains('check_suite_missing', array_column($report['gates'], 'code'));
            self::assertContains('workflow', array_column($report['gates'], 'code'));
        }
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

    public function testCiEvidenceDriftFailsClosedWithOwnGate(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['ci_evidence_revalidated'] = false;
        $report = ExactHeadMergegate::evaluate($this->policy(), $snapshot, 12, self::SHA);

        self::assertSame('fail', $report['status']);
        self::assertContains('ci_evidence_drift_during_evaluation', array_column($report['gates'], 'code'));
    }

    public function testLatestMatchingWorkflowRunMustBeCompleted(): void
    {
        $snapshot = $this->snapshot();
        $latest = $snapshot['workflow_runs'][0];
        $latest['id'] = 102;
        $latest['status'] = 'in_progress';
        $latest['conclusion'] = null;
        $snapshot['workflow_runs'][] = $latest;
        $snapshot['comments'] = [$this->attestationComment()];
        $report = ExactHeadMergegate::evaluate($this->policy(), $snapshot, 12, self::SHA);

        self::assertSame('fail', $report['status']);
        self::assertContains('workflow_missing_or_stale', array_column($report['gates'], 'code'));
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
                'body' => "<!-- exact-head-review-attestation:v2\n{invalid-json}\n-->",
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
                'created_at' => '2026-08-30T20:00:01Z',
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
                'author_association' => 'MEMBER',
                'actor_ref' => str_repeat('e', 64),
                'state' => null,
                'commit_sha' => self::SHA,
                'occurred_at' => '2026-08-30T20:00:01Z',
                'content_digest' => hash('sha256', 'inline'),
                'edit_count' => 0,
            ],
        ];
        $report = ExactHeadMergegate::evaluate($this->policy(), $snapshot, 12, self::SHA);
        self::assertSame('fail', $report['status']);
        self::assertContains('review_feedback_not_closed', array_column($report['gates'], 'code'));
    }

    public function testUntrustedReviewActivityCannotVetoAttestation(): void
    {
        $snapshot = $this->snapshot();
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
                'content_digest' => hash('sha256', 'inline'),
                'edit_count' => 0,
            ],
            [
                'kind' => 'review',
                'id' => 700,
                'author_association' => 'NONE',
                'actor_ref' => str_repeat('f', 64),
                'state' => 'CHANGES_REQUESTED',
                'commit_sha' => self::SHA,
                'occurred_at' => '2026-08-30T20:00:01Z',
                'content_digest' => hash('sha256', 'drive-by review'),
                'edit_count' => 0,
            ],
        ];

        $report = ExactHeadMergegate::evaluate($this->policy(), $snapshot, 12, self::SHA);

        self::assertSame('pass', $report['status']);
        self::assertSame(
            [
                'review_id' => 0,
                'review_comment_id' => 0,
                'review_payload_digest' => hash('sha256', json_encode([], JSON_THROW_ON_ERROR)),
            ],
            $report['review_activity_watermark'],
        );
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
                'content_digest' => hash('sha256', 'changes requested'),
                'edit_count' => 0,
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
                'content_digest' => hash('sha256', 'changes requested'),
                'edit_count' => 0,
            ],
            [
                'kind' => 'review',
                'id' => 701,
                'author_association' => 'MEMBER',
                'actor_ref' => str_repeat('d', 64),
                'state' => 'COMMENTED',
                'commit_sha' => self::SHA,
                'occurred_at' => '2026-08-30T19:59:30Z',
                'content_digest' => hash('sha256', 'commented followup'),
                'edit_count' => 0,
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
                'content_digest' => hash('sha256', 'changes requested'),
                'edit_count' => 0,
            ],
            [
                'kind' => 'review',
                'id' => 701,
                'author_association' => 'MEMBER',
                'actor_ref' => str_repeat('d', 64),
                'state' => 'APPROVED',
                'commit_sha' => self::SHA,
                'occurred_at' => '2026-08-30T19:59:30Z',
                'content_digest' => hash('sha256', 'approved followup'),
                'edit_count' => 0,
            ],
        ];
        $snapshot['comments'] = [
            $this->attestationComment('OWNER', 701, 0, $this->reviewPayloadDigest($snapshot['review_activity'])),
        ];
        self::assertSame('pass', ExactHeadMergegate::evaluate($this->policy(), $snapshot, 12, self::SHA)['status']);
    }

    public function testDismissedReviewClearsOutstandingChangesRequested(): void
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
                'content_digest' => hash('sha256', 'changes requested'),
                'edit_count' => 0,
            ],
            [
                'kind' => 'review',
                'id' => 701,
                'author_association' => 'MEMBER',
                'actor_ref' => str_repeat('d', 64),
                'state' => 'DISMISSED',
                'commit_sha' => self::SHA,
                'occurred_at' => '2026-08-30T19:59:30Z',
                'content_digest' => hash('sha256', 'dismissed followup'),
                'edit_count' => 0,
            ],
        ];
        $snapshot['comments'] = [
            $this->attestationComment('OWNER', 701, 0, $this->reviewPayloadDigest($snapshot['review_activity'])),
        ];

        self::assertSame('pass', ExactHeadMergegate::evaluate($this->policy(), $snapshot, 12, self::SHA)['status']);
    }

    public function testReviewActivityWatermarksOrderSameSecondEvidenceWithoutTimestampGuessing(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['review_activity'] = [
            [
                'kind' => 'review_comment',
                'id' => 650,
                'author_association' => 'MEMBER',
                'actor_ref' => str_repeat('e', 64),
                'state' => null,
                'commit_sha' => self::SHA,
                'occurred_at' => '2026-08-30T19:59:59Z',
                'content_digest' => hash('sha256', 'inline'),
                'edit_count' => 0,
            ],
        ];
        $snapshot['comments'] = [
            $this->attestationComment('OWNER', 0, 650, $this->reviewPayloadDigest($snapshot['review_activity'])),
        ];
        self::assertSame('pass', ExactHeadMergegate::evaluate($this->policy(), $snapshot, 12, self::SHA)['status']);

        $snapshot['review_activity'][] = [...$snapshot['review_activity'][0], 'id' => 651];
        $report = ExactHeadMergegate::evaluate($this->policy(), $snapshot, 12, self::SHA);
        self::assertSame('fail', $report['status']);
        self::assertContains('review_feedback_not_closed', array_column($report['gates'], 'code'));
    }

    public function testDeletedLowerInlineReviewCommentChangesReviewPayloadDigest(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['review_activity'] = [
            [
                'kind' => 'review_comment',
                'id' => 649,
                'author_association' => 'MEMBER',
                'actor_ref' => str_repeat('e', 64),
                'state' => null,
                'commit_sha' => self::SHA,
                'occurred_at' => '2026-08-30T19:59:58Z',
                'content_digest' => hash('sha256', 'inline'),
                'edit_count' => 0,
            ],
            [
                'kind' => 'review_comment',
                'id' => 650,
                'author_association' => 'MEMBER',
                'actor_ref' => str_repeat('f', 64),
                'state' => null,
                'commit_sha' => self::SHA,
                'occurred_at' => '2026-08-30T19:59:59Z',
                'content_digest' => hash('sha256', 'inline'),
                'edit_count' => 0,
            ],
        ];
        $snapshot['comments'] = [
            $this->attestationComment('OWNER', 0, 650, $this->reviewPayloadDigest($snapshot['review_activity'])),
        ];

        self::assertSame('pass', ExactHeadMergegate::evaluate($this->policy(), $snapshot, 12, self::SHA)['status']);

        array_shift($snapshot['review_activity']);
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
                    'content_digest' => hash('sha256', 'inline'),
                    'edit_count' => 0,
                ],
            ];
            $snapshot['comments'] = [$this->attestationComment('OWNER', 0, 650)];

            $report = ExactHeadMergegate::evaluate($this->policy(), $snapshot, 12, self::SHA);

            self::assertSame('fail', $report['status']);
            self::assertContains('review_feedback_not_closed', array_column($report['gates'], 'code'));
        }
    }

    public function testSameSecondEditedAttestationIsRejected(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['comments'] = [array_merge($this->attestationComment(), ['edit_count' => 1])];

        $report = ExactHeadMergegate::evaluate($this->policy(), $snapshot, 12, self::SHA);

        self::assertSame('fail', $report['status']);
        self::assertContains('review_attestation_invalid', array_column($report['gates'], 'code'));
    }

    public function testInlineBodyOrEditCountChangesInvalidateMatchingAttestation(): void
    {
        $activity = [
            'kind' => 'review_comment',
            'id' => 650,
            'author_association' => 'MEMBER',
            'actor_ref' => str_repeat('e', 64),
            'state' => null,
            'commit_sha' => self::SHA,
            'occurred_at' => '2026-08-30T19:59:59Z',
            'content_digest' => hash('sha256', 'original'),
            'edit_count' => 0,
        ];
        foreach (
            [
                'body-only edit at unchanged timestamp' => ['content_digest' => hash('sha256', 'edited body')],
                'edit and restore at unchanged timestamp' => ['edit_count' => 2],
            ]
            as $case => $change
        ) {
            $snapshot = $this->snapshot();
            $snapshot['review_activity'] = [$activity];
            $snapshot['comments'] = [
                $this->attestationComment('OWNER', 0, 650, $this->reviewPayloadDigest([$activity])),
            ];
            self::assertSame(
                'pass',
                ExactHeadMergegate::evaluate($this->policy(), $snapshot, 12, self::SHA)['status'],
                $case,
            );

            $snapshot['review_activity'][0] = array_merge($activity, $change);
            $report = ExactHeadMergegate::evaluate($this->policy(), $snapshot, 12, self::SHA);
            self::assertSame('fail', $report['status'], $case);
            self::assertContains('review_feedback_not_closed', array_column($report['gates'], 'code'), $case);
        }
    }

    public function testSameSecondEditToOlderTrustedIssueCommentIsBlocking(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['comments'] = [
            [
                'id' => 499,
                'author_association' => 'OWNER',
                'created_at' => '2026-08-30T19:59:59Z',
                'updated_at' => '2026-08-30T20:00:00Z',
                'body' => 'Edited after attestation second began.',
            ],
            $this->attestationComment(),
        ];

        $report = ExactHeadMergegate::evaluate($this->policy(), $snapshot, 12, self::SHA);

        self::assertSame('fail', $report['status']);
        self::assertContains('review_feedback_not_closed', array_column($report['gates'], 'code'));
    }

    public function testTrustedIssueCommentUpdatedInAttestationSecondFailsClosed(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['comments'] = [
            [
                'id' => 499,
                'author_association' => 'OWNER',
                'created_at' => '2026-08-30T20:00:00Z',
                'updated_at' => '2026-08-30T20:00:00Z',
                'body' => 'Ambiguous same-second owner feedback.',
            ],
            $this->attestationComment(),
        ];

        $report = ExactHeadMergegate::evaluate($this->policy(), $snapshot, 12, self::SHA);

        self::assertSame('fail', $report['status']);
        self::assertContains('review_feedback_not_closed', array_column($report['gates'], 'code'));
    }

    public function testChangedFormalReviewDigestAfterAttestationIsBlocking(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['review_activity'] = [
            [
                'kind' => 'review',
                'id' => 700,
                'author_association' => 'MEMBER',
                'actor_ref' => str_repeat('d', 64),
                'state' => 'COMMENTED',
                'commit_sha' => self::SHA,
                'occurred_at' => '2026-08-30T19:59:00Z',
                'content_digest' => hash('sha256', 'mutated body'),
                'edit_count' => 0,
            ],
        ];
        $snapshot['comments'] = [
            $this->attestationComment(
                'OWNER',
                700,
                0,
                $this->reviewPayloadDigest([
                    [
                        'id' => 700,
                        'actor_ref' => str_repeat('d', 64),
                        'state' => 'COMMENTED',
                        'commit_sha' => self::SHA,
                        'occurred_at' => '2026-08-30T19:59:00Z',
                        'content_digest' => hash('sha256', 'original body'),
                    ],
                ]),
            ),
        ];

        $report = ExactHeadMergegate::evaluate($this->policy(), $snapshot, 12, self::SHA);

        self::assertSame('fail', $report['status']);
        self::assertContains('review_feedback_not_closed', array_column($report['gates'], 'code'));
    }

    public function testFormalReviewEditCountIsBoundToTheReviewPayloadDigest(): void
    {
        $activity = [
            'kind' => 'review',
            'id' => 700,
            'author_association' => 'MEMBER',
            'actor_ref' => str_repeat('d', 64),
            'state' => 'APPROVED',
            'commit_sha' => self::SHA,
            'occurred_at' => '2026-08-30T19:59:30Z',
            'content_digest' => hash('sha256', 'approved'),
            'edit_count' => 0,
        ];
        $snapshot = $this->snapshot();
        $snapshot['review_activity'] = [$activity];
        $snapshot['comments'] = [$this->attestationComment('OWNER', 700, 0, $this->reviewPayloadDigest([$activity]))];
        self::assertSame('pass', ExactHeadMergegate::evaluate($this->policy(), $snapshot, 12, self::SHA)['status']);

        $snapshot['review_activity'][0]['edit_count'] = 2;
        $report = ExactHeadMergegate::evaluate($this->policy(), $snapshot, 12, self::SHA);
        self::assertSame('fail', $report['status']);
        self::assertContains('review_feedback_not_closed', array_column($report['gates'], 'code'));
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
            'attestation_marker' => 'exact-head-review-attestation:v2',
            'attestation_verdict' => 'no_findings',
            'ci_execution_contract_verified' => true,
        ];
    }

    /** @return array<string,mixed> */
    private function snapshot(): array
    {
        return [
            'pr_head_revalidated' => true,
            'ci_evidence_revalidated' => true,
            'ci_evidence_observation_count' => 2,
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

    private function attestation(
        int $reviewId = 0,
        int $reviewCommentId = 0,
        ?string $reviewPayloadDigest = null,
    ): string {
        $reviews = [];
        foreach (['correctness' => 'a', 'design' => 'b', 'tests' => 'c'] as $lens => $prefix) {
            $reviews[] = [
                'lens' => $lens,
                'reviewer_ref' => str_repeat($prefix, 64),
                'verdict' => 'no_findings',
            ];
        }

        $reviewPayloadDigest ??= $this->reviewPayloadDigest([]);

        return "<!-- exact-head-review-attestation:v2\n" .
            json_encode(
                [
                    'head_sha' => self::SHA,
                    'review_activity_watermark' => [
                        'review_id' => $reviewId,
                        'review_comment_id' => $reviewCommentId,
                        'review_payload_digest' => $reviewPayloadDigest,
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
        ?string $reviewPayloadDigest = null,
    ): array {
        return [
            'id' => 500,
            'author_association' => $association,
            'created_at' => '2026-08-30T20:00:00Z',
            'updated_at' => '2026-08-30T20:00:00Z',
            'body' => $this->attestation($reviewId, $reviewCommentId, $reviewPayloadDigest),
            'edit_count' => 0,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     */
    private function reviewPayloadDigest(array $entries): string
    {
        $payloadEntries = [];
        foreach ($entries as $entry) {
            if (!in_array($entry['author_association'] ?? null, ['OWNER', 'MEMBER', 'COLLABORATOR'], true)) {
                continue;
            }
            if (($entry['kind'] ?? null) === 'review') {
                if (($entry['commit_sha'] ?? null) !== self::SHA) {
                    continue;
                }

                $payloadEntries[] = [
                    'kind' => 'review',
                    'id' => (int) ($entry['id'] ?? 0),
                    'actor_ref' => $entry['actor_ref'] ?? null,
                    'state' => $entry['state'] ?? null,
                    'commit_sha' => $entry['commit_sha'] ?? null,
                    'occurred_at' => $entry['occurred_at'] ?? null,
                    'content_digest' => $entry['content_digest'] ?? null,
                    'edit_count' => $entry['edit_count'] ?? 0,
                ];
                continue;
            }

            if (($entry['kind'] ?? null) !== 'review_comment') {
                continue;
            }

            $payloadEntries[] = [
                'kind' => 'review_comment',
                'id' => (int) ($entry['id'] ?? 0),
                'actor_ref' => $entry['actor_ref'] ?? null,
                'commit_sha' => $entry['commit_sha'] ?? null,
                'occurred_at' => $entry['occurred_at'] ?? null,
                'content_digest' => $entry['content_digest'] ?? hash('sha256', ''),
                'edit_count' => $entry['edit_count'] ?? 0,
            ];
        }
        usort(
            $payloadEntries,
            static fn(array $left, array $right): int => [$left['id'], $left['occurred_at']] <=> [
                $right['id'],
                $right['occurred_at'],
            ],
        );

        return hash('sha256', json_encode($payloadEntries, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
