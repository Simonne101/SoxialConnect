<?php

namespace App\Domain\Video\Clipper;

use App\Domain\Media\Enums\MediaType;
use App\Domain\Quota\ClippingQuota;
use App\Domain\Quota\QuotaExceeded;
use App\Domain\Video\Enums\AcquisitionMethod;
use App\Domain\Video\Enums\AspectRatio;
use App\Domain\Video\Enums\ClipSource;
use App\Domain\Video\Enums\ClipStatus;
use App\Domain\Video\Enums\SourceOwnership;
use App\Domain\Video\Enums\VideoProjectKind;
use App\Domain\Video\Enums\VideoProjectStatus;
use App\Domain\Video\Enums\VideoSourceStatus;
use App\Domain\Video\Enums\VideoSourceType;
use App\Domain\Video\Sources\AcquisitionStep;
use App\Domain\Video\Sources\LibraryVideoSourceProvider;
use App\Jobs\AcquireVideoSourceJob;
use App\Jobs\AnalyzeClipSourceJob;
use App\Jobs\RenderClipJob;
use App\Models\Media;
use App\Models\User;
use App\Models\VideoClip;
use App\Models\VideoProject;
use App\Models\VideoSource;
use App\Models\VideoTranscript;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * YouTube Clipper URL-first (ADR-046, ADR-048, ADR-049). Le lien est l'entrée unique :
 * - référence YouTube (lien, identifiant, titre, chaîne, miniature via oEmbed officiel) : suffisante
 *   pour créer le projet et, avec Gemini, proposer des passages prévisualisables dans le lecteur YouTube ;
 * - source vidéo (VideoSource) : fichier rendable obtenu par une voie AUTORISÉE — fournisseur
 *   automatique (AcquireVideoSourceJob, VideoSourceProvider) ou, à défaut, import depuis la
 *   médiathèque en finalisation. Jamais de téléchargement depuis YouTube.
 * Dès que la source est prête, l'analyse reprend seule (FFprobe, transcription, recalage des passages)
 * puis les clips ≤ 60 s sont rendus par le moteur FFmpeg existant (RenderClipJob) → médiathèque.
 */
final class ClipperService
{
    /** Analyse en cours : aucune nouvelle demande de clip ni relance. */
    public const ANALYZING = [VideoProjectStatus::Pending, VideoProjectStatus::Analyzing, VideoProjectStatus::Transcribing, VideoProjectStatus::Detecting];

    public function __construct(
        private readonly YouTubeOEmbed $oembed,
        private readonly LibraryVideoSourceProvider $library,
        private readonly ClippingQuota $quotas,
    ) {}

    /**
     * Identification d'une vidéo YouTube (aperçu avant analyse) : lien validé, métadonnées oEmbed.
     *
     * @return array{video_id: string, url: string, title: string, author: string|null, thumbnail: string|null}
     *
     * @throws ClipperConflict
     */
    public function lookup(string $url): array
    {
        $youtube = YouTubeUrl::parse($url) ?? throw ClipperConflict::invalidUrl();

        return ['video_id' => $youtube->videoId, 'url' => $youtube->canonical(), ...$this->oembed->lookup($youtube)];
    }

    /**
     * Projet créé à partir du lien seul ; la vidéo source (facultative ici) peut être jointe plus tard.
     * rightsConfirmed : attestation de l'utilisateur (droits ou autorisation d'utiliser la vidéo), exigée
     * par la récupération automatique yt-dlp (ADR-059).
     *
     * @throws ClipperConflict
     */
    public function createProject(User $user, string $url, ?string $mediaId, AspectRatio $aspectRatio, bool $rightsConfirmed = false): VideoProject
    {
        $youtube = YouTubeUrl::parse($url) ?? throw ClipperConflict::invalidUrl();
        $window = max(0, (int) config('video.clipper.create_dedupe_seconds', 10));
        if ($window === 0) {
            return $this->newProject($user, $youtube, $mediaId, $aspectRatio, $rightsConfirmed);
        }

        // Double envoi (double clic, requête rejouée) : la même demande, dans la fenêtre, renvoie le projet
        // déjà créé au lieu d'en créer un second. Verrou par utilisateur et par vidéo.
        try {
            return Cache::lock("clipper-create:{$user->getKey()}:{$youtube->videoId}", 30)->block(20, function () use ($user, $youtube, $mediaId, $aspectRatio, $rightsConfirmed, $window) {
                $recent = VideoProject::query()
                    ->where('user_id', $user->getKey())
                    ->where('kind', VideoProjectKind::Clipping->value)
                    ->where('source_url', $youtube->canonical())
                    ->where('aspect_ratio', $aspectRatio->value)
                    ->where('status', '!=', VideoProjectStatus::Cancelled->value)
                    ->where('created_at', '>=', now()->subSeconds($window))
                    ->latest()->first();
                if ($recent !== null && ($mediaId === null || $recent->source_media_id === $mediaId)) {
                    return $recent;
                }

                return $this->newProject($user, $youtube, $mediaId, $aspectRatio, $rightsConfirmed);
            });
        } catch (LockTimeoutException) {
            throw ClipperConflict::analysisRunning();
        }
    }

