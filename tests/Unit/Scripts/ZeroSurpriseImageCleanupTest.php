<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;
use ReleaseGate\ZeroSurpriseImageCleanup;

require_once __DIR__ . '/../../../scripts/release-gate/lib/GateProcessRunner.php';
require_once __DIR__ . '/../../../scripts/release-gate/lib/ZeroSurpriseReport.php';
require_once __DIR__ . '/../../../scripts/release-gate/lib/ZeroSurpriseImageCleanup.php';

final class ZeroSurpriseImageCleanupTest extends TestCase
{
    private const PROJECT = 'zs-release-20260813t010203z-abcd';
    private const PHP_IMAGE = 'sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const PDF_IMAGE = 'sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    public function testDeletesOnlyExactProjectImagesAfterFreshReferenceChecks(): void
    {
        $docker = new FakeZeroSurpriseDocker(self::PROJECT, [
            self::PHP_IMAGE => $this->image(self::PHP_IMAGE, 'php-fpm', 125),
            self::PDF_IMAGE => $this->image(self::PDF_IMAGE, 'pdf-renderer', 75),
        ]);
        $freeMeasurements = [1_000, 1_150];
        $cleanup = new ZeroSurpriseImageCleanup($docker(...), static function (string $path) use (
            &$freeMeasurements,
        ): int {
            self::assertSame(realpath('/tmp'), $path);

            return array_shift($freeMeasurements) ?? throw new \RuntimeException('unexpected_measurement');
        });

        $result = $cleanup->cleanup(self::PROJECT, '/repo');

        self::assertSame('pass', $result['status']);
        self::assertSame(0, $result['exit_code']);
        self::assertSame(2, $result['details']['candidate_count']);
        self::assertSame(2, $result['details']['deleted_count']);
        self::assertSame(200, $result['details']['candidate_virtual_bytes']);
        self::assertSame(150, $result['details']['freed_bytes']);
        self::assertSame([self::PHP_IMAGE, self::PDF_IMAGE], $docker->deletedIds);
        self::assertSame(2, $docker->containerInventoryCount);
        self::assertSame([], $docker->images);
        $commands = implode(
            ' ',
            array_map(static fn(array $command): string => implode(' ', $command), $docker->commands),
        );
        self::assertStringContainsString('docker image rm ' . self::PHP_IMAGE, $commands);
        self::assertStringContainsString(
            'docker image ls --filter label=com.docker.compose.project=' . self::PROJECT . ' --quiet --no-trunc',
            $commands,
        );
        self::assertStringNotContainsString(' prune', $commands);
        self::assertStringNotContainsString(' --force', $commands);
    }

    public function testAcceptsProjectLocalRepositoryDigestThatDiffersFromImageId(): void
    {
        $image = $this->image(self::PHP_IMAGE, 'php-fpm', 125);
        $image['RepoDigests'] = [
            self::PROJECT . '-php-fpm@sha256:cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc',
        ];
        $docker = new FakeZeroSurpriseDocker(self::PROJECT, [self::PHP_IMAGE => $image]);

        $result = $this->cleanup($docker)->cleanup(self::PROJECT, '/repo');

        self::assertSame('pass', $result['status']);
        self::assertSame([self::PHP_IMAGE], $docker->deletedIds);
    }

    public function testNoCandidatesIsAMeasuredIdempotentSuccess(): void
    {
        $docker = new FakeZeroSurpriseDocker(self::PROJECT, []);
        $cleanup = new ZeroSurpriseImageCleanup($docker(...), static fn(string $path): int => 2_000);

        $result = $cleanup->cleanup(self::PROJECT, '/repo');

        self::assertSame('pass', $result['status']);
        self::assertSame(0, $result['details']['candidate_count']);
        self::assertSame(0, $result['details']['deleted_count']);
        self::assertSame(0, $result['details']['freed_bytes']);
        self::assertSame([], $docker->deletedIds);
    }

