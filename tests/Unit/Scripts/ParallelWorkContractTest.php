<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use Forscherhaus\AgentHarness\ParallelWorkContract;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../scripts/agent/lib/ParallelWorkContract.php';

class ParallelWorkContractTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $policy;

    /** @var array<string, mixed> */
    private array $ownershipMap;

    protected function setUp(): void
    {
        parent::setUp();
        $contract = json_decode(
            (string) file_get_contents(dirname(__DIR__, 3) . '/.codex/contracts/agent-workflow.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($contract);
        self::assertIsArray($contract['parallel_work'] ?? null);
        $this->policy = $contract['parallel_work'];
        $this->ownershipMap = [
            'prefix_match_overrides' => [
                'application/views/components/booking_' => 'filename_stem',
            ],
            'components' => [
                [
                    'component_id' => 'platform-quality-tooling',
                    'ownership_mode' => 'single-owner',
                    'manual_approval_required' => true,
                    'folder_prefixes' => ['scripts/ci/'],
                ],
                [
                    'component_id' => 'booking-public',
                    'ownership_mode' => 'single-owner',
                    'manual_approval_required' => true,
                    'folder_prefixes' => ['application/views/components/booking_'],
                ],
            ],
        ];
    }

    public function testAcceptsTwoDisjointWriterLanesOnOneBase(): void
    {
        self::assertSame(
            [],
            ParallelWorkContract::validate($this->validManifest(), $this->policy, $this->ownershipMap),
        );
    }

    public function testRejectsDisabledOrUnknownMachinePolicySemantics(): void
    {
        foreach (
            [
                'local_implementation_only',
                'requires_common_base_sha',
                'requires_disjoint_ownership',
                'external_mutations_remain_serial',
                'requires_semantic_independence_attestation',
            ]
            as $requirement
        ) {
            $policy = $this->policy;
            $policy[$requirement] = false;
            self::assertContains(
                'invalid_policy_requirement:' . $requirement,
                ParallelWorkContract::validate($this->validManifest(), $policy, $this->ownershipMap),
                $requirement,
            );
        }
    }

    public function testRejectsMoreThanTwoWriterLanes(): void
    {
        $manifest = $this->validManifest();
        $manifest['lanes'][] = [
            'id' => 'lane-c',
            'role' => 'implementation_worker',
            'base_sha' => str_repeat('a', 40),
            'ownership' => [$this->pathRule('tests/Fixtures/third')],
            'external_mutations' => [],
        ];

        self::assertContains(
            'too_many_writer_lanes',
            ParallelWorkContract::validate($manifest, $this->policy, $this->ownershipMap),
        );
    }

    public function testRejectsDifferentBaseSha(): void
    {
        $manifest = $this->validManifest();
        $manifest['lanes'][1]['base_sha'] = str_repeat('b', 40);

        self::assertContains(
            'base_sha_mismatch:1',
            ParallelWorkContract::validate($manifest, $this->policy, $this->ownershipMap),
        );
    }

    public function testRejectsOverlappingOwnership(): void
    {
        $manifest = $this->validManifest();
        $manifest['lanes'][1]['ownership'] = [$this->pathRule('scripts/github/fixtures')];

        self::assertContains(
            'ownership_overlap:0:1',
            ParallelWorkContract::validate($manifest, $this->policy, $this->ownershipMap),
        );
    }

    public function testRejectsStemPrefixOverlappingConcreteFile(): void
    {
        $manifest = $this->validManifest();
        $manifest['primary_approved_component_ids'] = ['booking-public', 'platform-quality-tooling'];
        $manifest['lanes'][0]['ownership'] = [
            $this->pathRule('application/views/components/booking_', 'filename_stem'),
        ];
        $manifest['lanes'][1]['ownership'] = [$this->pathRule('application/views/components/booking_sidebar.php')];

        self::assertContains(
            'ownership_overlap:0:1',
            ParallelWorkContract::validate($manifest, $this->policy, $this->ownershipMap),
        );
    }

    public function testTrailingUnderscoreDoesNotImplicitlyCreateStemOwnership(): void
    {
        $manifest = $this->validManifest();
        $manifest['primary_approved_component_ids'] = ['booking-public', 'platform-quality-tooling'];
        $manifest['lanes'][0]['ownership'] = [$this->pathRule('application/views/components/booking_')];
        $manifest['lanes'][1]['ownership'] = [$this->pathRule('application/views/components/booking_sidebar.php')];

        self::assertNotContains(
            'ownership_overlap:0:1',
            ParallelWorkContract::validate($manifest, $this->policy, $this->ownershipMap),
        );
    }

    public function testRejectsSemanticCrossLaneCoordination(): void
    {
        foreach (
            [
                ['shared_contracts', ['openapi.yml'], 'shared_contract_requires_serial_work'],
                ['cross_lane_dependencies', ['lane-a->lane-b'], 'cross_lane_dependency_requires_serial_work'],
                ['coordination_required', true, 'semantic_coordination_requires_serial_work'],
            ]
            as [$field, $value, $expectedError]
        ) {
            $manifest = $this->validManifest();
            $manifest['semantic_independence'][$field] = $value;

            self::assertContains(
                $expectedError,
                ParallelWorkContract::validate($manifest, $this->policy, $this->ownershipMap),
                (string) $field,
            );
        }
    }

    public function testRejectsExternalMutationForWorker(): void
    {
        $manifest = $this->validManifest();
        $manifest['lanes'][0]['external_mutations'] = ['push'];

        self::assertContains(
            'external_mutation_not_primary:0',
            ParallelWorkContract::validate($manifest, $this->policy, $this->ownershipMap),
        );
    }

    public function testRejectsPrimaryOwnedHarnessPath(): void
    {
        $manifest = $this->validManifest();
        $manifest['lanes'][0]['ownership'] = [$this->pathRule('scripts/agent')];

        self::assertContains(
            'primary_owned_path:0:scripts/agent',
            ParallelWorkContract::validate($manifest, $this->policy, $this->ownershipMap),
        );
    }

    public function testRequiresExplicitPrimaryApprovalForCanonicalSingleOwnerComponent(): void
    {
        $manifest = $this->validManifest();
        $manifest['primary_approved_component_ids'] = [];

        self::assertContains(
            'missing_primary_component_approval:platform-quality-tooling',
            ParallelWorkContract::validate($manifest, $this->policy, $this->ownershipMap),
        );
    }

    public function testRejectsUnusedOrUnknownComponentApproval(): void
    {
        $manifest = $this->validManifest();
        $manifest['lanes'][1]['ownership'] = [$this->pathRule('tests/Fixtures/lane-b')];

        $errors = ParallelWorkContract::validate($manifest, $this->policy, $this->ownershipMap);
        self::assertContains('unused_primary_component_approval:platform-quality-tooling', $errors);

        $manifest['primary_approved_component_ids'] = ['unknown-component'];
        self::assertContains(
            'unknown_primary_component_approval:unknown-component',
            ParallelWorkContract::validate($manifest, $this->policy, $this->ownershipMap),
        );
    }

    public function testStemStyleCanonicalPrefixRequiresComponentApproval(): void
    {
        $manifest = $this->validManifest();
        $manifest['lanes'][0]['ownership'] = [$this->pathRule('application/views/components/booking_sidebar.php')];

        self::assertContains(
            'missing_primary_component_approval:booking-public',
            ParallelWorkContract::validate($manifest, $this->policy, $this->ownershipMap),
        );
    }

    public function testDirectoryCanonicalPrefixDoesNotMatchSiblingTextPrefix(): void
    {
        $manifest = $this->validManifest();
        $manifest['primary_approved_component_ids'] = [];
        $manifest['lanes'][1]['ownership'] = [$this->pathRule('scripts/cinder/performance')];

        self::assertSame([], ParallelWorkContract::validate($manifest, $this->policy, $this->ownershipMap));
    }

    public function testRejectsDuplicateCanonicalComponentIds(): void
    {
        $ownershipMap = $this->ownershipMap;
        $ownershipMap['components'][] = $ownershipMap['components'][0];

        self::assertContains(
            'duplicate_canonical_component_id:platform-quality-tooling',
            ParallelWorkContract::validate($this->validManifest(), $this->policy, $ownershipMap),
        );
    }

    public function testRejectsMalformedCanonicalOwnershipMetadata(): void
    {
        $ownershipMap = $this->ownershipMap;
        $ownershipMap['components'][0]['ownership_mode'] = 'single-owenr';
        $ownershipMap['components'][1]['manual_approval_required'] = 'true';

        $errors = ParallelWorkContract::validate($this->validManifest(), $this->policy, $ownershipMap);

        self::assertContains('invalid_canonical_ownership_mode:platform-quality-tooling', $errors);
        self::assertContains('invalid_canonical_manual_approval:booking-public', $errors);
    }

    public function testRejectsSingleOwnerWithoutManualApproval(): void
    {
        $ownershipMap = $this->ownershipMap;
        $ownershipMap['components'][0]['manual_approval_required'] = false;

        self::assertContains(
            'invalid_canonical_single_owner_approval:platform-quality-tooling',
            ParallelWorkContract::validate($this->validManifest(), $this->policy, $ownershipMap),
        );
    }

    public function testRejectsMalformedOrUnusedCanonicalPrefixMatchOverride(): void
    {
        $ownershipMap = $this->ownershipMap;
        $ownershipMap['prefix_match_overrides']['application/views/components/booking_'] = 'implicit_magic';
        self::assertContains(
            'invalid_canonical_prefix_match_override',
            ParallelWorkContract::validate($this->validManifest(), $this->policy, $ownershipMap),
        );

        $ownershipMap = $this->ownershipMap;
        $ownershipMap['prefix_match_overrides']['tests/not-owned_'] = 'filename_stem';
        self::assertContains(
            'unused_canonical_prefix_match_override:tests/not-owned_',
            ParallelWorkContract::validate($this->validManifest(), $this->policy, $ownershipMap),
        );
    }

    /** @return array<string, mixed> */
    private function validManifest(): array
    {
        return [
            'schema_version' => 1,
            'base_sha' => str_repeat('a', 40),
            'primary_id' => 'primary',
            'primary_approved_component_ids' => ['platform-quality-tooling'],
            'semantic_independence' => [
                'shared_contracts' => [],
                'cross_lane_dependencies' => [],
                'coordination_required' => false,
            ],
            'lanes' => [
                [
                    'id' => 'lane-a',
                    'role' => 'implementation_worker',
                    'base_sha' => str_repeat('a', 40),
                    'ownership' => [$this->pathRule('scripts/github')],
                    'external_mutations' => [],
                ],
                [
                    'id' => 'lane-b',
                    'role' => 'implementation_worker',
                    'base_sha' => str_repeat('a', 40),
                    'ownership' => [$this->pathRule('scripts/ci/performance')],
                    'external_mutations' => [],
                ],
            ],
        ];
    }

    /** @return array{path: string, match: string} */
    private function pathRule(string $path, string $match = 'exact_or_descendants'): array
    {
        return ['path' => $path, 'match' => $match];
    }
}
