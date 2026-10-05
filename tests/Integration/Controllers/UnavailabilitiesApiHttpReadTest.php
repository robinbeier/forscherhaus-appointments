<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ReleaseGate\GateHttpClient;
use Tests\Integration\Support\DefenseCycleFixtures;
use Tests\Integration\Support\DefenseCycleHttpServer;

require_once dirname(__DIR__) . '/Support/DefenseCycleFixtures.php';
require_once dirname(__DIR__) . '/Support/DefenseCycleHttpServer.php';

/** Bounded HTTP regression coverage for Unavailabilities API v1 collection reads. */
final class UnavailabilitiesApiHttpReadTest extends TestCase
{
    private ?DefenseCycleFixtures $fixture = null;
    private ?DefenseCycleHttpServer $server = null;
    private array $credentials = [];
    /** @var list<int> */
    private array $ownedIds = [];

    protected function setUp(): void
    {
        if (getenv('FH_DEFENSE_ISOLATED') !== '1') {
            self::markTestSkipped('Run with the fresh, disposable synthetic Docker stack.');
        }

        try {
            $this->fixture = new DefenseCycleFixtures();
            $this->fixture->create();
            $this->credentials = $this->fixture->enableProviderHttpAuth();
            $this->seedUnavailabilities();
            $this->server = new DefenseCycleHttpServer();
        } catch (Throwable $error) {
            $this->cleanup();
            throw $error;
        }
    }

    protected function tearDown(): void
    {
        try {
            $this->server?->close();
        } finally {
            $this->cleanup();
        }
    }

    public function testProviderProjectionPaginationAndAliasesUseBasicAndBearerReads(): void
    {
        $clients = [
            'basic' => $this->basicClient(),
            'bearer' => $this->bearerClient(),
        ];
        $paths = ['api/v1/unavailabilities', 'api/v1/unavailabilities_api_v1/index'];

        foreach ($clients as $case => $client) {
            foreach ($paths as $path) {
                $response = $client->get($path, [
                    'sort' => '-id',
                    'with' => 'provider,provider',
                    'length' => 2,
                    'page' => 1,
                ]);
                self::assertSame(200, $response->statusCode, "$case $path");
                $rows = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
                self::assertCount(2, $rows);
                self::assertSame([$this->ownedIds[1], $this->ownedIds[0]], array_column($rows, 'id'));
                self::assertSame(['id', 'firstName', 'lastName'], array_keys($rows[1]['provider']));
                self::assertIsInt($rows[1]['provider']['id']);
                self::assertSame($this->fixture?->providerId, $rows[1]['provider']['id']);
                self::assertArrayNotHasKey('email', $rows[1]['provider']);
                self::assertNull($rows[0]['provider']);

                $selected = $client->get($path, [
                    'sort' => '-id',
                    'with' => 'provider',
                    'fields' => 'id',
                    'length' => 2,
                    'page' => 1,
                ]);
                self::assertSame(200, $selected->statusCode, "$case $path selected relation");
                $selectedRows = json_decode($selected->body, true, 512, JSON_THROW_ON_ERROR);
                self::assertSame(['id', 'provider'], array_keys($selectedRows[1]));
                self::assertSame($this->fixture?->providerId, $selectedRows[1]['provider']['id']);

                foreach ([['length' => 0], ['length' => 101], ['page' => 0], ['page' => 10001]] as $query) {
                    $invalid = $client->get($path, $query);
                    self::assertSame(400, $invalid->statusCode, "$case $path invalid pagination");
                }
            }

            foreach (['api/v1/unavailabilities/', 'api/v1/unavailabilities_api_v1/show/'] as $showPath) {
                $owned = $client->get($showPath . $this->ownedIds[0], ['with' => 'provider,provider']);
                self::assertSame(200, $owned->statusCode, "$case $showPath provider");
                $ownedBody = json_decode($owned->body, true, 512, JSON_THROW_ON_ERROR);
                self::assertSame(['id', 'firstName', 'lastName'], array_keys($ownedBody['provider']));
                self::assertSame($this->fixture?->providerId, $ownedBody['provider']['id']);

                $selected = $client->get($showPath . $this->ownedIds[0], [
                    'fields' => 'id',
                    'with' => 'provider',
                ]);
                self::assertSame(200, $selected->statusCode, "$case $showPath selected relation");
                $selectedBody = json_decode($selected->body, true, 512, JSON_THROW_ON_ERROR);
                self::assertSame(['id', 'provider'], array_keys($selectedBody));
                self::assertSame($this->fixture?->providerId, $selectedBody['provider']['id']);

                $missing = $client->get($showPath . $this->ownedIds[1], ['with' => 'provider']);
                self::assertSame(200, $missing->statusCode, "$case $showPath missing provider");
                $missingBody = json_decode($missing->body, true, 512, JSON_THROW_ON_ERROR);
                self::assertNull($missingBody['provider']);
            }
        }
    }

