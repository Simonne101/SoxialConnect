<?php

namespace App\Domain\Publishing\Adapters;

use App\Domain\Publishing\Contracts\SocialPublisher;
use App\Domain\Publishing\Data\PublishRequest;
use App\Domain\Publishing\Data\PublishResult;
use App\Domain\Publishing\Support\XPostLength;
use App\Domain\Social\OAuth\OAuthException;
use App\Domain\Social\OAuth\XOAuthProvider;
use App\Domain\Social\Tokens\XTokenManager;
use App\Models\SocialAccount;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Adaptateur X — compte de l'utilisateur (docs/publishing.md §15).
 *
 * API : « Create Post » (docs.x.com/x-api/posts/create-post) — POST https://api.x.com/2/tweets,
 * corps { "text": … }, jeton OAuth 2.0 utilisateur (scope tweet.write) fourni par XTokenManager.
 * Succès : 201 Created, { data: { id, text } }.
 *
 * Texte uniquement : les médias sont refusés avant tout envoi (envoi de médias X et scope
 * media.write pas encore intégrés). Pas de clé d'idempotence côté X : une interruption pendant
 * l'envoi ou une erreur serveur = résultat inconnu, jamais relancé automatiquement.
 */
final class XPublisher implements SocialPublisher
{
    private const CREATE_POST_URL = 'https://api.x.com/2/tweets';

    private const NOTHING = ' Rien n\'a été publié.';

    public function __construct(
        private readonly XOAuthProvider $oauth,
        private readonly XTokenManager $tokens,
    ) {}

    public function publish(PublishRequest $request): PublishResult
    {
        if (! $this->oauth->isConfigured()) {
            return PublishResult::notConfigured('La publication X n\'est pas configurée sur ce serveur (identifiants X absents). Rien n\'a été publié.');
        }

        $account = SocialAccount::query()->with('platform')->find($request->socialAccountId);
        if ($account === null || $account->platform?->key !== XOAuthProvider::PROVIDER) {
            return PublishResult::permanentFailure('ACCOUNT_MISSING', 'Le compte X de cette publication n\'existe plus.'.self::NOTHING);
        }
        if ($request->media !== []) {
            return PublishResult::permanentFailure('MEDIA_NOT_SUPPORTED', 'X : la publication d\'images et de vidéos n\'est pas encore prise en charge par SoxialConnect. Publiez un texte seul.'.self::NOTHING);
        }
        $text = $this->text($request);
        if ($text === '') {
            return PublishResult::permanentFailure('EMPTY_CONTENT', 'La publication ne contient pas de texte.'.self::NOTHING);
        }
        $length = XPostLength::of($text);
        if ($length > XPostLength::MAX) {
            return PublishResult::permanentFailure('TEXT_TOO_LONG', "X : le texte compte {$length} caractères selon les règles de X (maximum ".XPostLength::MAX.'). Raccourcissez-le.'.self::NOTHING);
        }

        try {
            $accessToken = $this->tokens->accessTokenFor($account);
        } catch (OAuthException $e) {
            return $e->transient
                ? PublishResult::temporaryFailure($e->errorCode, $e->getMessage().self::NOTHING)
                : PublishResult::permanentFailure($e->errorCode, $e->getMessage().self::NOTHING);
        }

        try {
            $response = Http::withToken($accessToken)
                ->acceptJson()
                ->connectTimeout(10)
                ->timeout(30)
                ->post(self::CREATE_POST_URL, ['text' => $text]);
        } catch (ConnectionException $e) {
            // Résolution DNS ou connexion impossible (cURL 6, 7) : rien n'a été envoyé.
            if (preg_match('/cURL error (6|7):/', $e->getMessage())) {
                return PublishResult::temporaryFailure('NETWORK_ERROR', 'X est momentanément injoignable. Nouvel essai automatique.'.self::NOTHING);
            }
            throw new RuntimeException('Envoi X interrompu : résultat inconnu.');
        }

        if ($response->status() === 201) {
            $id = $response->json('data.id');
            if (! is_string($id) || ! ctype_digit($id)) {
                throw new RuntimeException('Réponse X sans identifiant de publication : résultat inconnu.');
            }

            // Lien permanent officiel d'un post à partir de son seul identifiant (indépendant de
            // l'@identifiant, qui peut changer).
            return PublishResult::success($id, 'https://x.com/i/web/status/'.$id, ['post_id' => $id]);
        }

        return $this->fromApiError($response, $account);
    }

