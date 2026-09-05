# Root/Host Test Harness

This document defines the supported environment contract for the Linux
root/host PHPUnit tests that are included in the managed local pre-push check and in the
GitHub Actions `build-test` job. It changes only test-harness classification;
it does not change a production helper, production trust boundary, or rollout
authority.

## Profiles

### Local Docker Desktop

The supported local path is the managed `pre-push` flow on a host
with a reachable Docker Desktop daemon. The general PHPUnit suite runs as root
inside the repository's `php-fpm` container.

That container intentionally does not receive the host Docker socket or a
Docker client merely to make tests pass. Docker Desktop bind mounts may also be
unable to represent a `www-data:www-data` ownership transition even when
`chown(2)` returns success. A root/host test whose exact prerequisite cannot be
represented in this container reports one narrow PHPUnit skip before creating
its protected test roots or starting a Docker mutation. Other tests continue
to run.

This is a supported skip, not a simulated pass. The skip message names the
missing binary or socket, ownership transition, or capability behavior. An
existing but unsafe resource, an unreachable daemon behind an otherwise valid
binary/socket pair, an unexpected test result, or a failed production
invariant remains a failure.

### GitHub Actions Linux Root

The dedicated Linux-root invocation sets
`FH_ROOT_HOST_TESTS_REQUIRED=1`. In this profile every root/host prerequisite is
mandatory. A missing prerequisite fails before mutation; it never becomes a
skip. The job therefore remains the complete native-Linux proof for the exact
production paths, capabilities, Docker authority, and parent-death behavior.

## Prerequisite Boundaries

| Requirement | Required location | Classification before mutation |
| --- | --- | --- |
| PHP POSIX process support and `proc_open` | PHP test runtime | Missing: local precise skip; required profile fail |
| `SIGKILL` | Linux PHP test runtime | Use the runtime constant when defined; otherwise Linux signal number `9`; still kill the real wrapper and observe child death |
| `/usr/bin/python3` and `/usr/bin/setpriv` | Linux root-test environment | Missing: local precise skip; required profile fail |
| `/usr/bin/docker` | Linux host-test environment | Missing: local precise skip; required profile fail. Existing non-executable or untrusted metadata: fail. No `PATH` fallback |
| `/var/run/docker.sock` | Linux host-test environment | Missing: local precise skip; required profile fail. Existing wrong type or unusable permissions: fail |
| Docker daemon | Behind the exact binary/socket pair | A failed daemon probe is distinct from a missing binary or socket and always fails |
| `www-data` ownership transition | Filesystem that will hold the fixed test roots | Probe in a unique temporary leaf and remove it before the protected roots are created |
| `CAP_DAC_OVERRIDE`/`CAP_FOWNER` semantics | Kernel and `setpriv` execution profile | Prove the required negative and positive operations; different semantics are local unsupported-profile skips or required-profile failures |

The Docker checks are intentionally ordered: binary, socket, then daemon. A
later check cannot convert an earlier failure into success. Tests never select
another Docker executable, inject a fake socket, soften the trusted-path
assertion, or replace a real daemon operation with a stub.

## Security Invariants

- Production helpers and their fixed paths are unchanged.
- The dump-attestation parent-death test still kills the real PHP wrapper and
  requires the bound Python child to disappear.
- The Zero-Surprise executable test still calls the production helper's exact
  `/usr/bin/docker` trust check when that path exists.
- Session-mode normalization still proves that `CAP_DAC_OVERRIDE` alone is
  insufficient and that the approved one-time boundary additionally requires
  `CAP_FOWNER`.
- Session and application-log retention keep their production ownership,
  mode, identity, link, lock, and deletion assertions.
- A skip never authorizes production execution and never substitutes for the
  required GitHub Actions Linux-root job.

## Validation

Managed local gates:

```bash
git commit
git push
```

Focused diagnosis without changing the classification profile:

```bash
docker compose run --rm --no-deps php-fpm \
  php vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php \
  tests/Unit/Scripts/RootHostTestPrerequisitesTest.php
```

Do not use `SKIP_PRECOMMIT=1` or `SKIP_PREPUSH=1` to bypass a failed
prerequisite. Fix the supported host setup or investigate the reported
classification. Repository delivery, local green hooks, and green CI do not
authorize SSH, installation, cleanup, retention, service/timer activation, or
any other production action.
