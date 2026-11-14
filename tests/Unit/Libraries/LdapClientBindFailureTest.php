<?php

namespace Tests\Unit\Libraries;

use Ldap_client;
use PHPUnit\Framework\TestCase;

require_once APPPATH . 'libraries/Ldap_client.php';

class LdapClientBindFailureTest extends TestCase
{
    private function client(): object
    {
        return new class extends Ldap_client {
            public function assertBindFailure(int $error_code): void
            {
                $this->handleBindFailure($error_code);
            }

            public function assertConnection(mixed $connection, bool $protocol_configured): void
            {
                $this->assertConnectionReady($connection, $protocol_configured);
            }

            public array $calls = [];

            public function assertSecureConnection(string $host, int $port): mixed
            {
                return $this->establishSecureConnection($host, $port);
            }

            protected function setTlsCertificateVerification(): bool
            {
                $this->calls[] = 'certificate-verification';
                return true;
            }

            protected function connectToServer(string $host, int $port): mixed
            {
                $this->calls[] = ['connect', $host, $port];
                return (object) ['connection' => true];
            }

            protected function configureProtocol(mixed $connection): bool
            {
                $this->calls[] = 'protocol';
                return true;
            }

            protected function startTls(mixed $connection): bool
            {
                $this->calls[] = 'start-tls';
                return true;
            }

            public function __construct() {}
        };
    }

    public function testInvalidCredentialsAreKeptAsAnAuthenticationMiss(): void
    {
        $this->client()->assertBindFailure(49);
        $this->addToAssertionCount(1);
    }

    public function testOperationalBindFailureIsSurfaced(): void
    {
        $this->expectException(\LdapOperationalException::class);
        $this->expectExceptionMessage('result code 81');

        $this->client()->assertBindFailure(81);
    }

    public function testConnectionFailureIsSurfaced(): void
    {
        $this->expectException(\LdapOperationalException::class);
        $this->expectExceptionMessage('Unable to connect to the LDAP server.');

        $this->client()->assertConnection(false, false);
    }

    public function testProtocolSetupFailureIsSurfaced(): void
    {
        $this->expectException(\LdapOperationalException::class);
        $this->expectExceptionMessage('Unable to configure the LDAP connection.');

        $this->client()->assertConnection(new \stdClass(), false);
    }

    public function testSecureConnectionRequiresStartTlsBeforeReturningTheConnection(): void
    {
        $client = $this->client();

        self::assertIsObject($client->assertSecureConnection('ldap.example.invalid', 389));
        self::assertSame(
            ['certificate-verification', ['connect', 'ldap.example.invalid', 389], 'protocol', 'start-tls'],
            $client->calls,
        );
    }

    public function testFailedStartTlsIsOperationalFailureBeforeBindCanBeAttempted(): void
    {
        $client = new class extends Ldap_client {
            public array $calls = [];

            public function assertSecureConnection(string $host, int $port): mixed
            {
                return $this->establishSecureConnection($host, $port);
            }

            protected function setTlsCertificateVerification(): bool
            {
                $this->calls[] = 'certificate-verification';
                return true;
            }

            protected function connectToServer(string $host, int $port): mixed
            {
                $this->calls[] = 'connect';
                return (object) ['connection' => true];
            }

            protected function configureProtocol(mixed $connection): bool
            {
                $this->calls[] = 'protocol';
                return true;
            }

            protected function startTls(mixed $connection): bool
            {
                $this->calls[] = 'start-tls';
                return false;
            }

            public function __construct() {}
        };

        $this->expectException(\LdapOperationalException::class);
        $this->expectExceptionMessage('Unable to establish LDAP TLS.');

        try {
            $client->assertSecureConnection('ldap.example.invalid', 389);
        } finally {
            self::assertSame(['certificate-verification', 'connect', 'protocol', 'start-tls'], $client->calls);
        }
    }
}
