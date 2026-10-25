<?php

namespace Tests\Unit;

use App\Domain\Network\RestTarget;
use App\Domain\Network\RouterOsDuration;
use PHPUnit\Framework\TestCase;

class RestTargetTest extends TestCase
{
    public function test_forbidden_ips(): void
    {
        foreach (['127.0.0.1', '0.0.0.0', '169.254.169.254', '240.0.0.1', '::1', 'fe80::1', 'pas-une-ip'] as $ip) {
            $this->assertTrue(RestTarget::isForbiddenIp($ip, true), $ip);
            $this->assertTrue(RestTarget::isForbiddenIp($ip, false), $ip);
        }
        foreach (['10.1.2.3', '172.16.5.5', '192.168.1.1', '100.64.1.1', '100.127.255.254'] as $ip) {
            $this->assertTrue(RestTarget::isForbiddenIp($ip, false), $ip);
            $this->assertFalse(RestTarget::isForbiddenIp($ip, true), $ip);
        }
        foreach (['41.203.1.10', '8.8.8.8', '100.63.255.255', '100.128.0.1'] as $ip) {
            $this->assertFalse(RestTarget::isForbiddenIp($ip, false), $ip);
        }
    }

    public function test_host_syntax(): void
    {
        foreach (['41.203.1.10', 'routeur.example.com', 'a-b.example.ne'] as $h) {
            $this->assertTrue(RestTarget::isValidHostSyntax($h), $h);
        }
        foreach (['localhost', 'http://x.com', 'x.com:443', 'x.com/path', 'user@x.com', '-bad.com', 'x', '[::1]', '', "x.com\n", 'a b.com'] as $h) {
            $this->assertFalse(RestTarget::isValidHostSyntax($h), $h);
        }
    }

    public function test_ports(): void
    {
        $deny = [22, 5432];
        $this->assertTrue(RestTarget::isAllowedPort(443, $deny));
        $this->assertTrue(RestTarget::isAllowedPort(8729, $deny));
        $this->assertFalse(RestTarget::isAllowedPort(5432, $deny));
        $this->assertFalse(RestTarget::isAllowedPort(0, $deny));
        $this->assertFalse(RestTarget::isAllowedPort(70000, $deny));
    }

    public function test_routeros_durations(): void
    {
        $this->assertSame(93784, RouterOsDuration::parse('1d2h3m4s'));
        $this->assertSame(1800, RouterOsDuration::parse('00:30:00'));
        $this->assertSame(604800, RouterOsDuration::parse('1w'));
        $this->assertSame(0, RouterOsDuration::parse(null));
        $this->assertSame('1h30m', RouterOsDuration::formatMinutes(90));
        $this->assertSame('45m', RouterOsDuration::formatMinutes(45));
        $this->assertSame('2h', RouterOsDuration::formatMinutes(120));
    }
}
