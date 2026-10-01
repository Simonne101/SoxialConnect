<?php

namespace App\Domain\Publishing\Adapters;

use App\Domain\Media\Storage\MediaStorage;
use App\Domain\Publishing\Contracts\SocialPublisher;
use App\Domain\Publishing\Contracts\TracksPublicationStatus;
use App\Domain\Publishing\Data\PublishRequest;
use App\Domain\Publishing\Data\PublishResult;
use App\Domain\Social\OAuth\OAuthException;
use App\Domain\Social\OAuth\TikTokOAuthProvider;
use App\Domain\Social\TikTok\TikTokApiException;
use App\Domain\Social\TikTok\TikTokCreatorInfo;
use App\Domain\Social\Tokens\TikTokTokenManager;
use App\Models\Media;
use App\Models\SocialAccount;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Adaptateur TikTok — Content Posting API, Direct Post, transfert FILE_UPLOAD (docs/publishing.md §12).
 *
 * 1. Contrôles : compte, UNE vidéo lisible dans le stockage privé (aucune URL publique), taille.
 * 2. creator_info : confidentialité, interactions et durée revérifiées au moment de l'envoi ;
 *    configuration non autorisée → échec définitif SANS appeler video/init.
 * 3. video/init → publish_id + upload_url ; 4. envoi séquentiel des morceaux (206… puis 201).
 * 5. Fichier entièrement transmis → PublishResult::processing(publish_id) : le moteur suit le
 *    statut (checkStatus) jusqu'à PUBLISH_COMPLETE / FAILED. Jamais de succès supposé, jamais de
 *    nouvel init automatique après une transmission complète.
 */
final class TikTokPublisher implements SocialPublisher, TracksPublicationStatus
{
    private const INIT_URL = 'https://open.tiktokapis.com/v2/post/publish/video/init/';

    private const STATUS_URL = 'https://open.tiktokapis.com/v2/post/publish/status/fetch/';

    /** Limites officielles (Media Transfer Guide) : morceaux de 5 à 64 Mo, 1000 au maximum, 10 min par l'API. */
    private const MIN_CHUNK_BYTES = 5 * 1024 * 1024;

    private const MAX_CHUNKS = 1000;

    private const API_MAX_DURATION_SEC = 600;

    private const CHUNK_TIMEOUT_SECONDS = 60;

    /** Confidentialité choisie dans le Composer → valeur TikTok (privacy_level). */
    private const PRIVACY = [
        'public' => 'PUBLIC_TO_EVERYONE',
        'friends' => 'MUTUAL_FOLLOW_FRIENDS',
        'followers' => 'FOLLOWER_OF_CREATOR',
        'private' => 'SELF_ONLY',
    ];

    private const VIDEO_MIMES = ['video/mp4', 'video/quicktime', 'video/webm'];

    public function __construct(
        private readonly TikTokOAuthProvider $oauth,
        private readonly TikTokTokenManager $tokens,
        private readonly TikTokCreatorInfo $creatorInfo,
        private readonly MediaStorage $storage,
    ) {}

