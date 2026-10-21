<?php

namespace App\Domain\Publishing\Adapters;

use App\Domain\Publishing\Contracts\SocialPublisher;
use App\Domain\Publishing\Data\PublishRequest;
use App\Domain\Publishing\Data\PublishResult;
use App\Domain\Social\OAuth\LinkedInOAuthProvider;
use App\Domain\Social\OAuth\OAuthException;
use App\Domain\Social\Tokens\LinkedInTokenManager;
use App\Models\SocialAccount;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Adaptateur LinkedIn — profil personnel du membre (docs/publishing.md §14).
 *
 * API : « Share on LinkedIn » (learn.microsoft.com/linkedin/consumer/integrations/self-serve/
 * share-on-linkedin) — POST https://api.linkedin.com/v2/ugcPosts, en-tête
 * X-Restli-Protocol-Version: 2.0.0, auteur urn:li:person:{sub}, visibilité PUBLIC.
 * Succès : 201 Created, identifiant du post dans l'en-tête X-RestLi-Id.
 *
 * V1 : texte uniquement. Les médias sont refusés avant tout envoi (le flux d'upload d'images
 * LinkedIn n'est pas encore intégré). Pas de clé d'idempotence côté LinkedIn : une interruption
 * pendant l'envoi ou une erreur serveur = résultat inconnu, jamais relancé automatiquement.
 */
final class LinkedInPublisher implements SocialPublisher
{
    private const UGC_POSTS_URL = 'https://api.linkedin.com/v2/ugcPosts';

    private const NOTHING = ' Rien n\'a été publié.';

    public function __construct(
        private readonly LinkedInOAuthProvider $oauth,
        private readonly LinkedInTokenManager $tokens,
    ) {}

    public function publish(PublishRequest $request): PublishResult
    {
        if (! $this->oauth->isConfigured()) {
            return PublishResult::notConfigured('La publication LinkedIn n\'est pas configurée sur ce serveur (identifiants LinkedIn absents). Rien n\'a été publié.');
        }

        $account = SocialAccount::query()->with('platform')->find($request->socialAccountId);
        if ($account === null || $account->platform?->key !== LinkedInOAuthProvider::PROVIDER) {
            return PublishResult::permanentFailure('ACCOUNT_MISSING', 'Le compte LinkedIn de cette publication n\'existe plus.'.self::NOTHING);
        }
        if ($request->media !== []) {
            return PublishResult::permanentFailure('MEDIA_NOT_SUPPORTED', 'LinkedIn : la publication d\'images et de vidéos n\'est pas encore prise en charge par SoxialConnect. Publiez un texte seul.'.self::NOTHING);
        }
        $text = $this->text($request);
        if ($text === '') {
            return PublishResult::permanentFailure('EMPTY_CONTENT', 'La publication ne contient pas de texte.'.self::NOTHING);
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
                ->withHeaders(['X-Restli-Protocol-Version' => '2.0.0'])
                ->connectTimeout(10)
                ->timeout(30)
                ->post(self::UGC_POSTS_URL, $this->payload($account, $text));
        } catch (ConnectionException $e) {
            // Résolution DNS ou connexion impossible (cURL 6, 7) : rien n'a été envoyé.
            if (preg_match('/cURL error (6|7):/', $e->getMessage())) {
                return PublishResult::temporaryFailure('NETWORK_ERROR', 'LinkedIn est momentanément injoignable. Nouvel essai automatique.'.self::NOTHING);
            }
            throw new RuntimeException('Envoi LinkedIn interrompu : résultat inconnu.');
        }

        if ($response->status() === 201) {
            $id = $response->header('X-RestLi-Id') ?: $response->json('id');
            if (! is_string($id) || ! str_starts_with($id, 'urn:li:')) {
                throw new RuntimeException('Réponse LinkedIn sans identifiant de publication : résultat inconnu.');
            }

            return PublishResult::success($id, 'https://www.linkedin.com/feed/update/'.$id.'/', ['urn' => $id]);
        }

        return $this->fromApiError($response, $account);
    }

    /**
     * Charge utile ugcPosts (texte seul, visibilité publique).
     *
     * @return array<string, mixed>
     */
    public function payload(SocialAccount $account, string $text): array
    {
        $author = (string) ($account->metadata['person_urn'] ?? 'urn:li:person:'.$account->external_account_id);

        return [
            'author' => $author,
            'lifecycleState' => 'PUBLISHED',
            'specificContent' => [
                'com.linkedin.ugc.ShareContent' => [
                    'shareCommentary' => ['text' => $text],
                    'shareMediaCategory' => 'NONE',
                ],
            ],
            'visibility' => ['com.linkedin.ugc.MemberNetworkVisibility' => 'PUBLIC'],
        ];
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
     * Erreurs de l'API (corps { message, serviceErrorCode, status }) : seuls le statut, le code de
     * service et le message (sans donnée sensible) sont conservés, jamais le jeton.
     */
    private function fromApiError(Response $response, SocialAccount $account): PublishResult
    {
        $status = $response->status();
        $message = mb_substr(trim((string) ($response->json('message') ?? '')), 0, 300);
        $meta = array_filter([
            'http_status' => $status,
            'service_error_code' => $response->json('serviceErrorCode'),
            'message' => $message ?: null,
        ]);

        if ($status >= 500) {
            // Le post a pu être créé malgré l'erreur : résultat inconnu, jamais de relance aveugle.
            throw new RuntimeException('Erreur serveur LinkedIn après envoi : résultat inconnu.');
        }

        return match (true) {
            $status === 401 => $this->tokenRejected($account, $meta),
            $status === 403 => PublishResult::permanentFailure('PERMISSION_DENIED', 'LinkedIn refuse la publication pour ce compte (permission « Share on LinkedIn » manquante ou retirée). Reconnectez le compte.'.self::NOTHING, $status, $meta),
            $status === 429 => PublishResult::temporaryFailure('RATE_LIMITED', 'Limite de publications LinkedIn atteinte pour le moment. Nouvel essai automatique.'.self::NOTHING, $status, $meta),
            $status === 422 && str_contains(mb_strtolower($message), 'duplicate') => PublishResult::permanentFailure('DUPLICATE_CONTENT', 'LinkedIn refuse ce texte : il est identique à une publication récente.'.self::NOTHING, $status, $meta),
            in_array($status, [400, 422], true) => PublishResult::permanentFailure('INVALID_REQUEST', 'LinkedIn a refusé le contenu de la publication.'.self::NOTHING, $status, $meta),
            default => PublishResult::permanentFailure('HTTP_ERROR', "LinkedIn a répondu par une erreur ({$status}).".self::NOTHING, $status, $meta),
        };
    }

    /** @param array<string, mixed> $meta */
    private function tokenRejected(SocialAccount $account, array $meta): PublishResult
    {
        $this->tokens->rejected($account);

        return PublishResult::permanentFailure('TOKEN_INVALID', 'LinkedIn a refusé l\'autorisation (expirée ou retirée). Reconnectez le compte LinkedIn.'.self::NOTHING, 401, $meta);
    }
}
