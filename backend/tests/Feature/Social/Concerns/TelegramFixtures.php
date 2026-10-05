<?php

namespace Tests\Feature\Social\Concerns;

use App\Models\Media;
use App\Models\SocialAccount;
use App\Models\SocialPlatform;
use App\Models\User;
use Closure;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Données de test Telegram : bot FICTIF, faux serveur Bot API (Http::fake) qui enregistre chaque
 * appel (méthode + paramètres, fichiers lus pendant la requête), destinations et médias.
 * Aucun appel réel (Http::preventStrayRequests).
 */
trait TelegramFixtures
{
    protected const BOT_TOKEN = '123456789:TEST-bot-token-ne-doit-jamais-sortir';

    protected const BOT_ID = 123456789;

    protected const WEBHOOK_SECRET = 'test-webhook-secret-0123456789abcdef';

    protected const CHANNEL_ID = '-1001234567890';

    /** @var list<array{method: string, data: array<string, mixed>}> */
    protected array $telegramCalls = [];

    protected function configureTelegram(): SocialPlatform
    {
        config([
            'services.telegram.bot_token' => self::BOT_TOKEN,
            'services.telegram.bot_username' => 'SoxialTestBot',
            'services.telegram.webhook_secret' => self::WEBHOOK_SECRET,
            'services.telegram.webhook_url' => 'https://tunnel.example.test/api/v1/webhooks/telegram',
        ]);

        return SocialPlatform::query()->where('key', 'telegram')->first()
            ?? SocialPlatform::factory()->create(['key' => 'telegram', 'name' => 'Telegram']);
    }

    protected static function tgOk(mixed $result): PromiseInterface
    {
        return Http::response(['ok' => true, 'result' => $result]);
    }

    protected static function tgError(int $code, string $description, array $parameters = []): PromiseInterface
    {
        return Http::response(array_filter(['ok' => false, 'error_code' => $code, 'description' => $description, 'parameters' => $parameters ?: null]), $code);
    }

    /** @var array<string, Closure> */
    private array $telegramHandlers = [];

    private bool $telegramFaked = false;

    /** Coupure réseau simulée (message cURL réaliste, URL du bot comprise). */
    protected static function connectionFailure(int $curlCode): Closure
    {
        return fn (array $d, Request $r) => Http::failedConnection("cURL error {$curlCode}: simulated for https://api.telegram.org/bot".self::BOT_TOKEN.'/x')($r);
    }

    /**
     * Faux serveur Bot API. Par défaut : bot administrateur d'une chaîne publique « Ma chaîne »
     * (@machaine) avec le droit de publier ; la personne qui relie en est propriétaire.
     * Un nouvel appel remplace les réponses (un seul Http::fake : les faux ne s'empilent pas).
     *
     * @param  array<string, Closure(array<string, mixed>, Request): mixed>  $overrides  méthode => réponse
     */
    protected function fakeTelegram(array $overrides = []): void
    {
        $this->telegramHandlers = array_merge([
            'getMe' => fn () => self::tgOk(['id' => self::BOT_ID, 'is_bot' => true, 'username' => 'SoxialTestBot']),
            'sendMessage' => fn () => self::tgOk(['message_id' => 101, 'date' => time()]),
            'getChat' => fn (array $d) => self::tgOk(['id' => (int) $d['chat_id'], 'type' => 'channel', 'title' => 'Ma chaîne', 'username' => 'machaine']),
            'getChatMember' => fn (array $d) => (int) $d['user_id'] === self::BOT_ID
                ? self::tgOk(['status' => 'administrator', 'can_post_messages' => true])
                : self::tgOk(['status' => 'creator']),
            'sendPhoto' => fn () => self::tgOk(['message_id' => 201]),
            'sendAnimation' => fn () => self::tgOk(['message_id' => 202]),
            'sendVideo' => fn () => self::tgOk(['message_id' => 203]),
            'sendMediaGroup' => fn (array $d) => self::tgOk(array_map(fn ($i) => ['message_id' => 300 + $i], array_keys(json_decode($d['media'], true)))),
        ], $overrides);
        if ($this->telegramFaked) {
            return;
        }
        $this->telegramFaked = true;

        Http::fake(function (Request $request) {
            $this->assertStringStartsWith('https://api.telegram.org/bot'.self::BOT_TOKEN.'/', $request->url());
            $method = Str::afterLast((string) parse_url($request->url(), PHP_URL_PATH), '/');
            $data = $request->isMultipart() ? $this->multipartFields($request) : (array) $request->data();
            $this->telegramCalls[] = ['method' => $method, 'data' => $data];
            $handler = $this->telegramHandlers[$method] ?? null;

            return $handler ? $handler($data, $request) : self::tgError(404, 'Not Found');
        });
    }

    /** Champs d'une requête multipart ; le contenu des fichiers est lu pendant la requête. @return array<string, mixed> */
    private function multipartFields(Request $request): array
    {
        $fields = [];
        foreach ((array) $request->data() as $part) {
            $contents = $part['contents'] ?? null;
            if (is_resource($contents)) {
                rewind($contents);
                $contents = stream_get_contents($contents);
            } elseif (is_object($contents) && method_exists($contents, '__toString')) {
                $contents = (string) $contents;
            }
            $fields[$part['name']] = isset($part['filename'])
                ? ['filename' => $part['filename'], 'contents' => $contents]
                : $contents;
        }

        return $fields;
    }

    /** @return list<array<string, mixed>> paramètres des appels de cette méthode */
    protected function telegramCalls(string $method): array
    {
        return array_values(array_map(fn ($c) => $c['data'], array_filter($this->telegramCalls, fn ($c) => $c['method'] === $method)));
    }

    protected function telegramAccount(User $user, array $attributes = []): SocialAccount
    {
        $platform = $this->configureTelegram();

        return SocialAccount::factory()->for($user)->create(array_merge([
            'social_platform_id' => $platform->id,
            'external_account_id' => self::CHANNEL_ID,
            'account_type' => 'channel',
            'display_name' => 'Ma chaîne',
            'username' => '@machaine',
            'metadata' => ['provider' => 'telegram', 'chat_type' => 'channel'],
        ], $attributes));
    }

    /** Média prêt avec son fichier dans le stockage factice (Storage::fake('media') appelé au préalable). */
    protected function telegramMedia(User $user, string $mime, int $bytes = 2048, ?string $fill = null): Media
    {
        [$type, $extension] = match ($mime) {
            'image/jpeg' => ['image', 'jpg'],
            'image/png' => ['image', 'png'],
            'image/gif' => ['image', 'gif'],
            'video/mp4' => ['video', 'mp4'],
            'video/quicktime' => ['video', 'mov'],
            default => ['document', 'pdf'],
        };
        $media = Media::factory()->for($user)->create([
            'type' => $type,
            'original_name' => "fichier-prive.{$extension}",
            'mime_type' => $mime,
            'extension' => $extension,
            'size_bytes' => $bytes,
        ]);
        Storage::disk('media')->put($media->path, str_repeat($fill ?? substr($extension, 0, 1), $bytes));

        return $media;
    }
}
