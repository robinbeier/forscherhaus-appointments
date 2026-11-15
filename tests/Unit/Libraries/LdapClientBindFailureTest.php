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

            public function assertIdentityMatches(array $before, ?array $after): bool
            {
                return $this->identityMatches($before, $after);
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

            protected function configureTimeouts(mixed $connection): bool
            {
                $this->calls[] = 'timeouts';
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

    public function testIdentityComparisonRejectsRebindingRoleAndCredentialChanges(): void
    {
        $client = $this->client();
        $before = [
            'id' => 7,
            'ldap_dn' => 'uid=old,ou=people,dc=example,dc=org',
            'id_roles' => 2,
            'credential_version' => 4,
        ];

        self::assertTrue($client->assertIdentityMatches($before, $before));
        foreach (['id', 'ldap_dn', 'id_roles', 'credential_version'] as $field) {
            $changed = $before;
            $changed[$field] = $field === 'ldap_dn' ? 'uid=new,ou=people,dc=example,dc=org' : 99;
            self::assertFalse($client->assertIdentityMatches($before, $changed), $field);
        }
        self::assertFalse($client->assertIdentityMatches($before, null));
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
            ['certificate-verification', ['connect', 'ldap.example.invalid', 389], 'timeouts', 'protocol', 'start-tls'],
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

            protected function configureTimeouts(mixed $connection): bool
            {
                $this->calls[] = 'timeouts';
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
            self::assertSame(
                ['certificate-verification', 'connect', 'timeouts', 'protocol', 'start-tls'],
                $client->calls,
            );
        }
    }

    public function testTimeoutSetupFailureIsOperationalFailureBeforeProtocolOrTls(): void
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
                return new \stdClass();
            }
            protected function configureTimeouts(mixed $connection): bool
            {
                $this->calls[] = 'timeouts';
                return false;
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

        $this->expectException(\LdapOperationalException::class);
        $this->expectExceptionMessage('Unable to configure the LDAP connection.');

        try {
            $client->assertSecureConnection('ldap.example.invalid', 389);
        } finally {
            self::assertSame(['certificate-verification', 'connect', 'timeouts'], $client->calls);
        }
    }
}
