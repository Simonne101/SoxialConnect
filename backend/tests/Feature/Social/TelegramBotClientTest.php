<?php

namespace Tests\Feature\Social;

use App\Domain\Social\Telegram\TelegramApiException;
use App\Domain\Social\Telegram\TelegramBotClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Social\Concerns\TelegramFixtures;
use Tests\TestCase;

/**
 * Le token du bot fait partie de l'URL de l'API Telegram (/bot<token>/<méthode>) : une erreur
 * réseau de cURL recopie cette URL dans son message. Le client ne doit jamais la laisser remonter
 * (ni message, ni exception chaînée), sinon le gestionnaire d'exceptions l'écrirait dans les journaux.
 */
class TelegramBotClientTest extends TestCase
{
    use TelegramFixtures;

    public function test_a_network_error_never_carries_the_bot_token(): void
    {
        $this->configureTelegram();
        Http::fake(fn () => throw new ConnectionException(
            'cURL error 28: Operation timed out for https://api.telegram.org/bot'.self::BOT_TOKEN.'/getWebhookInfo'
        ));

        try {
            app(TelegramBotClient::class)->call('getWebhookInfo');
            $this->fail('Une erreur réseau doit lever TelegramApiException.');
        } catch (TelegramApiException $e) {
            $this->assertTrue($e->transient);
            $this->assertStringNotContainsString(self::BOT_TOKEN, $e->getMessage());
            $this->assertNull($e->getPrevious(), 'Exception d\'origine (avec URL) non chaînée.');
        }
    }
}
