<?php

namespace Tests\Feature\Security;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Proxies de confiance (bootstrap/app.php) : seul le proxy local (Vite en développement derrière
 * un tunnel HTTPS, Nginx local en production) peut indiquer le schéma https via X-Forwarded-Proto.
 */
class TrustedProxyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Route::get('/_test/scheme', fn (Request $r) => response()->json([
            'secure' => $r->secure(),
            'url' => url('/chemin'),
            'ip' => $r->ip(),
        ]));
    }

    public function test_local_proxy_can_declare_https(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-For' => '203.0.113.7'])
            ->getJson('/_test/scheme')
            ->assertJsonPath('secure', true)
            ->assertJsonPath('url', fn (string $url) => str_starts_with($url, 'https://'))
            ->assertJsonPath('ip', '203.0.113.7');
    }

    public function test_ipv6_loopback_proxy_is_trusted_too(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '::1'])
            ->withHeaders(['X-Forwarded-Proto' => 'https'])
            ->getJson('/_test/scheme')
            ->assertJsonPath('secure', true);
    }

    public function test_forwarded_headers_from_any_other_address_are_ignored(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])
            ->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-For' => '203.0.113.7'])
            ->getJson('/_test/scheme')
            ->assertJsonPath('secure', false)
            ->assertJsonPath('ip', '198.51.100.20');
    }
}
