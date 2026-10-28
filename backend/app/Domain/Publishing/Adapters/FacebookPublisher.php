<?php

namespace App\Domain\Publishing\Adapters;

use App\Domain\Media\Storage\MediaStorage;
use App\Domain\Publishing\Contracts\SocialPublisher;
use App\Domain\Publishing\Data\PublishRequest;
use App\Domain\Publishing\Data\PublishResult;
use App\Domain\Social\OAuth\FacebookOAuthProvider;
use App\Domain\Social\OAuth\OAuthException;
use App\Domain\Social\Tokens\FacebookTokenManager;
use App\Models\Media;
use App\Models\SocialAccount;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Adaptateur Facebook — Page (docs/publishing.md §16), API Graph avec le jeton de la Page :
 * - texte seul       : POST /{page-id}/feed (message) ;
 * - une photo        : POST /{page-id}/photos (url, caption) ;
 * - 2 à 10 photos    : chaque photo envoyée non publiée (published=false), puis POST /{page-id}/feed
 *                      avec attached_media ;
 * - une vidéo        : POST /{page-id}/videos (file_url, description).
 * Facebook télécharge lui-même chaque fichier par une URL signée de courte durée (MediaStorage).
 * Pas de clé d'idempotence côté Facebook : une interruption pendant l'envoi ou une erreur serveur =
 * résultat inconnu, jamais relancé automatiquement. Reels Facebook et premier commentaire : non pris
 * en charge (permissions et API distinctes), signalés plutôt qu'ignorés.
 */
final class FacebookPublisher implements SocialPublisher
{
    private const NOTHING = ' Rien n\'a été publié.';

    private const MAX_PHOTOS = 10;

    /** Formats d'image acceptés par l'API Photos de Facebook (WebP refusé). */
    private const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/gif'];

    private const VIDEO_TYPES = ['video/mp4', 'video/quicktime'];

    public function __construct(
        private readonly FacebookOAuthProvider $oauth,
        private readonly FacebookTokenManager $tokens,
        private readonly MediaStorage $storage,
    ) {}

    public function publish(PublishRequest $request): PublishResult
    {
        if (! $this->oauth->isConfigured()) {
            return PublishResult::notConfigured('La publication Facebook n\'est pas configurée sur ce serveur (identifiants Meta absents). Rien n\'a été publié.');
        }
        $account = SocialAccount::query()->with('platform')->find($request->socialAccountId);
        if ($account === null || $account->platform?->key !== FacebookOAuthProvider::PROVIDER) {
            return PublishResult::permanentFailure('ACCOUNT_MISSING', 'La Page Facebook de cette publication n\'existe plus.'.self::NOTHING);
        }
        if ($request->format === 'reel') {
            return PublishResult::permanentFailure('FORMAT_NOT_SUPPORTED', 'Facebook : les Reels ne sont pas encore pris en charge par SoxialConnect. Choisissez le format « Publication ».'.self::NOTHING);
        }
        $media = $this->prepareMedia($request);
        if ($media instanceof PublishResult) {
            return $media;
        }
        $text = $this->text($request);
        if ($media === [] && $text === '') {
            return PublishResult::permanentFailure('EMPTY_CONTENT', 'La publication ne contient ni texte ni média.'.self::NOTHING);
        }

        try {
            $token = $this->tokens->accessTokenFor($account);
        } catch (OAuthException $e) {
            return $e->transient
                ? PublishResult::temporaryFailure($e->errorCode, $e->getMessage().self::NOTHING)
                : PublishResult::permanentFailure($e->errorCode, $e->getMessage().self::NOTHING);
        }

        $page = rawurlencode($account->external_account_id);
        $meta = array_filter(['first_comment' => filled($request->options['firstComment'] ?? null) ? 'not_supported' : null]);
        $isVideo = $media !== [] && in_array($media[0]['mime'], self::VIDEO_TYPES, true);

        return match (true) {
            $media === [] => $this->finish($this->post($token, "{$page}/feed", ['message' => $text]), $account, 'id', $meta),
            $isVideo => $this->finish($this->post($token, "{$page}/videos", ['file_url' => $media[0]['url'], 'description' => $text]), $account, 'id', $meta, video: true),
            count($media) === 1 => $this->finish($this->post($token, "{$page}/photos", ['url' => $media[0]['url'], 'caption' => $text]), $account, 'post_id', $meta),
            default => $this->publishAlbum($token, $page, $media, $text, $account, $meta),
        };
    }

