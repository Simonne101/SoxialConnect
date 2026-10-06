<?php

namespace App\Domain\Video;

use App\Domain\Video\Exceptions\VideoConflict;
use App\Domain\Video\Support\VideoProjectLock;
use App\Models\Media;
use App\Models\VideoProject;
use App\Models\VideoProjectAsset;
use App\Models\VideoTimelineItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Assets d'un projet vidéo (ADR-044) : rattachement de médias EXISTANTS de la médiathèque.
 * Aucune copie de fichier ; seuls les médias utilisables (prêts, analysés selon ScanPolicy) du
 * propriétaire du projet sont acceptés (la base le garantit aussi : clés étrangères composites).
 */
final class VideoAssetService
{
    /** Types de médias utilisables dans une vidéo. */
    public const MEDIA_TYPES = ['image', 'video', 'audio'];

    /** Rattache le média (idempotent : un média déjà rattaché renvoie l'asset existant). */
    public function attach(VideoProject $project, string $mediaId, ?int $expectedVersion = null): VideoProjectAsset
    {
        return DB::transaction(function () use ($project, $mediaId, $expectedVersion) {
            $locked = VideoProjectLock::acquire($project, $expectedVersion);
            $media = Media::query()->ownedBy($locked->user)->usable()->whereKey($mediaId)->first();
            if ($media === null || ! in_array($media->type->value, self::MEDIA_TYPES, true)) {
                throw ValidationException::withMessages(['media_id' => 'Média introuvable, pas encore prêt ou d\'un type non pris en charge (image, vidéo, audio).']);
            }

            $existing = VideoProjectAsset::query()->where('video_project_id', $locked->getKey())->where('media_id', $media->getKey())->first();
            if ($existing !== null) {
                return $existing;
            }
            $max = (int) config('video.limits.max_assets');
            if (VideoProjectAsset::query()->where('video_project_id', $locked->getKey())->count() >= $max) {
                throw VideoConflict::limitReached('médias', $max);
            }

            $asset = new VideoProjectAsset;
            $asset->forceFill(['video_project_id' => $locked->getKey(), 'user_id' => $locked->user_id, 'media_id' => $media->getKey()])->save();
            VideoProjectLock::bump($locked);

            return $asset;
        });
    }

    /** Retire le média du projet ; refusé tant que la timeline l'utilise (le média reste dans la médiathèque). */
    public function detach(VideoProjectAsset $asset, ?int $expectedVersion = null): void
    {
        DB::transaction(function () use ($asset, $expectedVersion) {
            $locked = VideoProjectLock::acquire($asset->project, $expectedVersion);
            if (VideoTimelineItem::query()->where('video_project_asset_id', $asset->getKey())->exists()) {
                throw VideoConflict::assetInUse();
            }
            $asset->delete();
            VideoProjectLock::bump($locked);
        });
    }
}
