# Two-connection integration tests

`Tests\Integration\Support\TwoConnectionHarness` owns two fresh CodeIgniter
connections for one synthetic test scenario. It restores `get_instance()->db`
and rolls back/closes the owned connections on success, assertions, and errors.
It rejects the existing shared connection and duplicate underlying handles.
It never grants authority or changes application access rules.

## Checkpoint and result contract

A scenario records these checkpoints in order:

1. `before_authority`: entry before the relevant permission checks.
2. `after_authority`: the initial checks have passed; the primary transaction
   may be open, but parent locking has not yet happened.
3. `after_parent_locks`: the production parent-lock method returned.
4. `before_write`: the intended production write method is about to run.
5. `before_commit`: dependent model work returned while the outer transaction
   remains active; the outer commit has not run yet.

A rejected operation has a shorter trace. Assert the exact expected trace and
an explicit branch marker reached inside the scenario. Calling `run()` with a
branch name is not proof that the branch executed. Also assert the HTTP/result
status, persisted records, and notification count where applicable. A test that
never reaches its intended checkpoint is a fixture/control-flow failure, not
successful evidence of concurrency behavior.

The callbacks themselves execute sequentially. Real database interleaving comes
from operations on the two independent database connections while their
transaction lifetimes overlap. Do not describe callback ordering alone as a
parallel DB test or as proof of an arbitrary concurrent schedule.

## Reference cases

`tests/Unit/Models/TwoConnectionWriteContractTest.php` runs with the usual Docker
PHPUnit suite and the integration coverage shard, using disposable seeded data:

- A legitimate administrative calendar update pauses after its initial
  permission checks. A second connection commits an administrative provider
  reassignment on the synthetic appointment. The original request detects the
  changed parent IDs and returns 409 before its write method. The peer change
  remains intact; the rejected request changes neither notes nor notifications.
- A positive administrative control reaches all checkpoints, persists its
  requested update and sends exactly one no-op notification. This guards
  against fixtures that merely fail before reaching the intended write path.
- A service-buffer transaction acquires real production parent locks. The peer
  tries an appointment `FOR UPDATE NOWAIT` lock and must receive MySQL 3572.
  The service update/regeneration then commits, after which the peer completes
  the real appointment reschedule and regenerates the expected buffer.

NOWAIT is the bounded wait contract for the lock-contention reference: it
produces immediate database evidence without timing sleeps. A deadlock (1213),
lock wait timeout (1205), missing fixture, or other SQL error is a test failure,
not equivalent to the expected contention result. The service scenario proves
that the peer cannot acquire the parent during resync and can reschedule after
commit; it does not claim to exercise a queued asynchronous controller request.

Existing Calendar permission tests remain responsible for ordinary 403
rejection coverage. These new references use authorized administrative actions
and test consistency/serialization, not exploit reproduction.

## Local verification

Use the repository Docker helper so the worktree has its own portless Compose
project and synthetic database. With that test stack seeded:

```bash
source scripts/ci/docker_compose_helpers.sh
ci_docker_compose exec -T php-fpm php vendor/bin/phpunit --filter TwoConnection
```

Repeat the focused command to check fixture cleanup, then run the full pre-PR
gate. `TwoConnectionHarnessContractTest` covers independent ownership, factory
failure, ordered checkpoints, explicit branch evidence, nested rollback and
cleanup failures. The lock hierarchy itself is documented in
[database-lock-order.md](database-lock-order.md).