    public function testKeywordSearchKeepsNullProviderRowsAndAppointmentIdsOnCollectionAliases(): void
    {
        foreach ([$this->basicClient(), $this->bearerClient()] as $client) {
            foreach (['api/v1/unavailabilities', 'api/v1/unavailabilities_api_v1/index'] as $path) {
                $response = $client->get($path, [
                    'q' => $this->fixture?->run,
                    'sort' => '-id',
                    'with' => 'provider',
                    'length' => 2,
                    'page' => 1,
                ]);

                self::assertSame(200, $response->statusCode, $path . ': ' . $response->body);
                $rows = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
                self::assertSame([$this->ownedIds[1], $this->ownedIds[0]], array_column($rows, 'id'));
                self::assertSame($this->ownedIds[1], $rows[0]['id']);
                self::assertNull($rows[0]['provider']);
                self::assertSame($this->fixture?->providerId, $rows[1]['provider']['id']);
                self::assertSame(['id', 'firstName', 'lastName'], array_keys($rows[1]['provider']));

                $selected = $client->get($path, [
                    'q' => $this->fixture?->run,
                    'sort' => '-id',
                    'fields' => 'id',
                    'with' => 'provider',
                    'length' => 1,
                    'page' => 2,
                ]);
                self::assertSame(200, $selected->statusCode, $path . ': ' . $selected->body);
                $selectedRows = json_decode($selected->body, true, 512, JSON_THROW_ON_ERROR);
                self::assertCount(1, $selectedRows);
                self::assertSame(['id', 'provider'], array_keys($selectedRows[0]));
                self::assertSame($this->ownedIds[0], $selectedRows[0]['id']);
                self::assertSame(['id', 'firstName', 'lastName'], array_keys($selectedRows[0]['provider']));

                $missing = $client->get($path, ['q' => 'not-found-' . $this->fixture?->run]);
                self::assertSame(200, $missing->statusCode, $path . ': ' . $missing->body);
                self::assertSame([], json_decode($missing->body, true, 512, JSON_THROW_ON_ERROR));
            }
        }
    }

    public function testCollectionAndShowRejectMissingInvalidAndProviderCredentials(): void
    {
        $clients = [
            'anonymous' => new GateHttpClient($this->server?->baseUrl),
            'invalid-bearer' => new GateHttpClient(
                $this->server?->baseUrl,
                additionalHeaders: ['Authorization' => 'Bearer ' . $this->credentials['token'] . '-invalid'],
            ),
            'provider-basic' => new GateHttpClient(
                $this->server?->baseUrl,
                additionalHeaders: [
                    'Authorization' =>
                        'Basic ' .
                        base64_encode($this->credentials['provider_username'] . ':' . $this->credentials['password']),
                ],
            ),
        ];
        $paths = [
            'api/v1/unavailabilities',
            'api/v1/unavailabilities_api_v1/index',
            'api/v1/unavailabilities/' . $this->ownedIds[0],
            'api/v1/unavailabilities_api_v1/show/' . $this->ownedIds[0],
        ];

        foreach ($clients as $case => $client) {
            foreach ($paths as $path) {
                $response = $client->get($path, ['with' => 'provider']);
                self::assertSame(401, $response->statusCode, "$case $path");
                self::assertStringNotContainsString((string) $this->fixture?->run, $response->body);
            }
        }
    }

