#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd -- "$SCRIPT_DIR/../.." && pwd)"
cd "$ROOT_DIR"

docker pull mariadb@sha256:2f2b6bbcdbaf88afe53b76cb8d73927b623559180c5ab15db2049736f32ec590
systemd-analyze verify \
  scripts/ops/systemd/fh-session-retention.service \
  scripts/ops/systemd/fh-session-retention.timer \
  scripts/ops/systemd/fh-release-archive-dump-retention.service \
  scripts/ops/systemd/fh-release-archive-dump-retention.timer \
  scripts/ops/systemd/fh-dump-producer-admission.service \
  scripts/ops/systemd/fh-dump-producer-admission.timer
sudo env FH_ROOT_HOST_TESTS_REQUIRED=1 php vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php \
  tests/Unit/Scripts/DeployResultReceiptStorageTest.php \
  tests/Unit/Scripts/DeployRuntimeConfigPermissionsTest.php \
  tests/Unit/Scripts/DeployRuntimeConfigRollbackTest.php \
  tests/Unit/Scripts/GateCliSupportTest.php \
  tests/Unit/Scripts/DeploymentHostRunnerV1RootTest.php \
  tests/Unit/Scripts/DeploymentDumpAttestationProducerV1RootTest.php \
  tests/Unit/Scripts/BackupSetProducerRootTest.php \
  tests/Unit/Scripts/PublishReleasePairRootTest.php \
  tests/Unit/Scripts/ReleaseArchiveDumpRetentionRootTest.php \
  tests/Unit/Scripts/SessionRetentionRootTest.php \
  tests/Unit/Scripts/ZeroSurpriseProductionImageCleanupRootTest.php \
  tests/Unit/Scripts/ReleaseArtifactValidatorTest.php
sudo python3 -m unittest tests.Unit.Scripts.release_archive_dump_retention_v1_test
