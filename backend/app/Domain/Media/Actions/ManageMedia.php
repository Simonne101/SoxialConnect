<?php

namespace App\Domain\Media\Actions;

use App\Domain\Content\Enums\VariantStatus;
use App\Domain\Media\Enums\MediaStatus;
use App\Domain\Media\Exceptions\MediaConflict;
use App\Domain\Media\Support\MediaTag;
use App\Domain\Media\Upload\MediaFileInspector;
use App\Domain\Video\Enums\ClipStatus;
use App\Domain\Video\Enums\RenderStatus;
use App\Domain\Video\Enums\VideoProjectStatus;
use App\Models\Media;
use App\Models\PostVariant;
use App\Models\User;
use App\Models\VideoProject;
use App\Services\Audit\AuditAction;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/** Opérations sur un média existant : renommer, déplacer, tags, suppression. */
final class ManageMedia
{
    /** Statuts de variante qui interdisent la suppression d'un média utilisé (docs/database.md §5). */
    private const BLOCKING_VARIANT_STATUSES = [VariantStatus::Scheduled, VariantStatus::Queued, VariantStatus::Publishing];

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{name?: string, folder_id?: string|null}  $data  validé ; folder_id appartient à l'utilisateur
     */
    public function update(Media $media, array $data): Media
    {
        if (array_key_exists('name', $data)) {
            $media->original_name = MediaFileInspector::sanitizeName((string) $data['name'], (string) $media->extension);
        }
        if (array_key_exists('folder_id', $data)) {
            $media->folder_id = $data['folder_id'];
        }
        $media->save();

        return $media;
    }

    public function addTag(Media $media, string $tag): Media
    {
        return DB::transaction(function () use ($media, $tag) {
            $locked = Media::query()->whereKey($media->getKey())->lockForUpdate()->firstOrFail();
            $tags = $locked->tags ?? [];
            $normalized = MediaTag::normalize($tag);
            if (! in_array($normalized, $tags, true)) {
                $max = (int) config('media.tags.max_per_media', 20);
                if (count($tags) >= $max) {
                    throw MediaConflict::tooManyTags($max);
                }
                $tags[] = $normalized;
                $locked->tags = MediaTag::sort($tags);
                $locked->save();
            }

            return $locked;
        });
    }

    public function removeTag(Media $media, string $tag): Media
    {
        return DB::transaction(function () use ($media, $tag) {
            $locked = Media::query()->whereKey($media->getKey())->lockForUpdate()->firstOrFail();
            $normalized = MediaTag::normalize($tag);
            $tags = array_values(array_filter($locked->tags ?? [], fn (string $t) => $t !== $normalized));
            $locked->tags = $tags ?: null;
            $locked->save();

            return $locked;
        });
    }

    public function isInUse(Media $media): bool
    {
        return PostVariant::query()
            ->whereIn('status', array_map(fn (VariantStatus $s) => $s->value, self::BLOCKING_VARIANT_STATUSES))
            ->whereHas('post', fn ($post) => $post->whereHas('postMedia', fn ($pm) => $pm->where('media_id', $media->getKey())))
            ->exists()
            || $this->isUsedByActiveVideoRender($media)
            || $this->isUsedByActiveClipping($media);
    }

    /** Vidéo source du YouTube Clipper en cours d'analyse ou dont un clip est en génération (ADR-046). */
    private function isUsedByActiveClipping(Media $media): bool
    {
        $analyzing = [VideoProjectStatus::Pending->value, VideoProjectStatus::Analyzing->value, VideoProjectStatus::Transcribing->value, VideoProjectStatus::Detecting->value];

        return VideoProject::query()
            ->where('source_media_id', $media->getKey())
            ->where(fn ($q) => $q
                ->whereIn('status', $analyzing)
                ->orWhereHas('clips', fn ($c) => $c->where('status', ClipStatus::Rendering->value)))
            ->exists();
    }

    /** Média d'un projet vidéo (asset ou fond de scène) dont un rendu est en file ou en cours (ADR-044). */
    private function isUsedByActiveVideoRender(Media $media): bool
    {
        return VideoProject::query()
            ->whereHas('renderJobs', fn ($q) => $q->whereIn('status', [RenderStatus::Queued->value, RenderStatus::Rendering->value]))
            ->where(fn ($q) => $q
                ->whereHas('assets', fn ($a) => $a->where('media_id', $media->getKey()))
                ->orWhereHas('scenes', fn ($s) => $s->where('media_id', $media->getKey())))
            ->exists();
    }

    /**
     * Suppression logique : statut « deleted » + soft delete. Le fichier physique est conservé
     * jusqu'à la purge (commande media:purge-deleted) : aucune opération en cours n'en dépend plus.
     */
    public function delete(User $user, Media $media): void
    {
        if ($this->isInUse($media)) {
            throw MediaConflict::inUse();
        }
        $media->status = MediaStatus::Deleted;
        $media->save();
        $media->delete();
        $this->audit->record(AuditAction::MediaDeleted, $user, $media);
    }
}
