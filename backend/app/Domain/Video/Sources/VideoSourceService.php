<?php

namespace App\Domain\Video\Sources;

use App\Domain\Media\Actions\ManageMedia;
use App\Domain\Media\Actions\UploadMedia;
use App\Domain\Media\Exceptions\MediaRejected;
use App\Domain\Media\Exceptions\MediaScanUnavailable;
use App\Domain\Quota\DiskGuard;
use App\Domain\Quota\DiskSpaceInsufficient;
use App\Domain\Quota\QuotaExceeded;
use App\Domain\Quota\StorageQuota;
use App\Domain\Video\Clipper\ClipperConflict;
use App\Domain\Video\Clipper\ClipperService;
use App\Domain\Video\Enums\AcquisitionMethod;
use App\Domain\Video\Enums\ClipStatus;
use App\Domain\Video\Enums\SourceOwnership;
use App\Domain\Video\Enums\VideoProjectStatus;
use App\Domain\Video\Enums\VideoSourceStatus;
use App\Domain\Video\Sources\YtDlp\YtDlpVideoSourceProvider;
use App\Jobs\AcquireVideoSourceJob;
use App\Models\Media;
use App\Models\VideoClip;
use App\Models\VideoProject;
use App\Models\VideoSource;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Acquisition de la source vidéo d'un projet du Clipper (ADR-049), exécutée par AcquireVideoSourceJob :
 *
 * 0. fichier de CETTE vidéo déjà présent dans la médiathèque de l'utilisateur (autre projet) : réutilisé
 *    sans copie ni requête réseau (LibraryVideoSourceProvider, ADR-053) ;
 * 1. propriété de la vidéo vérifiée par l'OAuth YouTube de l'utilisateur (lecture seule) ;
 * 2. premier fournisseur configuré qui prend la source en charge (VideoSourceProviders) — aucun :
 *    « unavailable » + code explicite (PROVIDER_NOT_CONFIGURED, NO_PROVIDER_FOR_SOURCE), action requise ;
 * 3. fournisseur en attente : relances espacées et bornées (jamais d'attente bloquante dans un worker) ;
 * 4. fichier obtenu : importé dans la médiathèque par UploadMedia (inspection, antivirus), taille
 *    plafonnée, conservé `retention_days` puis purgé ; une fois traité, lié au projet
 *    (ClipperService::linkSource) et l'analyse reprend seule.
 * Aucun succès simulé : seul un fichier réel, accepté par la médiathèque, rend la source « ready ».
 */
class VideoSourceService
{
    /** Codes « aucune voie d'acquisition » : diagnostic recalculé selon la configuration actuelle. */
    public const NO_PROVIDER_CODES = ['PROVIDER_NOT_CONFIGURED', 'NO_PROVIDER_FOR_SOURCE', 'VIDEO_DOWNLOAD_DISABLED', 'YTDLP_UNAVAILABLE'];

    public function __construct(
        private readonly VideoSourceProviders $providers,
        private readonly YouTubeOwnershipVerifier $ownership,
        private readonly UploadMedia $upload,
        private readonly StorageQuota $quota,
        private readonly DiskGuard $disk,
        private readonly ManageMedia $media,
        private readonly ClipperService $clipper,
        private readonly LibraryVideoSourceProvider $library,
    ) {}

    public function acquire(string $sourceId): void
    {
        $source = VideoSource::query()->with(['user', 'project'])->find($sourceId);
        if ($source === null || $source->project === null || in_array($source->status, [VideoSourceStatus::Ready, VideoSourceStatus::Expired], true)) {
            return; // supprimée, déjà prête ou purgée : un job dupliqué ne fait rien
        }
        if ($source->project->source_media_id !== null) {
            return; // un fichier a été importé entre-temps
        }
        if ($source->project->status === VideoProjectStatus::Cancelled) {
            return; // projet annulé : aucune étape suivante (ADR-051)
        }

        // Fichier déjà importé, traitement de la médiathèque en cours : finalisation.
        if ($source->status === VideoSourceStatus::Resolving && $source->media_id !== null) {
            $this->finalize($source);

            return;
        }

        // Fichier déjà disponible localement pour cette vidéo : aucune nouvelle acquisition ni import.
        if ($source->status !== VideoSourceStatus::WaitingForProvider && $this->library->isConfigured()) {
            $step = $this->library->begin($source);
            if ($step->kind === AcquisitionStep::REUSED) {
                $this->update($source, ['status' => VideoSourceStatus::Resolving, 'acquisition_method' => AcquisitionMethod::Library, 'media_id' => $step->mediaId, 'purge_at' => $step->purgeAt, 'attempts' => 0, 'error_code' => null, 'error_message' => null, 'next_check_at' => null]);
                $this->finalize($source);

                return;
            }
        }

        // Propriété vérifiée au lancement de l'acquisition : jamais revérifiée pendant l'attente d'un fournisseur
        // (sinon, au-delà de 24 h, la source repasserait « resolving » et un export en cours serait relancé).
        if ($source->status !== VideoSourceStatus::WaitingForProvider) {
            $this->verifyOwnership($source);
        }

        $pending = $source->status === VideoSourceStatus::WaitingForProvider ? $this->providers->named((string) ($source->metadata['provider'] ?? '')) : null;
        $candidates = $pending !== null ? [$pending] : $this->providers->candidates($source);
        Log::info('clipper.source_acquisition', [
            'source_id' => $source->getKey(),
            'video_id' => $source->external_reference,
            'ownership' => $source->ownership_status->value,
            'pending' => $pending?->name(),
            'configured' => array_map(fn (VideoSourceProvider $p) => $p->name(), $this->providers->configured()),
            'candidates' => array_map(fn (VideoSourceProvider $p) => $p->name(), $candidates),
        ]);
        if ($candidates === []) {
            [$code, $message] = $this->noProviderDiagnosis($source);
            $this->update($source, ['status' => VideoSourceStatus::Unavailable, 'acquisition_method' => null, 'error_code' => $code, 'error_message' => $message, 'next_check_at' => null]);

            return;
        }

        // Quotas (ADR-071) vérifiés AVANT tout téléchargement : espace restant de l'utilisateur, puis disque du
        // serveur (fichier téléchargé + copie dans la médiathèque), réservé le temps de l'acquisition.
        $minQuota = max(0, (int) config('quotas.storage.acquisition_min_mb', 50)) * 1024 * 1024;
        if ($this->quota->enabled() && $this->quota->remainingBytes((string) $source->user_id) < max(1, $minQuota)) {
            $this->update($source, ['status' => VideoSourceStatus::Failed, 'error_code' => 'STORAGE_QUOTA_EXCEEDED', 'error_message' => 'Espace de stockage insuffisant pour récupérer la vidéo : libérez de l\'espace dans la médiathèque puis relancez la récupération.', 'next_check_at' => null]);

            return;
        }
        try {
            $diskReservation = $this->disk->reserve(self::maxAcquiredBytes($source) * max(1, (int) config('quotas.disk.acquisition_factor', 2)), 'acquisition', (string) config('video.temp_path'));
        } catch (DiskSpaceInsufficient) {
            $this->update($source, ['status' => VideoSourceStatus::Failed, 'error_code' => DiskSpaceInsufficient::CODE, 'error_message' => 'Espace disque insuffisant sur le serveur pour récupérer la vidéo. Relancez la récupération plus tard.', 'next_check_at' => null]);

            return;
        }
        try {
            $this->acquireWith($source, $pending, $candidates);
        } finally {
            $this->disk->release($diskReservation);
        }
    }

    /** @param list<VideoSourceProvider> $candidates */
    private function acquireWith(VideoSource $source, ?VideoSourceProvider $pending, array $candidates): void
    {
        // Fournisseurs essayés dans l'ordre : une voie INUTILISABLE pour cette source (ex. consentement Google
        // absent) laisse sa chance à la suivante ; si toutes le sont, la première raison est conservée.
        $firstUnavailable = null;
        foreach ($candidates as $provider) {
            // Voie choisie enregistrée AVANT l'étape (un téléchargement yt-dlp dure plusieurs minutes) : l'interface
            // affiche « Récupération en cours » plutôt qu'un état périmé.
            if ($pending === null) {
                $this->update($source, ['status' => VideoSourceStatus::Resolving, 'acquisition_method' => $provider->method(), 'error_code' => null, 'error_message' => null]);
            }
            try {
                $step = $pending !== null ? $provider->poll($source) : $provider->begin($source);
            } catch (Throwable $e) {
                Log::error('clipper.source_provider_failed', ['source_id' => $source->getKey(), 'provider' => $provider->name(), 'exception' => $e::class]);
                $step = AcquisitionStep::failed('PROVIDER_ERROR', 'Le fournisseur de la vidéo a rencontré une erreur.', transient: true);
            }
            Log::info('clipper.source_provider_step', ['source_id' => $source->getKey(), 'provider' => $provider->name(), 'kind' => $step->kind, 'error_code' => $step->errorCode]);
            if ($step->kind !== AcquisitionStep::UNAVAILABLE) {
                $this->apply($source, $provider, $step);

                return;
            }
            $firstUnavailable ??= [$provider, $step];
        }
        $this->apply($source, $firstUnavailable[0], $firstUnavailable[1]);
    }

    /**
     * Taille maximale d'un fichier acquis automatiquement : limite du Clipper (video.sources.max_bytes) ET
     * limite des vidéos de la médiathèque (media.max_size_mb.video), où le fichier est importé ; pour une source
     * donnée, aussi l'espace de stockage restant de son propriétaire (ADR-071 : jamais un téléchargement qui ne
     * pourrait pas être importé).
     */
    public static function maxAcquiredBytes(?VideoSource $source = null): int
    {
        $max = max(1, min((int) config('video.sources.max_bytes'), (int) config('media.max_size_mb.video', 1024) * 1024 * 1024));
        if ($source !== null && $source->user_id !== null) {
            $max = max(1, min($max, app(StorageQuota::class)->remainingBytes((string) $source->user_id)));
        }

        return $max;
    }

    /**
     * Aucune voie utilisable pour cette source : code et message selon la configuration ACTUELLE. Une voie
     * désactivée ou mal installée (yt-dlp) est signalée comme telle, jamais confondue avec un échec de
     * téléchargement ni avec l'absence de toute voie.
     *
     * @return array{0: string, 1: string}
     */
    public function noProviderDiagnosis(VideoSource $source): array
    {
        foreach ($this->providers->all() as $provider) {
            if ($provider instanceof YtDlpVideoSourceProvider && $provider->supports($source) && ($inactive = $provider->inactiveReason()) !== null) {
                return [(string) $inactive->errorCode, (string) $inactive->message];
            }
        }
        $configured = $this->providers->configured() !== [];

        return [$configured ? 'NO_PROVIDER_FOR_SOURCE' : 'PROVIDER_NOT_CONFIGURED', $this->unavailableReason($source, $configured)];
    }

    /**
     * Diagnostic affiché pour une source restée « aucune voie » : recalculé (une voie a pu être activée
     * depuis), jamais le texte enregistré lors d'une tentative antérieure.
     *
     * @return array{0: string, 1: string}
     */
    public function currentNoProviderDiagnosis(VideoSource $source): array
    {
        if ($this->providers->for($source) !== null) {
            return [(string) $source->error_code, 'Une voie de récupération automatique est désormais disponible pour cette vidéo : relancez la récupération (elle est aussi relancée automatiquement).'];
        }

        return $this->noProviderDiagnosis($source);
    }

    /**
     * Sources restées « indisponibles » faute de fournisseur alors qu'un fournisseur est désormais configuré
     * (ex. export Google activé après coup) : nouvelle recherche, jamais pour un projet annulé ou déjà doté
     * d'un fichier. Renvoie le nombre de recherches relancées.
     */
    public function retryStaleUnavailable(): int
    {
        if ($this->providers->configured() === []) {
            return 0;
        }
        $count = 0;
        $stale = VideoSource::query()->with('project')
            ->where('status', VideoSourceStatus::Unavailable->value)
            ->whereIn('error_code', self::NO_PROVIDER_CODES)
            ->get();
        foreach ($stale as $source) {
            $project = $source->project;
            if ($project === null || $project->source_media_id !== null || $project->status === VideoProjectStatus::Cancelled) {
                continue;
            }
            // Aucune voie pour CETTE source : ne relancer que si une voie la prend désormais en charge.
            if ($source->error_code !== 'PROVIDER_NOT_CONFIGURED' && $this->providers->for($source) === null) {
                continue;
            }
            try {
                $this->clipper->retryAcquisition($project);
                $count++;
            } catch (ClipperConflict) {
                // Source prête entre-temps : rien à relancer.
            } catch (QuotaExceeded) {
                // Quota de clipping atteint (ADR-071) : nouvel essai au prochain passage.
            }
        }

        return $count;
    }

    /**
     * Purge des fichiers acquis automatiquement dont la conservation est dépassée (jamais un import
     * de l'utilisateur, jamais un fichier en cours d'utilisation). Renvoie le nombre de sources purgées.
     */
    public function expireDue(): int
    {
        $count = 0;
        $due = VideoSource::query()
            ->where('status', VideoSourceStatus::Ready->value)
            ->whereNotNull('purge_at')->where('purge_at', '<=', now())
            ->where('acquisition_method', '!=', AcquisitionMethod::Import->value)
            ->pluck('id');
        foreach ($due as $id) {
            $count += $this->expire((string) $id) ? 1 : 0;
        }

        return $count;
    }

    /** Média encore lié à une autre source prête ou à un autre projet (non supprimé). */
    private function stillUsed(Media $media, VideoSource $source): bool
    {
        return VideoSource::query()->where('media_id', $media->getKey())->whereKeyNot($source->getKey())->where('status', VideoSourceStatus::Ready->value)->exists()
            || VideoProject::query()->where('source_media_id', $media->getKey())->whereKeyNot($source->video_project_id)->exists();
    }

    private function expire(string $sourceId): bool
    {
        $media = DB::transaction(function () use ($sourceId): Media|false|null {
            $source = VideoSource::query()->whereKey($sourceId)->lockForUpdate()->first();
            if ($source === null || $source->status !== VideoSourceStatus::Ready || $source->acquisition_method === AcquisitionMethod::Import) {
                return false;
            }
            $project = VideoProject::query()->whereKey($source->video_project_id)->lockForUpdate()->first();
            if ($project !== null && (in_array($project->status, ClipperService::ANALYZING, true)
                || VideoClip::query()->where('video_project_id', $project->getKey())->where('status', ClipStatus::Rendering->value)->exists())) {
                return false; // utilisée en ce moment : purge au prochain passage
            }
            if ($project !== null && $project->source_media_id === $source->media_id) {
                $project->forceFill(['source_media_id' => null])->save();
            }
            $media = $source->media_id !== null ? Media::query()->find($source->media_id) : null;
            // Fichier partagé avec un autre projet (réutilisation, ADR-053) : jamais supprimé tant qu'il sert.
            if ($media !== null && $this->stillUsed($media, $source)) {
                $media = null;
            }
            $source->forceFill(['status' => VideoSourceStatus::Expired, 'media_id' => null, 'purge_at' => null, 'error_code' => 'SOURCE_EXPIRED', 'error_message' => 'Le fichier de la vidéo a été supprimé après sa durée de conservation. Il peut être obtenu à nouveau.'])->save();

            return $media;
        });
        if ($media === false) {
            return false;
        }
        if ($media instanceof Media && $media->user !== null) {
            $this->media->delete($media->user, $media); // suppression logique, purge physique par media:purge-deleted
        }

        return true;
    }

    /**
     * Erreur d'ACQUISITION précise (affichée avec l'import d'un fichier autorisé en solution de
     * remplacement). Le clipping ne dépend d'aucun compte social (ADR-058) : on ne demande jamais de
     * connecter la chaîne qui a publié la vidéo ; l'export Google n'est mentionné que comme voie
     * facultative pour les vidéos de la PROPRE chaîne de l'utilisateur.
     */
    public function unavailableReason(VideoSource $source, bool $providerConfigured): string
    {
        $base = 'Le fichier de cette vidéo n\'est pas accessible automatiquement : un lien YouTube permet d\'identifier et d\'analyser la vidéo, pas d\'en obtenir le fichier. Importez un fichier dont vous détenez les droits pour générer les clips.';
        if (! $providerConfigured) {
            return $base;
        }

        return match ($source->ownership_status) {
            SourceOwnership::NotOwned => $base.' (L\'export officiel Google ne s\'applique qu\'aux vidéos de vos propres chaînes connectées.)',
            default => $base.' (S\'il s\'agit d\'une vidéo de votre propre chaîne, l\'export officiel Google est aussi possible, après connexion de cette chaîne dans Comptes sociaux.)',
        };
    }

    private function verifyOwnership(VideoSource $source): void
    {
        $fresh = $source->ownership_checked_at !== null && $source->ownership_checked_at->gt(now()->subDay());
        if ($fresh || $source->user === null || $source->external_reference === '') {
            return;
        }
        $result = $this->ownership->verify($source->user, $source->external_reference);
        $this->update($source, [
            'ownership_status' => $result['status'],
            'ownership_account_id' => $result['account_id'],
            'ownership_checked_at' => now(),
            'status' => VideoSourceStatus::Resolving,
            'metadata' => [...($source->metadata ?? []), 'ownership_reason' => $result['reason']],
        ]);
    }

    private function apply(VideoSource $source, VideoSourceProvider $provider, AcquisitionStep $step): void
    {
        $maxAttempts = max(1, (int) config('video.sources.max_attempts', 30));
        switch ($step->kind) {
            case AcquisitionStep::WAITING:
            case AcquisitionStep::FAILED:
                if ($step->kind === AcquisitionStep::FAILED && ! $step->transient) {
                    $this->update($source, ['status' => VideoSourceStatus::Failed, 'acquisition_method' => $provider->method(), 'error_code' => $step->errorCode, 'error_message' => $step->message, 'next_check_at' => null]);

                    return;
                }
                // Échéance propre au fournisseur (ex. export Google : jusqu'à 7 jours), sinon nombre de relances borné.
                $deadline = $step->metadata['deadline'] ?? $source->metadata['deadline'] ?? null;
                $expired = is_string($deadline) && strtotime($deadline) !== false
                    ? now()->getTimestamp() >= strtotime($deadline)
                    : $source->attempts + 1 >= $maxAttempts;
                if ($expired) {
                    $this->update($source, ['status' => VideoSourceStatus::Failed, 'acquisition_method' => $provider->method(), 'error_code' => 'ACQUISITION_TIMEOUT', 'error_message' => 'La vidéo n\'a pas pu être obtenue dans le délai prévu.', 'next_check_at' => null]);

                    return;
                }
                $this->update($source, [
                    'status' => VideoSourceStatus::WaitingForProvider,
                    'acquisition_method' => $provider->method(),
                    'attempts' => $source->attempts + 1,
                    'next_check_at' => now()->addSeconds($step->retryAfterSeconds),
                    'error_code' => $step->errorCode,
                    'error_message' => $step->message,
                    'metadata' => [...($source->metadata ?? []), ...$step->metadata, 'provider' => $provider->name()],
                ]);
                AcquireVideoSourceJob::dispatch($source->getKey())->delay(now()->addSeconds($step->retryAfterSeconds));

                return;

            case AcquisitionStep::UNAVAILABLE:
                $this->update($source, ['status' => VideoSourceStatus::Unavailable, 'acquisition_method' => null, 'error_code' => $step->errorCode, 'error_message' => $step->message, 'next_check_at' => null]);

                return;

            case AcquisitionStep::ACQUIRED:
                $this->import($source, $provider, $step);

                return;
        }
    }

    /** Fichier du fournisseur → médiathèque (inspection + antivirus), puis finalisation différée. */
    private function import(VideoSource $source, VideoSourceProvider $provider, AcquisitionStep $step): void
    {
        $path = (string) $step->localPath;
        try {
            $size = is_file($path) ? (int) filesize($path) : 0;
            $maxBytes = self::maxAcquiredBytes();
            if ($size <= 0 || $size > $maxBytes) {
                $this->update($source, ['status' => VideoSourceStatus::Failed, 'acquisition_method' => $provider->method(), 'error_code' => $size <= 0 ? 'SOURCE_INVALID' : 'SOURCE_TOO_LARGE', 'error_message' => $size <= 0 ? 'Le fichier obtenu est vide ou illisible.' : sprintf('La vidéo obtenue dépasse la taille maximale acceptée par la médiathèque (%d Mo).', intdiv($maxBytes, 1024 * 1024))]);

                return;
            }
            try {
                $media = $this->upload->handle($source->user, new UploadedFile($path, (string) $step->fileName, $step->mimeType, null, true));
            } catch (MediaRejected $e) {
                // Raison précise de la médiathèque (messages déjà destinés à l'utilisateur, sans chemin).
                $this->update($source, ['status' => VideoSourceStatus::Failed, 'acquisition_method' => $provider->method(), 'error_code' => $e->reason === 'too_large' ? 'SOURCE_TOO_LARGE' : 'SOURCE_REJECTED', 'error_message' => 'Le fichier obtenu a été refusé par la médiathèque : '.$e->getMessage()]);
                Log::warning('clipper.source_import_rejected', ['source_id' => $source->getKey(), 'reason' => $e->reason, 'bytes' => $size]);

                return;
            } catch (MediaScanUnavailable) {
                $this->update($source, ['status' => VideoSourceStatus::Failed, 'acquisition_method' => $provider->method(), 'error_code' => 'SOURCE_REJECTED', 'error_message' => 'Le fichier obtenu n\'a pas pu être importé : l\'analyse de sécurité de la médiathèque est indisponible.']);

                return;
            } catch (QuotaExceeded $e) {
                $this->update($source, ['status' => VideoSourceStatus::Failed, 'acquisition_method' => $provider->method(), 'error_code' => $e->errorCode, 'error_message' => $e->getMessage()]);

                return;
            } catch (DiskSpaceInsufficient $e) {
                $this->update($source, ['status' => VideoSourceStatus::Failed, 'acquisition_method' => $provider->method(), 'error_code' => DiskSpaceInsufficient::CODE, 'error_message' => $e->getMessage()]);

                return;
            }
            $this->update($source, ['status' => VideoSourceStatus::Resolving, 'acquisition_method' => $provider->method(), 'media_id' => $media->getKey(), 'attempts' => 0, 'error_code' => null, 'error_message' => null]);
            AcquireVideoSourceJob::dispatch($source->getKey())->delay(now()->addSeconds(10));
        } finally {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    /** Média traité par la médiathèque : durée contrôlée puis liaison au projet (analyse relancée). */
    private function finalize(VideoSource $source): void
    {
        $media = Media::query()->find($source->media_id);
        if ($media === null) {
            $this->update($source, ['status' => VideoSourceStatus::Failed, 'error_code' => 'SOURCE_MISSING', 'error_message' => 'Le fichier obtenu n\'est plus disponible.']);

            return;
        }
        if (! $media->isUsable()) {
            if ($source->attempts + 1 >= max(1, (int) config('video.sources.max_attempts', 30))) {
                $this->update($source, ['status' => VideoSourceStatus::Failed, 'error_code' => 'SOURCE_PROCESSING_FAILED', 'error_message' => 'Le fichier obtenu n\'a pas pu être traité par la médiathèque.']);

                return;
            }
            $this->update($source, ['attempts' => $source->attempts + 1]);
            AcquireVideoSourceJob::dispatch($source->getKey())->delay(now()->addSeconds(15));

            return;
        }
        $maxMs = (int) config('video.clipper.max_source_minutes', 120) * 60_000;
        if ($media->duration_ms !== null && $media->duration_ms > $maxMs) {
            $this->update($source, ['status' => VideoSourceStatus::Failed, 'error_code' => 'SOURCE_TOO_LONG', 'error_message' => 'La vidéo dépasse la durée maximale acceptée pour le Clipper.']);

            return;
        }
        try {
            // Fichier réutilisé : conservation héritée de sa source d'origine (jamais prolongée ni créée).
            $purgeAt = $source->acquisition_method === AcquisitionMethod::Library
                ? $source->purge_at
                : now()->addDays(max(1, (int) config('video.sources.retention_days', 7)));
            $this->clipper->linkSource($source->project, $media, $source->acquisition_method ?? AcquisitionMethod::External, $purgeAt);
        } catch (ClipperConflict $e) {
            if (in_array($e->errorCode, ['ANALYSIS_RUNNING', 'NOT_RENDERABLE'], true)) {
                AcquireVideoSourceJob::dispatch($source->getKey())->delay(now()->addSeconds(30)); // projet occupé : nouvel essai

                return;
            }
            $this->update($source, ['status' => VideoSourceStatus::Failed, 'error_code' => $e->errorCode, 'error_message' => $e->getMessage()]);
        }
    }

    /** @param array<string, mixed> $attributes */
    private function update(VideoSource $source, array $attributes): void
    {
        $source->forceFill($attributes)->save();
    }
}
