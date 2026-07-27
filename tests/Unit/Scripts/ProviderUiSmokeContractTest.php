<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;
use ReleaseGate\ProviderUiSmokeContract;

require_once __DIR__ . '/../../../scripts/release-gate/lib/ProviderUiSmokeContract.php';

final class ProviderUiSmokeContractTest extends TestCase
{
    public function testBrowserSessionIdStaysBelowUnixSocketNameBudget(): void
    {
        $sessionId = ProviderUiSmokeContract::buildBrowserSessionId();

        self::assertMatchesRegularExpression('/\Apui-[a-f0-9]{8}\z/D', $sessionId);
        self::assertLessThanOrEqual(12, strlen($sessionId));
    }

    public function testExtractScriptVarsIgnoresIntegrationWordsOutsideWindowVars(): void
    {
        $html = <<<'HTML'
        <script>
            window.vars = (function () {
                const vars = {"csrf_token":"synthetic","date_format":"DMY"};
                return function (key) { return vars[key]; };
            })();
        </script>
        <script>
            const lang = {"add_to_google_calendar":"Synthetic translation"};
        </script>
        HTML;

        self::assertSame(
            ['csrf_token' => 'synthetic', 'date_format' => 'DMY'],
            ProviderUiSmokeContract::extractScriptVars($html),
        );
    }

    public function testExtractScriptVarsPreservesAnExactForbiddenKeyForGateRejection(): void
    {
        $html = <<<'HTML'
        <script>
            window.vars = (function () {
                const vars = {"customer_filter_providers":[1],"caldav_password":"synthetic"};
                return function (key) { return vars[key]; };
            })();
        </script>
        HTML;

        $scriptVars = ProviderUiSmokeContract::extractScriptVars($html);

        self::assertArrayHasKey('customer_filter_providers', $scriptVars);
        self::assertArrayHasKey('caldav_password', $scriptVars);
    }
}
