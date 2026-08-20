<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;
use Tests\Support\RootHostTestPrerequisites;

final class RootHostTestPrerequisitesTest extends TestCase
{
    public function testSigkillUsesPortableNormativeNumberWhenDefinitionIsMissing(): void
    {
        self::assertSame(9, RootHostTestPrerequisites::signalNumber('SIGKILL', null));
        self::assertTrue(RootHostTestPrerequisites::signalCheck(null, true)['ok']);
        $missingPosix = RootHostTestPrerequisites::signalCheck(null, false);
        self::assertSame('process_signal_missing', $missingPosix['code']);
        self::assertSame('skip', RootHostTestPrerequisites::outcome($missingPosix, false));
        self::assertSame('fail', RootHostTestPrerequisites::outcome($missingPosix, true));
    }

    public function testRequiredProfileIsExplicitAndLocalProfileIsNotRequired(): void
    {
        self::assertTrue(RootHostTestPrerequisites::requiredProfile(['FH_ROOT_HOST_TESTS_REQUIRED' => '1']));
        self::assertFalse(RootHostTestPrerequisites::requiredProfile(['FH_ROOT_HOST_TESTS_REQUIRED' => '0']));
        self::assertFalse(RootHostTestPrerequisites::requiredProfile(['FH_ROOT_HOST_TESTS_REQUIRED' => '']));
        $missing = RootHostTestPrerequisites::classify(false, 'missing', 'missing');
        self::assertSame('skip', RootHostTestPrerequisites::outcome($missing, false));
        self::assertSame('fail', RootHostTestPrerequisites::outcome($missing, true));
        $unsafe = RootHostTestPrerequisites::classify(false, 'unsafe', 'unsafe', false);
        self::assertSame('fail', RootHostTestPrerequisites::outcome($unsafe, false));
    }

    public function testDockerChecksRemainIndependentlyClassified(): void
    {
        self::assertSame(
            'docker_binary_missing',
            RootHostTestPrerequisites::dockerBinaryCheck('/missing/docker')['code'],
        );
        $untrustedBinary = tempnam(sys_get_temp_dir(), 'untrusted-docker-');
        self::assertNotFalse($untrustedBinary);
        try {
            chmod($untrustedBinary, 0644);
            $binary = RootHostTestPrerequisites::dockerBinaryCheck($untrustedBinary);
            self::assertSame('docker_binary_untrusted', $binary['code']);
            self::assertSame('fail', RootHostTestPrerequisites::outcome($binary, false));
        } finally {
            @unlink($untrustedBinary);
        }
        self::assertSame(
            'docker_socket_missing',
            RootHostTestPrerequisites::dockerSocketCheck('/missing/docker.sock')['code'],
        );
        $regularFile = tempnam(sys_get_temp_dir(), 'not-a-docker-socket-');
        self::assertNotFalse($regularFile);
        try {
            $socket = RootHostTestPrerequisites::dockerSocketCheck($regularFile);
            self::assertSame('docker_socket_unusable', $socket['code']);
            self::assertSame('fail', RootHostTestPrerequisites::outcome($socket, false));
        } finally {
            @unlink($regularFile);
        }
        $daemon = RootHostTestPrerequisites::dockerDaemonFromResult(true, 1, '');
        self::assertSame('docker_daemon_unreachable', $daemon['code']);
        self::assertSame('fail', RootHostTestPrerequisites::outcome($daemon, false));
        self::assertTrue(RootHostTestPrerequisites::dockerDaemonFromResult(true, 0, "29.7.2\n")['ok']);
    }

    public function testCapabilityAndOwnershipSemanticsArePurelyClassified(): void
    {
        self::assertTrue(RootHostTestPrerequisites::ownershipFromIdentity(33, 33, 33, 33)['ok']);
        self::assertSame(
            'ownership_unsupported',
            RootHostTestPrerequisites::ownershipFromIdentity(0, 0, 33, 33)['code'],
        );
        self::assertTrue(RootHostTestPrerequisites::capabilitySemanticsFromExitCodes(1, 0)['ok']);
        self::assertSame(
            'capability_semantics_unsupported',
            RootHostTestPrerequisites::capabilitySemanticsFromExitCodes(0, 0)['code'],
        );
        self::assertSame(
            'capability_semantics_unsupported',
            RootHostTestPrerequisites::capabilitySemanticsFromExitCodes(1, 1)['code'],
        );
    }

    public function testZombieOrReusedPidCountsAsTerminatedButOriginalRunningPidDoesNot(): void
    {
        self::assertTrue(
            RootHostTestPrerequisites::originalProcessIsRunning(['state' => 'S', 'start_time' => '10'], '10'),
        );
        self::assertFalse(
            RootHostTestPrerequisites::originalProcessIsRunning(['state' => 'Z', 'start_time' => '10'], '10'),
        );
        self::assertFalse(
            RootHostTestPrerequisites::originalProcessIsRunning(['state' => 'S', 'start_time' => '11'], '10'),
        );
        self::assertFalse(RootHostTestPrerequisites::originalProcessIsRunning(null, '10'));
    }
}
