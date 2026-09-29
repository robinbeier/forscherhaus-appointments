<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Tests\Integration\Support\DefenseCycleFixtures;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__) . '/Support/DefenseCycleFixtures.php';
require_once dirname(__DIR__) . '/Support/DefenseCycleHttpServer.php';

/** Verify that anonymous reschedule responses carry application-owned no-store headers. */
final class RescheduleCacheHttpTest extends TestCase
{
    private ?DefenseCycleFixtures $fixture = null;
    private ?DefenseCycleHttpServer $server = null;

    protected function setUp(): void
    {
        if (getenv('FH_DEFENSE_ISOLATED') !== '1') {
            self::markTestSkipped('Run with the fresh isolated synthetic stack.');
        }

        try {
            $this->fixture = new DefenseCycleFixtures();
            $this->fixture->create();
            $this->server = new DefenseCycleHttpServer(disableSessionCacheLimiter: true);
        } catch (Throwable $error) {
            try {
                $this->server?->close();
            } finally {
                $this->fixture?->cleanup();
            }
            throw $error;
        }
    }

    protected function tearDown(): void
    {
        try {
            $this->server?->close();
        } finally {
            $this->fixture?->cleanup();
        }
    }

    public function testRescheduleResponsesAreNoStoreForValidLockedAndUnknownHashes(): void
    {
        $fixture = $this->fixture;
        $client = $this->server?->client();
        self::assertNotNull($fixture);
        self::assertNotNull($client);

        $valid = $fixture->appointment();
        $locked = $fixture->appointment();
        $this->moveAppointmentNearCutoff((int) $locked['id']);
        self::assertTrue(
            get_instance()
                ->db->where('name', 'book_advance_timeout')
                ->update('settings', ['value' => '60']),
        );

        $validBefore = $fixture->row('appointments', (int) $valid['id']);
        $lockedBefore = $fixture->row('appointments', (int) $locked['id']);
        $customerBefore = $fixture->row('users', $fixture->customerId);

        $validResponse = $client->get('booking/reschedule/' . rawurlencode((string) $valid['hash']));
        self::assertSame(200, $validResponse->statusCode);
        self::assertSame('no-store', strtolower((string) $validResponse->header('cache-control')));
        self::assertStringContainsString('manage_mode', $validResponse->body);
        self::assertStringContainsString($fixture->run, $validResponse->body);
        self::assertSame($validBefore, $fixture->row('appointments', (int) $valid['id']));
        self::assertSame($customerBefore, $fixture->row('users', $fixture->customerId));

        $lockedResponse = $client->get('booking/reschedule/' . rawurlencode((string) $locked['hash']));
        self::assertSame(200, $lockedResponse->statusCode);
        self::assertSame('no-store', strtolower((string) $lockedResponse->header('cache-control')));
        self::assertStringContainsString(lang('appointment_locked'), $lockedResponse->body);
        self::assertStringNotContainsString('manage_mode', $lockedResponse->body);
        self::assertSame($lockedBefore, $fixture->row('appointments', (int) $locked['id']));
        self::assertSame($customerBefore, $fixture->row('users', $fixture->customerId));

        $invalidResponse = $client->get('booking/reschedule/' . rawurlencode($fixture->run . '-missing-hash'));
        self::assertSame(200, $invalidResponse->statusCode);
        self::assertSame('no-store', strtolower((string) $invalidResponse->header('cache-control')));
        self::assertStringContainsString(lang('appointment_not_found'), $invalidResponse->body);
        self::assertStringNotContainsString('manage_mode', $invalidResponse->body);
        self::assertSame($validBefore, $fixture->row('appointments', (int) $valid['id']));
        self::assertSame($lockedBefore, $fixture->row('appointments', (int) $locked['id']));
        self::assertSame($customerBefore, $fixture->row('users', $fixture->customerId));
    }

    private function moveAppointmentNearCutoff(int $appointmentId): void
    {
        $start = new DateTimeImmutable('+5 minutes', new DateTimeZone('UTC'));
        self::assertTrue(
            get_instance()
                ->db->where('id', $appointmentId)
                ->update('appointments', [
                    'start_datetime' => $start->format('Y-m-d H:i:s'),
                    'end_datetime' => $start->modify('+30 minutes')->format('Y-m-d H:i:s'),
                ]),
        );
    }
}
