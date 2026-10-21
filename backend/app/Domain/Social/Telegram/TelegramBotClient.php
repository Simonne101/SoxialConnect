<?php

namespace App\Domain\Social\Telegram;

use GuzzleHttp\Exception\TransferException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Client de la Bot API Telegram (https://core.telegram.org/bots/api) pour le bot partagé de
 * SoxialConnect (config services.telegram).
 *
 * Sécurité : le token figure dans l'URL de chaque appel (https://api.telegram.org/bot<token>/…).
 * Cette URL n'est donc jamais journalisée, jamais placée dans un message d'erreur et jamais
 * transmise via une exception chaînée : toute erreur est convertie en TelegramApiException.
 */
class TelegramBotClient
{
    /** Délai d'un appel simple (lecture, envoi de texte). */
    private const TIMEOUT_SECONDS = 20;

    public function isConfigured(): bool
    {
        return filled(config('services.telegram.bot_token'));
    }

    /** Liaison des chaînes : bot, nom du bot et secret du webhook requis. */
    public function canLinkChats(): bool
    {
        return $this->isConfigured()
            && filled(config('services.telegram.bot_username'))
            && filled(config('services.telegram.webhook_secret'));
    }

    public function botUsername(): string
    {
        return ltrim((string) config('services.telegram.bot_username'), '@');
    }

    /**
     * Appel JSON d'une méthode de la Bot API ; renvoie le champ « result ».
     *
     * @param  array<string, mixed>  $params
     *
     * @throws TelegramApiException
     */
    public function call(string $method, array $params = [], int $timeout = self::TIMEOUT_SECONDS): mixed
    {
        return $this->send($method, fn (PendingRequest $http, string $url) => $http->timeout($timeout)->asJson()->post($url, $params));
    }

    /**
     * Envoi multipart (fichiers lus en flux depuis le stockage privé : aucune URL publique).
     *
     * @param  array<string, scalar>  $fields  champs texte (chat_id, caption, media JSON…)
     * @param  list<array{name: string, stream: resource, filename: string, mime: string}>  $files
     *
     * @throws TelegramApiException
     */
    public function upload(string $method, array $fields, array $files, int $timeout): mixed
    {
        return $this->send($method, function (PendingRequest $http, string $url) use ($fields, $files, $timeout) {
            foreach ($files as $file) {
                $http = $http->attach($file['name'], $file['stream'], $file['filename'], ['Content-Type' => $file['mime']]);
            }

            return $http->timeout($timeout)->post($url, array_map(fn ($v) => is_bool($v) ? ($v ? 'true' : 'false') : (string) $v, $fields));
        });
    }

    /**
     * Profil du bot (getMe), mis en cache : son identifiant sert à vérifier ses droits dans une chaîne.
     *
     * @return array{id: int, username: string}
     */
    public function me(): array
    {
        $key = 'telegram:bot-me:'.hash('sha256', (string) config('services.telegram.bot_token'));

        return Cache::remember($key, 3600, function () {
            $me = $this->call('getMe');
            if (! is_array($me) || ! isset($me['id'])) {
                throw TelegramApiException::invalidResponse();
            }

            return ['id' => (int) $me['id'], 'username' => (string) ($me['username'] ?? '')];
        });
    }

    /** @param callable(PendingRequest, string): Response $request */
    private function send(string $method, callable $request): mixed
    {
        if (! $this->isConfigured()) {
            throw new TelegramApiException('Le bot Telegram n\'est pas configuré sur ce serveur.', 0, '', false);
        }
        $url = rtrim((string) config('services.telegram.api_base', 'https://api.telegram.org'), '/')
            .'/bot'.config('services.telegram.bot_token').'/'.$method;

        try {
            $response = $request(Http::acceptJson()->connectTimeout(10), $url);
        } catch (ConnectionException $e) {
            // Échec de résolution DNS ou de connexion (cURL 6, 7) : rien n'a été envoyé.
            // Autres cas (délai dépassé, coupure en cours d'envoi) : la requête a pu être traitée.
            // Le message d'origine contient l'URL (donc le token) : il n'est ni repris ni chaîné.
            $notSent = (bool) preg_match('/cURL error (6|7):/', $e->getMessage());
            throw TelegramApiException::network(! $notSent);
        } catch (TransferException) {
            throw TelegramApiException::network(true);
        }

        $body = $response->json();
        if (! is_array($body)) {
            throw $response->successful() ? TelegramApiException::invalidResponse() : TelegramApiException::fromResponse($response->status(), []);
        }
        if (($body['ok'] ?? false) !== true) {
            throw TelegramApiException::fromResponse($response->status(), $body);
        }

        return $body['result'] ?? null;
    }
}
