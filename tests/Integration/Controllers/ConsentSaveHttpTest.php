<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ReleaseGate\GateHttpClient;
use Tests\Integration\Support\DefenseCycleFixtures;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__) . '/Support/DefenseCycleFixtures.php';
require_once dirname(__DIR__) . '/Support/DefenseCycleHttpServer.php';

/** Real HTTP contract coverage for the public consent save endpoint. */
final class ConsentSaveHttpTest extends TestCase
{
    private const ENDPOINT = 'consents/save';
    private const DIRECT_ENDPOINT = 'index.php/consents/save';

    private ?DefenseCycleFixtures $fixture = null;
    private ?DefenseCycleHttpServer $server = null;
    private int $baselineConsentCount = 0;
    private bool $consentBaselineCaptured = false;
    /** @var list<int> */
    private array $ownedConsentIds = [];

    protected function setUp(): void
    {
        if (getenv('FH_DEFENSE_ISOLATED') !== '1') {
            self::markTestSkipped('Run with the fresh isolated synthetic stack.');
        }

        try {
            $this->fixture = new DefenseCycleFixtures();
            $this->fixture->create();
            $this->baselineConsentCount = get_instance()->db->count_all('consents');
            $this->consentBaselineCaptured = true;
            $this->server = new DefenseCycleHttpServer();
        } catch (Throwable $error) {
            try {
                $this->server?->close();
            } finally {
                try {
                    $this->cleanupConsents();
                } finally {
                    $this->fixture?->cleanup();
                }
            }
            throw $error;
        }
    }

    protected function tearDown(): void
    {
        try {
            $this->server?->close();
        } finally {
            try {
                $this->cleanupConsents();
            } finally {
                $this->fixture?->cleanup();
            }
        }
    }

    public function testNonPostDirectAndRewriteRoutesRejectBeforeMutation(): void
    {
        $payload = ['consent' => ['type' => 'cookie', 'email' => $this->syntheticEmail('query')]];
        $before = $this->consentSnapshot();
        $urls = [];
        foreach ([self::DIRECT_ENDPOINT, self::ENDPOINT] as $path) {
            $client = new GateHttpClient($this->server()->baseUrl, '');
            $response = $client->get($path, $payload);
            $urls[] = $response->url;
            self::assertSame(404, $response->statusCode, $response->body);
            self::assertSame($before, $this->consentSnapshot());
        }
        self::assertCount(2, $urls);
        self::assertNotSame($urls[0], $urls[1]);
    }

    public function testAllNonPostMethodsRejectWithoutMutationOnBothRoutes(): void
    {
        $before = $this->consentSnapshot();
        foreach ([self::DIRECT_ENDPOINT, self::ENDPOINT] as $path) {
            $client = new GateHttpClient($this->server()->baseUrl, '');
            foreach (['HEAD', 'PUT', 'PATCH', 'DELETE'] as $method) {
                $response = $client->requestApp($method, $path, [
                    'consent' => ['type' => 'cookie', 'email' => $this->syntheticEmail(strtolower($method))],
                ]);
                self::assertSame(404, $response->statusCode, $response->body);
                self::assertSame($before, $this->consentSnapshot());
            }
        }
    }

    public function testMissingAndInvalidCsrfPostsRejectBeforeMutationOnBothRoutes(): void
    {
        $before = $this->consentSnapshot();
        foreach ([self::DIRECT_ENDPOINT, self::ENDPOINT] as $path) {
            $client = new GateHttpClient($this->server()->baseUrl, '');
            $form = ['consent' => ['type' => 'cookie', 'email' => $this->syntheticEmail('csrf')]];
            foreach ([$form, $form + ['csrf_token' => 'invalid-synthetic-csrf']] as $payload) {
                $response = $client->requestApp('POST', $path, $payload);
                self::assertSame(403, $response->statusCode, $response->body);
                self::assertStringEndsWith('/' . $path, parse_url($response->url, PHP_URL_PATH) ?: '');
                self::assertSame($before, $this->consentSnapshot());
            }
        }
    }

