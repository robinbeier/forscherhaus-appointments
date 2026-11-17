<?php

declare(strict_types=1);

namespace Tests\Unit\Libraries;

use PHPUnit\Framework\TestCase;

/** Actual Accounts and real KDFs; database/model doubles expose reset ordering, not real SQL isolation. */
final class AccountsPasswordSnapshotTest extends TestCase
{
    public function testCommittedCredentialReplacementInvalidatesEverySuccessfulVerifierShape(): void
    {
        foreach (['legacy', 'modern'] as $format) {
            foreach (['reader', 'active'] as $phase) {
                $result = $this->probe($format, $phase, 'reset');
                self::assertFalse($result['authenticated'], $format . '/' . $phase);
                self::assertTrue($result['replacement_preserved']);
            }
        }
    }

    public function testUnchangedCredentialsAuthenticateInBothRolloutPhases(): void
    {
        foreach (['legacy', 'modern'] as $format) {
            foreach (['reader', 'active'] as $phase) {
                $result = $this->probe($format, $phase, 'stable');
                self::assertTrue($result['authenticated'], $format . '/' . $phase);
                self::assertSame(2, $result['reads']);
                self::assertSame($format === 'modern' || $phase === 'active', $result['modern']);
            }
        }
    }

    public function testUnknownAndAmbiguousUsernameStillRunPasswordVerificationWork(): void
    {
        foreach (['unknown', 'duplicate'] as $mode) {
            $result = $this->probe('legacy', 'reader', 'stable', $mode);
            self::assertFalse($result['authenticated'], $mode);
            self::assertSame(1, $result['reads'], $mode);
            // A bypass returns in microseconds; both KDFs take measurable work.
            self::assertGreaterThan(1_000_000, $result['elapsed_ns'], $mode);
        }
    }

    public function testLdapLinkedAccountNeverAuthenticatesWithRetainedLocalVerifier(): void
    {
        foreach (['legacy', 'modern'] as $format) {
            $result = $this->probe($format, 'reader', 'stable', 'ldap');
            self::assertFalse($result['authenticated'], $format);
            self::assertSame(2, $result['reads'], $format);
            self::assertTrue($result['original_preserved'], $format);
        }
    }

    public function testLdapLinkAndCredentialVersionDriftDuringVerificationInvalidatesLogin(): void
    {
        $result = $this->probe('legacy', 'reader', 'stable', 'ldap-transition');

        self::assertFalse($result['authenticated']);
        self::assertSame(2, $result['reads']);
        self::assertTrue($result['original_preserved']);
    }

    private function probe(string $format, string $phase, string $change, string $mode = ''): array
    {
        $process = proc_open(
            [
                PHP_BINARY,
                dirname(__DIR__, 2) . '/Fixtures/accounts_password_race_probe.php',
                $format,
                $phase,
                $change,
                $mode,
            ],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $error);
        return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    }
}
