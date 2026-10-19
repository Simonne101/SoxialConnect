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

    public function test_redacts_telegram_bot_tokens_google_tokens_and_signed_url_signatures(): void
    {
        $bot = '123456789:AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw';
        $message = "cURL error 28 for https://api.telegram.org/bot{$bot}/getWebhookInfo ; token seul {$bot} "
            .'ya29.a0AfB_byC-access-token-value refresh 1//0gAbCdEfGhIjKlMnOpQrStUvWx '
            .'https://app.test/api/v1/media/abc/file/original?expires=1791969587&signature=9f8e7d6c5b4a39281706 '
            .'X-Telegram-Bot-Api-Secret-Token: webhook-secret-value';

        $out = $this->redactor->redactString($message);

        foreach ([$bot, 'AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw', 'ya29.a0AfB_byC', '1//0gAbCdEfGhIj', '9f8e7d6c5b4a39281706', 'webhook-secret-value'] as $secret) {
            $this->assertStringNotContainsString($secret, $out);
        }
        $this->assertStringContainsString('https://api.telegram.org/bot[REDACTED]/getWebhookInfo', $out, 'L\'URL reste lisible, sans le jeton.');
        $this->assertStringContainsString('expires=1791969587', $out);
    }

    public function test_redacts_json_serialized_secrets_but_keeps_api_error_codes(): void
    {
        $out = $this->redactor->redactString('{"access_token":"EAAB\"quoted","refresh_token":"r-1","code":"NONEXISTENT_VERSION","token_type":"Bearer"}');

        $this->assertStringNotContainsString('EAAB', $out);
        $this->assertStringNotContainsString('r-1', $out);
        $this->assertStringContainsString('"code":"NONEXISTENT_VERSION"', $out, 'Code d\'erreur d\'API conservé pour le diagnostic.');
        $this->assertStringContainsString('"token_type"', $out);
    }

    public function test_exceptions_and_nested_previous_exceptions_become_redacted_arrays(): void
    {
        $root = new \RuntimeException('Échec https://api.telegram.org/bot987654321:AAFakeTokenValueForTesting123456/sendMessage');
        $middle = new \LogicException('Refresh refusé : {"refresh_token":"1//secret-refresh-value-xyz-123456"}', 0, $root);
        $top = new \RuntimeException('Appel échoué avec Authorization: Bearer ya29.top-secret-token', 7, $middle);

        $out = $this->redactor->redact(['exception' => $top, 'user_id' => 42]);

        $this->assertIsArray($out['exception'], 'Jamais l\'objet : le formateur l\'écrirait en clair.');
        $this->assertSame([\RuntimeException::class, 7, \LogicException::class, \RuntimeException::class], [
            $out['exception']['class'], $out['exception']['code'], $out['exception']['previous']['class'], $out['exception']['previous']['previous']['class'],
        ]);
        $json = json_encode($out);
        foreach (['ya29.top-secret-token', 'secret-refresh-value', 'AAFakeTokenValueForTesting123456'] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
        $this->assertStringContainsString('sendMessage', $json);
        $this->assertSame(42, $out['user_id']);
        $this->assertStringNotContainsString('ya29.top-secret-token', $this->redactor->redactThrowableString($top));
    }
}