    /**
     * @throws ClipperConflict
     * @throws QuotaExceeded projets actifs ou créations sur 24 h (ADR-071)
     */
    private function newProject(User $user, YouTubeUrl $youtube, ?string $mediaId, AspectRatio $aspectRatio, bool $rightsConfirmed): VideoProject
    {
        return $this->quotas->forNewProject($user, fn () => $this->createNewProject($user, $youtube, $mediaId, $aspectRatio, $rightsConfirmed));
    }

    private function createNewProject(User $user, YouTubeUrl $youtube, ?string $mediaId, AspectRatio $aspectRatio, bool $rightsConfirmed): VideoProject
    {
        $media = $mediaId !== null ? ($this->sourceMedia($user->getKey(), $mediaId) ?? throw ClipperConflict::invalidSource()) : null;
        $video = $this->oembed->lookup($youtube);
        $reused = $media === null ? $this->reusableSource($user, $youtube->videoId) : null;
        $media ??= $reused?->media;

        return DB::transaction(function () use ($user, $youtube, $media, $reused, $video, $aspectRatio, $rightsConfirmed) {
            $project = new VideoProject([
                'kind' => VideoProjectKind::Clipping,
                'source_type' => VideoSourceType::Url,
                'source_url' => $youtube->canonical(),
                'source_media_id' => $media?->getKey(),
                'title' => mb_substr($video['title'], 0, 255),
                'aspect_ratio' => $aspectRatio,
                'settings' => array_filter(['youtube_video_id' => $youtube->videoId, 'youtube_author' => $video['author'], 'youtube_thumbnail' => $video['thumbnail']]),
            ]);
            $project->user_id = $user->getKey();
            $project->forceFill(['status' => VideoProjectStatus::Pending, 'lock_version' => 0])->save();

            $source = $this->newSource($project, $youtube->videoId);
            if ($rightsConfirmed) {
                $this->attestRights($source);
            }
            if ($reused !== null) {
                $this->recordSource($source, $reused->media, AcquisitionMethod::Library, $reused->purgeAt);
            } elseif ($media !== null) {
                $this->recordSource($source, $media, AcquisitionMethod::Import, null);
            } else {
                $source->save();
                AcquireVideoSourceJob::dispatch($source->getKey())->afterCommit();
            }
            AnalyzeClipSourceJob::dispatch($project->getKey())->afterCommit();

            return $project;
        });
    }

