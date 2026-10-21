<?php

namespace Tests\Feature\Security;

use App\Domain\Social\Telegram\TelegramApiException;
use App\Domain\Social\Telegram\TelegramBotClient;
use App\Support\Logging\RedactSecretsProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Monolog\Formatter\JsonFormatter;
use RuntimeException;
use Tests\TestCase;

/**
 * Aucun secret dans ce qui est réellement ÉCRIT (fichiers de journaux en texte et en JSON, failed_jobs) — y
 * compris dans le message, la pile et les exceptions précédentes d'une exception rapportée (docs/security.md
 * §13, audit étape 8, lot 1). Les fichiers de test sont créés dans storage/framework/testing puis retirés.
 */
class LogRedactionTest extends TestCase
{
    use RefreshDatabase;

    private const BOT_TOKEN = '123456789:AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw';

    private const GOOGLE_TOKEN = 'ya29.a0AfB_test-access-token-ne-doit-jamais-sortir';

    private const REFRESH = '1//0gTestRefreshTokenNeDoitJamaisSortir';

    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            File::delete($file);
        }
        parent::tearDown();
    }

    /** Canal de sonde : même configuration que les canaux réels (tap d'expurgation), fichier temporaire. */
    private function probe(bool $json): string
    {
        $path = storage_path('framework/testing/log-probe-'.Str::random(8).'.log');
        File::ensureDirectoryExists(dirname($path));
        $this->files[] = $path;
        config(['logging.channels.probe' => array_filter([
            'driver' => 'single',
            'path' => $path,
            'level' => 'debug',
            'formatter' => $json ? JsonFormatter::class : null,
            'tap' => [RedactSecretsProcessor::class],
        ])]);
        Log::forgetChannel('probe');

        return $path;
    }

    private function secretException(): RuntimeException
    {
        $network = new ConnectionException('cURL error 28 for https://api.telegram.org/bot'.self::BOT_TOKEN.'/getWebhookInfo');
        $refresh = new RuntimeException('Refus Google : {"refresh_token":"'.self::REFRESH.'"}', 0, $network);

        return new RuntimeException('Appel échoué : Authorization: Bearer '.self::GOOGLE_TOKEN, 0, $refresh);
    }

    private function assertNoSecret(string $written): void
    {
        foreach ([self::BOT_TOKEN, 'AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw', self::GOOGLE_TOKEN, 'test-access-token-ne-doit', self::REFRESH, 'TestRefreshToken'] as $secret) {
            $this->assertStringNotContainsString($secret, $written);
        }
    }

    public function test_text_and_json_log_files_never_contain_secrets_from_messages_contexts_or_nested_exceptions(): void
    {
        foreach ([false, true] as $json) {
            $path = $this->probe($json);
            Log::channel('probe')->error('Échec vers https://api.telegram.org/bot'.self::BOT_TOKEN.'/sendMessage', [
                'exception' => $this->secretException(),
                'oauth' => ['access_token' => self::GOOGLE_TOKEN, 'scope' => 'youtube.readonly'],
                'url' => 'https://example.test/cb?code=4/0AY0e-g7oauth-code&state=abc',
            ]);

            $written = File::get($path);
            $this->assertNoSecret($written);
            $this->assertStringNotContainsString('4/0AY0e-g7oauth-code', $written);
            // Le diagnostic reste exploitable : classes, chaîne d'exceptions, méthodes, portée.
            $this->assertStringContainsString('ConnectionException', $written);
            $this->assertStringContainsString('getWebhookInfo', $written);
            $this->assertStringContainsString('youtube.readonly', $written);
        }
    }

    public function test_an_exception_reported_by_the_handler_is_written_without_secrets(): void
    {
        $path = $this->probe(true);
        config(['logging.default' => 'probe']);
        Log::forgetChannel();

        report($this->secretException());

        $written = File::get($path);
        $this->assertNotSame('', $written);
        $this->assertNoSecret($written);
        $this->assertStringContainsString('RuntimeException', $written);
    }

    public function test_a_telegram_network_failure_never_carries_the_bot_token(): void
    {
        config(['services.telegram.bot_token' => self::BOT_TOKEN]);
        Http::fake(fn () => throw new ConnectionException('cURL error 28: timed out for https://api.telegram.org/bot'.self::BOT_TOKEN.'/getMe'));

        try {
            app(TelegramBotClient::class)->call('getMe');
            $this->fail('Une TelegramApiException était attendue.');
        } catch (TelegramApiException $e) {
            $this->assertStringNotContainsString(self::BOT_TOKEN, $e->getMessage());
            $this->assertNull($e->getPrevious(), 'L\'exception d\'origine (URL avec jeton) n\'est pas chaînée.');
        }
    }

    public function test_failed_jobs_store_a_redacted_exception_and_an_untouched_payload(): void
    {
        $payload = json_encode(['uuid' => (string) Str::uuid(), 'displayName' => 'App\\Jobs\\PublishPostVariantJob', 'data' => ['variantId' => 'v-1']]);

        app('queue.failer')->log('redis', 'publishing', $payload, $this->secretException());

        $row = DB::table('failed_jobs')->sole();
        $this->assertNoSecret($row->exception);
        $this->assertStringContainsString('RuntimeException', $row->exception);
        $this->assertSame($payload, $row->payload, 'Le payload reste rejouable.');
        $this->assertSame(1, app('queue.failer')->count());
    }

    public function test_every_channel_that_writes_applies_the_redaction(): void
    {
        $writing = collect(config('logging.channels'))->reject(fn ($c, $name) => in_array($name, ['stack', 'null', 'emergency', 'probe'], true));

        $this->assertNotEmpty($writing);
        foreach ($writing as $name => $channel) {
            $this->assertContains(RedactSecretsProcessor::class, (array) ($channel['tap'] ?? []), "Canal « {$name} » sans expurgation.");
        }
        $this->assertSame('testing', env('LOG_STACK'), 'Les tests écrivent dans leur propre fichier (storage/logs/testing-*.log).');
    }

    public function test_the_emergency_logger_used_when_a_channel_is_broken_is_redacted_too(): void
    {
        $path = storage_path('framework/testing/emergency-'.Str::random(8).'.log');
        File::ensureDirectoryExists(dirname($path));
        $this->files[] = $path;
        config(['logging.channels.emergency.path' => $path, 'logging.channels.broken' => ['driver' => 'custom', 'via' => 'App\Absent\LoggerFactory']]);
        Log::forgetChannel('broken');

        Log::channel('broken')->error('Échec : Authorization: Bearer '.self::GOOGLE_TOKEN, ['exception' => $this->secretException(), 'refresh_token' => self::REFRESH]);

        $written = (string) File::get($path);
        $this->assertStringContainsString('Unable to create configured logger', $written, 'Le journal de secours a bien servi.');
        $this->assertNoSecret($written);
    }

    public function test_http_client_errors_never_leak_tokens_from_urls_headers_or_response_bodies(): void
    {
        $path = $this->probe(true);
        Http::fake(['graph.example.com/*' => Http::response(['error' => ['message' => 'Invalid token', 'access_token' => self::GOOGLE_TOKEN]], 401)]);

        try {
            Http::withToken(self::GOOGLE_TOKEN)->get('https://graph.example.com/v1/me?access_token='.self::GOOGLE_TOKEN.'&refresh_token='.self::REFRESH)->throw();
            $this->fail('Une RequestException était attendue.');
        } catch (\Illuminate\Http\Client\RequestException $e) {
            Log::channel('probe')->error('Appel distant refusé : '.$e->getMessage(), ['exception' => $e, 'url' => 'https://graph.example.com/v1/me?access_token='.self::GOOGLE_TOKEN]);
        }

        $written = (string) File::get($path);
        $this->assertStringContainsString('Appel distant refusé', $written);
        $this->assertNoSecret($written);
    }

    public function test_daily_files_rotate_with_a_bounded_retention(): void
    {
        foreach (['json', 'daily'] as $name) {
            $channel = config("logging.channels.{$name}");
            $this->assertSame('daily', $channel['driver'], "Canal « {$name} » : un fichier par jour.");
            $this->assertGreaterThan(0, (int) $channel['max_files'], "Canal « {$name} » : rétention bornée.");
        }
        $this->assertSame(storage_path('logs/emergency.log'), config('logging.channels.emergency.path'), 'Journal de secours séparé.');
    }
}