    /** Texte de la variante (ou de la publication), puis les hashtags choisis qui n'y figurent pas déjà. */
    public function text(PublishRequest $request): string
    {
        $text = trim($request->body);
        $missing = [];
        foreach ((array) ($request->options['hashtags'] ?? []) as $tag) {
            $tag = is_string($tag) ? trim(ltrim($tag, '#')) : '';
            if ($tag !== '' && ! preg_match('/(^|\s)#'.preg_quote($tag, '/').'(\s|$)/iu', $text)) {
                $missing[] = '#'.$tag;
            }
        }

        return $missing === [] ? $text : trim($text."\n\n".implode(' ', $missing));
    }

    /**
     * Erreurs de l'API (format « Problem » : { title, detail, type, status }, parfois reason) :
     * seuls ces champs, tronqués, sont conservés. Jamais le jeton, jamais l'identifiant client.
     */
    private function fromApiError(Response $response, SocialAccount $account): PublishResult
    {
        $status = $response->status();
        $detail = mb_substr(trim((string) ($response->json('detail') ?? $response->json('errors.0.message') ?? '')), 0, 300);
        $reason = (string) ($response->json('reason') ?? '');
        $type = (string) ($response->json('type') ?? '');
        $meta = array_filter([
            'http_status' => $status,
            'title' => is_string($response->json('title')) ? mb_substr($response->json('title'), 0, 100) : null,
            'type' => $type ?: null,
            'reason' => $reason ?: null,
            'detail' => $detail ?: null,
        ]);

        if ($status >= 500) {
            // Le post a pu être créé malgré l'erreur : résultat inconnu, jamais de relance aveugle.
            throw new RuntimeException('Erreur serveur X après envoi : résultat inconnu.');
        }

        $lower = mb_strtolower($detail);
        $apiAccess = $status === 402 || $reason === 'client-not-enrolled' || str_contains($type, 'client-forbidden');

        return match (true) {
            $status === 401 => $this->tokenRejected($account, $meta),
            $status === 429 => $this->rateLimited($response, $meta),
            $apiAccess => PublishResult::permanentFailure('API_ACCESS_DENIED', 'X refuse l\'accès à son API pour l\'application SoxialConnect (offre ou crédits du compte développeur X). Contactez l\'administrateur.'.self::NOTHING, $status, $meta),
            str_contains($lower, 'duplicate') => PublishResult::permanentFailure('DUPLICATE_CONTENT', 'X refuse ce texte : il est identique à une publication récente.'.self::NOTHING, $status, $meta),
            str_contains($lower, 'too long') => PublishResult::permanentFailure('TEXT_TOO_LONG', 'X refuse ce texte : il est trop long.'.self::NOTHING, $status, $meta),
            $status === 403 => PublishResult::permanentFailure('PERMISSION_DENIED', 'X refuse la publication pour ce compte (permission d\'écriture manquante, compte restreint ou application en lecture seule).'.self::NOTHING, $status, $meta),
            $status === 400 => PublishResult::permanentFailure('INVALID_REQUEST', 'X a refusé le contenu de la publication.'.self::NOTHING, $status, $meta),
            default => PublishResult::permanentFailure('HTTP_ERROR', "X a répondu par une erreur ({$status}).".self::NOTHING, $status, $meta),
        };
    }

    /**
     * 429 : temporaire, relancé selon le backoff du moteur (config publishing.backoff). L'heure de
     * fin de fenêtre annoncée par X (en-tête x-rate-limit-reset) est conservée pour l'historique.
     *
     * @param  array<string, mixed>  $meta
     */
    private function rateLimited(Response $response, array $meta): PublishResult
    {
        $reset = $response->header('x-rate-limit-reset');
        $resetAt = ctype_digit($reset) ? CarbonImmutable::createFromTimestamp((int) $reset) : null;
        if ($resetAt !== null) {
            $meta['rate_limit_reset_at'] = $resetAt->toIso8601String();
        }
        $when = $resetAt ? ' (fin de la limite annoncée par X : '.$resetAt->setTimezone(config('app.timezone'))->format('d/m/Y H:i').')' : '';

        return PublishResult::temporaryFailure('RATE_LIMITED', "Limite de publications X atteinte pour le moment{$when}. Nouvel essai automatique.".self::NOTHING, 429, $meta);
    }

    /** @param array<string, mixed> $meta */
    private function tokenRejected(SocialAccount $account, array $meta): PublishResult
    {
        $this->tokens->rejected($account);

        return PublishResult::permanentFailure('TOKEN_INVALID', 'X a refusé l\'autorisation (expirée ou retirée). Reconnectez le compte X.'.self::NOTHING, 401, $meta);
    }
}
