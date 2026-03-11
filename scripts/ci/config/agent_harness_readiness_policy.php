<?php

declare(strict_types=1);

return [
    'target_score' => 4.5,
    'dimensions' => [
        'steering_sources' => [
            'label' => 'Steering sources',
            'weight' => 20,
        ],
        'blocking_gates' => [
            'label' => 'Blocking gates',
            'weight' => 30,
        ],
        'generated_topology' => [
            'label' => 'Generated topology',
            'weight' => 20,
        ],
        'report_sanity' => [
            'label' => 'Report sanity',
            'weight' => 15,
        ],
        'scheduled_hygiene' => [
            'label' => 'Scheduled hygiene',
            'weight' => 15,
        ],
    ],
    'required_sources' => [
        'README.md' => ['docs/agent-harness-index.md', 'WORKFLOW.md', 'AGENTS.md'],
        'AGENTS.md' => ['docs/agent-harness-index.md'],
        'WORKFLOW.md' => ['docs/agent-harness-index.md'],
        'docs/agent-harness-index.md' => [
            '.github/workflows/ci.yml',
            'docs/architecture-map.md',
            'docs/ownership-map.md',
        ],
    ],
    'blocking_jobs' => [
        'phpstan-application',
        'js-lint-changed',
        'architecture-ownership-map',
        'architecture-boundaries',
        'typed-request-dto',
        'typed-request-contracts',
        'api-contract-openapi',
        'write-contract-booking',
        'write-contract-api',
        'booking-controller-flows',
        'coverage-delta',
    ],
    'generated_topology_commands' => [
        [
            'id' => 'architecture_docs',
            'label' => 'Architecture/ownership docs are current',
            'command' => ['python3', 'scripts/docs/generate_architecture_ownership_docs.py', '--check'],
        ],
        [
            'id' => 'ownership_map',
            'label' => 'Architecture/ownership map validates',
            'command' => ['python3', 'scripts/ci/check_architecture_ownership_map.py'],
        ],
        [
            'id' => 'codeowners_sync',
            'label' => 'Generated CODEOWNERS is current',
            'command' => ['python3', 'scripts/docs/generate_codeowners_from_map.py', '--check'],
        ],
    ],
    'hygiene_workflow' => [
        'path' => '.github/workflows/hygiene.yml',
        'job' => 'harness-hygiene',
        'required_steps' => [
            'Generate harness readiness report',
            'Run report date sanity check',
            'Check generated architecture/ownership docs',
            'Validate architecture/ownership map',
            'Check generated CODEOWNERS',
            'Upload hygiene artifacts',
        ],
    ],
];
