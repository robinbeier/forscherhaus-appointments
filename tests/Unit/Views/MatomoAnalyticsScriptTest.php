<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class MatomoAnalyticsScriptTest extends TestCase
{
    public function testStoredUrlArgumentCannotEnableScriptWhenOperatorConfigIsAbsent(): void
    {
        $output = $this->render('', '7', 'https://attacker.invalid/');

        self::assertStringNotContainsString('<script>', $output);
        self::assertStringNotContainsString('attacker.invalid', $output);
    }

    public function testExactOperatorHttpsBaseEnablesScriptAndEscapesJavascriptStrings(): void
    {
        $output = $this->render('https://analytics.example.test/matomo', '7</script><script>alert(1)</script>');

        self::assertStringContainsString('g.src = u + \'matomo.js\'', $output);
        self::assertStringContainsString('https:\\/\\/analytics.example.test\\/matomo\\/', $output);
        self::assertStringContainsString('\\u003C\\/script\\u003E', $output);
        self::assertStringNotContainsString('</script><script>alert(1)</script>', $output);
    }

    #[DataProvider('invalidOperatorUrls')]
    public function testAmbiguousOperatorUrlsRemainDisabled(string $url): void
    {
        self::assertStringNotContainsString('<script>', $this->render($url, '7'));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidOperatorUrls(): iterable
    {
        yield 'http' => ['http://analytics.example.test/'];
        yield 'query' => ['https://analytics.example.test/?token=secret'];
        yield 'fragment' => ['https://analytics.example.test/#tracker'];
        yield 'userinfo' => ['https://user:pass@analytics.example.test/'];
    }

    private function render(string $url, string $siteId, string $storedUrl = ''): string
    {
        $process = proc_open(
            [
                PHP_BINARY,
                dirname(__DIR__, 2) . '/Fixtures/matomo_analytics_script_probe.php',
                $url,
                $siteId,
                $storedUrl,
            ],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $errors);

        return $output;
    }
}
