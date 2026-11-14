<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once APPPATH . 'libraries/Api.php';

/**
 * Regression coverage for the Basic-admin transaction lifecycle.
 *
 * The doubles prove commit/rollback decisions and status handling. They do
 * not prove MariaDB row-lock scheduling; that remains an integration concern.
 */
final class ApiMutationTransactionTest extends TestCase
{
    public function testSuccessfulTwoHundredResponseCommitsTheOuterTransaction(): void
    {
        $database = new ApiMutationTransactionDbDouble();
        $api = $this->apiWithTransaction($database);

        $api->trackResponseStatus(200);
        $api->finalizeMutationTransaction();

        self::assertSame(['status', 'query', 'query', 'commit'], $database->events);
        self::assertSame(0, $database->rollbacks);
    }

    public function testErrorResponseRollsBackTheOuterTransaction(): void
    {
        $database = new ApiMutationTransactionDbDouble();
        $api = $this->apiWithTransaction($database);

        $api->trackResponseStatus(500);
        $api->finalizeMutationTransaction();

        self::assertSame(['rollback'], $database->events);
        self::assertSame(0, $database->commits);
    }

    public function testMissingResponseStatusRollsBackInsteadOfCommitting(): void
    {
        $database = new ApiMutationTransactionDbDouble();
        $api = $this->apiWithTransaction($database);

        $api->finalizeMutationTransaction();

        self::assertSame(['rollback'], $database->events);
        self::assertSame(0, $database->commits);
    }

    public function testActorLockIsDeferredUntilImmediatelyBeforeCommit(): void
    {
        $database = new ApiMutationTransactionDbDouble();
        $api = (new ReflectionClass(Api::class))->newInstanceWithoutConstructor();
        $controller = $this->controllerWithDb($database);
        $controller->output = new ApiMutationTransactionOutputDouble();
        (new ReflectionProperty(Api::class, 'CI'))->setValue($api, $controller);
        (new ReflectionProperty(Api::class, 'basicActorId'))->setValue($api, 73);

        $begin = new ReflectionMethod(Api::class, 'beginBasicMutationTransaction');
        $begin->invoke($api);
        self::assertSame([], $database->queries);

        $api->trackResponseStatus(200);
        $api->finalizeMutationTransaction();

        self::assertSame(['begin', 'status', 'query', 'query', 'commit'], $database->events);
        self::assertStringContainsString('FROM ea_users WHERE id = ? FOR UPDATE', $database->queries[0]['sql']);
        self::assertStringNotContainsString('FOR UPDATE', $database->queries[1]['sql']);
        self::assertSame([73], $database->queries[0]['bindings']);
    }

    public function testDemotionBeforeFinalCommitRollsBackAndReturnsUnauthorized(): void
    {
        $database = new ApiMutationTransactionDbDouble();
        $database->actorRow = ['id' => 73, 'id_roles' => 2];
        $database->roleSlug = 'provider';
        $api = $this->apiWithTransaction($database);
        $controller = (new ReflectionProperty(Api::class, 'CI'))->getValue($api);
        $controller->output = new ApiMutationTransactionOutputDouble();

        $api->trackResponseStatus(200);
        $api->finalizeMutationTransaction();

        self::assertSame(['status', 'query', 'query', 'rollback'], $database->events);
        self::assertSame(401, $controller->output->status);
        self::assertStringContainsString('WWW-Authenticate: Basic', $controller->output->headers[0]);
        self::assertStringNotContainsString('provider', $controller->output->body);
    }

    public function testMissingActorBeforeFinalCommitRollsBackAndReturnsUnauthorized(): void
    {
        $database = new ApiMutationTransactionDbDouble();
        $database->actorRow = [];
        $api = $this->apiWithTransaction($database);
        $controller = (new ReflectionProperty(Api::class, 'CI'))->getValue($api);
        $controller->output = new ApiMutationTransactionOutputDouble();

        $api->trackResponseStatus(200);
        $api->finalizeMutationTransaction();

        self::assertSame(['status', 'query', 'rollback'], $database->events);
        self::assertSame(401, $controller->output->status);
        self::assertStringContainsString('WWW-Authenticate: Basic', $controller->output->headers[0]);
    }

