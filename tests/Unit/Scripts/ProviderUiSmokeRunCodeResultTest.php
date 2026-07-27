<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;
use ReleaseGate\GateAssertionException;

require_once __DIR__ . '/../../../scripts/release-gate/lib/ProviderUiSmokeRunCodeResult.php';

use function ReleaseGate\parseProviderUiSmokeRunCodeResult;

class ProviderUiSmokeRunCodeResultTest extends TestCase
{
    public function testParsesBooleanAndCountOnlyMarker(): void
    {
        $payload = $this->validPayload();
        $result = parseProviderUiSmokeRunCodeResult([
            'stdout' => "debug\n__PROVIDER_UI_SMOKE_GATE__" . json_encode($payload, JSON_THROW_ON_ERROR) . "\n",
        ]);

        self::assertSame($payload, $result);
    }

    public function testParsesQuotedEscapedMarkerFromCliOutput(): void
    {
        $payload = $this->validPayload();
        $encoded = addcslashes(json_encode($payload, JSON_THROW_ON_ERROR), '"');
        $result = parseProviderUiSmokeRunCodeResult([
            'stdout' => 'generic [ref=e1]: "__PROVIDER_UI_SMOKE_GATE__' . $encoded . "\"\n",
        ]);

        self::assertSame($payload, $result);
    }

    public function testRejectsUnexpectedStringField(): void
    {
        $payload = $this->validPayload();
        $payload['error'] = 'must-not-be-emitted';

        $this->expectException(GateAssertionException::class);
        $this->expectExceptionMessage('unexpected field set');

        parseProviderUiSmokeRunCodeResult([
            'stdout' => '__PROVIDER_UI_SMOKE_GATE__' . json_encode($payload, JSON_THROW_ON_ERROR),
        ]);
    }

    public function testRejectsNonBooleanResultField(): void
    {
        $payload = $this->validPayload();
        $payload['dashboard_loaded'] = 'yes';

        $this->expectException(GateAssertionException::class);
        $this->expectExceptionMessage('non-boolean');

        parseProviderUiSmokeRunCodeResult([
            'stdout' => '__PROVIDER_UI_SMOKE_GATE__' . json_encode($payload, JSON_THROW_ON_ERROR),
        ]);
    }

    /**
     * @return array<string, bool|int>
     */
    private function validPayload(): array
    {
        return [
            'ok' => true,
            'network_policy_installed' => true,
            'dashboard_loaded' => true,
            'buttons_present' => true,
            'script_vars_safe' => true,
            'primary_metrics_status_ok' => true,
            'primary_row_matches' => true,
            'preparation_downloaded' => true,
            'parent_downloaded' => true,
            'empty_metrics_status_ok' => true,
            'empty_state_visible' => true,
            'empty_preparation_downloaded' => true,
            'restore_metrics_status_ok' => true,
            'primary_row_count' => 1,
            'empty_row_count' => 0,
            'blocked_request_count' => 0,
            'page_error_count' => 0,
            'console_error_count' => 0,
            'flow_error_count' => 0,
        ];
    }
}
