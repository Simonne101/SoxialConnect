<?php

namespace App\Http\Resources;

use App\Domain\Video\Clipper\ClipLimits;
use App\Domain\Video\Enums\VideoSourceStatus;
use App\Domain\Video\Sources\VideoSourceService;
use App\Domain\Video\Sources\YtDlp\YtDlpVideoSourceProvider;
use App\Models\VideoProject;
use App\Models\VideoSource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Projet du YouTube Clipper (ADR-046, ADR-049). @mixin VideoProject */
class ClipperProjectResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $settings = $this->settings ?? [];

        return [
            'id' => $this->id,
            'title' => $this->title,
            'status' => $this->status->value,
            'youtube_url' => $this->source_url,
            'youtube_video_id' => $settings['youtube_video_id'] ?? null,
            'youtube_author' => $settings['youtube_author'] ?? null,
            'youtube_thumbnail' => $settings['youtube_thumbnail'] ?? null,
            // Source rendable (fichier de la médiathèque) jointe : le rendu est possible (ADR-048).
            'renderable' => $this->source_media_id !== null,
            'aspect_ratio' => $this->aspect_ratio->value,
            'duration_ms' => $this->duration_ms,
            'language' => $this->language,
            'error_code' => $this->status->value === 'failed' ? ($settings['analysis_error_code'] ?? 'ANALYSIS_FAILED') : null,
            'error_message' => $this->error,
            // Ce que l'analyse a réellement fait (ADR-047) : mode « ai » | « local » | « none »,
            // transcription « local » | « ai » | null, repli éventuel et notes affichables.
            'analysis' => isset($settings['analysis']) ? [
                'mode' => $settings['analysis']['mode'] ?? 'none',
                'input' => $settings['analysis']['input'] ?? 'file',
                'transcription' => $settings['analysis']['transcription'] ?? null,
                'transcription_engine' => $settings['analysis']['transcription_engine'] ?? null,
                'fallback' => $settings['analysis']['fallback'] ?? null,
                'notes' => array_values((array) ($settings['analysis']['notes'] ?? [])),
            ] : null,
            'has_transcript' => array_key_exists('ready_transcripts_count', $this->resource->getAttributes()) && $this->resource->getAttribute('ready_transcripts_count') > 0,
            'max_clip_ms' => ClipLimits::MAX_CLIP_MS,
            'min_clip_ms' => ClipLimits::minClipMs(),
            'source' => $this->whenLoaded('sourceMedia', fn () => $this->sourceMedia?->isUsable() ? new MediaResource($this->sourceMedia) : null),
            // Acquisition de la source (ADR-049) : statut, propriété, voie, erreur normalisée.
            'source_status' => $this->whenLoaded('videoSource', fn () => $this->sourceStatus()),
            'clips' => VideoClipResource::collection($this->whenLoaded('clips')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * État de la source vidéo : celui de VideoSource, ou déduit pour un projet antérieur à l'ADR-049
     * (fichier joint = prêt par import ; sinon en attente d'acquisition).
     *
     * rights_attested : l'utilisateur a attesté détenir les droits sur la vidéo ; download_available : la
     * récupération automatique yt-dlp est active sur ce serveur (ADR-059).
     *
     * @return array{status: string, ownership: string, method: ?string, error_code: ?string, error_message: ?string, purge_at: ?string, rights_attested: bool, download_available: bool}
     */
    private function sourceStatus(): array
    {
        $source = $this->videoSource;
        if ($source === null) {
            return [
                'status' => $this->source_media_id !== null ? 'ready' : 'pending',
                'ownership' => 'unverified',
                'method' => $this->source_media_id !== null ? 'import' : null,
                'error_code' => null,
                'error_message' => null,
                'purge_at' => null,
                'rights_attested' => false,
                'download_available' => $this->downloadAvailable(),
            ];
        }

        return [
            // Fichier lié au projet : prêt, quel que soit l'historique de l'acquisition.
            'status' => $this->source_media_id !== null ? 'ready' : $source->status->value,
            'ownership' => $source->ownership_status->value,
            'method' => $source->acquisition_method?->value,
            'error_code' => $this->source_media_id !== null ? null : $this->sourceDiagnosis($source)[0],
            'error_message' => $this->source_media_id !== null ? null : $this->sourceDiagnosis($source)[1],
            'purge_at' => $source->purge_at?->toIso8601String(),
            'rights_attested' => ($source->metadata['rights_attested'] ?? false) === true,
            'download_available' => $this->downloadAvailable(),
        ];
    }

    private function downloadAvailable(): bool
    {
        return app(YtDlpVideoSourceProvider::class)->isConfigured();
    }

    /**
     * Message d'une source indisponible faute de voie d'acquisition : recalculé selon la configuration
     * ACTUELLE (un texte enregistré avant l'activation d'un fournisseur n'est jamais réaffiché tel quel).
     */
    /** @return array{0: ?string, 1: ?string} code et message */
    private function sourceDiagnosis(VideoSource $source): array
    {
        if ($source->status === VideoSourceStatus::Unavailable && in_array($source->error_code, VideoSourceService::NO_PROVIDER_CODES, true)) {
            return app(VideoSourceService::class)->currentNoProviderDiagnosis($source);
        }

        return [$source->error_code, $source->error_message];
    }
}
