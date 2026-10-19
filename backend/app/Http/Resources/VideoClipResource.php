<?php

namespace App\Http\Resources;

use App\Domain\Video\Clipper\ClipRenderRunner;
use App\Domain\Video\Enums\ClipStatus;
use App\Models\VideoClip;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Cache;

/** @mixin VideoClip */
class VideoClipResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'topic' => $this->topic,
            'reason' => $this->reason,
            // Clip Intelligence Engine (ADR-051) : accroche, confiance (0-100) et critères notés par l'IA ;
            // le score global ci-dessous est calculé par le backend (ClipScorer), jamais repris de l'IA.
            'hook' => $this->hook,
            'confidence' => $this->confidence,
            'score_breakdown' => $this->score_breakdown,
            'start_ms' => $this->start_ms,
            'end_ms' => $this->end_ms,
            'duration_ms' => $this->end_ms - $this->start_ms,
            'duration_ms' => $this->end_ms - $this->start_ms,
            'aspect_ratio' => $this->aspect_ratio->value,
            'source' => $this->source->value,
            'status' => $this->status->value,
            // Étape du rendu en cours : preparing | rendering | validating | publishing (null sinon).
            'render_stage' => $this->render_stage,
            // Sous-étape réelle du rendu en cours (cutting | composing + % | fallback | validating | publishing).
            'render_progress' => $this->status === ClipStatus::Rendering ? Cache::get(ClipRenderRunner::PROGRESS_KEY.$this->id) : null,
            // Rendu demandé mais pas encore pris par un worker : temps d'attente en file (ms). Une attente anormale
            // (aucun worker vidéo actif) devient visible au lieu d'un « en cours » sans fin.
            'queued_ms' => $this->status === ClipStatus::Rendering && $this->started_at === null && $this->updated_at !== null
                ? max(0, (int) $this->updated_at->diffInMilliseconds(now())) : null,
            'subtitles' => (bool) $this->subtitles_enabled,
            'subtitle_style' => $this->subtitles_enabled ? $this->subtitle_style : null,
            'score' => $this->score !== null ? (int) round((float) $this->score) : null,
            'error_code' => $this->error_code,
            'error_message' => $this->error_message,
            // Clip publié : média normal de la médiathèque (URLs signées), jamais un chemin de fichier.
            'output' => $this->whenLoaded('outputMedia', fn () => $this->outputMedia?->isUsable() ? new MediaResource($this->outputMedia) : null),
            // Moteur réellement utilisé pour le MP4 publié (métadonnées « composition » du média, ADR-052/055) :
            // fallback = Remotion a échoué et le clip a été produit par le rendu de secours FFmpeg (sans accroche).
            'render' => $this->whenLoaded('outputMedia', fn () => $this->renderInfo()),
            'created_at' => $this->created_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
        ];
    }

    /**
     * Moteur du rendu publié, uniquement pour un clip prêt dont le fichier est utilisable ; null sinon.
     * Clips antérieurs à la traçabilité du moteur : engine null (aucune supposition).
     *
     * @return array{engine: ?string, fallback: bool, hook: bool}|null
     */
    private function renderInfo(): ?array
    {
        if ($this->status !== ClipStatus::Rendered || $this->outputMedia === null || ! $this->outputMedia->isUsable()) {
            return null;
        }
        $composition = (array) ($this->outputMedia->metadata['composition'] ?? []);
        $engine = in_array($composition['composer'] ?? null, ['remotion', 'ffmpeg'], true) ? $composition['composer'] : null;

        return [
            'engine' => $engine,
            'fallback' => $engine === 'ffmpeg' && ($composition['fallback_from'] ?? null) === 'remotion',
            'hook' => $engine === 'remotion' && ($composition['hook'] ?? false) === true,
            // Son vérifié avant publication : ok | source_without_audio | source_silent (null : clip antérieur au contrôle).
            'audio' => $this->outputMedia->metadata['audio']['status'] ?? null,
        ];
    }
}
