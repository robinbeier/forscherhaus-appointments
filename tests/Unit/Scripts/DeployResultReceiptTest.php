<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use Ops\DeployResultV1;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__ . '/../../../scripts/ops/lib/DeployResultV1.php';

final class DeployResultReceiptTest extends TestCase
{
    public function testDeployScriptExposesClosedReceiptCandidateOutcomes(): void
    {
        $script = file_get_contents(__DIR__ . '/../../../deploy_ea.sh');

        self::assertIsString($script);
        self::assertStringContainsString('--result-file', $script);
        foreach (
            [
                'succeeded',
                'failed_pre_switch',
                'internal_rollback_succeeded',
                'rollback_failed_or_unverifiable',
                'switch_recovery_required',
                'interrupted_pre_switch',
            ]
            as $outcome
        ) {
            self::assertStringContainsString($outcome, $script);
        }
    }

    public function testDocsPinObservedChildAndDurableStateAsAuthorityBoundary(): void
    {
        $contract = file_get_contents(__DIR__ . '/../../../docs/deployment-run-v1.md');

        self::assertIsString($contract);
        self::assertStringContainsString('The receipt alone is not authoritative.', $contract);
        self::assertStringContainsString('host-global production-change lock and the run lock', $contract);
        self::assertStringContainsString('observed the terminal child/systemd result', $contract);
        self::assertStringContainsString('exact receipt-byte', $contract);
        self::assertStringContainsString('never authorize a respawn', $contract);
        self::assertStringContainsString('`74` is deliberately not a valid', $contract);
    }

    public function testReceiptLibraryDefinesUnambiguousOutcomeExitBindings(): void
    {
        $library = __DIR__ . '/../../../scripts/ops/lib/DeployResultV1.php';

        self::assertFileExists($library);
        self::assertSame(
            [
                'succeeded' => 0,
                'failed_pre_switch' => 30,
                'internal_rollback_succeeded' => 30,
                'rollback_failed_or_unverifiable' => 31,
                'switch_recovery_required' => 32,
                'interrupted_pre_switch' => 143,
            ],
            DeployResultV1::OUTCOME_EXIT_CODES,
        );
    }

    public function testPublicationFailureExitIsOutsideTheReceiptSchema(): void
    {
        self::assertNotContains(74, DeployResultV1::OUTCOME_EXIT_CODES);

        $this->expectException(RuntimeException::class);
        DeployResultV1::create('failed_pre_switch', 74);
    }

    #[DataProvider('outcomeProvider')]
    public function testEveryOutcomeHasCanonicalClosedReceiptAndEvidence(
        string $outcome,
        int $exitCode,
        array $expectedEvidence,
    ): void {
        $receipt = DeployResultV1::create($outcome, $exitCode);
        $encoded = DeployResultV1::canonicalJson($receipt);

        self::assertSame(
            sprintf('{"schema":"deploy_result.v1","outcome":"%s","exit_code":%d}' . "\n", $outcome, $exitCode),
            $encoded,
        );
        self::assertSame($receipt, DeployResultV1::decode($encoded));
        self::assertSame($expectedEvidence, DeployResultV1::deployEvidence($outcome));
    }

    /** @return iterable<string,array{string,int,array<string,int|string>}> */
    public static function outcomeProvider(): iterable
    {
        yield 'success' => [
            'succeeded',
            0,
            ['status' => 'succeeded', 'invocation_count' => 1, 'exit_code' => 0, 'rollback_outcome' => 'not_run'],
        ];
        yield 'pre-switch failure' => [
            'failed_pre_switch',
            30,
            ['status' => 'failed', 'invocation_count' => 1, 'exit_code' => 30, 'rollback_outcome' => 'not_run'],
        ];
        yield 'internal rollback succeeded' => [
            'internal_rollback_succeeded',
            30,
            ['status' => 'failed', 'invocation_count' => 1, 'exit_code' => 30, 'rollback_outcome' => 'succeeded'],
        ];
        yield 'rollback failed or unverifiable' => [
            'rollback_failed_or_unverifiable',
            31,
            ['status' => 'failed', 'invocation_count' => 1, 'exit_code' => 31, 'rollback_outcome' => 'failed'],
        ];
        yield 'partial switch' => [
            'switch_recovery_required',
            32,
            [
                'status' => 'failed',
                'invocation_count' => 1,
                'exit_code' => 32,
                'rollback_outcome' => 'recovery_required',
            ],
        ];
        yield 'pre-switch interruption' => [
            'interrupted_pre_switch',
            143,
            ['status' => 'failed', 'invocation_count' => 1, 'exit_code' => 143, 'rollback_outcome' => 'not_run'],
        ];
    }

    #[DataProvider('invalidReceiptProvider')]
    public function testMalformedOrOpenReceiptIsRejected(array $receipt): void
    {
        $this->expectException(RuntimeException::class);

        DeployResultV1::validate($receipt);
    }

    /** @return iterable<string,array{array<string,mixed>}> */
    public static function invalidReceiptProvider(): iterable
    {
        yield 'missing field' => [['schema' => 'deploy_result.v1', 'outcome' => 'succeeded']];
        yield 'extra field' => [
            [
                'schema' => 'deploy_result.v1',
                'outcome' => 'succeeded',
                'exit_code' => 0,
                'detail' => 'forbidden',
            ],
        ];
        yield 'wrong schema' => [['schema' => 'deploy_result.v2', 'outcome' => 'succeeded', 'exit_code' => 0]];
        yield 'unknown outcome' => [['schema' => 'deploy_result.v1', 'outcome' => 'unknown', 'exit_code' => 0]];
        yield 'wrong exit' => [['schema' => 'deploy_result.v1', 'outcome' => 'succeeded', 'exit_code' => 30]];
        yield 'float exit' => [['schema' => 'deploy_result.v1', 'outcome' => 'succeeded', 'exit_code' => 0.0]];
        yield 'list' => [['deploy_result.v1', 'succeeded', 0]];
    }

    #[DataProvider('invalidEncodingProvider')]
    public function testMalformedOrNonCanonicalEncodingIsRejected(string $encoded): void
    {
        $this->expectException(RuntimeException::class);

        DeployResultV1::decode($encoded);
    }

    /** @return iterable<string,array{string}> */
    public static function invalidEncodingProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'malformed' => ['{'];
        yield 'scalar' => ['null'];
        yield 'alternate key order' => ['{"outcome":"succeeded","schema":"deploy_result.v1","exit_code":0}' . "\n"];
        yield 'missing newline' => ['{"schema":"deploy_result.v1","outcome":"succeeded","exit_code":0}'];
        yield 'duplicate key' => [
            '{"schema":"deploy_result.v1","outcome":"succeeded","exit_code":31,"exit_code":0}' . "\n",
        ];
        yield 'nul' => ["{\0}"];
        yield 'oversized' => [str_repeat('x', 513)];
    }

    public function testReceiptCannotCarrySecretsPathsCommandsHostsOrFreeText(): void
    {
        $forbidden = ['secret', 'token', 'path', 'command', 'host', 'stdout', 'stderr', 'message'];

        foreach ($forbidden as $field) {
            $receipt = DeployResultV1::create('failed_pre_switch', 30);
            $receipt[$field] = '/sensitive/value';
            try {
                DeployResultV1::validate($receipt);
                self::fail($field . ' was accepted');
            } catch (RuntimeException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