    public function testRejectsAdditionalTagWithoutDeletingAnything(): void
    {
        $image = $this->image(self::PHP_IMAGE, 'php-fpm', 125);
        $image['RepoTags'][] = 'production-php-fpm:rollback';
        $docker = new FakeZeroSurpriseDocker(self::PROJECT, [self::PHP_IMAGE => $image]);

        $result = $this->cleanup($docker)->cleanup(self::PROJECT, '/repo');

        self::assertSame('fail', $result['status']);
        self::assertSame('image_tag_mismatch', $result['details']['reason']);
        self::assertSame([], $docker->deletedIds);
    }

    public function testPreservesActiveRollbackKumaAndForeignImages(): void
    {
        $rendererActive = 'sha256:cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';
        $rendererRollback = 'sha256:dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd';
        $kumaActive = 'sha256:eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee';
        $kumaRollback = 'sha256:ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff';
        $images = [
            self::PHP_IMAGE => $this->image(self::PHP_IMAGE, 'php-fpm', 125),
            $rendererActive => $this->foreignImage($rendererActive, 'production-pdf-renderer:active'),
            $rendererRollback => $this->foreignImage($rendererRollback, 'production-pdf-renderer:rollback'),
            $kumaActive => $this->foreignImage($kumaActive, 'production-kuma:active'),
            $kumaRollback => $this->foreignImage($kumaRollback, 'production-kuma:rollback'),
        ];
        $docker = new FakeZeroSurpriseDocker(self::PROJECT, $images, [
            '1111111111111111111111111111111111111111111111111111111111111111' => $rendererActive,
            '2222222222222222222222222222222222222222222222222222222222222222' => $kumaActive,
        ]);

        $result = $this->cleanup($docker)->cleanup(self::PROJECT, '/repo');

        self::assertSame('pass', $result['status']);
        self::assertSame([self::PHP_IMAGE], $docker->deletedIds);
        self::assertEqualsCanonicalizing(
            [$rendererActive, $rendererRollback, $kumaActive, $kumaRollback],
            array_keys($docker->images),
        );
    }

    public function testRejectsContainerReferenceWithoutDeletingAnything(): void
    {
        $docker = new FakeZeroSurpriseDocker(
            self::PROJECT,
            [self::PHP_IMAGE => $this->image(self::PHP_IMAGE, 'php-fpm', 125)],
            ['cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc' => self::PHP_IMAGE],
        );

        $result = $this->cleanup($docker)->cleanup(self::PROJECT, '/repo');

        self::assertSame('fail', $result['status']);
        self::assertSame('image_has_container_reference', $result['details']['reason']);
        self::assertSame([], $docker->deletedIds);
    }

    public function testRejectsForeignProjectServiceOrDigestWithoutDeletion(): void
    {
        $mutations = [
            'project' => static function (array $image): array {
                $image['Config']['Labels']['com.docker.compose.project'] = 'zs-other-20260813t010203z-abcd';

                return $image;
            },
            'service' => static function (array $image): array {
                $image['Config']['Labels']['com.docker.compose.service'] = 'mysql';

                return $image;
            },
            'digest' => static function (array $image): array {
                $image['RepoDigests'] = ['registry.invalid/app@' . self::PHP_IMAGE];

                return $image;
            },
        ];
        $reasons = [
            'project' => 'image_project_mismatch',
            'service' => 'image_service_mismatch',
            'digest' => 'image_digest_mismatch',
        ];

        foreach ($mutations as $name => $mutation) {
            $docker = new FakeZeroSurpriseDocker(self::PROJECT, [
                self::PHP_IMAGE => $mutation($this->image(self::PHP_IMAGE, 'php-fpm', 125)),
            ]);
            if ($name === 'project') {
                $docker->forcedCandidateIds = [self::PHP_IMAGE];
            }

            $result = $this->cleanup($docker)->cleanup(self::PROJECT, '/repo');

            self::assertSame('fail', $result['status'], $name);
            self::assertSame($reasons[$name], $result['details']['reason'], $name);
            self::assertSame([], $docker->deletedIds, $name);
        }
    }

    public function testRejectsSnapshotMutationImmediatelyBeforeDelete(): void
    {
        $docker = new FakeZeroSurpriseDocker(self::PROJECT, [
            self::PHP_IMAGE => $this->image(self::PHP_IMAGE, 'php-fpm', 125),
        ]);
        $docker->mutateBeforeSecondImageList = static function (array $images): array {
            $images[self::PHP_IMAGE]['Size'] = 126;

            return $images;
        };

        $result = $this->cleanup($docker)->cleanup(self::PROJECT, '/repo');

        self::assertSame('fail', $result['status']);
        self::assertSame('image_snapshot_changed', $result['details']['reason']);
        self::assertSame([], $docker->deletedIds);
    }

