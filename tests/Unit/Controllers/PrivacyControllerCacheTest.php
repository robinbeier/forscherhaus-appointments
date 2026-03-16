<?php

namespace Tests\Unit\Controllers;

use Privacy;
use RuntimeException;
use Tests\TestCase;

require_once APPPATH . 'controllers/Privacy.php';

final class PrivacyControllerCacheTest extends TestCase
{
    private bool $hadCacheProperty = false;

    private mixed $originalCache = null;

    protected function setUp(): void
    {
        parent::setUp();

        $CI = &get_instance();
        $this->hadCacheProperty = property_exists($CI, 'cache');
        $this->originalCache = $this->hadCacheProperty ? $CI->cache : null;
    }

    protected function tearDown(): void
    {
        $CI = &get_instance();

        if ($this->hadCacheProperty) {
            $CI->cache = $this->originalCache;
        } elseif (property_exists($CI, 'cache')) {
            unset($CI->cache);
        }

        parent::tearDown();
    }

    public function testCustomerTokenCacheReturnsExistingCacheFromFrameworkInstance(): void
    {
        $cache = new class() {
            public function get(string $key): ?int
            {
                return $key === 'customer-token-known' ? 42 : null;
            }
        };

        $CI = &get_instance();
        $CI->cache = $cache;

        $controller = $this->createControllerWithLoader(function (): void {
            self::fail('Expected existing cache to avoid loader bootstrap.');
        });

        $resolved = $this->invokeCustomerTokenCache($controller);

        $this->assertSame($cache, $resolved);
        $this->assertSame($cache, $controller->cache);
    }

    public function testCustomerTokenCacheBootstrapsCacheDriverWhenMissing(): void
    {
        $cache = new class() {
            public function get(string $key): ?int
            {
                return $key === 'customer-token-known' ? 42 : null;
            }
        };

        $CI = &get_instance();
        unset($CI->cache);

        $controller = $this->createControllerWithLoader(function (Privacy $controller) use ($cache, &$CI): void {
            $controller->cache = $cache;
            $CI->cache = $cache;
        });

        $resolved = $this->invokeCustomerTokenCache($controller);

        $this->assertSame($cache, $resolved);
        $this->assertSame($cache, $CI->cache);
    }

    public function testCustomerTokenCacheReturnsNullWhenBootstrapThrows(): void
    {
        $CI = &get_instance();
        unset($CI->cache);

        $controller = $this->createControllerWithLoader(function (): void {
            throw new RuntimeException('cache bootstrap failed');
        });

        $resolved = $this->invokeCustomerTokenCache($controller);

        $this->assertNull($resolved);
        $this->assertFalse(property_exists($CI, 'cache'));
    }

    public function testCustomerTokenCacheReturnsNullWhenBootstrapDoesNotProvideGetMethod(): void
    {
        $invalidCache = new class() {
            public function save(string $key, mixed $value, int $ttl): bool
            {
                return true;
            }
        };

        $CI = &get_instance();
        unset($CI->cache);

        $controller = $this->createControllerWithLoader(function (Privacy $controller) use ($invalidCache, &$CI): void {
            $controller->cache = $invalidCache;
            $CI->cache = $invalidCache;
        });

        $resolved = $this->invokeCustomerTokenCache($controller);

        $this->assertNull($resolved);
    }

    private function createControllerWithLoader(\Closure $driverHandler): Privacy
    {
        return new class($driverHandler) extends Privacy {
            public object $load;

            public function __construct(\Closure $driverHandler)
            {
                $this->load = new class($driverHandler, $this) {
                    public function __construct(private \Closure $driverHandler, private Privacy $controller)
                    {
                    }

                    public function driver(string $driver, array $params): void
                    {
                        ($this->driverHandler)($this->controller, $driver, $params);
                    }
                };
            }
        };
    }

    private function invokeCustomerTokenCache(Privacy $controller): ?object
    {
        $method = new \ReflectionMethod(Privacy::class, 'customerTokenCache');
        $method->setAccessible(true);

        $cache = $method->invoke($controller);

        return is_object($cache) ? $cache : null;
    }
}
