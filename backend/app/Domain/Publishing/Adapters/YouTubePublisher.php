<?php

namespace App\Domain\Publishing\Adapters;

use App\Domain\Media\Storage\MediaStorage;
use App\Domain\Publishing\Contracts\SocialPublisher;
use App\Domain\Publishing\Data\PublishRequest;
use App\Domain\Publishing\Data\PublishResult;
use App\Domain\Social\OAuth\OAuthException;
use App\Domain\Social\OAuth\YouTubeOAuthProvider;
use App\Domain\Social\Tokens\YouTubeTokenManager;
use App\Models\Media;
use App\Models\SocialAccount;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Adaptateur YouTube (YouTube Data API v3, videos.insert, envoi « resumable » officiel).
 *
 * Contrat SocialPublisher (docs/publishing.md §8) :
 * - succès UNIQUEMENT si YouTube renvoie la ressource vidéo créée avec son identifiant ;
 * - erreurs classées temporaire / définitive ; la relance reste décidée par PublishingService ;
 * - interruption PENDANT l'envoi du fichier (délai, coupure) : exception = résultat inconnu,
 *   jamais relancé automatiquement (la vidéo a pu être créée) ;
 * - le fichier est lu côté serveur depuis le stockage privé (aucune URL publique) ;
 * - vidéos toujours envoyées en « private » pendant cette phase.
 */
final class YouTubePublisher implements SocialPublisher
{
    /** Phase 1.5 : jamais de publication publique automatique. */
    public const PRIVACY_STATUS = 'private';

    private const INSERT_URL = 'https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&part=snippet,status';

    /** Inférieur au timeout du job (PublishPostVariantJob::$timeout = 300 s). */
    private const UPLOAD_TIMEOUT_SECONDS = 270;

    public function __construct(
        private readonly YouTubeOAuthProvider $oauth,
        private readonly YouTubeTokenManager $tokens,
        private readonly MediaStorage $storage,
    ) {}