    /**
     * Clip d'un passage (proposé par l'analyse ou choisi par l'utilisateur), toujours ≤ 60 s.
     * Sous-titres facultatifs : uniquement si la vidéo a été transcrite.
     *
     * @param  array{position?: string, size?: string}|null  $subtitleStyle
     *
     * @throws ClipperConflict
     */
    public function requestClip(VideoProject $project, int $startMs, int $endMs, AspectRatio $aspectRatio, ?string $title = null, ?VideoClip $suggestion = null, bool $subtitles = false, ?array $subtitleStyle = null): VideoClip
    {
        // Rendu (FFmpeg, Remotion) : place de projet actif exigée si le projet ne l'occupe pas déjà (ADR-071).
        return $this->quotas->forProjectWork($project, fn () => DB::transaction(function () use ($project, $startMs, $endMs, $aspectRatio, $title, $suggestion, $subtitles, $subtitleStyle) {
            $locked = VideoProject::query()->whereKey($project->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status === VideoProjectStatus::Cancelled) {
                throw ClipperConflict::cancelled();
            }
            if ($locked->source_media_id === null) {
                throw ClipperConflict::sourceRequired();
            }
            if (in_array($locked->status, self::ANALYZING, true) || $locked->duration_ms === null) {
                throw ClipperConflict::notReady();
            }
            $violation = ClipLimits::violation($startMs, $endMs, $locked->duration_ms);
            if ($violation !== null) {
                throw ClipperConflict::invalidRange($violation);
            }
            if ($subtitles && ! $this->hasTranscript($locked)) {
                throw ClipperConflict::subtitlesUnavailable();
            }

            if ($suggestion !== null) {
                $clip = VideoClip::query()->whereKey($suggestion->getKey())->where('video_project_id', $locked->getKey())->lockForUpdate()->firstOrFail();
                if (! in_array($clip->status, [ClipStatus::Suggested, ClipStatus::Edited, ClipStatus::Failed], true)) {
                    throw ClipperConflict::notRenderable();
                }
            } else {
                $clip = new VideoClip;
                $clip->video_project_id = $locked->getKey();
                $clip->source = ClipSource::Manual;
            }
            $clip->fill([
                'start_ms' => $startMs,
                'end_ms' => $endMs,
                'aspect_ratio' => $aspectRatio,
                'subtitles_enabled' => $subtitles,
                'subtitle_style' => $subtitles ? array_filter(['position' => $subtitleStyle['position'] ?? 'bottom', 'size' => $subtitleStyle['size'] ?? 'medium']) : null,
            ]);
            $clip->title = mb_substr(trim((string) $title) ?: ($clip->title ?: 'Clip — '.$locked->title), 0, 255);
            $clip->forceFill([
                'status' => ClipStatus::Rendering,
                'output_media_id' => null,
                'error_code' => null,
                'error_message' => null,
                'started_at' => null,
                'finished_at' => null,
                'render_stage' => null,
            ])->save();

            RenderClipJob::dispatch($clip->getKey())->afterCommit();

            return $clip;
        }));
    }

    /**
     * Finalisation par import (ADR-049) : l'utilisateur choisit le fichier de cette vidéo dans sa
     * médiathèque quand aucune acquisition automatique n'est possible.
     *
     * @throws ClipperConflict
     */
    public function attachSource(VideoProject $project, string $mediaId): VideoProject
    {
        $media = $this->sourceMedia($project->user_id, $mediaId) ?? throw ClipperConflict::invalidSource();

        // L'analyse (FFprobe, whisper, IA) reprend : place de projet actif exigée (ADR-071).
        return $this->quotas->forProjectWork($project, fn () => $this->linkSource($project, $media, AcquisitionMethod::Import));
    }

    /**
     * Source rendable disponible (import ou fournisseur autorisé) : liée au projet, puis l'analyse
     * reprend d'elle-même (FFprobe, transcription, recalage des passages proposés depuis le lien).
     *
     * @throws ClipperConflict
     */
    public function linkSource(VideoProject $project, Media $media, AcquisitionMethod $method, ?CarbonInterface $purgeAt = null): VideoProject
    {
        if ($media->size_bytes !== null && $media->size_bytes > (int) config('video.sources.max_bytes')) {
            throw ClipperConflict::sourceTooLarge();
        }

        return DB::transaction(function () use ($project, $media, $method, $purgeAt) {
            $locked = VideoProject::query()->whereKey($project->getKey())->lockForUpdate()->firstOrFail();
            if (in_array($locked->status, self::ANALYZING, true)) {
                throw ClipperConflict::analysisRunning();
            }
            if (VideoClip::query()->where('video_project_id', $locked->getKey())->where('status', ClipStatus::Rendering->value)->exists()) {
                throw ClipperConflict::notRenderable();
            }
            $locked->forceFill([
                'source_media_id' => $media->getKey(),
                'duration_ms' => null,
                'status' => VideoProjectStatus::Pending,
                'error' => null,
            ])->save();
            $source = VideoSource::query()->where('video_project_id', $locked->getKey())->lockForUpdate()->first()
                ?? $this->newSource($locked, (string) ($locked->settings['youtube_video_id'] ?? ''));
            $this->recordSource($source, $media, $method, $purgeAt);
            AnalyzeClipSourceJob::dispatch($locked->getKey())->afterCommit();

            return $locked;
        });
    }

