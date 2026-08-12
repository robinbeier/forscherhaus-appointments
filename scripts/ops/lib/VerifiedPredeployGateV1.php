<?php

declare(strict_types=1);

namespace Ops;

use RuntimeException;

/**
 * Non-constructible carrier for sections created by the privileged authority
 * verifiers. Arrays exposed by section() are output only; they cannot be fed
 * back into the ordered assembler as authority.
 */
final class VerifiedPredeployGateV1
{
    /** @param array<string,mixed> $section */
    private function __construct(
        private readonly string $gate,
        private readonly string $runId,
        private readonly string $intentSha256,
        private readonly array $section,
    ) {}

    /** @param array<string,mixed> $section */
    private static function issue(
        object $issuer,
        string $gate,
        string $runId,
        string $intentSha256,
        array $section,
    ): self {
        if (!$issuer instanceof DeploymentEvidenceAuthorityV1Issuer) {
            throw new RuntimeException('predeploy gate result lacks authority issuer');
        }
        return new self($gate, $runId, $intentSha256, $section);
    }

    /** @param array<string,mixed> $section */
    public static function issueForAuthority(
        object $issuer,
        string $gate,
        string $runId,
        string $intentSha256,
        array $section,
    ): self {
        return self::issue($issuer, $gate, $runId, $intentSha256, $section);
    }

    public function gate(): string
    {
        return $this->gate;
    }
    public function runId(): string
    {
        return $this->runId;
    }
    public function intentSha256(): string
    {
        return $this->intentSha256;
    }

    /** @return array<string,mixed> */
    public function section(): array
    {
        return $this->section;
    }

    private function __clone() {}

    public function __serialize(): array
    {
        throw new RuntimeException('predeploy gate authority is not serializable');
    }

    public function __unserialize(array $data): void
    {
        throw new RuntimeException('predeploy gate authority is not serializable');
    }
}

/** @internal Only DeploymentEvidenceAuthorityV1 may instantiate this issuer. */
final class DeploymentEvidenceAuthorityV1Issuer
{
    private function __construct() {}

    public static function forAuthority(string $caller): self
    {
        if ($caller !== DeploymentEvidenceAuthorityV1::class) {
            throw new RuntimeException('predeploy authority issuer is private');
        }
        return new self();
    }
}
