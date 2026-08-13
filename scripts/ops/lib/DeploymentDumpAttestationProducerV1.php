<?php

declare(strict_types=1);

namespace Ops;

use RuntimeException;

require_once __DIR__ . '/DeploymentEvidenceAuthorityV1.php';

final class DeploymentDumpAttestationProducerV1
{
    public const HELPER_PATH = '/usr/local/libexec/fh/deployment_dump_attestation_v1.py';
    public const PYTHON_PATH = '/usr/bin/python3';
    public const MARIADB_IMAGE = DeploymentEvidenceAuthorityV1::DUMP_RESTORE_IMAGE;

    /** @return array{status:string,path:string,dump_sha256:string,attestation_sha256:string} */
    public static function produce(string $backupSetId): array
    {
        $created = self::createdAt($backupSetId);
        return self::produceSelected([$backupSetId], $created);
    }

    /** @return array{status:string,path:string,dump_sha256:string,attestation_sha256:string} */
    public static function produceLatestHandoff(): array
    {
        return self::produceSelected(['--latest-handoff'], null);
    }

    /**
     * @param list<string> $selector
     * @return array{status:string,path:string,dump_sha256:string,attestation_sha256:string}
     */
    private static function produceSelected(array $selector, ?string $expectedCreated): array
    {
        $helperBefore = self::trustedHelperMetadata(self::HELPER_PATH);
        $pipes = [];
        $process = proc_open(
            array_merge([self::PYTHON_PATH, '-I', '-B', self::HELPER_PATH], $selector),
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
            null,
            [],
        );
        if (!is_resource($process)) {
            throw new RuntimeException('dump attestation helper is unavailable');
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1], 16_385);
        $stderr = stream_get_contents($pipes[2], 4_097);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        if ($helperBefore !== self::trustedHelperMetadata(self::HELPER_PATH)) {
            throw new RuntimeException('dump attestation helper changed during execution');
        }
        if ($exit === 75) {
            throw new DeploymentDumpAttestationBusyV1('dump attestation production is busy');
        }
        if ($exit !== 0 || !is_string($stdout) || strlen($stdout) > 16_384 || !is_string($stderr)) {
            throw new RuntimeException('dump attestation production failed');
        }
        $response = json_decode($stdout, true);
        if (
            !is_array($response) ||
            array_keys($response) !== [
                'attestation_bytes_base64',
                'attestation_sha256',
                'dump_sha256',
                'path',
                'status',
            ] ||
            !in_array($response['status'], ['attached', 'published'], true)
        ) {
            throw new RuntimeException('dump attestation helper response is malformed');
        }
        $bytes = is_string($response['attestation_bytes_base64'])
            ? base64_decode($response['attestation_bytes_base64'], true)
            : false;
        foreach (['attestation_sha256', 'dump_sha256'] as $field) {
            if (!is_string($response[$field]) || preg_match('/^[0-9a-f]{64}$/D', $response[$field]) !== 1) {
                throw new RuntimeException('dump attestation helper digest is malformed');
            }
        }
        if (
            !is_string($bytes) ||
            !hash_equals($response['attestation_sha256'], hash('sha256', $bytes)) ||
            !is_string($response['path']) ||
            $response['path'] !== DeploymentEvidenceAuthorityV1::dumpAttestationPath($response['dump_sha256'])
        ) {
            throw new RuntimeException('dump attestation helper response is contradictory');
        }
        $record = json_decode($bytes, true);
        $recordCreated = is_array($record) ? ($record['dump']['created_at_utc'] ?? null) : null;
        if (
            !is_array($record) ||
            ($record['dump']['sha256'] ?? null) !== $response['dump_sha256'] ||
            !is_string($recordCreated)
        ) {
            throw new RuntimeException('dump attestation helper authority is contradictory');
        }
        $created = self::createdAtFromRecord($recordCreated, $expectedCreated);
        $canonical = DeploymentEvidenceAuthorityV1::validateProducedDumpAttestation(
            $bytes,
            $response['dump_sha256'],
            $record['dump']['size_bytes'] ?? 0,
            $created,
            gmdate('Y-m-d\\TH:i:s\\Z'),
        );
        if (!hash_equals($bytes, DeploymentEvidenceAuthorityV1::encodeFile($canonical))) {
            throw new RuntimeException('dump attestation helper returned non-canonical evidence');
        }
        return [
            'status' => $response['status'],
            'path' => $response['path'],
            'dump_sha256' => $response['dump_sha256'],
            'attestation_sha256' => $response['attestation_sha256'],
        ];
    }

    private static function createdAtFromRecord(string $createdAt, ?string $expectedCreated): string
    {
        if (preg_match('/^20[0-9]{2}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$/D', $createdAt) !== 1) {
            throw new RuntimeException('dump attestation created time is invalid');
        }
        $backupSetId = str_replace(['-', ':'], '', $createdAt);
        $validated = self::createdAtWithoutFreshness($backupSetId);
        if (
            !hash_equals($createdAt, $validated) ||
            ($expectedCreated !== null && !hash_equals($expectedCreated, $validated))
        ) {
            throw new RuntimeException('dump attestation created time is contradictory');
        }
        return $validated;
    }

    private static function createdAt(string $backupSetId): string
    {
        $createdAt = self::createdAtWithoutFreshness($backupSetId);
        $value = \DateTimeImmutable::createFromFormat(
            '!Y-m-d\\TH:i:s\\Z',
            $createdAt,
            new \DateTimeZone('UTC'),
        );
        if ($value === false) {
            throw new RuntimeException('backup-set ID is invalid');
        }
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        if ($value > $now) {
            throw new RuntimeException('backup-set ID is in the future');
        }
        if ($now->getTimestamp() - $value->getTimestamp() >= 14_400) {
            throw new RuntimeException('backup-set ID is stale');
        }
        return $createdAt;
    }

    private static function createdAtWithoutFreshness(string $backupSetId): string
    {
        if (preg_match('/^20[0-9]{6}T[0-9]{6}Z$/D', $backupSetId) !== 1) {
            throw new RuntimeException('backup-set ID is invalid');
        }
        $value = \DateTimeImmutable::createFromFormat('!Ymd\\THis\\Z', $backupSetId, new \DateTimeZone('UTC'));
        if ($value === false || $value->format('Ymd\\THis\\Z') !== $backupSetId) {
            throw new RuntimeException('backup-set ID is invalid');
        }
        return $value->format('Y-m-d\\TH:i:s\\Z');
    }

    /** @return array{int,int,int,int,int,int,int,int,int} */
    private static function trustedHelperMetadata(string $path): array
    {
        $current = '';
        foreach (explode('/', trim(dirname($path), '/')) as $component) {
            $current .= '/' . $component;
            $ancestor = lstat($current);
            if (
                !is_array($ancestor) ||
                is_link($current) ||
                !is_dir($current) ||
                ($ancestor['uid'] ?? -1) !== 0 ||
                (($ancestor['mode'] ?? 0) & 0022) !== 0
            ) {
                throw new RuntimeException('installed dump attestation helper ancestor is unsafe');
            }
        }
        if (is_link($path) || !is_file($path)) {
            throw new RuntimeException('installed dump attestation helper is unsafe');
        }
        $metadata = lstat($path);
        if (
            !is_array($metadata) ||
            ($metadata['uid'] ?? -1) !== 0 ||
            ($metadata['gid'] ?? -1) !== 0 ||
            (($metadata['mode'] ?? 0) & 0777) !== 0555 ||
            ($metadata['nlink'] ?? -1) !== 1 ||
            ($metadata['size'] ?? 0) <= 0
        ) {
            throw new RuntimeException('installed dump attestation helper is unsafe');
        }
        return array_map(static fn(string $field): int => $metadata[$field], [
            'dev',
            'ino',
            'mode',
            'uid',
            'gid',
            'nlink',
            'size',
            'mtime',
            'ctime',
        ]);
    }
}

final class DeploymentDumpAttestationBusyV1 extends RuntimeException {}