    public function publish(PublishRequest $request): PublishResult
    {
        if (! $this->oauth->isConfigured()) {
            return PublishResult::notConfigured('La publication TikTok n\'est pas configurée sur ce serveur (identifiants TikTok absents). Rien n\'a été publié.');
        }

        $account = SocialAccount::query()->with('platform')->find($request->socialAccountId);
        if ($account === null || $account->platform?->key !== TikTokOAuthProvider::PROVIDER) {
            return PublishResult::permanentFailure('ACCOUNT_MISSING', 'Le compte TikTok de cette publication n\'existe plus. Rien n\'a été publié.');
        }

        // --- 1. Média : exactement une vidéo, lue côté serveur dans le stockage privé ------------
        $videos = array_values(array_filter($request->media, fn (array $m) => $m['type'] === 'video'));
        if (count($request->media) !== 1 || count($videos) !== 1) {
            return PublishResult::permanentFailure('MEDIA_INCOMPATIBLE', 'TikTok attend exactement une vidéo. Rien n\'a été publié.');
        }
        $media = Media::query()->find($videos[0]['id']);
        $path = $media ? $this->storage->variantPath($media, MediaStorage::ORIGINAL) : null;
        if ($media === null || $path === null || ! $this->storage->exists($path)) {
            return PublishResult::permanentFailure('MEDIA_NOT_FOUND', 'Le fichier vidéo est introuvable dans la médiathèque. Remplacez-le puis réessayez. Rien n\'a été publié.');
        }
        $mime = (string) $media->mime_type;
        $size = (int) $this->storage->disk()->size($path);
        if (! in_array($mime, self::VIDEO_MIMES, true) || $size <= 0) {
            return PublishResult::permanentFailure('MEDIA_INCOMPATIBLE', 'Format non accepté par TikTok (MP4, MOV ou WebM ; H.264 recommandé). Rien n\'a été publié.');
        }
        $maxBytes = (int) config('services.tiktok.max_upload_mb', 500) * 1024 * 1024;
        if ($size > $maxBytes) {
            return PublishResult::permanentFailure('MEDIA_TOO_LARGE', 'Vidéo trop volumineuse pour l\'envoi vers TikTok (maximum '.config('services.tiktok.max_upload_mb', 500).' Mo). Rien n\'a été publié.');
        }

        // --- 2. Réglages choisis dans le Composer (aucune valeur par défaut pour la confidentialité) ---
        $options = $request->options;
        $privacy = self::PRIVACY[$options['privacy'] ?? ''] ?? null;
        if ($privacy === null) {
            return PublishResult::permanentFailure('PRIVACY_REQUIRED', 'Choisissez la confidentialité de la vidéo TikTok dans le Composer. Rien n\'a été publié.');
        }
        $allowComments = (bool) ($options['allowComments'] ?? false);
        $allowDuet = (bool) ($options['allowDuet'] ?? false);
        $allowStitch = (bool) ($options['allowStitch'] ?? false);
        $commercial = (bool) ($options['commercialContent'] ?? false);
        $brandOrganic = $commercial && (bool) ($options['brandOrganic'] ?? false);
        $brandContent = $commercial && (bool) ($options['brandContent'] ?? false);
        if ($commercial && ! $brandOrganic && ! $brandContent) {
            return PublishResult::permanentFailure('COMMERCIAL_CONTENT_INCOMPLETE', 'Contenu commercial activé : indiquez « Votre marque » et/ou « Contenu de marque ». Rien n\'a été publié.');
        }

        try {
            // --- 3. Informations du créateur, revérifiées au moment de l'envoi --------------------
            $creator = $this->creatorInfo->fetch($account);
            if (! in_array($privacy, $creator['privacy_level_options'], true)) {
                return PublishResult::permanentFailure('PRIVACY_NOT_ALLOWED', 'Cette confidentialité n\'est pas disponible pour ce compte TikTok. Modifiez-la dans le Composer. Rien n\'a été publié.');
            }
            if (($allowComments && $creator['comment_disabled']) || ($allowDuet && $creator['duet_disabled']) || ($allowStitch && $creator['stitch_disabled'])) {
                return PublishResult::permanentFailure('INTERACTION_NOT_ALLOWED', 'Une interaction choisie (commentaires, Duo ou Stitch) est désactivée sur ce compte TikTok. Rien n\'a été publié.');
            }
            $maxDuration = min(self::API_MAX_DURATION_SEC, $creator['max_video_post_duration_sec'] ?? self::API_MAX_DURATION_SEC);
            if ($media->duration_ms !== null && $media->duration_ms > $maxDuration * 1000) {
                return PublishResult::permanentFailure('VIDEO_TOO_LONG', "Vidéo trop longue pour ce compte TikTok (maximum {$maxDuration} s). Rien n'a été publié.");
            }

            // --- 4. Initialisation : aucun contenu n'existe encore (relance sûre en cas d'échec) ---
            [$chunkSize, $chunkCount] = $this->chunking($size);
            $init = $this->apiPost($account, self::INIT_URL, [
                'post_info' => [
                    'title' => $this->caption($request),
                    'privacy_level' => $privacy,
                    'disable_comment' => ! $allowComments,
                    'disable_duet' => ! $allowDuet,
                    'disable_stitch' => ! $allowStitch,
                    'brand_organic_toggle' => $brandOrganic,
                    'brand_content_toggle' => $brandContent,
                ],
                'source_info' => [
                    'source' => 'FILE_UPLOAD',
                    'video_size' => $size,
                    'chunk_size' => $chunkSize,
                    'total_chunk_count' => $chunkCount,
                ],
            ]);
        } catch (OAuthException $e) {
            return $this->fromOAuth($e);
        } catch (TikTokApiException $e) {
            return $this->fromApi($e);
        } catch (ConnectionException) {
            return PublishResult::temporaryFailure('NETWORK_ERROR', 'TikTok est momentanément injoignable. Nouvel essai automatique. Rien n\'a été publié.');
        }

        $publishId = $init->json('data.publish_id');
        $uploadUrl = $init->json('data.upload_url');
        if (! is_string($publishId) || $publishId === '' || ! is_string($uploadUrl) || ! str_starts_with($uploadUrl, 'https://')) {
            return PublishResult::temporaryFailure('UPLOAD_SESSION_FAILED', 'TikTok n\'a pas ouvert l\'envoi de la vidéo. Nouvel essai automatique. Rien n\'a été publié.');
        }

        // --- 5. Envoi séquentiel des morceaux ------------------------------------------------------
        $stream = $this->storage->readStream($path);
        if (! is_resource($stream)) {
            return PublishResult::permanentFailure('MEDIA_NOT_FOUND', 'Le fichier vidéo n\'a pas pu être lu. Rien n\'a été publié.');
        }
        try {
            for ($index = 0; $index < $chunkCount; $index++) {
                $first = $index * $chunkSize;
                $length = $index === $chunkCount - 1 ? $size - $first : $chunkSize;
                $bytes = $this->readExactly($stream, $length);
                if ($bytes === null) {
                    return PublishResult::permanentFailure('MEDIA_NOT_FOUND', 'Le fichier vidéo n\'a pas pu être lu entièrement. Rien n\'a été publié.');
                }
                $isLast = $index === $chunkCount - 1;

                try {
                    $response = Http::timeout(self::CHUNK_TIMEOUT_SECONDS)
                        ->withHeaders([
                            'Content-Range' => 'bytes '.$first.'-'.($first + $length - 1).'/'.$size,
                            'Content-Length' => (string) $length,
                        ])
                        ->withBody($bytes, $mime)
                        ->put($uploadUrl);
                } catch (ConnectionException) {
                    if ($isLast) {
                        // Dernier morceau parti sans réponse : la vidéo est peut-être complète.
                        // Aucun nouvel envoi : le suivi du publish_id tranchera.
                        return PublishResult::processing($publishId, ['publish_id' => $publishId, 'upload' => 'response_lost', 'privacy_level' => $privacy]);
                    }

                    return PublishResult::temporaryFailure('UPLOAD_INTERRUPTED', 'Envoi de la vidéo interrompu avant la fin. Nouvel essai automatique. Rien n\'a été publié.');
                }
                unset($bytes);

                $expected = $isLast ? [200, 201] : [206];
                if (! in_array($response->status(), $expected, true)) {
                    return $response->serverError() || $response->status() === 429 || in_array($response->status(), [200, 201, 206], true)
                        ? PublishResult::temporaryFailure('UPLOAD_FAILED', 'TikTok n\'a pas accepté l\'envoi de la vidéo ('.$response->status().'). Nouvel essai automatique. Rien n\'a été publié.', $response->status())
                        : PublishResult::permanentFailure('UPLOAD_REJECTED', 'TikTok a refusé le fichier vidéo ('.$response->status().'). Rien n\'a été publié.', $response->status());
                }
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        // --- 6. Fichier entièrement transmis : traitement chez TikTok, suivi par le moteur -------
        return PublishResult::processing($publishId, ['publish_id' => $publishId, 'privacy_level' => $privacy, 'chunks' => $chunkCount]);
    }

    /**
     * Statut d'une publication (POST /v2/post/publish/status/fetch/). Jamais de nouvel envoi.
     */
    public function checkStatus(SocialAccount $account, string $trackingId): PublishResult
    {
        try {
            $response = $this->apiPost($account, self::STATUS_URL, ['publish_id' => $trackingId]);
        } catch (OAuthException $e) {
            // Impossible de vérifier pour l'instant (autorisation) : le suivi continue jusqu'au délai maximal.
            return PublishResult::temporaryFailure($e->errorCode, $e->getMessage());
        } catch (TikTokApiException $e) {
            return $e->transient
                ? PublishResult::temporaryFailure($e->errorCode, $e->getMessage(), $e->httpStatus)
                : PublishResult::unknown('STATUS_UNAVAILABLE', 'TikTok ne permet pas de vérifier cette publication. Vérifiez sur TikTok avant de relancer.', ['status_error' => $e->errorCode]);
        } catch (ConnectionException) {
            return PublishResult::temporaryFailure('NETWORK_ERROR', 'TikTok est momentanément injoignable.');
        }

        $status = (string) $response->json('data.status');
        $meta = ['publish_id' => $trackingId, 'status' => $status];

        return match ($status) {
            'PROCESSING_UPLOAD', 'PROCESSING_DOWNLOAD' => PublishResult::processing($trackingId, $meta),
            'PUBLISH_COMPLETE' => $this->completed($trackingId, $response, $meta),
            'FAILED' => PublishResult::permanentFailure('PUBLISH_FAILED', 'TikTok n\'a pas pu publier la vidéo ('.$this->failReason($response).').', null, $meta + ['fail_reason' => $this->failReason($response)]),
            default => PublishResult::unknown('UNEXPECTED_STATUS', 'Statut TikTok inattendu. Vérifiez sur TikTok avant de relancer.', $meta),
        };
    }

    /**
     * Légende TikTok (post_info.title, 2200 caractères au maximum) : texte de la variante ou de la
     * publication, puis les hashtags choisis qui n'y figurent pas déjà.
     */
    public function caption(PublishRequest $request): string
    {
        $text = trim($request->body);
        $tags = array_values(array_filter(array_map(
            fn ($t) => is_string($t) ? trim(ltrim($t, '#')) : '',
            (array) ($request->options['hashtags'] ?? []),
        )));
        foreach ($tags as $tag) {
            if (! preg_match('/(^|\s)#'.preg_quote($tag, '/').'(\s|$)/iu', $text)) {
                $text .= ($text === '' ? '' : ' ').'#'.$tag;
            }
        }

        return Str::limit($text, 2200, '');
    }

    /**
     * Découpage conforme au Media Transfer Guide : moins de 5 Mo → un seul morceau ; sinon
     * morceaux de chunk_size, total = floor(taille / chunk_size), le dernier absorbe le reste.
     *
     * @return array{0: int, 1: int}
     */
    public function chunking(int $size): array
    {
        $chunk = max(self::MIN_CHUNK_BYTES, (int) config('services.tiktok.chunk_size_mb', 16) * 1024 * 1024);
        if ($size < self::MIN_CHUNK_BYTES || $size <= $chunk) {
            return [$size, 1];
        }

        return [$chunk, min(self::MAX_CHUNKS, intdiv($size, $chunk))];
    }

    /**
     * Appel JSON authentifié ; jeton refusé → un seul renouvellement forcé puis un seul nouvel essai.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws OAuthException|TikTokApiException|ConnectionException
     */
    private function apiPost(SocialAccount $account, string $url, array $payload): Response
    {
        $token = $this->tokens->accessTokenFor($account);
        foreach ([false, true] as $retried) {
            $response = Http::withToken($token)->acceptJson()->asJson()->timeout(30)->post($url, $payload);
            $error = TikTokApiException::fromResponse($response);
            if ($error === null) {
                return $response;
            }
            if (! $error->tokenInvalid || $retried) {
                throw $error;
            }
            $token = $this->tokens->accessTokenFor($account, forceRefresh: true);
        }

        throw new TikTokApiException('TOKEN_INVALID', 'TikTok a refusé l\'autorisation.', tokenInvalid: true);
    }

    /** @param array<string, mixed> $meta */
    private function completed(string $trackingId, Response $response, array $meta): PublishResult
    {
        // Identifiant public renvoyé seulement pour une vidéo publique validée par la modération.
        $ids = $response->json('data.publicaly_available_post_id');
        $postId = is_array($ids) ? ($ids[0] ?? null) : $ids;
        $postId = is_scalar($postId) && (string) $postId !== '' ? (string) $postId : null;

        return PublishResult::success($postId ?? $trackingId, null, $meta + ['post_id' => $postId]);
    }

    private function failReason(Response $response): string
    {
        $reason = $response->json('data.fail_reason');

        return is_string($reason) && preg_match('/^[a-z0-9_]{1,64}$/i', $reason) ? $reason : 'raison non communiquée';
    }

    /** @param resource $stream */
    private function readExactly($stream, int $length): ?string
    {
        $buffer = '';
        while (strlen($buffer) < $length && ! feof($stream)) {
            $part = fread($stream, $length - strlen($buffer));
            if ($part === false) {
                return null;
            }
            $buffer .= $part;
        }

        return strlen($buffer) === $length ? $buffer : null;
    }

    private function fromOAuth(OAuthException $e): PublishResult
    {
        return $e->transient
            ? PublishResult::temporaryFailure($e->errorCode, $e->getMessage().' Rien n\'a été publié.')
            : PublishResult::permanentFailure($e->errorCode, $e->getMessage().' Rien n\'a été publié.');
    }

    private function fromApi(TikTokApiException $e): PublishResult
    {
        return $e->transient
            ? PublishResult::temporaryFailure($e->errorCode, $e->getMessage(), $e->httpStatus)
            : PublishResult::permanentFailure($e->errorCode, $e->getMessage(), $e->httpStatus);
    }
}