    /**
     * Texte de la variante (ou de la publication), puis les hashtags choisis qui n'y figurent pas déjà.
     */
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
     * Médias utilisables, dans l'ordre : URL signée de l'original et type. Une seule vidéo, OU 1 à 10
     * photos (jamais un mélange) ; tout autre cas est refusé avant le moindre envoi.
     *
     * @return list<array{url: string, mime: string}>|PublishResult
     */
    private function prepareMedia(PublishRequest $request): array|PublishResult
    {
        $prepared = [];
        foreach ($request->media as $item) {
            $media = Media::query()->find($item['id']);
            $path = $media ? $this->storage->variantPath($media, MediaStorage::ORIGINAL) : null;
            $url = $media ? $this->storage->temporaryUrl($media, MediaStorage::ORIGINAL) : null;
            if ($media === null || $path === null || $url === null || ! $this->storage->exists($path)) {
                return PublishResult::permanentFailure('MEDIA_NOT_FOUND', 'Un fichier de cette publication est introuvable dans la médiathèque. Remplacez-le puis réessayez.'.self::NOTHING);
            }
            $mime = strtolower((string) $media->mime_type);
            if (! in_array($mime, [...self::IMAGE_TYPES, ...self::VIDEO_TYPES], true)) {
                return PublishResult::permanentFailure('MEDIA_TYPE_NOT_SUPPORTED', 'Facebook accepte les images JPEG, PNG ou GIF et les vidéos MP4 ou MOV. Remplacez le fichier non pris en charge.'.self::NOTHING);
            }
            $prepared[] = ['url' => $url, 'mime' => $mime];
        }
        $videos = count(array_filter($prepared, fn (array $m) => in_array($m['mime'], self::VIDEO_TYPES, true)));
        if ($videos > 0 && count($prepared) > 1) {
            return PublishResult::permanentFailure('MIXED_MEDIA_NOT_SUPPORTED', 'Facebook : une publication contient soit une seule vidéo, soit des photos. Séparez-les en deux publications.'.self::NOTHING);
        }
        if (count($prepared) > self::MAX_PHOTOS) {
            return PublishResult::permanentFailure('TOO_MANY_MEDIA', 'Facebook : au plus 10 photos par publication avec SoxialConnect.'.self::NOTHING);
        }

        return $prepared;
    }

    /**
     * Album : photos envoyées non publiées (aucune n'apparaît seule sur la Page), puis une seule
     * publication qui les regroupe. Un échec avant la publication finale ne publie rien.
     *
     * @param  list<array{url: string, mime: string}>  $media
     * @param  array<string, string>  $meta
     */
    private function publishAlbum(string $token, string $page, array $media, string $text, SocialAccount $account, array $meta): PublishResult
    {
        $attached = [];
        foreach ($media as $item) {
            $response = $this->post($token, "{$page}/photos", ['url' => $item['url'], 'published' => 'false']);
            if ($response === null) {
                return $this->unreachable();
            }
            if (! $response->successful() || ! is_string($response->json('id'))) {
                return $this->fromApiError($response, $account);
            }
            $attached[] = ['media_fbid' => $response->json('id')];
        }
        $fields = ['message' => $text];
        foreach ($attached as $i => $photo) {
            $fields["attached_media[{$i}]"] = (string) json_encode($photo);
        }

        return $this->finish($this->post($token, "{$page}/feed", $fields), $account, 'id', $meta + ['photo_count' => count($attached)]);
    }

    /** @param array<string, mixed> $meta */
    private function finish(?Response $response, SocialAccount $account, string $idField, array $meta, bool $video = false): PublishResult
    {
        if ($response === null) {
            return $this->unreachable();
        }
        if (! $response->successful() || is_array($response->json('error'))) {
            return $this->fromApiError($response, $account);
        }
        $id = $response->json($idField) ?? $response->json('id');
        if (! is_string($id) || $id === '') {
            throw new RuntimeException('Réponse Facebook sans identifiant de publication : résultat inconnu.');
        }
        $url = $video
            ? 'https://www.facebook.com/'.rawurlencode($account->external_account_id).'/videos/'.rawurlencode($id).'/'
            : 'https://www.facebook.com/'.rawurlencode($id);

        return PublishResult::success($id, $url, $meta + ['page_id' => $account->external_account_id] + ($video ? ['video_id' => $id] : []));
    }