    /**
     * Relance de l'analyse (ADR-049) après une erreur temporaire (Gemini 503, délai dépassé…) : même
     * projet, même source, transcription réutilisée si la source n'a pas changé ; seuls les passages
     * proposés (jamais les clips générés ou modifiés) sont remplacés.
     *
     * @throws ClipperConflict
     */
    public function reanalyze(VideoProject $project): VideoProject
    {
        return $this->quotas->forProjectWork($project, fn () => DB::transaction(function () use ($project) {
            $locked = VideoProject::query()->whereKey($project->getKey())->lockForUpdate()->firstOrFail();
            if (in_array($locked->status, self::ANALYZING, true)) {
                throw ClipperConflict::analysisRunning();
            }
            if (VideoClip::query()->where('video_project_id', $locked->getKey())->where('status', ClipStatus::Rendering->value)->exists()) {
                throw ClipperConflict::notRenderable();
            }
            VideoClip::query()->where('video_project_id', $locked->getKey())->where('status', ClipStatus::Suggested->value)->delete();
            $settings = $locked->settings ?? [];
            unset($settings['analysis'], $settings['analysis_error_code']);
            $locked->forceFill(['status' => VideoProjectStatus::Pending, 'error' => null, 'settings' => $settings])->save();
            AnalyzeClipSourceJob::dispatch($locked->getKey())->afterCommit();

            return $locked;
        }));
    }

    /**
     * Nouvelle tentative d'acquisition automatique (fournisseur indisponible, échec, source expirée).
     * Une acquisition déjà en cours n'est jamais doublée. rightsConfirmed : attestation de droits
     * enregistrée sur la source avant la relance (ADR-059).
     *
     * @throws ClipperConflict
     */
    public function retryAcquisition(VideoProject $project, bool $rightsConfirmed = false): VideoSource
    {
        return $this->quotas->forProjectWork($project, fn () => DB::transaction(function () use ($project, $rightsConfirmed) {
            $locked = VideoProject::query()->whereKey($project->getKey())->lockForUpdate()->firstOrFail();
            $source = VideoSource::query()->where('video_project_id', $locked->getKey())->lockForUpdate()->first()
                ?? $this->newSource($locked, (string) ($locked->settings['youtube_video_id'] ?? ''));
            if ($source->exists && $source->isReady() && $locked->source_media_id !== null) {
                throw ClipperConflict::sourceAlreadyReady();
            }
            if ($rightsConfirmed) {
                $this->attestRights($source);
            }
            if (in_array($source->status, [VideoSourceStatus::Resolving, VideoSourceStatus::WaitingForProvider], true)) {
                return $source;
            }
            $source->forceFill(['status' => VideoSourceStatus::Pending, 'attempts' => 0, 'next_check_at' => null, 'error_code' => null, 'error_message' => null, 'ownership_checked_at' => null])->save();
            AcquireVideoSourceJob::dispatch($source->getKey())->afterCommit();

            return $source;
        }));
    }

    /**
     * Annulation COOPÉRATIVE du traitement (ADR-051) : le projet passe « cancelled » ; chaque étape
     * vérifie cet état avant de démarrer et avant d'enregistrer son résultat (analyse, transcription,
     * acquisition), un job retardataire s'arrête donc sans effet. Un rendu FFmpeg en cours est
     * réellement interrompu (le moteur surveille l'état du clip et arrête le processus) ; une
     * transcription whisper.cpp déjà lancée va à son terme mais son résultat est ignoré. Les dossiers
     * de travail sont supprimés par chaque job ; les médias déjà publiés ne sont jamais touchés.
     *
     * @throws ClipperConflict
     */
    public function cancel(VideoProject $project): VideoProject
    {
        return DB::transaction(function () use ($project) {
            $locked = VideoProject::query()->whereKey($project->getKey())->lockForUpdate()->firstOrFail();
            $rendering = VideoClip::query()->where('video_project_id', $locked->getKey())->where('status', ClipStatus::Rendering->value)->lockForUpdate()->get();
            $source = VideoSource::query()->where('video_project_id', $locked->getKey())->lockForUpdate()->first();
            $acquiring = $source !== null && in_array($source->status, [VideoSourceStatus::Pending, VideoSourceStatus::Resolving, VideoSourceStatus::WaitingForProvider], true);
            if (! in_array($locked->status, self::ANALYZING, true) && $rendering->isEmpty() && ! $acquiring) {
                throw ClipperConflict::nothingToCancel();
            }

            $locked->forceFill(['status' => VideoProjectStatus::Cancelled, 'error' => 'Traitement annulé.'])->save();
            foreach ($rendering as $clip) {
                $clip->forceFill(['status' => ClipStatus::Failed, 'render_stage' => null, 'error_code' => 'CANCELLED', 'error_message' => 'Génération annulée.', 'finished_at' => now()])->save();
            }
            if ($acquiring) {
                $source->forceFill(['status' => VideoSourceStatus::Failed, 'next_check_at' => null, 'error_code' => 'CANCELLED', 'error_message' => 'Recherche de la source vidéo annulée.'])->save();
            }

            return $locked;
        });
    }

