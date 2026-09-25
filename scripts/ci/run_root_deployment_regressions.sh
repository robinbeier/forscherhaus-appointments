#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd -- "$SCRIPT_DIR/../.." && pwd)"
cd "$ROOT_DIR"
mkdir -p storage/logs/ci

docker pull mariadb@sha256:2f2b6bbcdbaf88afe53b76cb8d73927b623559180c5ab15db2049736f32ec590
systemd-analyze verify \
  scripts/ops/systemd/fh-session-retention.service \
  scripts/ops/systemd/fh-session-retention.timer \
  scripts/ops/systemd/fh-release-archive-dump-retention.service \
  scripts/ops/systemd/fh-release-archive-dump-retention.timer
sudo env FH_ROOT_HOST_TESTS_REQUIRED=1 php vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php \
  --log-junit storage/logs/ci/root-deployment.junit.xml \
  --log-otr storage/logs/ci/root-deployment.otr.xml \
  tests/Unit/Scripts/DeployResultReceiptStorageTest.php \
  tests/Unit/Scripts/DeployRuntimeConfigPermissionsTest.php \
  tests/Unit/Scripts/DeployRuntimeConfigRollbackTest.php \
  tests/Unit/Scripts/DeployStableResultTest.php \
  tests/Unit/Scripts/OrdinaryDeploymentCoordinationTest.php \
  tests/Unit/Scripts/GateCliSupportTest.php \
  tests/Unit/Scripts/DeploymentDumpAttestationProducerV1RootTest.php \
  tests/Unit/Scripts/BackupSetProducerRootTest.php \
  tests/Unit/Scripts/BackupTimerTransitionContractTest.php \
  tests/Unit/Scripts/ProdReleaseReadinessPreflightTest.php \
  tests/Unit/Scripts/PublishReleasePairRootTest.php \
  tests/Unit/Scripts/ReleasePairAdmissionRootTest.php \
  tests/Unit/Scripts/ReleaseArchiveDumpRetentionRootTest.php \
  tests/Unit/Scripts/SessionRetentionRootTest.php \
  tests/Unit/Scripts/ReleaseArtifactValidatorTest.php
sudo python3 -m unittest tests.Unit.Scripts.release_archive_dump_retention_v1_test
sudo python3 -B -m unittest \
  tests.Unit.Scripts.backup_handoff_admission_v1_test \
  tests.Unit.Scripts.bound_release_deploy_v1_test
