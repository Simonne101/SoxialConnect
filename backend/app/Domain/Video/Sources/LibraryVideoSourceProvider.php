<?php

namespace App\Domain\Video\Sources;

use App\Domain\Media\Enums\MediaType;
use App\Domain\Video\Enums\AcquisitionMethod;
use App\Domain\Video\Enums\VideoSourceStatus;
use App\Models\Media;
use App\Models\VideoSource;

/**
 * Réutilisation d'un fichier DÉJÀ disponible localement (ADR-053) : si l'utilisateur a déjà fourni, dans
 * un autre de ses projets, le fichier rendable de CETTE vidéo YouTube (import autorisé ou acquisition
 * officielle), ce média est lié au nouveau projet, sans copie, sans import et sans requête réseau.
 *
 * Isolation : uniquement les sources prêtes du même utilisateur, même plateforme, même identifiant de
 * vidéo, et un média vidéo du même propriétaire encore utilisable (non supprimé, analyse antivirus
 * acceptée). Aucun rapprochement « deviné » (nom de fichier, durée) : seul un lien explicite déjà
 * établi entre ce fichier et cette vidéo compte. Exécuté avant les fournisseurs externes.
 */
class LibraryVideoSourceProvider implements VideoSourceProvider
{
    public function name(): string
    {
        return 'library';
    }

    public function method(): AcquisitionMethod
    {
        return AcquisitionMethod::Library;
    }

    public function isConfigured(): bool
    {
        return (bool) config('video.sources.reuse_library', true);
    }

    public function supports(VideoSource $source): bool
    {
        return $this->candidate($source) !== null;
    }

    public function begin(VideoSource $source): AcquisitionStep
    {
        $candidate = $this->candidate($source);

        return $candidate !== null
            ? AcquisitionStep::reused((string) $candidate->media_id, $candidate->purge_at)
            : AcquisitionStep::unavailable('NO_LOCAL_FILE', 'Aucun fichier de cette vidéo n\'est encore disponible dans votre médiathèque.');
    }

    public function poll(VideoSource $source): AcquisitionStep
    {
        return $this->begin($source);
    }

    /** Source prête la plus récente du même utilisateur pour la même vidéo, dont le média est utilisable. */
    private function candidate(VideoSource $source): ?VideoSource
    {
        if ($source->external_reference === '' || $source->user_id === null) {
            return null;
        }
        $candidates = VideoSource::query()
            ->where('user_id', $source->user_id)
            ->where('provider', $source->provider)
            ->where('external_reference', $source->external_reference)
            ->where('status', VideoSourceStatus::Ready->value)
            ->whereNotNull('media_id')
            ->when($source->exists, fn ($query) => $query->whereKeyNot($source->getKey()))
            ->latest('updated_at')
            ->limit(10)
            ->get();
        foreach ($candidates as $candidate) {
            $media = Media::query()->where('user_id', $source->user_id)->whereKey($candidate->media_id)->first();
            if ($media !== null && $media->type === MediaType::Video && $media->isUsable()) {
                return $candidate;
            }
        }

        return null;
    }
}