    /**
     * Fichier de cette vidéo déjà fourni par l'utilisateur dans un autre projet (ADR-053) : réutilisé
     * dès la création, sans copie, si ses limites (taille, durée) sont toujours respectées.
     *
     * @return object{media: Media, purgeAt: ?CarbonInterface}|null
     */
    private function reusableSource(User $user, string $videoId): ?object
    {
        if (! $this->library->isConfigured()) {
            return null;
        }
        $probe = new VideoSource;
        $probe->forceFill(['user_id' => $user->getKey(), 'provider' => VideoSource::PROVIDER_YOUTUBE, 'external_reference' => mb_substr($videoId, 0, 64)]);
        $step = $this->library->begin($probe);
        $media = $step->kind === AcquisitionStep::REUSED ? $this->sourceMedia($user->getKey(), $step->mediaId) : null;
        if ($media === null
            || ($media->size_bytes !== null && $media->size_bytes > (int) config('video.sources.max_bytes'))
            || ($media->duration_ms !== null && $media->duration_ms > (int) config('video.clipper.max_source_minutes', 120) * 60_000)) {
            return null;
        }

        return (object) ['media' => $media, 'purgeAt' => $step->purgeAt];
    }

    private function newSource(VideoProject $project, string $videoId): VideoSource
    {
        $source = new VideoSource;
        $source->forceFill([
            'user_id' => $project->user_id,
            'video_project_id' => $project->getKey(),
            'provider' => VideoSource::PROVIDER_YOUTUBE,
            'external_reference' => mb_substr($videoId, 0, 64),
            'source_type' => VideoSource::TYPE_YOUTUBE_VIDEO,
            'ownership_status' => SourceOwnership::Unverified,
            'status' => VideoSourceStatus::Pending,
            'attempts' => 0,
        ]);

        return $source;
    }

    /**
     * Attestation de l'utilisateur : il détient les droits ou l'autorisation d'utiliser cette vidéo
     * (ADR-059). Horodatée sur la source ; condition de la récupération automatique par yt-dlp.
     */
    private function attestRights(VideoSource $source): void
    {
        $source->forceFill(['metadata' => [...($source->metadata ?? []), 'rights_attested' => true, 'rights_attested_at' => now()->toIso8601String()]]);
        if ($source->exists) {
            $source->save();
        }
    }

    /** Fichier rendable enregistré sur la source (les imports de l'utilisateur ne sont jamais purgés). */
    private function recordSource(VideoSource $source, Media $media, AcquisitionMethod $method, ?CarbonInterface $purgeAt): void
    {
        $source->forceFill([
            'acquisition_method' => $method,
            'status' => VideoSourceStatus::Ready,
            'media_id' => $media->getKey(),
            'duration_ms' => $media->duration_ms,
            'size_bytes' => $media->size_bytes,
            'mime_type' => $media->mime_type,
            'purge_at' => $method === AcquisitionMethod::Import ? null : $purgeAt,
            'next_check_at' => null,
            'error_code' => null,
            'error_message' => null,
        ])->save();
    }

    /** Transcription horodatée disponible (sous-titres possibles). */
    public function hasTranscript(VideoProject $project): bool
    {
        return VideoTranscript::query()->where('video_project_id', $project->getKey())->where('status', 'ready')->exists();
    }

    public function deleteProject(VideoProject $project): void
    {
        $project->delete(); // suppression logique : les clips déjà publiés restent dans la médiathèque
    }

    /** Vidéo de la médiathèque utilisable par cet utilisateur (jamais un identifiant d'un autre compte). */
    public function sourceMedia(string $userId, ?string $mediaId): ?Media
    {
        if ($mediaId === null) {
            return null;
        }
        $media = Media::query()->where('user_id', $userId)->whereKey($mediaId)->first();

        return $media !== null && $media->type === MediaType::Video && $media->isUsable() ? $media : null;
    }
}