    /**
     * null : Facebook injoignable avant tout envoi (résolution DNS ou connexion impossible, cURL 6 ou 7).
     *
     * @param  array<string, string>  $fields
     */
    private function post(string $token, string $path, array $fields): ?Response
    {
        try {
            return Http::withToken($token)->asForm()->acceptJson()->connectTimeout(10)->timeout(60)->post($this->oauth->graphUrl($path), $fields + [
                'appsecret_proof' => $this->oauth->appSecretProof($token),
            ]);
        } catch (ConnectionException $e) {
            if (preg_match('/cURL error (6|7):/', $e->getMessage())) {
                return null;
            }
            throw new RuntimeException('Envoi Facebook interrompu : résultat inconnu.');
        }
    }

    private function unreachable(): PublishResult
    {
        return PublishResult::temporaryFailure('NETWORK_ERROR', 'Facebook est momentanément injoignable. Nouvel essai automatique.'.self::NOTHING);
    }

    /**
     * Erreurs de l'API Graph (corps { error: { message, type, code, error_subcode } }) : seuls le statut,
     * les codes et un message borné sont conservés, jamais le jeton.
     */
    private function fromApiError(Response $response, SocialAccount $account): PublishResult
    {
        $status = $response->status();
        $code = (int) $response->json('error.code');
        $subcode = (int) $response->json('error.error_subcode');
        $message = mb_substr(trim((string) $response->json('error.message')), 0, 300);
        $meta = array_filter(['http_status' => $status, 'graph_code' => $code ?: null, 'graph_subcode' => $subcode ?: null, 'message' => $message ?: null]);

        if ($status >= 500 || in_array($code, [1, 2], true)) {
            // La publication a pu être créée malgré l'erreur : résultat inconnu, jamais de relance aveugle.
            throw new RuntimeException('Erreur serveur Facebook après envoi : résultat inconnu.');
        }

        return match (true) {
            $code === 190 => $this->tokenRejected($account, $meta),
            in_array($code, [4, 17, 32, 613, 80001], true) => PublishResult::temporaryFailure('RATE_LIMITED', 'Limite de publications Facebook atteinte pour le moment. Nouvel essai automatique.'.self::NOTHING, $status, $meta),
            $code === 10 || ($code >= 200 && $code < 300) => PublishResult::permanentFailure('PERMISSION_DENIED', 'Facebook refuse la publication sur cette Page (permission retirée ou rôle insuffisant). Reconnectez la Page.'.self::NOTHING, $status, $meta),
            $code === 506 => PublishResult::permanentFailure('DUPLICATE_CONTENT', 'Facebook refuse ce texte : il est identique à une publication récente de la Page.'.self::NOTHING, $status, $meta),
            $code === 368 => PublishResult::permanentFailure('POLICY_BLOCKED', 'Facebook a bloqué cette publication (règles de la communauté ou restriction temporaire de la Page).'.self::NOTHING, $status, $meta),
            $code === 324 || $code === 352 || str_contains(mb_strtolower($message), 'url') => PublishResult::permanentFailure('MEDIA_REJECTED', 'Facebook n\'a pas pu récupérer ou lire un fichier de la publication. Vérifiez le format puis réessayez.'.self::NOTHING, $status, $meta),
            default => PublishResult::permanentFailure('INVALID_REQUEST', "Facebook a refusé la publication ({$status}).".self::NOTHING, $status, $meta),
        };
    }

    /** @param array<string, mixed> $meta */
    private function tokenRejected(SocialAccount $account, array $meta): PublishResult
    {
        $this->tokens->rejected($account);

        return PublishResult::permanentFailure('TOKEN_INVALID', 'Facebook a refusé l\'autorisation (retirée ou expirée). Reconnectez la Page Facebook.'.self::NOTHING, 401, $meta);
    }
}
