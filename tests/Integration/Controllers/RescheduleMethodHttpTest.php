<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ReleaseGate\GateHttpClient;
use Tests\Integration\Support\DefenseCycleFixtures;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__) . '/Support/DefenseCycleFixtures.php';
require_once dirname(__DIR__) . '/Support/DefenseCycleHttpServer.php';

/** Verify that the public reschedule page is GET-only over real HTTP. */
final class RescheduleMethodHttpTest extends TestCase
{
    private ?DefenseCycleFixtures $fixture = null;
    private ?DefenseCycleHttpServer $server = null;
    private ?DefenseCycleHttpServer $peerServer = null;
    /** @var array<string, string>|null */
    private ?array $cacheBeforeTest = null;

    protected function setUp(): void
    {
        if (getenv('FH_DEFENSE_ISOLATED') !== '1') {
            self::markTestSkipped('Run with the fresh isolated synthetic stack.');
        }

        try {
            $this->fixture = new DefenseCycleFixtures();
            $this->fixture->create();
            $this->server = new DefenseCycleHttpServer();
            $this->peerServer = new DefenseCycleHttpServer();
        } catch (Throwable $error) {
            try {
                $this->peerServer?->close();
                $this->server?->close();
            } finally {
                try {
                    $this->cleanupOwnedCacheFiles();
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
            $this->peerServer?->close();
            $this->server?->close();
        } finally {
            try {
                $this->cleanupOwnedCacheFiles();
            } finally {
                $this->fixture?->cleanup();
            }
        }
    }

    public function testNonGetRescheduleMethodsCannotIssueOrReplaceAuthority(): void
    {
        $fixture = $this->fixture;
        $client = $this->server?->client();
        $peerDirectClient = $this->peerServer?->client();
        $peerRewriteClient = new GateHttpClient((string) $this->peerServer?->baseUrl, '');
        self::assertNotNull($fixture);
        self::assertNotNull($client);
        self::assertNotNull($peerDirectClient);

        $appointment = $fixture->appointment();
        $hash = rawurlencode((string) $appointment['hash']);
        $path = 'booking/reschedule/' . $hash;
        $this->cacheBeforeTest = $this->ownedCacheFiles();
        $page = $client->get($path);

        self::assertSame(200, $page->statusCode, $page->body);
        $authority = $this->authorityRow((int) $appointment['id']);
        self::assertSame((int) $appointment['id'], (int) ($authority['appointment_id'] ?? 0));
        self::assertSame($fixture->customerId, (int) ($authority['customer_id'] ?? 0));
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) ($authority['token_digest'] ?? ''));
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) ($authority['snapshot_digest'] ?? ''));
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) ($authority['context_digest'] ?? ''));
        self::assertGreaterThan(time(), strtotime((string) $authority['expires_at']));

        $vars = $this->scriptVars($page->body);
        $customerToken = (string) ($vars['customer_token'] ?? '');
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $customerToken);
        self::assertSame($fixture->customerId, $this->customerTokenValue($customerToken));

        foreach ([['HEAD', $peerRewriteClient], ['POST', $peerDirectClient]] as [$method, $requestClient]) {
            $beforeRejected = $this->ownedCacheFiles();
            $response = $requestClient->requestApp($method, $path, [], null, false);

            self::assertSame($beforeRejected, $this->ownedCacheFiles(), $method . ' must not create customer tokens.');
            self::assertSame(405, $response->statusCode, $method . ' must be rejected.');
            self::assertSame('GET', strtoupper((string) $response->header('allow')), $method . ' must advertise GET.');
            self::assertSame($authority, $this->authorityRow((int) $appointment['id']));
            self::assertSame(
                $fixture->customerId,
                $this->customerTokenValue($customerToken),
                'The customer token issued by the valid GET must remain authenticated.',
            );
            self::assertStringNotContainsString($customerToken, $response->body);
        }
    }

    public function testGetRescheduleProjectsOnlyThePublicAppointmentFields(): void
    {
        $fixture = $this->fixture;
        $client = $this->server?->client();
        self::assertNotNull($fixture);
        self::assertNotNull($client);

        $appointment = $fixture->appointment();
        $appointmentId = (int) $appointment['id'];
        $db = get_instance()->db;
        $before = $fixture->row('appointments', $appointmentId);
        $internal = [
            'id_google_calendar' => $fixture->run . '_google_internal',
            'id_caldav_calendar' => $fixture->run . '_caldav_internal',
        ];
        $withInternal = array_replace($before, $internal);

        $this->cacheBeforeTest = $this->ownedCacheFiles();

        try {
            self::assertTrue($db->where('id', $appointmentId)->update('appointments', $internal));

            $page = $client->get('booking/reschedule/' . rawurlencode((string) $appointment['hash']));

            self::assertSame(200, $page->statusCode, $page->body);
            $vars = $this->scriptVars($page->body);
            self::assertIsArray($vars['appointment_data'] ?? null);

            $projected = $vars['appointment_data'];
            $projectedKeys = array_keys($projected);
            sort($projectedKeys);
            self::assertSame(
                ['hash', 'id', 'id_services', 'id_users_provider', 'notes', 'start_datetime'],
                $projectedKeys,
                'The public reschedule page must receive only the fields used by its UI.',
            );
            self::assertSame($appointmentId, (int) $projected['id']);
            self::assertSame((string) $appointment['hash'], $projected['hash']);
            self::assertSame((int) $appointment['id_services'], (int) $projected['id_services']);
            self::assertSame((int) $appointment['id_users_provider'], (int) $projected['id_users_provider']);
            self::assertSame((string) $appointment['start_datetime'], $projected['start_datetime']);
            self::assertSame((string) $appointment['notes'], $projected['notes']);
            self::assertArrayNotHasKey('id_google_calendar', $projected);
            self::assertArrayNotHasKey('id_caldav_calendar', $projected);
            self::assertStringNotContainsString($internal['id_google_calendar'], $page->body);
            self::assertStringNotContainsString($internal['id_caldav_calendar'], $page->body);

            $customerToken = (string) ($vars['customer_token'] ?? '');
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $customerToken);
            self::assertSame($fixture->customerId, $this->customerTokenValue($customerToken));

            $authority = $this->authorityRow($appointmentId);
            self::assertSame($appointmentId, (int) ($authority['appointment_id'] ?? 0));
            self::assertSame($fixture->customerId, (int) ($authority['customer_id'] ?? 0));
            self::assertSame($withInternal, $fixture->row('appointments', $appointmentId));
        } finally {
            self::assertTrue($db->where('id', $appointmentId)->update('appointments', $before));
            self::assertSame($before, $fixture->row('appointments', $appointmentId));
        }
    }

    /** @return array<string, mixed> */
    private function authorityRow(int $appointmentId): array
    {
        return get_instance()
            ->db->get_where('reschedule_authorities', ['appointment_id' => $appointmentId])
            ->row_array() ?? [];
    }

    /** @return array<string, mixed> */
    private function scriptVars(string $body): array
    {
        self::assertSame(1, preg_match('/const vars = (\{.*?\});\s*return/s', $body, $matches));

        $vars = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($vars);

        return $vars;
    }

    private function customerTokenValue(string $token): int
    {
        $cache = get_instance()->cache ?? null;
        if (!is_object($cache) || !method_exists($cache, 'get')) {
            get_instance()->load->driver('cache', ['adapter' => 'file']);
            $cache = get_instance()->cache ?? null;
        }
        self::assertIsObject($cache);

        return (int) $cache->get('customer-token-' . $token);
    }

    /** @return array<string, string> */
    private function ownedCacheFiles(): array
    {
        $cachePath = rtrim((string) config('cache_path'), DIRECTORY_SEPARATOR);
        $customerId = $this->fixture?->customerId;
        if (!is_dir($cachePath) || $customerId === null) {
            return [];
        }

        $files = [];
        foreach (glob($cachePath . DIRECTORY_SEPARATOR . 'customer-token-*') ?: [] as $path) {
            if (!is_file($path) || is_link($path) || !preg_match('/^customer-token-[a-f0-9]{64}$/', basename($path))) {
                continue;
            }

            $raw = file_get_contents($path);
            $entry = $raw === false ? false : @unserialize($raw, ['allowed_classes' => false]);
            if (!is_array($entry) || ($entry['data'] ?? null) !== $customerId || ($entry['ttl'] ?? null) !== 600) {
                continue;
            }

            $files[$path] = hash_file('sha256', $path) ?: '';
        }

        ksort($files);
        return $files;
    }

    private function cleanupOwnedCacheFiles(): void
    {
        if ($this->cacheBeforeTest === null) {
            return;
        }

        $after = $this->ownedCacheFiles();
        foreach (array_diff_key($after, $this->cacheBeforeTest) as $path => $hash) {
            self::assertSame($hash, $this->ownedCacheFiles()[$path] ?? null);
            self::assertTrue(unlink($path), 'Could not remove test-created cache file: ' . $path);
            self::assertFalse(is_file($path), 'Test-created cache file remains: ' . $path);
        }
        foreach ($this->cacheBeforeTest as $path => $hash) {
            self::assertFileExists($path);
            self::assertSame($hash, hash_file('sha256', $path));
        }
    }
}
