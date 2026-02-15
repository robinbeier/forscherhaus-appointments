<?php

namespace Tests\Unit\Controllers;

use Dashboard_export;
use InvalidArgumentException;
use Tests\TestCase;

require_once APPPATH . 'controllers/Dashboard_export.php';

class DashboardExportControllerTest extends TestCase
{
    public function testResolveThresholdAcceptsValidValues(): void
    {
        $controller = $this->createControllerWithThreshold(0.9);

        $this->assertSame(0.0, $controller->callResolveThreshold(0));
        $this->assertSame(0.9, $controller->callResolveThreshold('0.9'));
        $this->assertSame(1.0, $controller->callResolveThreshold(1));
    }

    public function testResolveThresholdUsesConfiguredDefaultWhenMissing(): void
    {
        $controller = $this->createControllerWithThreshold(0.9);

        $this->assertSame(0.9, $controller->callResolveThreshold(null));
        $this->assertSame(0.9, $controller->callResolveThreshold(''));
    }

    public function testResolveThresholdRejectsInvalidValues(): void
    {
        $controller = $this->createControllerWithThreshold(0.9);

        $this->expectException(InvalidArgumentException::class);
        $controller->callResolveThreshold('1.2');
    }

    public function testNormalizeProviderIdsReturnsUniquePositiveIntegers(): void
    {
        $controller = $this->createControllerWithThreshold(0.9);

        $normalized = $controller->callNormalizeProviderIds([1, '2', 2, 0, -3, '', null, ' 4 ', '4']);

        $this->assertSame([1, 2, 4], $normalized);
    }

    private function createControllerWithThreshold(float $configuredThreshold): object
    {
        return new class ($configuredThreshold) extends Dashboard_export {
            private float $configuredThreshold;

            public function __construct(float $configuredThreshold)
            {
                $this->configuredThreshold = $configuredThreshold;
            }

            public function callResolveThreshold(mixed $thresholdInput): float
            {
                return $this->resolveThreshold($thresholdInput);
            }

            public function callNormalizeProviderIds(mixed $providerIds): array
            {
                return $this->normalizeProviderIds($providerIds);
            }

            protected function getConfiguredThreshold(): float
            {
                return $this->configuredThreshold;
            }
        };
    }
}
