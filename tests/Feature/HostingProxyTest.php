<?php

namespace Tests\Feature;

use App\Support\HostingProxy;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * HTTPS behind a shared host's reverse proxy (InfinityFree: nginx terminates HTTPS in
 * front of Apache). Configured only through TRUSTED_PROXIES / FORCE_HTTPS; with nothing
 * set (local XAMPP) forwarded headers are ignored.
 */
class HostingProxyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        TrustProxies::flushState();

        // A probe route behind the normal global middleware (TrustProxies included).
        Route::get('/_probe/request', fn (Request $request) => response()->json([
            'scheme' => $request->getScheme(),
            'secure' => $request->isSecure(),
            'host' => $request->getHost(),
            'ip' => $request->ip(),
            'url' => url('/somewhere'),
        ]));
    }

    protected function tearDown(): void
    {
        TrustProxies::flushState();
        parent::tearDown();
    }

    private const FORWARDED = [
        'X-Forwarded-Proto' => 'https',
        'X-Forwarded-Port' => '443',
        'X-Forwarded-Host' => 'attacker.example',
        'X-Forwarded-For' => '203.0.113.9',
    ];

    private function configure(array $settings): void
    {
        config(array_merge([
            'memberflow.trusted_proxies' => null,
            'memberflow.trusted_proxy_forwarded_for' => false,
            'memberflow.force_https' => false,
        ], $settings));
        HostingProxy::apply();
    }

    public function test_forwarded_headers_are_ignored_when_no_proxy_is_configured(): void
    {
        $this->configure([]);

        $probe = $this->get('/_probe/request', self::FORWARDED)->json();
        $this->assertSame('http', $probe['scheme']);
        $this->assertFalse($probe['secure']);
        $this->assertSame('127.0.0.1', $probe['ip']);
        $this->assertStringStartsWith('http://', $probe['url']);
        $this->assertStringNotContainsString('attacker.example', $probe['url']);
    }

    public function test_a_trusted_proxy_makes_requests_and_generated_urls_https(): void
    {
        $this->configure(['memberflow.trusted_proxies' => '*']);

        $probe = $this->get('/_probe/request', self::FORWARDED)->json();
        $this->assertSame('https', $probe['scheme']);
        $this->assertTrue($probe['secure']);
        $this->assertStringStartsWith('https://', $probe['url']);
    }

    public function test_forwarded_host_is_never_trusted_and_client_ip_only_when_enabled(): void
    {
        $this->configure(['memberflow.trusted_proxies' => '*']);
        $probe = $this->get('/_probe/request', self::FORWARDED)->json();
        $this->assertNotSame('attacker.example', $probe['host']);
        $this->assertStringNotContainsString('attacker.example', $probe['url']);
        $this->assertSame('127.0.0.1', $probe['ip']);

        TrustProxies::flushState();
        $this->configure(['memberflow.trusted_proxies' => '*', 'memberflow.trusted_proxy_forwarded_for' => true]);
        $probe = $this->get('/_probe/request', self::FORWARDED)->json();
        $this->assertNotSame('attacker.example', $probe['host']);
        $this->assertSame('203.0.113.9', $probe['ip']);
    }

    public function test_only_the_listed_proxy_addresses_are_trusted(): void
    {
        $this->configure(['memberflow.trusted_proxies' => '10.0.0.5, 10.0.0.6']);

        // The test client connects from 127.0.0.1, which is not in the list.
        $this->get('/_probe/request', self::FORWARDED)->assertJson(['scheme' => 'http', 'secure' => false]);
    }

    public function test_force_https_makes_generated_urls_https(): void
    {
        $this->configure(['memberflow.force_https' => true]);

        $this->assertStringStartsWith('https://', url('/somewhere'));
        $this->assertStringStartsWith('https://', route('login'));
    }

    public function test_the_login_form_posts_over_https_behind_the_proxy(): void
    {
        $this->configure(['memberflow.trusted_proxies' => '*']);

        $html = $this->get('/login', self::FORWARDED)->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#action="https://[^"/]+/login"#', $html);
        $this->assertStringNotContainsString('attacker.example', $html);
    }
}
