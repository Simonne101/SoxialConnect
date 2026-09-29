<?php

namespace Tests\Unit;

use App\Support\Logging\SecretRedactor;
use PHPUnit\Framework\TestCase;

/** Aucun secret ne doit atteindre les journaux (docs/security.md §13). */
class SecretRedactorTest extends TestCase
{
    private SecretRedactor $redactor;

    protected function setUp(): void
    {
        $this->redactor = new SecretRedactor(['password', 'access_token', 'refresh_token', 'client_secret', 'authorization']);
    }

    public function test_redacts_sensitive_keys_recursively_and_case_insensitively(): void
    {
        $out = $this->redactor->redact([
            'email' => 'marie@example.com',
            'Password' => 'secret-1234',
            'oauth' => ['access_token' => 'EAAB123', 'refresh_token' => 'r-456', 'scope' => 'pages_manage_posts'],
            'headers' => ['Authorization' => 'Bearer xyz'],
        ]);

        $this->assertSame('marie@example.com', $out['email']);
        $this->assertSame(SecretRedactor::MASK, $out['Password']);
        $this->assertSame(SecretRedactor::MASK, $out['oauth']['access_token']);
        $this->assertSame(SecretRedactor::MASK, $out['oauth']['refresh_token']);
        $this->assertSame('pages_manage_posts', $out['oauth']['scope']);
        $this->assertSame(SecretRedactor::MASK, $out['headers']['Authorization']);
    }

    public function test_redacts_secret_patterns_inside_strings(): void
    {
        $message = 'GET https://graph.example/me?access_token=EAABsecret&fields=id Authorization: Bearer abc.def-ghi '
            .'jwt=eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.dozjgNryP4J3jVmNHl0w5N_XgL0n3I9PlFUP0THsR8U '
            .'key=base64:MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=';

        $out = $this->redactor->redactString($message);

        $this->assertStringNotContainsString('EAABsecret', $out);
        $this->assertStringNotContainsString('abc.def-ghi', $out);
        $this->assertStringNotContainsString('eyJzdWIiOiIxMjM0NTY3ODkwIn0', $out);
        $this->assertStringNotContainsString('MDEyMzQ1Njc4OWFiY2RlZjAx', $out);
        $this->assertStringContainsString('fields=id', $out);
    }
}