    public function testBasicCustomerIdentityLockIsReleasedAfterOuterCommit(): void
    {
        $database = new ApiMutationTransactionDbDouble();
        $api = $this->apiWithTransaction($database);

        self::assertNotNull($api->holdBasicCustomerCreationLock('held@example.invalid'));
        $api->trackResponseStatus(201);
        $api->finalizeMutationTransaction();

        self::assertSame(['query', 'status', 'query', 'query', 'commit', 'query'], $database->events);
        self::assertStringContainsString('GET_LOCK', $database->queries[0]['sql']);
        self::assertStringContainsString('RELEASE_LOCK', $database->queries[3]['sql']);
    }

    private function apiWithTransaction(ApiMutationTransactionDbDouble $database): Api
    {
        $api = (new ReflectionClass(Api::class))->newInstanceWithoutConstructor();
        $controller = $this->controllerWithDb($database);

        $ciProperty = new ReflectionProperty(Api::class, 'CI');
        $ciProperty->setValue($api, $controller);

        $transactionProperty = new ReflectionProperty(Api::class, 'basicMutationTransaction');
        $transactionProperty->setValue($api, true);
        (new ReflectionProperty(Api::class, 'basicActorId'))->setValue($api, 73);

        return $api;
    }

    private function controllerWithDb(ApiMutationTransactionDbDouble $database): ApiMutationTransactionControllerDouble
    {
        return new ApiMutationTransactionControllerDouble($database);
    }
}

final class ApiMutationTransactionControllerDouble extends EA_Controller
{
    public function __construct(public ApiMutationTransactionDbDouble $db) {}

    public object $output;
}

final class ApiMutationTransactionOutputDouble
{
    public int $status = 0;
    /** @var list<string> */
    public array $headers = [];
    public string $body = '';

    public function set_status_header(int $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function set_header(string $header): self
    {
        $this->headers[] = $header;
        return $this;
    }

    public function set_content_type(string $contentType): self
    {
        return $this;
    }

    public function set_output(string $body): self
    {
        $this->body = $body;
        return $this;
    }
}

final class ApiMutationTransactionDbDouble
{
    /** @var list<string> */
    public array $events = [];
    public int $commits = 0;
    public int $rollbacks = 0;
    /** @var list<array{sql:string,bindings:array<int,mixed>}> */
    public array $queries = [];
    public array $actorRow = ['id' => 73, 'id_roles' => 1];
    public string $roleSlug = DB_SLUG_ADMIN;

    public function trans_begin(): bool
    {
        $this->events[] = 'begin';
        return true;
    }

    public function dbprefix(string $table): string
    {
        return 'ea_' . $table;
    }

    public function query(string $sql, array $bindings = []): ApiMutationTransactionQueryDouble
    {
        $this->events[] = 'query';
        $this->queries[] = ['sql' => $sql, 'bindings' => $bindings];
        if (str_contains($sql, 'GET_LOCK')) {
            return new ApiMutationTransactionQueryDouble(['acquired' => 1]);
        }
        if (str_contains($sql, 'RELEASE_LOCK')) {
            return new ApiMutationTransactionQueryDouble(['released' => 1]);
        }
        $row = str_contains($sql, 'FROM ea_users') ? $this->actorRow : ['slug' => $this->roleSlug];
        return new ApiMutationTransactionQueryDouble($row);
    }

    public function trans_status(): bool
    {
        $this->events[] = 'status';
        return true;
    }

    public function trans_commit(): bool
    {
        $this->events[] = 'commit';
        $this->commits++;
        return true;
    }

    public function trans_rollback(): bool
    {
        $this->events[] = 'rollback';
        $this->rollbacks++;
        return true;
    }

    public function trans_active(): bool
    {
        return true;
    }
}

final class ApiMutationTransactionQueryDouble
{
    public function __construct(private readonly array $row) {}

    public function row_array(): array
    {
        return $this->row;
    }

    public function row(): object
    {
        return (object) $this->row;
    }
}
