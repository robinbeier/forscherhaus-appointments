<?php

namespace Tests\Unit\Helper;

use PHPUnit\Framework\TestCase;

require_once APPPATH . 'helpers/rate_limit_helper.php';

final class RateLimitHelperTest extends TestCase
{
    public function testLoopbackBypassOnlyAppliesToExplicitLocalHosts(): void
    {
        $this->assertTrue(rate_limit_is_local_loopback_request('127.0.0.1', 'localhost'));
        $this->assertTrue(rate_limit_is_local_loopback_request('127.0.0.1', 'localhost:8080'));
        $this->assertTrue(rate_limit_is_local_loopback_request('127.0.0.1', '127.0.0.1:3000'));
        $this->assertTrue(rate_limit_is_local_loopback_request('::1', '::1'));
        $this->assertTrue(rate_limit_is_local_loopback_request('::1', '[::1]'));
        $this->assertTrue(rate_limit_is_local_loopback_request('::1', '[::1]:8080'));
        $this->assertFalse(rate_limit_is_local_loopback_request('127.0.0.1', 'dasforscherhaus-leg.de'));
        $this->assertFalse(rate_limit_is_local_loopback_request('203.0.113.10', 'localhost'));
    }
}
