<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

final class ApiOpenApiWriteContractMatrixTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function matrix(): array
    {
        $path = __DIR__ . '/../../../scripts/ci/config/api_openapi_write_contract_matrix.php';
        $matrix = require $path;

        self::assertIsArray($matrix);

        return $matrix;
    }

    public function testMatrixIsWriteEnabledAndContainsExpectedCheckOrder(): void
    {
        $matrix = $this->matrix();

        self::assertArrayHasKey('read_only', $matrix);
        self::assertFalse($matrix['read_only']);
        self::assertArrayHasKey('checks', $matrix);
        self::assertIsArray($matrix['checks']);

        $ids = array_map(static fn(array $check): string => (string) ($check['id'] ?? ''), $matrix['checks']);

        self::assertSame(
            [
                'appointments_write_unauthorized_guard',
                'customers_store_contract',
                'appointments_store_contract',
                'appointments_update_contract',
                'appointments_destroy_contract',
                'customers_destroy_contract',
            ],
            $ids,
        );
    }

    public function testEachCheckHasMethodAndExpectedStatus(): void
    {
        $matrix = $this->matrix();

        foreach ($matrix['checks'] as $index => $check) {
            self::assertIsArray($check, 'Check #' . $index . ' must be an object.');
            self::assertContains($check['method'] ?? null, ['POST', 'PUT', 'DELETE']);
            self::assertIsInt($check['expected_status'] ?? null);
            self::assertNotSame('', trim((string) ($check['openapi_path'] ?? '')));
        }
    }

    public function testUnauthorizedGuardIsConfiguredForNoAuthAndHeaderCheck(): void
    {
        $matrix = $this->matrix();
        $guard = $matrix['checks'][0];

        self::assertSame('appointments_write_unauthorized_guard', $guard['id']);
        self::assertSame('none', $guard['auth']);
        self::assertSame(401, $guard['expected_status']);
        self::assertTrue($guard['require_www_authenticate']);
    }

    public function testDependencyGraphReferencesKnownChecks(): void
    {
        $matrix = $this->matrix();
        $knownChecks = array_fill_keys(
            array_map(static fn(array $check): string => (string) ($check['id'] ?? ''), $matrix['checks']),
            true,
        );

        $dependenciesByCheck = [];

        foreach ($matrix['checks'] as $check) {
            $checkId = (string) ($check['id'] ?? '');
            $dependsOn = $check['depends_on'] ?? [];

            self::assertIsArray($dependsOn, $checkId . ' depends_on must be an array.');
            $dependenciesByCheck[$checkId] = $dependsOn;

            foreach ($dependsOn as $dependencyId) {
                self::assertIsString($dependencyId);
                self::assertArrayHasKey(
                    $dependencyId,
                    $knownChecks,
                    $checkId . ' depends_on references an unknown check ID.',
                );
            }
        }

        self::assertSame(
            [
                'appointments_write_unauthorized_guard' => [],
                'customers_store_contract' => [],
                'appointments_store_contract' => ['customers_store_contract'],
                'appointments_update_contract' => ['appointments_store_contract'],
                'appointments_destroy_contract' => ['appointments_store_contract'],
                'customers_destroy_contract' => ['appointments_destroy_contract'],
            ],
            $dependenciesByCheck,
        );
    }
}
