<?php

namespace App\Domain\Publishing\Adapters;

use App\Domain\Media\Storage\MediaStorage;
use App\Domain\Publishing\Contracts\SocialPublisher;
use App\Domain\Publishing\Contracts\TracksPublicationStatus;
use App\Domain\Publishing\Data\PublishRequest;
use App\Domain\Publishing\Data\PublishResult;
use App\Domain\Social\OAuth\InstagramOAuthProvider;
use App\Domain\Social\OAuth\OAuthException;
use App\Domain\Social\Tokens\InstagramTokenManager;
use App\Models\Media;
use App\Models\SocialAccount;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Adaptateur Instagram — compte professionnel (docs/publishing.md §17), API de publication de contenu
 * (graph.instagram.com) en deux temps :
 * 1. conteneur : POST /{ig-user-id}/media (image_url, video_url, media_type, caption) ; carrousel :
 *    un conteneur par photo (is_carousel_item), puis un conteneur CAROUSEL qui les regroupe ;
 * 2. publication : POST /{ig-user-id}/media_publish (creation_id) → identifiant du média publié.
 * Instagram télécharge lui-même chaque fichier par une URL signée de courte durée (MediaStorage).
 * Photos : publiées aussitôt (suivi si Instagram n'a pas fini de les préparer). Vidéos (Reel, story) :
 * PublishResult::processing(conteneur), puis checkStatus() publie dès que le conteneur est FINISHED.
 * V1 : photos JPEG uniquement (exigence d'Instagram), carrousel de photos seulement ; premier
 * commentaire non pris en charge (permission distincte).
 */
final class InstagramPublisher implements SocialPublisher, TracksPublicationStatus
{
    private const NOTHING = ' Rien n\'a été publié.';

    private const CAPTION_LIMIT = 2200;

    private const HASHTAG_LIMIT = 30;

    private const CAROUSEL_MIN = 2;

    private const CAROUSEL_MAX = 10;

    private const VIDEO_TYPES = ['video/mp4', 'video/quicktime'];

    public function __construct(
        private readonly InstagramOAuthProvider $oauth,
        private readonly InstagramTokenManager $tokens,
        private readonly MediaStorage $storage,
    ) {}

    public function publish(PublishRequest $request): PublishResult
    {
        if (! $this->oauth->isConfigured()) {
            return PublishResult::notConfigured('La publication Instagram n\'est pas configurée sur ce serveur (identifiants de l\'app Instagram absents). Rien n\'a été publié.');
        }
        $account = SocialAccount::query()->with('platform')->find($request->socialAccountId);
        if ($account === null || $account->platform?->key !== InstagramOAuthProvider::PROVIDER) {
            return PublishResult::permanentFailure('ACCOUNT_MISSING', 'Le compte Instagram de cette publication n\'existe plus.'.self::NOTHING);
        }
        $media = $this->prepareMedia($request);
        if ($media instanceof PublishResult) {
            return $media;
        }
        $plan = $this->plan($request->format, $media);
        if ($plan instanceof PublishResult) {
            return $plan;
        }
        $caption = $this->caption($request);
        if ($plan !== 'STORIES' && $refusal = $this->captionRefusal($caption)) {
            return $refusal;
        }

        try {
            $token = $this->tokens->accessTokenFor($account);
        } catch (OAuthException $e) {
            return $e->transient
                ? PublishResult::temporaryFailure($e->errorCode, $e->getMessage().self::NOTHING)
                : PublishResult::permanentFailure($e->errorCode, $e->getMessage().self::NOTHING);
        }

        $user = rawurlencode($account->external_account_id);
        $meta = array_filter(['media_type' => $plan, 'first_comment' => filled($request->options['firstComment'] ?? null) ? 'not_supported' : null]);

        // --- 1. Conteneur(s) -----------------------------------------------------------------------
        if ($plan === 'CAROUSEL') {
            $children = [];
            foreach ($media as $item) {
                $child = $this->post($token, "{$user}/media", ['image_url' => $item['url'], 'is_carousel_item' => 'true']);
                if (! $child instanceof Response) {
                    return $child;
                }
                if (! $this->ok($child)) {
                    return $this->fromApiError($child, $account);
                }
                $children[] = $this->containerId($child);
            }
            $fields = ['media_type' => 'CAROUSEL', 'children' => implode(',', $children), 'caption' => $caption];
        } else {
            $fields = match ($plan) {
                'IMAGE' => ['image_url' => $media[0]['url'], 'caption' => $caption],
                'REELS' => array_filter(['media_type' => 'REELS', 'video_url' => $media[0]['url'], 'caption' => $caption, 'share_to_feed' => 'true', 'cover_url' => $this->coverUrl($request)]),
                'STORIES' => ['media_type' => 'STORIES', $media[0]['video'] ? 'video_url' : 'image_url' => $media[0]['url']],
            };
        }
        $container = $this->post($token, "{$user}/media", $fields);
        if (! $container instanceof Response) {
            return $container;
        }
        if (! $this->ok($container)) {
            return $this->fromApiError($container, $account);
        }
        $containerId = $this->containerId($container);
        $meta['container_id'] = $containerId;

        // --- 2. Publication : vidéo → suivi jusqu'à la fin de la préparation ; photo → immédiate ------
        if ($plan === 'REELS' || ($plan === 'STORIES' && $media[0]['video'])) {
            return PublishResult::processing($containerId, $meta);
        }

        return $this->publishContainer($account, $token, $containerId, $meta);
    }

    public function checkStatus(SocialAccount $account, string $trackingId): PublishResult
    {
        $meta = ['container_id' => $trackingId];
        try {
            $token = $this->tokens->accessTokenFor($account);
            $response = Http::withToken($token)->acceptJson()->connectTimeout(10)->timeout(20)
                ->get($this->oauth->graphUrl(rawurlencode($trackingId)), ['fields' => 'status_code,status']);
        } catch (OAuthException $e) {
            // Impossible de vérifier pour l'instant (autorisation) : le suivi continue jusqu'au délai maximal.
            return PublishResult::temporaryFailure($e->errorCode, $e->getMessage());
        } catch (ConnectionException) {
            return PublishResult::temporaryFailure('NETWORK_ERROR', 'Instagram est momentanément injoignable.');
        }
        if (! $this->ok($response)) {
            return $response->serverError() || in_array((int) $response->json('error.code'), [4, 17, 32, 613], true)
                ? PublishResult::temporaryFailure('STATUS_UNAVAILABLE', 'Instagram ne répond pas pour le moment : nouvelle vérification plus tard.', $response->status())
                : PublishResult::unknown('STATUS_UNAVAILABLE', 'Instagram ne permet pas de vérifier cette publication. Vérifiez sur Instagram avant de relancer.', $meta);
        }

        $status = (string) $response->json('status_code');
        $meta['status'] = $status;

        return match ($status) {
            'IN_PROGRESS' => PublishResult::processing($trackingId, $meta),
            'FINISHED' => $this->publishFromStatus($account, $token, $trackingId, $meta),
            'ERROR' => PublishResult::permanentFailure('MEDIA_PROCESSING_FAILED', 'Instagram n\'a pas pu préparer la vidéo (format, durée ou dimensions non acceptés). Vérifiez le fichier puis réessayez.'.self::NOTHING, null, $meta + ['detail' => mb_substr((string) $response->json('status'), 0, 200)]),
            'EXPIRED' => PublishResult::permanentFailure('CONTAINER_EXPIRED', 'La vidéo préparée par Instagram a expiré avant d\'être publiée. Relancez la publication.'.self::NOTHING, null, $meta),
            'PUBLISHED' => PublishResult::unknown('ALREADY_PUBLISHED', 'Instagram indique que ce contenu est déjà publié. Vérifiez sur Instagram avant de relancer.', $meta),
            default => PublishResult::unknown('UNEXPECTED_STATUS', 'Statut Instagram inattendu. Vérifiez sur Instagram avant de relancer.', $meta),
        };
    }

    /**
     * Légende : texte de la variante (ou de la publication), puis les hashtags choisis qui n'y figurent pas déjà.
     */
    public function caption(PublishRequest $request): string
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
     * Type de publication Instagram selon le format choisi et les médias : IMAGE, CAROUSEL, REELS ou STORIES.
     *
     * @param  list<array{url: string, video: bool}>  $media
     */
    private function plan(string $format, array $media): string|PublishResult
    {
        $count = count($media);
        $videos = count(array_filter($media, fn (array $m) => $m['video']));
        $refuse = fn (string $code, string $message) => PublishResult::permanentFailure($code, 'Instagram : '.$message.self::NOTHING);

        if ($count === 0) {
            return $refuse('MEDIA_REQUIRED', 'une photo ou une vidéo est obligatoire.');
        }

        return match ($format) {
            'story' => $count === 1 ? 'STORIES' : $refuse('TOO_MANY_MEDIA', 'une story contient une seule photo ou une seule vidéo.'),
            'reel' => $count === 1 && $videos === 1 ? 'REELS' : $refuse('REEL_REQUIRES_ONE_VIDEO', 'un Reel contient une seule vidéo.'),
            'carousel', 'feed' => match (true) {
                $count === 1 && $format === 'feed' => $videos === 1 ? 'REELS' : 'IMAGE',
                $videos > 0 => $refuse('CAROUSEL_VIDEO_NOT_SUPPORTED', 'les carrousels avec vidéo ne sont pas encore pris en charge par SoxialConnect. Utilisez des photos, ou publiez la vidéo en Reel.'),
                $count < self::CAROUSEL_MIN => $refuse('CAROUSEL_TOO_SMALL', 'un carrousel contient au moins 2 photos.'),
                $count > self::CAROUSEL_MAX => $refuse('TOO_MANY_MEDIA', 'un carrousel contient au plus 10 photos.'),
                default => 'CAROUSEL',
            },
            default => $refuse('FORMAT_NOT_SUPPORTED', 'format de publication non pris en charge.'),
        };
    }

    private function captionRefusal(string $caption): ?PublishResult
    {
        $length = mb_strlen($caption);
        if ($length > self::CAPTION_LIMIT) {
            return PublishResult::permanentFailure('TEXT_TOO_LONG', "La légende dépasse la limite Instagram de 2200 caractères ({$length}).".self::NOTHING);
        }
        $hashtags = preg_match_all('/(^|\s)#[\p{L}\p{N}_]+/u', $caption);
        if ($hashtags > self::HASHTAG_LIMIT) {
            return PublishResult::permanentFailure('TOO_MANY_HASHTAGS', "Instagram accepte au plus 30 hashtags par publication ({$hashtags}).".self::NOTHING);
        }

        return null;
    }

    /**
     * Médias utilisables, dans l'ordre : URL signée de l'original et nature. Photos JPEG et vidéos MP4/MOV.
     *
     * @return list<array{url: string, video: bool}>|PublishResult
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
            if ($mime !== 'image/jpeg' && ! in_array($mime, self::VIDEO_TYPES, true)) {
                return PublishResult::permanentFailure('MEDIA_TYPE_NOT_SUPPORTED', 'Instagram n\'accepte que les photos JPEG et les vidéos MP4 ou MOV. Convertissez le fichier (une image PNG en JPEG, par exemple).'.self::NOTHING);
            }
            $prepared[] = ['url' => $url, 'video' => $mime !== 'image/jpeg'];
        }

        return $prepared;
    }

    /** Couverture d'un Reel choisie dans le Composer (image JPEG de la médiathèque), sinon null. */
    private function coverUrl(PublishRequest $request): ?string
    {
        $id = $request->options['coverMediaId'] ?? null;
        $cover = is_string($id) ? Media::query()->find($id) : null;

        return $cover !== null && strtolower((string) $cover->mime_type) === 'image/jpeg'
            ? $this->storage->temporaryUrl($cover, MediaStorage::ORIGINAL)
            : null;
    }

    /**
     * POST /{ig-user-id}/media_publish puis lien public (permalink, au mieux). Média pas encore prêt
     * (code 9007) : suivi, publication à la prochaine vérification.
     *
     * @param  array<string, mixed>  $meta
     */
    private function publishContainer(SocialAccount $account, string $token, string $containerId, array $meta): PublishResult
    {
        $response = $this->post($token, rawurlencode($account->external_account_id).'/media_publish', ['creation_id' => $containerId]);
        if (! $response instanceof Response) {
            return $response;
        }
        if (! $this->ok($response)) {
            if ((int) $response->json('error.code') === 9007) {
                return PublishResult::processing($containerId, $meta);
            }

            return $this->fromApiError($response, $account);
        }
        $id = $response->json('id');
        if (! is_string($id) || $id === '') {
            throw new RuntimeException('Réponse Instagram sans identifiant de publication : résultat inconnu.');
        }

        return PublishResult::success($id, $this->permalink($token, $id), $meta + ['media_id' => $id]);
    }

    /**
     * Publication déclenchée par le suivi : une interruption pendant media_publish = résultat inconnu
     * (jamais relancé à l'aveugle), une erreur classée = résultat de l'adaptateur.
     *
     * @param  array<string, mixed>  $meta
     */
    private function publishFromStatus(SocialAccount $account, string $token, string $containerId, array $meta): PublishResult
    {
        try {
            return $this->publishContainer($account, $token, $containerId, $meta);
        } catch (RuntimeException) {
            return PublishResult::unknown('PUBLISH_RESULT_UNKNOWN', 'La publication Instagram a été interrompue : vérifiez sur Instagram avant de relancer.', $meta);
        }
    }

    private function permalink(string $token, string $mediaId): ?string
    {
        try {
            $response = Http::withToken($token)->acceptJson()->connectTimeout(5)->timeout(10)
                ->get($this->oauth->graphUrl(rawurlencode($mediaId)), ['fields' => 'permalink']);
        } catch (ConnectionException) {
            return null;
        }
        $link = $response->successful() ? $response->json('permalink') : null;

        return is_string($link) && str_starts_with($link, 'https://www.instagram.com/') ? $link : null;
    }

    /**
     * Response, ou PublishResult si Instagram est injoignable AVANT tout envoi (cURL 6 ou 7).
     *
     * @param  array<string, string>  $fields
     */
    private function post(string $token, string $path, array $fields): Response|PublishResult
    {
        try {
            return Http::withToken($token)->asForm()->acceptJson()->connectTimeout(10)->timeout(60)->post($this->oauth->graphUrl($path), $fields);
        } catch (ConnectionException $e) {
            if (preg_match('/cURL error (6|7):/', $e->getMessage())) {
                return PublishResult::temporaryFailure('NETWORK_ERROR', 'Instagram est momentanément injoignable. Nouvel essai automatique.'.self::NOTHING);
            }
            throw new RuntimeException('Envoi Instagram interrompu : résultat inconnu.');
        }
    }

    private function ok(Response $response): bool
    {
        return $response->successful() && ! is_array($response->json('error'));
    }

    /** Identifiant de conteneur renvoyé par Instagram ; absent = résultat inconnu (jamais d'identifiant inventé). */
    private function containerId(Response $response): string
    {
        $id = $response->json('id');
        if ((! is_string($id) && ! is_int($id)) || (string) $id === '') {
            throw new RuntimeException('Réponse Instagram sans identifiant de conteneur : résultat inconnu.');
        }

        return (string) $id;
    }

    /**
     * Erreurs de l'API (corps { error: { message, type, code, error_subcode } }) : seuls le statut, les
     * codes et un message borné sont conservés, jamais le jeton.
     */
    private function fromApiError(Response $response, SocialAccount $account): PublishResult
    {
        $status = $response->status();
        $code = (int) $response->json('error.code');
        $subcode = (int) $response->json('error.error_subcode');
        $message = mb_substr(trim((string) ($response->json('error.error_user_msg') ?: $response->json('error.message'))), 0, 300);
        $meta = array_filter(['http_status' => $status, 'graph_code' => $code ?: null, 'graph_subcode' => $subcode ?: null, 'message' => $message ?: null]);

        if ($status >= 500 || in_array($code, [1, 2], true)) {
            throw new RuntimeException('Erreur serveur Instagram : résultat inconnu.');
        }

        return match (true) {
            $code === 190 => $this->tokenRejected($account, $meta),
            $subcode === 2207042 || $code === 9 => PublishResult::temporaryFailure('PUBLISH_LIMIT_REACHED', 'Limite quotidienne de publications Instagram atteinte pour ce compte (100 par 24 h). Nouvel essai automatique plus tard.'.self::NOTHING, $status, $meta),
            in_array($code, [4, 17, 32, 613], true) => PublishResult::temporaryFailure('RATE_LIMITED', 'Instagram limite temporairement les requêtes. Nouvel essai automatique.'.self::NOTHING, $status, $meta),
            $code === 10 || ($code >= 200 && $code < 300) => PublishResult::permanentFailure('PERMISSION_DENIED', 'Instagram refuse la publication pour ce compte (permission retirée). Reconnectez le compte Instagram.'.self::NOTHING, $status, $meta),
            $code === 36003 || ($subcode >= 2207000 && $subcode < 2208000) => PublishResult::permanentFailure('MEDIA_REJECTED', 'Instagram a refusé un fichier (format, taille, dimensions ou durée non acceptés, ou fichier injoignable). Vérifiez-le puis réessayez.'.self::NOTHING, $status, $meta),
            default => PublishResult::permanentFailure('INVALID_REQUEST', "Instagram a refusé la publication ({$status}).".self::NOTHING, $status, $meta),
        };
    }

    /** @param array<string, mixed> $meta */
    private function tokenRejected(SocialAccount $account, array $meta): PublishResult
    {
        $this->tokens->rejected($account);

        return PublishResult::permanentFailure('TOKEN_INVALID', 'Instagram a refusé l\'autorisation (retirée ou expirée). Reconnectez le compte Instagram.'.self::NOTHING, 401, $meta);
    }
}
