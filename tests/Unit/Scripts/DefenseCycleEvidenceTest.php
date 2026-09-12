<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;
use ReleaseGate\DefenseCycleEvidence;

require_once __DIR__ . '/../../../scripts/release-gate/lib/DefenseCycleEvidence.php';

final class DefenseCycleEvidenceTest extends TestCase
{
    private const COMMIT = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function testCompleteProductionEvidenceVerifiesAllSixInvariants(): void
    {
        $report = new DefenseCycleEvidence(self::COMMIT);
        foreach (DefenseCycleEvidence::INVARIANTS as $invariant) {
            $report->append($this->observation($invariant));
        }

        self::assertTrue($report->isVerified());
        self::assertSame('complete', $report->toArray()['coverage']);
    }

    public function testPartialOrNonProductionEvidenceCannotVerify(): void
    {
        $report = new DefenseCycleEvidence(self::COMMIT);
        foreach (DefenseCycleEvidence::INVARIANTS as $invariant) {
            $observation = $this->observation($invariant);
            $observation['environment'] = 'isolated';
            $observation['coverage'] = 'partial';
            $report->append($observation);
        }

        self::assertFalse($report->isVerified());
        self::assertSame('partial', $report->toArray()['coverage']);
    }

    public function testHistoricalFailureBlocksLaterPositiveObservations(): void
    {
        $report = new DefenseCycleEvidence(self::COMMIT);
        $failed = $this->observation('ROB-550');
        $failed['status'] = 'failed';
        $failed['gap'] = 'invariant did not hold';
        $report->append($failed);
        foreach (array_diff(DefenseCycleEvidence::INVARIANTS, ['ROB-550']) as $invariant) {
            $report->append($this->observation($invariant));
        }
        $report->append($this->observation('ROB-550'));

        self::assertFalse($report->isVerified());
        self::assertCount(7, $report->toArray()['observations']);
    }

    public function testWrongCommitAndMissingCleanupRemainVisibleAndBlocked(): void
    {
        $report = new DefenseCycleEvidence(self::COMMIT);
        $wrong = $this->observation('ROB-538');
        $wrong['commit'] = str_repeat('b', 40);
        $report->append($wrong);
        $unclean = $this->observation('ROB-548');
        $unclean['cleanup'] = false;
        $report->append($unclean);

        $result = $report->toArray();
        self::assertFalse($report->isVerified());
        self::assertCount(2, $result['invalid_observations']);
        self::assertSame(str_repeat('b', 40), $result['observations'][0]['commit']);
    }

    public function testSupplementalEnvironmentEvidenceIsRetainedWithoutSatisfyingProductionCoverage(): void
    {
        $report = new DefenseCycleEvidence(self::COMMIT);
        foreach (DefenseCycleEvidence::INVARIANTS as $invariant) {
            $observation = $this->observation($invariant);
            $observation['environment'] = 'source';
            $report->append($observation);
        }

        self::assertFalse($report->isVerified());
        self::assertSame([], $report->toArray()['invalid_observations']);
        self::assertCount(6, $report->toArray()['observations']);
    }

    public function testVerifiedObservationWithGapOrEmptyResultCannotCompleteCoverage(): void
    {
        $report = new DefenseCycleEvidence(self::COMMIT);
        foreach (DefenseCycleEvidence::INVARIANTS as $invariant) {
            $observation = $this->observation($invariant);
            if ($invariant === 'ROB-538') {
                $observation['gap'] = 'unresolved discrepancy';
            }
            if ($invariant === 'ROB-548') {
                $observation['observed'] = '';
            }
            $report->append($observation);
        }

        self::assertFalse($report->isVerified());
        self::assertContains('ROB-538', $report->toArray()['missing_invariants']);
        self::assertContains('ROB-548', $report->toArray()['missing_invariants']);
    }

    public function testNonProductionTargetsNeverVerifyTheCycle(): void
    {
        foreach (['source', 'isolated'] as $environment) {
            $report = new DefenseCycleEvidence(self::COMMIT, $environment);
            foreach (DefenseCycleEvidence::INVARIANTS as $invariant) {
                $observation = $this->observation($invariant);
                $observation['environment'] = $environment;
                $report->append($observation);
            }
            self::assertFalse($report->isVerified());
            self::assertSame('not_safely_testable', $report->toArray()['overall_status']);
        }
    }

    public function testMissingCoverageFieldDefaultsToPartial(): void
    {
        $report = new DefenseCycleEvidence(self::COMMIT);
        $observation = $this->observation('ROB-538');
        unset($observation['coverage']);
        $report->append($observation);

        self::assertSame('partial', $report->toArray()['observations'][0]['coverage']);
        self::assertFalse($report->isVerified());
    }

    /** @return array<string,mixed> */
    private function observation(string $invariant): array
    {
        return [
            'invariant' => $invariant,
            'commit' => self::COMMIT,
            'environment' => 'production',
            'method' => 'fixed-head invariant check',
            'expected' => 'invariant holds',
            'observed' => 'invariant holds',
            'status' => 'verified',
            'coverage' => 'complete',
            'gap' => '',
            'cleanup' => true,
        ];
    }
}