    public function testOptionsRemainsGloballyHandledWithoutConsentMutation(): void
    {
        $before = $this->consentSnapshot();
        $response = $this->server()->client()->requestApp('OPTIONS', self::ENDPOINT);

        self::assertSame(200, $response->statusCode, $response->body);
        self::assertSame($before, $this->consentSnapshot());
    }

    public function testCallerSuppliedConsentIdCannotOverwriteAnotherSyntheticConsent(): void
    {
        $targetId = $this->insertConsent([
            'first_name' => 'Target',
            'last_name' => 'Synthetic',
            'email' => $this->syntheticEmail('target'),
            'ip' => '198.51.100.10',
            'type' => 'original',
            'create_datetime' => date('Y-m-d H:i:s', strtotime('-48 hours')),
            'update_datetime' => date('Y-m-d H:i:s', strtotime('-48 hours')),
        ]);
        $before = $this->consentRow($targetId);
        $beforeTable = $this->consentSnapshot();

        $client = $this->server()->client();
        $client->get(self::ENDPOINT);
        $response = $client->post(self::ENDPOINT, [
            'consent' => [
                'id' => $targetId,
                'email' => $this->syntheticEmail('attacker'),
                'type' => 'attacker',
                'ip' => '203.0.113.99',
            ],
        ]);

        self::assertSame(404, $response->statusCode, $response->body);
        self::assertSame($before, $this->consentRow($targetId));
        self::assertSame($beforeTable, $this->consentSnapshot());
    }

    public function testValidCsrfPostIsRejectedWithoutConsentMutation(): void
    {
        $before = $this->consentSnapshot();
        foreach ([self::DIRECT_ENDPOINT, self::ENDPOINT] as $path) {
            $client = new GateHttpClient($this->server()->baseUrl, '');
            $client->get($path);
            $response = $client->post($path, [
                'consent' => [
                    'first_name' => 'Synthetic',
                    'last_name' => 'Consent',
                    'email' => $this->syntheticEmail('valid-csrf'),
                    'type' => 'cookie',
                    'ip' => '203.0.113.99',
                ],
            ]);

            self::assertSame(404, $response->statusCode, $response->body);
            self::assertStringEndsWith('/' . $path, parse_url($response->url, PHP_URL_PATH) ?: '');
            self::assertSame($before, $this->consentSnapshot());
        }
    }

    private function server(): DefenseCycleHttpServer
    {
        self::assertNotNull($this->server);
        return $this->server;
    }

    /** @param array<string, mixed> $row */
    private function insertConsent(array $row): int
    {
        self::assertTrue(get_instance()->db->insert('consents', $row));
        $id = (int) get_instance()->db->insert_id();
        self::assertGreaterThan(0, $id);
        $this->ownedConsentIds[] = $id;
        return $id;
    }

    /** @return array<string, mixed> */
    private function consentRow(int $id): array
    {
        return get_instance()
            ->db->get_where('consents', ['id' => $id])
            ->row_array() ?? [];
    }

    private function consentCount(): int
    {
        return get_instance()->db->count_all('consents');
    }

    /** @return list<array<string, mixed>> */
    private function consentSnapshot(): array
    {
        $rows = get_instance()->db->get('consents')->result_array();
        usort($rows, static fn(array $left, array $right): int => strcmp(json_encode($left), json_encode($right)));
        return $rows;
    }

    private function syntheticEmail(string $case): string
    {
        return $this->fixture()->run . '_consent_' . $case . '@synthetic.invalid';
    }

    private function fixture(): DefenseCycleFixtures
    {
        self::assertNotNull($this->fixture);
        return $this->fixture;
    }

    private function cleanupConsents(): void
    {
        if (!$this->consentBaselineCaptured) {
            return;
        }
        $db = get_instance()->db;
        foreach (array_unique($this->ownedConsentIds) as $id) {
            $db->delete('consents', ['id' => $id]);
        }
        if ($this->fixture !== null) {
            $db->like('email', $this->fixture->run . '_consent_', 'after');
            $db->delete('consents');
        }
        if ($db->count_all('consents') !== $this->baselineConsentCount) {
            throw new RuntimeException('Consent fixture cleanup count mismatch.');
        }
        $this->ownedConsentIds = [];
    }
}
