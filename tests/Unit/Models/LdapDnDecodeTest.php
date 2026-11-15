<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once APPPATH . 'models/Admins_model.php';
require_once APPPATH . 'models/Providers_model.php';
require_once APPPATH . 'models/Secretaries_model.php';

final class LdapDnDecodeTest extends TestCase
{
    #[DataProvider('staffModelProvider')]
    public function testOmittedLdapDnDoesNotCarryForwardAStaleBaseValue(string $modelClass): void
    {
        $model = match ($modelClass) {
            Admins_model::class => new class extends Admins_model {
                public function __construct() {}
                public function decode(array &$resource, ?array $base = null): void
                {
                    $this->api_decode($resource, $base);
                }
            },
            Providers_model::class => new class extends Providers_model {
                public function __construct() {}
                public function decode(array &$resource, ?array $base = null): void
                {
                    $this->api_decode($resource, $base);
                }
            },
            Secretaries_model::class => new class extends Secretaries_model {
                public function __construct() {}
                public function decode(array &$resource, ?array $base = null): void
                {
                    $this->api_decode($resource, $base);
                }
            },
        };

        $resource = ['id' => 42, 'firstName' => 'Updated'];
        $model->decode($resource, ['id' => 42, 'ldap_dn' => 'uid=stale,dc=example,dc=org']);

        self::assertArrayNotHasKey('ldap_dn', $resource);
    }

    public static function staffModelProvider(): array
    {
        return [
            'admin' => [Admins_model::class],
            'provider' => [Providers_model::class],
            'secretary' => [Secretaries_model::class],
        ];
    }

    public function testExplicitLdapDnStillDecodesAsAnIntentionalRebind(): void
    {
        $model = new class extends Admins_model {
            public function __construct() {}
            public function decode(array &$resource, ?array $base = null): void
            {
                $this->api_decode($resource, $base);
            }
        };
        $resource = ['id' => 42, 'ldapDn' => 'uid=new,dc=example,dc=org'];

        $model->decode($resource, ['id' => 42, 'ldap_dn' => 'uid=old,dc=example,dc=org']);

        self::assertSame('uid=new,dc=example,dc=org', $resource['ldap_dn']);
    }
}
