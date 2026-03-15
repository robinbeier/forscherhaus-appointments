<?php

namespace Tests\Unit\Bootstrap;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sentry\Event;
use Sentry\SentrySdk;
use Sentry\State\Hub;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;
use Sentry\Transport\TransportInterface;
use SentryBootstrap;

require_once dirname(__DIR__, 3) . '/application/bootstrap/SentryBootstrap.php';

class SentryBootstrapTest extends TestCase
{
    public function testBuildOptionsReturnsEmptyArrayWithoutDsn(): void
    {
        $options = SentryBootstrap::buildOptionsFromEnvironmentMap([], 'production', '/tmp/missing-release');

        $this->assertSame([], $options);
    }

    public function testBuildOptionsIncludesReleaseServerNameAndTracingOptions(): void
    {
        $releaseFile = tempnam(sys_get_temp_dir(), 'sentry-release-');
        file_put_contents($releaseFile, "ea_20260314_2130  2026-03-14T21:29:57Z\n");

        try {
            $options = SentryBootstrap::buildOptionsFromEnvironmentMap(
                [
                    'SENTRY_DSN' => 'https://examplePublicKey@o0.ingest.sentry.io/1',
                    'SENTRY_TRACES_SAMPLE_RATE' => '0.05',
                    'SENTRY_SEND_DEFAULT_PII' => 'true',
                ],
                'production',
                $releaseFile,
                ['HTTP_HOST' => 'dasforscherhaus-leg.de'],
            );

            $this->assertSame('https://examplePublicKey@o0.ingest.sentry.io/1', $options['dsn']);
            $this->assertSame('production', $options['environment']);
            $this->assertSame('ea_20260314_2130', $options['release']);
            $this->assertSame('dasforscherhaus-leg.de', $options['server_name']);
            $this->assertSame(0.05, $options['traces_sample_rate']);
            $this->assertTrue($options['send_default_pii']);
        } finally {
            @unlink($releaseFile);
        }
    }

    public function testBuildOptionsFromGlobalsReadsApacheStyleServerVars(): void
    {
        $releaseFile = tempnam(sys_get_temp_dir(), 'sentry-release-');
        file_put_contents($releaseFile, "ea_20260314_2316  2026-03-14T22:22:07Z\n");

        $originalEnv = [];
        foreach (['SENTRY_DSN', 'SENTRY_TRACES_SAMPLE_RATE', 'SENTRY_SEND_DEFAULT_PII', 'SENTRY_SERVER_NAME'] as $key) {
            $originalEnv[$key] = $_ENV[$key] ?? null;
            $_ENV[$key] = '';
        }

        try {
            $options = SentryBootstrap::buildOptionsFromGlobals('production', $releaseFile, [
                'REDIRECT_SENTRY_DSN' => 'https://apachePublicKey@o0.ingest.sentry.io/2',
                'REDIRECT_SENTRY_TRACES_SAMPLE_RATE' => '0.1',
                'REDIRECT_SENTRY_SEND_DEFAULT_PII' => 'off',
                'SENTRY_SERVER_NAME' => 'apache.example.test',
                'HTTP_HOST' => 'ignored-host.example.test',
            ]);

            $this->assertSame('https://apachePublicKey@o0.ingest.sentry.io/2', $options['dsn']);
            $this->assertSame(0.1, $options['traces_sample_rate']);
            $this->assertFalse($options['send_default_pii']);
            $this->assertSame('apache.example.test', $options['server_name']);
            $this->assertSame('ea_20260314_2316', $options['release']);
        } finally {
            @unlink($releaseFile);

            foreach ($originalEnv as $key => $value) {
                if ($value === null) {
                    unset($_ENV[$key]);
                    continue;
                }

                $_ENV[$key] = $value;
            }
        }
    }

    public function testReadReleaseIdentifierReturnsNullForMissingFile(): void
    {
        $this->assertNull(SentryBootstrap::readReleaseIdentifier('/tmp/definitely-missing-release-file'));
    }

    public function testCaptureExceptionAddsConfiguredTagsAndExtra(): void
    {
        $transport = new class implements TransportInterface {
            public ?Event $event = null;

            public function send(Event $event): Result
            {
                $this->event = $event;

                return new Result(ResultStatus::success(), $event);
            }

            public function close(?int $timeout = null): Result
            {
                return new Result(ResultStatus::success(), $this->event);
            }
        };

        \Sentry\init([
            'dsn' => 'https://examplePublicKey@o0.ingest.sentry.io/1',
            'default_integrations' => false,
            'transport' => $transport,
        ]);

        SentryBootstrap::captureException(
            new RuntimeException('export smoke'),
            [
                'area' => 'dashboard_export',
                'export_type' => 'principal_pdf',
            ],
            [
                'controller' => 'Dashboard_export',
                'request_uri' => '/index.php/dashboard_export/principal_pdf',
            ],
        );

        \Sentry\flush();

        $this->assertNotNull($transport->event);
        $this->assertSame('dashboard_export', $transport->event->getTags()['area'] ?? null);
        $this->assertSame('principal_pdf', $transport->event->getTags()['export_type'] ?? null);
        $this->assertSame('Dashboard_export', $transport->event->getExtra()['controller'] ?? null);
        $this->assertSame(
            '/index.php/dashboard_export/principal_pdf',
            $transport->event->getExtra()['request_uri'] ?? null,
        );

        SentrySdk::setCurrentHub(new Hub());
    }
}
