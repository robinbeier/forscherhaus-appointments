<?php

declare(strict_types=1);

namespace ReleaseGate;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;

final class ZeroSurpriseBreakglassValidator
{
    /**
     * @return array{ok:bool,errors:array<int, string>,normalized:array<string, mixed>}
     */
    public function validateFile(
        string $ackPath,
        string $expectedReleaseId,
        bool $disablePredeployRequested,
        bool $disableCanaryRequested,
        ?DateTimeImmutable $nowUtc = null,
    ): array {
        $errors = [];
        $normalized = [
            'ack_path' => $ackPath,
            'expected_release_id' => $expectedReleaseId,
            'disable_predeploy_requested' => $disablePredeployRequested,
            'disable_canary_requested' => $disableCanaryRequested,
        ];

        $now = $nowUtc ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $now = $now->setTimezone(new DateTimeZone('UTC'));
        $normalized['validated_at_utc'] = $now->format('c');

        if (trim($ackPath) === '') {
            $errors[] = 'Breakglass file path must not be empty.';

            return $this->buildResult($errors, $normalized);
        }

        if (!is_file($ackPath)) {
            $errors[] = 'Breakglass file not found: ' . $ackPath;

            return $this->buildResult($errors, $normalized);
        }

        if (!is_readable($ackPath)) {
            $errors[] = 'Breakglass file is not readable: ' . $ackPath;

            return $this->buildResult($errors, $normalized);
        }

        $raw = file_get_contents($ackPath);
        if (!is_string($raw)) {
            $errors[] = 'Failed to read breakglass file: ' . $ackPath;

            return $this->buildResult($errors, $normalized);
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $errors[] = 'Breakglass JSON is invalid: ' . $exception->getMessage();

            return $this->buildResult($errors, $normalized);
        }

        if (!is_array($decoded)) {
            $errors[] = 'Breakglass JSON root must be an object.';

            return $this->buildResult($errors, $normalized);
        }

        $releaseId = trim((string) ($decoded['release_id'] ?? ''));
        $ticket = trim((string) ($decoded['ticket'] ?? ''));
        $reason = trim((string) ($decoded['reason'] ?? ''));
        $approvedBy = trim((string) ($decoded['approved_by'] ?? ''));
        $expiresAtUtcRaw = trim((string) ($decoded['expires_at_utc'] ?? ''));
        $allowDisablePredeploy = filter_var(
            $decoded['allow_disable_predeploy'] ?? null,
            FILTER_VALIDATE_BOOLEAN,
            FILTER_NULL_ON_FAILURE,
        );
        $allowDisableCanary = filter_var(
            $decoded['allow_disable_canary'] ?? null,
            FILTER_VALIDATE_BOOLEAN,
            FILTER_NULL_ON_FAILURE,
        );

        if ($releaseId === '') {
            $errors[] = 'release_id must be a non-empty string.';
        } elseif ($releaseId !== $expectedReleaseId) {
            $errors[] = sprintf('release_id mismatch: expected "%s", got "%s".', $expectedReleaseId, $releaseId);
        }

        if ($ticket === '') {
            $errors[] = 'ticket must be a non-empty string.';
        }

        if ($reason === '') {
            $errors[] = 'reason must be a non-empty string.';
        }

        if ($approvedBy === '') {
            $errors[] = 'approved_by must be a non-empty string.';
        }

        if ($expiresAtUtcRaw === '') {
            $errors[] = 'expires_at_utc must be a non-empty datetime string.';
        } else {
            try {
                $expiresAtUtc = new DateTimeImmutable($expiresAtUtcRaw);
                $expiresAtUtc = $expiresAtUtc->setTimezone(new DateTimeZone('UTC'));
                $normalized['expires_at_utc'] = $expiresAtUtc->format('c');

                if ($expiresAtUtc <= $now) {
                    $errors[] = 'expires_at_utc must be in the future.';
                }
            } catch (\Exception $exception) {
                $errors[] = 'expires_at_utc is invalid: ' . $exception->getMessage();
            }
        }

        if ($allowDisablePredeploy === null) {
            $errors[] = 'allow_disable_predeploy must be a boolean.';
        }

        if ($allowDisableCanary === null) {
            $errors[] = 'allow_disable_canary must be a boolean.';
        }

        if ($disablePredeployRequested && $allowDisablePredeploy !== true) {
            $errors[] = 'Breakglass does not allow disabling the predeploy gate.';
        }

        if ($disableCanaryRequested && $allowDisableCanary !== true) {
            $errors[] = 'Breakglass does not allow disabling the postdeploy canary.';
        }

        $normalized['release_id'] = $releaseId;
        $normalized['ticket'] = $ticket;
        $normalized['reason'] = $reason;
        $normalized['approved_by'] = $approvedBy;
        $normalized['allow_disable_predeploy'] = $allowDisablePredeploy;
        $normalized['allow_disable_canary'] = $allowDisableCanary;

        return $this->buildResult($errors, $normalized);
    }

    /**
     * @param array<int, string> $errors
     * @param array<string, mixed> $normalized
     * @return array{ok:bool,errors:array<int, string>,normalized:array<string, mixed>}
     */
    private function buildResult(array $errors, array $normalized): array
    {
        return [
            'ok' => $errors === [],
            'errors' => $errors,
            'normalized' => $normalized,
        ];
    }
}
