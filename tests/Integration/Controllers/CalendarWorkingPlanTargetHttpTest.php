<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ReleaseGate\GateHttpClient;
use Tests\Integration\Support\DefenseCycleFixtures;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__) . '/Support/DefenseCycleFixtures.php';
require_once dirname(__DIR__) . '/Support/DefenseCycleHttpServer.php';

/** Regression coverage for provider-only working-plan exception deletion. */
final class CalendarWorkingPlanTargetHttpTest extends TestCase
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
            $this->server = new DefenseCycleHttpServer();
        } catch (Throwable $error) {
            $this->server?->close();
            $this->fixture?->cleanup();
            throw $error;
        }
    }

    protected function tearDown(): void
    {
        try {
            $this->server?->close();
            if ($this->fixture !== null) {
                get_instance()->db->delete('user_settings', ['id_users' => $this->fixture->customerId]);
            }
        } finally {
            $this->fixture?->cleanup();
        }
    }

    public function testDeleteRejectsNonProviderAndUnknownTargetsThroughCanonicalAndDirectAlias(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);

        $date = '2035-08-11';
        $this->seedWorkingPlanException($date);
        $this->seedCustomerWorkingPlanException($date);
        $before = [
            'provider' => $this->settingsFor($fixture->providerId),
            'customer' => $this->settingsFor($fixture->customerId),
        ];
        $client = $this->authenticatedClient();
        $aliasClient = $this->authenticatedClient(false);
        $cases = [
            'customer_matching_date' => [$fixture->customerId, $date],
            'customer_missing_date' => [$fixture->customerId, '2035-08-12'],
            'nonexistent_id' => [$this->nonexistentUserId(), $date],
        ];

        foreach (
            [
                [$client, 'calendar/delete_working_plan_exception'],
                [$aliasClient, 'backend_api/ajax_delete_working_plan_exception'],
            ]
            as $clientAndPath
        ) {
            [$requestClient, $path] = $clientAndPath;
            foreach ($cases as $name => [$providerId, $caseDate]) {
                $response = $requestClient->post($path, [
                    'provider_id' => $providerId,
                    'date' => $caseDate,
                ]);
                if ($path === 'backend_api/ajax_delete_working_plan_exception') {
                    self::assertSame(303, $response->statusCode, $path . ' ' . $name . ': ' . $response->body);
                    self::assertStringEndsWith(
                        '/calendar/delete_working_plan_exception',
                        (string) $response->header('location'),
                        $path . ' ' . $name,
                    );
                } else {
                    self::assertGreaterThanOrEqual(
                        400,
                        $response->statusCode,
                        $path . ' ' . $name . ': ' . $response->body,
                    );
                    self::assertLessThan(
                        500,
                        $response->statusCode,
                        $path . ' ' . $name . ' returned a server error: ' . $response->body,
                    );
                }
                self::assertSame(
                    $before['provider'],
                    $this->settingsFor($fixture->providerId),
                    $path . ' ' . $name . ' mutated provider settings.',
                );
                self::assertSame(
                    $before['customer'],
                    $this->settingsFor($fixture->customerId),
                    $path . ' ' . $name . ' mutated customer settings.',
                );
            }
        }
    }

    public function testDeleteStillSucceedsForProviderThroughCanonicalAndDirectAlias(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $date = '2035-08-13';
        $this->seedWorkingPlanException($date);
        $before = $this->providerSettings();

        $client = $this->authenticatedClient();
        $aliasClient = $this->authenticatedClient(false);
        $response = $client->post('calendar/delete_working_plan_exception', [
            'provider_id' => $fixture->providerId,
            'date' => $date,
        ]);
        self::assertSame(200, $response->statusCode, $response->body);
        $payload = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue((bool) ($payload['success'] ?? false), $response->body);
        self::assertArrayNotHasKey($date, $this->providerSettings()['working_plan_exceptions']);
        self::assertNotSame($before, $this->providerSettings());

        $this->seedWorkingPlanException($date);
        $before = $this->providerSettings();
        $response = $aliasClient->post('backend_api/ajax_delete_working_plan_exception', [
            'provider_id' => $fixture->providerId,
            'date' => $date,
        ]);
        self::assertSame(303, $response->statusCode, $response->body);
        self::assertStringEndsWith('/calendar/delete_working_plan_exception', (string) $response->header('location'));
        self::assertSame($before, $this->providerSettings());
        /*
         * The legacy alias is a redirect entrypoint; the canonical POST above
         * proves the legitimate provider delete remains successful.
         */
    }

    private function authenticatedClient(bool $followRedirects = true): GateHttpClient
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $client = $followRedirects
            ? $this->server?->client()
            : new GateHttpClient($this->server->baseUrl, additionalHeaders: ['X-FH-Test' => 'rob-799']);
        self::assertNotNull($client);
        self::assertSame(200, $client->get('login')->statusCode);
        $response = $client->post('login/validate', [
            'username' => $fixture->run . '_actor',
            'password' => $fixture->password,
        ]);
        self::assertSame(200, $response->statusCode, $response->body);
        self::assertTrue((bool) (json_decode($response->body, true, 512, JSON_THROW_ON_ERROR)['success'] ?? false));
        return $client;
    }

    private function seedWorkingPlanException(string $date): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        get_instance()->load->model('providers_model');
        get_instance()->providers_model->save_working_plan_exception($fixture->providerId, $date, [
            'start' => '09:00',
            'end' => '11:00',
            'breaks' => [],
        ]);
    }

    /** @return array{working_plan:string,working_plan_exceptions:array<string,mixed>} */
    private function providerSettings(): array
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        return $this->settingsFor($fixture->providerId);
    }

    /** @return array{working_plan:string,working_plan_exceptions:array<string,mixed>} */
    private function settingsFor(int $userId): array
    {
        $row = $this->fixture?->userSettingsRow($userId) ?? [];
        return [
            'working_plan' => (string) ($row['working_plan'] ?? ''),
            'working_plan_exceptions' => json_decode((string) ($row['working_plan_exceptions'] ?? '{}'), true) ?: [],
        ];
    }

    private function seedCustomerWorkingPlanException(string $date): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $row = $fixture->userSettingsRow($fixture->providerId);
        self::assertNotEmpty($row);
        unset($row['id']);
        $row['id_users'] = $fixture->customerId;
        $row['username'] = $fixture->run . '_customer';
        $row['working_plan_exceptions'] = json_encode(
            [
                $date => ['start' => '09:00', 'end' => '11:00', 'breaks' => []],
            ],
            JSON_THROW_ON_ERROR,
        );
        self::assertTrue((bool) get_instance()->db->insert('user_settings', $row));
        self::assertArrayHasKey($date, $this->settingsFor($fixture->customerId)['working_plan_exceptions']);
    }

    private function nonexistentUserId(): int
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $max = get_instance()->db->select_max('id')->get('users')->row_array()['id'] ?? 0;
        return max((int) $max, $fixture->actorId, $fixture->providerId, $fixture->customerId) + 1000;
    }
}