    public function publish(PublishRequest $request): PublishResult
    {
        if (! $this->oauth->isConfigured()) {
            return PublishResult::notConfigured('La publication YouTube n\'est pas configurée sur ce serveur (identifiants Google absents). Rien n\'a été publié.');
        }

        $account = SocialAccount::query()->with('platform')->find($request->socialAccountId);
        if ($account === null || $account->platform?->key !== YouTubeOAuthProvider::PROVIDER) {
            return PublishResult::permanentFailure('ACCOUNT_MISSING', 'Le compte YouTube de cette publication n\'existe plus. Rien n\'a été publié.');
        }

        // --- Média : exactement une vidéo, lisible dans le stockage privé ---------------------
        $videos = array_values(array_filter($request->media, fn (array $m) => $m['type'] === 'video'));
        if (count($request->media) !== 1 || count($videos) !== 1) {
            return PublishResult::permanentFailure('MEDIA_INCOMPATIBLE', 'YouTube attend exactement une vidéo (ni image, ni plusieurs médias). Rien n\'a été publié.');
        }
        $media = Media::query()->find($videos[0]['id']);
        $path = $media ? $this->storage->variantPath($media, MediaStorage::ORIGINAL) : null;
        if ($media === null || $path === null || ! $this->storage->exists($path)) {
            return PublishResult::permanentFailure('MEDIA_NOT_FOUND', 'Le fichier vidéo est introuvable dans la médiathèque. Remplacez-le puis réessayez. Rien n\'a été publié.');
        }
        $mime = (string) $media->mime_type;
        $size = (int) $this->storage->disk()->size($path);
        if (! str_starts_with($mime, 'video/') || $size <= 0) {
            return PublishResult::permanentFailure('MEDIA_INCOMPATIBLE', 'Ce fichier n\'est pas une vidéo valide pour YouTube. Rien n\'a été publié.');
        }

        // --- Jeton valide (rafraîchi si nécessaire) --------------------------------------------
        try {
            $accessToken = $this->tokens->accessTokenFor($account);
        } catch (OAuthException $e) {
            return $this->fromOAuth($e);
        }

        // --- 1. Ouverture de la session d'envoi : aucune vidéo n'existe encore (relance sûre) ----
        $metadata = $this->metadata($request);
        try {
            $session = $this->openSession($accessToken, $metadata, $mime, $size);
            if ($session->status() === 401) {
                // Jeton refusé malgré sa date : un seul renouvellement forcé, puis un seul nouvel essai.
                $accessToken = $this->tokens->accessTokenFor($account, forceRefresh: true);
                $session = $this->openSession($accessToken, $metadata, $mime, $size);
            }
        } catch (OAuthException $e) {
            return $this->fromOAuth($e);
        } catch (ConnectionException) {
            return PublishResult::temporaryFailure('NETWORK_ERROR', 'YouTube est momentanément injoignable. Nouvel essai automatique. Rien n\'a été publié.');
        }
        if (! $session->successful()) {
            return $this->fromApiError($session, 'session');
        }
        $uploadUrl = $session->header('Location');
        if (! is_string($uploadUrl) || ! str_starts_with($uploadUrl, 'https://')) {
            return PublishResult::temporaryFailure('UPLOAD_SESSION_FAILED', 'YouTube n\'a pas ouvert la session d\'envoi. Nouvel essai automatique. Rien n\'a été publié.');
        }

        // --- 2. Envoi du fichier (flux, sans copie en mémoire) -----------------------------------
        $stream = $this->storage->readStream($path);
        if (! is_resource($stream)) {
            return PublishResult::permanentFailure('MEDIA_NOT_FOUND', 'Le fichier vidéo n\'a pas pu être lu. Rien n\'a été publié.');
        }
        try {
            $upload = Http::withToken($accessToken)
                ->timeout(self::UPLOAD_TIMEOUT_SECONDS)
                ->withHeaders(['Content-Length' => (string) $size])
                ->withBody(Utils::streamFor($stream), $mime)
                ->put($uploadUrl);
        } catch (ConnectionException) {
            // Envoi interrompu : YouTube a peut-être créé la vidéo → résultat inconnu (moteur).
            throw new RuntimeException('Envoi YouTube interrompu : résultat inconnu.');
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if (! $upload->successful()) {
            return $upload->status() === 308
                ? PublishResult::temporaryFailure('UPLOAD_INCOMPLETE', 'L\'envoi de la vidéo est incomplet. Nouvel essai automatique. Rien n\'a été publié.', 308)
                : $this->fromApiError($upload, 'upload');
        }

        // --- 3. Confirmation : la ressource vidéo créée, avec son identifiant ------------------
        $videoId = $upload->json('id');
        if ($upload->json('kind') !== 'youtube#video' || ! is_string($videoId) || $videoId === '') {
            throw new RuntimeException('Réponse YouTube invalide après l\'envoi : résultat inconnu.');
        }
        $uploadStatus = $upload->json('status.uploadStatus');
        if (in_array($uploadStatus, ['rejected', 'failed', 'deleted'], true)) {
            return PublishResult::permanentFailure('VIDEO_REJECTED', 'YouTube a refusé la vidéo après l\'envoi ('.$uploadStatus.'). Vérifiez-la dans YouTube Studio.', $upload->status(), [
                'video_id' => $videoId,
                'upload_status' => $uploadStatus,
                'rejection_reason' => $upload->json('status.rejectionReason'),
            ]);
        }

        return PublishResult::success($videoId, 'https://www.youtube.com/watch?v='.rawurlencode($videoId), [
            'upload_status' => $uploadStatus,
            'privacy_status' => $upload->json('status.privacyStatus'),
        ]);
    }

    /** @param array<string, mixed> $metadata */
    private function openSession(string $accessToken, array $metadata, string $mime, int $size): Response
    {
        return Http::withToken($accessToken)
            ->acceptJson()
            ->timeout(30)
            ->withHeaders(['X-Upload-Content-Type' => $mime, 'X-Upload-Content-Length' => (string) $size])
            ->post(self::INSERT_URL, $metadata);
    }

    /**
     * Ressource vidéo envoyée à YouTube (snippet + status). Les textes de la variante priment,
     * puis ceux de la publication ; rien n'est modifié dans SoxialConnect.
     *
     * @return array<string, mixed>
     */
    public function metadata(PublishRequest $request): array
    {
        $options = $request->options;
        // YouTube refuse « < » et « > » dans le titre et la description.
        $clean = fn (?string $text) => trim(str_replace(['<', '>'], '', (string) $text));

        $title = $clean($options['title'] ?? null)
            ?: $clean($request->title)
            ?: $clean(Str::before(trim($request->body), "\n"));
        $description = $clean($options['description'] ?? null) ?: $clean($request->body);
        $tags = array_values(array_filter(array_map(
            fn ($tag) => is_string($tag) ? trim(ltrim($tag, '#')) : '',
            (array) ($options['hashtags'] ?? []),
        )));

        $snippet = array_filter([
            'title' => Str::limit($title !== '' ? $title : 'Vidéo', 100, ''),
            'description' => Str::limit($description, 4900, ''),
            'tags' => $tags ?: null,
            'categoryId' => config('services.youtube.category_id') ?: null,
        ], fn ($v) => $v !== null && $v !== '');

        $status = ['privacyStatus' => self::PRIVACY_STATUS];
        if (isset($options['madeForKids'])) {
            $status['selfDeclaredMadeForKids'] = (bool) $options['madeForKids'];
        }

        return ['snippet' => $snippet, 'status' => $status];
    }

    private function fromOAuth(OAuthException $e): PublishResult
    {
        return $e->transient
            ? PublishResult::temporaryFailure($e->errorCode, $e->getMessage().' Rien n\'a été publié.')
            : PublishResult::permanentFailure($e->errorCode, $e->getMessage().' Rien n\'a été publié.');
    }

    /** Erreurs de l'API (format Google : error.errors[0].reason) — ni token ni corps brut conservés. */
    private function fromApiError(Response $response, string $stage): PublishResult
    {
        $status = $response->status();
        $reason = (string) ($response->json('error.errors.0.reason') ?? $response->json('error.status') ?? '');
        $meta = ['reason' => $reason ?: null, 'stage' => $stage];
        $nothing = ' Rien n\'a été publié.';

        return match (true) {
            in_array($reason, ['quotaExceeded', 'dailyLimitExceeded'], true) => PublishResult::temporaryFailure('QUOTA_EXCEEDED', 'Quota YouTube du projet atteint. Nouvel essai plus tard (le quota se renouvelle chaque jour).'.$nothing, $status, $meta),
            $status === 429 || in_array($reason, ['rateLimitExceeded', 'userRateLimitExceeded'], true) => PublishResult::temporaryFailure('RATE_LIMITED', 'Trop de requêtes vers YouTube. Nouvel essai automatique.'.$nothing, $status, $meta),
            $reason === 'youtubeSignupRequired' => PublishResult::permanentFailure('CHANNEL_REQUIRED', 'Ce compte Google n\'a pas de chaîne YouTube active.'.$nothing, $status, $meta),
            $status === 401 => PublishResult::permanentFailure('TOKEN_INVALID', 'YouTube a refusé l\'autorisation. Reconnectez le compte puis réessayez.'.$nothing, $status, $meta),
            $status === 403 => PublishResult::permanentFailure('PERMISSION_DENIED', 'Permission YouTube insuffisante (mise en ligne non autorisée). Reconnectez le compte en acceptant toutes les autorisations.'.$nothing, $status, $meta),
            $status === 413 || $reason === 'uploadLimitExceeded' => PublishResult::permanentFailure('UPLOAD_LIMIT_EXCEEDED', 'Vidéo trop volumineuse ou limite d\'envoi YouTube atteinte pour cette chaîne.'.$nothing, $status, $meta),
            $status === 400 => PublishResult::permanentFailure('INVALID_REQUEST', 'YouTube a refusé les informations de la vidéo (titre, description, mots-clés ou format).'.$nothing, $status, $meta),
            $status >= 500 => PublishResult::temporaryFailure('PLATFORM_UNAVAILABLE', 'YouTube rencontre un problème temporaire. Nouvel essai automatique.'.$nothing, $status, $meta),
            default => PublishResult::permanentFailure('HTTP_ERROR', "YouTube a répondu par une erreur ({$status}).".$nothing, $status, $meta),
        };
    }
}
