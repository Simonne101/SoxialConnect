<?php

namespace Tests\Feature\Security;

use Tests\TestCase;

/** CORS strict (jamais « * ») et en-têtes de sécurité (docs/security.md §11). */
class CorsAndHeadersTest extends TestCase
{
    public function test_preflight_from_spa_origin_is_allowed_with_credentials(): void
    {
        $this->call('OPTIONS', '/api/v1/auth/login', [], [], [], [
            'HTTP_ORIGIN' => 'http://localhost:5173',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type,x-xsrf-token',
        ])
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173')
            ->assertHeader('Access-Control-Allow-Credentials', 'true');
    }

    public function test_unknown_origin_is_not_allowed(): void
    {
        $response = $this->call('OPTIONS', '/api/v1/auth/login', [], [], [], [
            'HTTP_ORIGIN' => 'https://evil.example',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ]);

        $this->assertNotSame('https://evil.example', $response->headers->get('Access-Control-Allow-Origin'));
        $this->assertNotSame('*', $response->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_security_headers_and_request_id(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', (string) $response->headers->get('X-Request-Id'));
    }

    public function test_csrf_cookie_endpoint_sets_xsrf_cookie(): void
    {
        $this->withHeaders(['Origin' => 'http://localhost:5173', 'Referer' => 'http://localhost:5173/'])
            ->get('/sanctum/csrf-cookie')
            ->assertNoContent()
            ->assertCookie('XSRF-TOKEN');
    }
}
