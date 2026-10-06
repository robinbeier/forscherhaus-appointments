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
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('result code 81');

        $this->client()->assertBindFailure(81);
    }

    public function testConnectionFailureIsSurfaced(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unable to connect to the LDAP server.');

        $this->client()->assertConnection(false, false);
    }

    public function testProtocolSetupFailureIsSurfaced(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unable to configure the LDAP connection.');

        $this->client()->assertConnection(new \stdClass(), false);
    }
}