    public function testDirectReadAliasesRejectNonGetWithoutDataOrMutation(): void
    {
        $ci = &get_instance();
        $id = $this->ownedIds[0];
        $before = $ci->db->get_where('appointments', ['id' => $id])->row_array();
        $client = $this->basicClient();

        foreach (['POST', 'PUT', 'PATCH', 'DELETE', 'HEAD'] as $method) {
            foreach (['api/v1/unavailabilities_api_v1/index', 'api/v1/unavailabilities_api_v1/show/' . $id] as $path) {
                $response = $client->requestApp($method, $path);
                self::assertSame(405, $response->statusCode, "$method $path");
                self::assertSame('GET', $response->header('allow'));
                self::assertSame('', $response->body);
                self::assertSame($before, $ci->db->get_where('appointments', ['id' => $id])->row_array());
            }
        }
    }

    public function testRepeatedProviderRelationUsesOneProjectedCollectionQuery(): void
    {
        $ci = &get_instance();
        $ci->load->model('unavailabilities_model');
        $first = $ci->unavailabilities_model->find($this->ownedIds[0]);
        $missing = $ci->unavailabilities_model->find($this->ownedIds[1]);
        $ci->unavailabilities_model->api_encode($first);
        $ci->unavailabilities_model->api_encode($missing);
        $rows = [$first, $first, $missing];

        $queryStart = count($ci->db->queries);
        $ci->unavailabilities_model->loadCollection($rows, ['provider', 'provider', 'provider']);

        self::assertSame(1, count($ci->db->queries) - $queryStart);
        self::assertSame(['id', 'firstName', 'lastName'], array_keys($rows[0]['provider']));
        self::assertSame($rows[0]['provider'], $rows[1]['provider']);
        self::assertSame($this->fixture?->providerId, $rows[0]['provider']['id']);
        self::assertNull($rows[2]['provider']);
    }

    public function testUnsupportedRelationIsRejectedForAnEmptyCollection(): void
    {
        $ci = &get_instance();
        $ci->load->model('unavailabilities_model');

        $rows = [];
        $this->expectException(InvalidArgumentException::class);
        $ci->unavailabilities_model->loadCollection($rows, ['unsupported']);
    }

    private function seedUnavailabilities(): void
    {
        $ci = &get_instance();
        $ci->load->model('unavailabilities_model');
        $this->ownedIds[] = $ci->unavailabilities_model->save([
            'start_datetime' => date('Y-m-d 11:00:00', strtotime('+14 days')),
            'end_datetime' => date('Y-m-d 11:30:00', strtotime('+14 days')),
            'location' => 'Synthetic',
            'notes' => $this->fixture?->run,
            'id_users_provider' => $this->fixture?->providerId,
        ]);

        $db = $ci->db;
        $db->insert('appointments', [
            'book_datetime' => date('Y-m-d H:i:s'),
            'start_datetime' => date('Y-m-d 12:00:00', strtotime('+14 days')),
            'end_datetime' => date('Y-m-d 12:30:00', strtotime('+14 days')),
            'location' => 'Synthetic',
            'notes' => $this->fixture?->run,
            'hash' => substr(bin2hex(random_bytes(8)), 0, 12),
            'is_unavailability' => 1,
            'id_users_provider' => null,
            'id_parent_appointment' => null,
        ]);
        $this->ownedIds[] = (int) $db->insert_id();
    }

    private function cleanup(): void
    {
        if ($this->ownedIds !== []) {
            $ci = &get_instance();
            $ci->db->where_in('id', $this->ownedIds)->delete('appointments');
            $this->ownedIds = [];
        }
        $this->fixture?->cleanup();
        $this->fixture = null;
    }

    private function basicClient(): GateHttpClient
    {
        return new GateHttpClient(
            $this->server?->baseUrl,
            additionalHeaders: [
                'Authorization' =>
                    'Basic ' .
                    base64_encode($this->credentials['admin_username'] . ':' . $this->credentials['password']),
            ],
        );
    }

    private function bearerClient(): GateHttpClient
    {
        return new GateHttpClient(
            $this->server?->baseUrl,
            additionalHeaders: ['Authorization' => 'Bearer ' . $this->credentials['token']],
        );
    }
}