    public function testDeleteFailureIsRuntimeFailureAndPreservesCandidate(): void
    {
        $docker = new FakeZeroSurpriseDocker(self::PROJECT, [
            self::PHP_IMAGE => $this->image(self::PHP_IMAGE, 'php-fpm', 125),
        ]);
        $docker->deleteFailure = true;

        $result = $this->cleanup($docker)->cleanup(self::PROJECT, '/repo');

        self::assertSame('fail', $result['status']);
        self::assertSame(2, $result['exit_code']);
        self::assertSame('image_delete_failed', $result['details']['reason']);
        self::assertSame(0, $result['details']['deleted_count']);
        self::assertArrayHasKey(self::PHP_IMAGE, $docker->images);
    }

    public function testRejectsUnsafeProjectBeforeAnyDockerCommand(): void
    {
        $docker = new FakeZeroSurpriseDocker(self::PROJECT, []);

        $result = $this->cleanup($docker)->cleanup('prod', '/repo');

        self::assertSame('invalid_compose_project', $result['details']['reason']);
        self::assertSame([], $docker->commands);
    }

    public function testDockerInventoryFailureDoesNotExposeDiagnostics(): void
    {
        $runner = static function (array $command, string $workingDirectory, int $timeoutSeconds): array {
            if ($command === ['docker', 'info', '--format', '{{json .DockerRootDir}}']) {
                return [
                    'exit_code' => 0,
                    'stdout' => '"/tmp"' . PHP_EOL,
                    'stderr' => '',
                    'duration_ms' => 1.0,
                    'timed_out' => false,
                ];
            }

            return [
                'exit_code' => 1,
                'stdout' => '',
                'stderr' => 'registry.invalid/secret-customer-image:latest',
                'duration_ms' => 1.0,
                'timed_out' => false,
            ];
        };
        $cleanup = new ZeroSurpriseImageCleanup($runner, static fn(string $path): int => 2_000);

        $result = $cleanup->cleanup(self::PROJECT, '/repo');

        self::assertSame('image_inventory_failed', $result['details']['reason']);
        self::assertStringNotContainsString('secret-customer', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function testUnexpectedSnakeCaseExceptionCannotBecomeAReportedReason(): void
    {
        $runner = static function (array $command, string $workingDirectory, int $timeoutSeconds): array {
            throw new \RuntimeException('secret_token');
        };
        $cleanup = new ZeroSurpriseImageCleanup($runner, static fn(string $path): int => 2_000);

        $result = $cleanup->cleanup(self::PROJECT, '/repo');

        self::assertSame('cleanup_internal_error', $result['details']['reason']);
        self::assertStringNotContainsString('secret_token', json_encode($result, JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    private function image(string $id, string $service, int $size): array
    {
        $tag = self::PROJECT . '-' . $service . ':latest';

        return [
            'Id' => $id,
            'RepoTags' => [$tag],
            'RepoDigests' => [substr($tag, 0, -strlen(':latest')) . '@' . $id],
            'Size' => $size,
            'Config' => [
                'Labels' => [
                    'com.docker.compose.project' => self::PROJECT,
                    'com.docker.compose.service' => $service,
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function foreignImage(string $id, string $tag): array
    {
        return [
            'Id' => $id,
            'RepoTags' => [$tag],
            'RepoDigests' => [],
            'Size' => 100,
            'Config' => [
                'Labels' => [
                    'com.docker.compose.project' => 'production',
                    'com.docker.compose.service' => 'protected',
                ],
            ],
        ];
    }

    private function cleanup(FakeZeroSurpriseDocker $docker): ZeroSurpriseImageCleanup
    {
        return new ZeroSurpriseImageCleanup($docker(...), static fn(string $path): int => 2_000);
    }
}

final class FakeZeroSurpriseDocker
{
    /**
     * @var array<string, array<string, mixed>>
     */
    public array $images;

    /**
     * @var array<string, string>
     */
    public array $containers;

    /**
     * @var array<int, array<int, string>>
     */
    public array $commands = [];

    /**
     * @var array<int, string>
     */
    public array $deletedIds = [];

    public int $containerInventoryCount = 0;

    public bool $deleteFailure = false;

    /**
     * @var array<int, string>|null
     */
    public ?array $forcedCandidateIds = null;

    /**
     * @var (callable(array<string, array<string, mixed>>): array<string, array<string, mixed>>)|null
     */
    public mixed $mutateBeforeSecondImageList = null;

    private int $imageListCount = 0;

    /**
     * @param array<string, array<string, mixed>> $images
     * @param array<string, string> $containers
     */
    public function __construct(private readonly string $project, array $images, array $containers = [])
    {
        $this->images = $images;
        $this->containers = $containers;
    }

    /**
     * @param array<int, string> $command
     * @return array<string, mixed>
     */
    public function __invoke(array $command, string $workingDirectory, int $timeoutSeconds): array
    {
        TestCase::assertSame('/repo', $workingDirectory);
        TestCase::assertGreaterThan(0, $timeoutSeconds);
        $this->commands[] = $command;

        if ($command === ['docker', 'info', '--format', '{{json .DockerRootDir}}']) {
            return $this->result(0, '"/tmp"' . PHP_EOL);
        }

        if (array_slice($command, 0, 3) === ['docker', 'image', 'ls']) {
            ++$this->imageListCount;
            if ($this->imageListCount === 2 && $this->mutateBeforeSecondImageList !== null) {
                $this->images = ($this->mutateBeforeSecondImageList)($this->images);
            }

            $ids = $this->forcedCandidateIds;
            if ($ids === null) {
                $ids = [];
                foreach ($this->images as $id => $image) {
                    if (($image['Config']['Labels']['com.docker.compose.project'] ?? null) === $this->project) {
                        $ids[] = $id;
                    }
                }
            }
            sort($ids, SORT_STRING);

            return $this->result(0, $ids === [] ? '' : implode(PHP_EOL, $ids) . PHP_EOL);
        }

        if (array_slice($command, 0, 3) === ['docker', 'image', 'inspect']) {
            $ids = array_slice($command, 3);
            if (count($ids) === 1 && !isset($this->images[$ids[0]])) {
                return $this->result(1, '', 'No such image');
            }

            $records = [];
            foreach ($ids as $id) {
                if (!isset($this->images[$id])) {
                    return $this->result(1, '', 'No such image');
                }

                $records[] = $this->images[$id];
            }

            return $this->result(0, json_encode($records, JSON_THROW_ON_ERROR));
        }

        if ($command === ['docker', 'container', 'ls', '--all', '--quiet', '--no-trunc']) {
            ++$this->containerInventoryCount;
            $ids = array_keys($this->containers);

            return $this->result(0, $ids === [] ? '' : implode(PHP_EOL, $ids) . PHP_EOL);
        }

        if (array_slice($command, 0, 3) === ['docker', 'container', 'inspect']) {
            $records = [];
            foreach (array_slice($command, 3) as $id) {
                if (!isset($this->containers[$id])) {
                    return $this->result(1, '', 'No such container');
                }

                $records[] = ['Image' => $this->containers[$id]];
            }

            return $this->result(0, json_encode($records, JSON_THROW_ON_ERROR));
        }

        if (array_slice($command, 0, 3) === ['docker', 'image', 'rm']) {
            $id = $command[3] ?? '';
            if ($this->deleteFailure || !isset($this->images[$id])) {
                return $this->result(1, '', 'delete failed');
            }

            unset($this->images[$id]);
            $this->deletedIds[] = $id;

            return $this->result(0, 'Deleted: ' . $id . PHP_EOL);
        }

        return $this->result(99, '', 'unexpected command');
    }

    /**
     * @return array<string, mixed>
     */
    private function result(int $exitCode, string $stdout = '', string $stderr = ''): array
    {
        return [
            'exit_code' => $exitCode,
            'stdout' => $stdout,
            'stderr' => $stderr,
            'duration_ms' => 1.0,
            'timed_out' => false,
        ];
    }
}
