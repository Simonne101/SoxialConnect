<?php

namespace App\Domain\Video\Clipper\Planning;

/**
 * Plan de rendu d'un clip (ADR-051) : CE QUI doit être produit, sans dire COMMENT. Il ne connaît ni
 * FFmpeg ni Remotion : le moteur FFmpeg y lit le découpage et le cadrage, la composition Remotion
 * les sous-titres, l'accroche et le modèle. Temps en millisecondes ; légendes relatives au clip.
 */
final readonly class RenderPlan
{
    /**
     * @param  list<array{start_ms: int, end_ms: int, text: string, words?: list<array{0: int, 1: int, 2: string}>}>  $captions
     */
    public function __construct(
        public int $sourceStartMs,
        public int $sourceEndMs,
        public string $aspectRatio,
        public string $format,
        public int $width,
        public int $height,
        public int $fps,
        public string $template,
        public string $crop,
        public bool $subtitles,
        public array $captions,
        public ?string $hook,
        public int $hookDurationMs,
    ) {}

    public function durationMs(): int
    {
        return $this->sourceEndMs - $this->sourceStartMs;
    }

    public function durationInFrames(): int
    {
        return max(1, (int) round($this->durationMs() * $this->fps / 1000));
    }

    /** @return array<string, mixed> forme sérialisable (contrat d'entrée des moteurs de rendu) */
    public function toArray(): array
    {
        return [
            'schema' => 'soxial.clip_render_plan.v1',
            'source_start_ms' => $this->sourceStartMs,
            'source_end_ms' => $this->sourceEndMs,
            'duration_ms' => $this->durationMs(),
            'aspect_ratio' => $this->aspectRatio,
            'format' => $this->format,
            'width' => $this->width,
            'height' => $this->height,
            'fps' => $this->fps,
            'duration_in_frames' => $this->durationInFrames(),
            'template' => $this->template,
            'crop' => $this->crop,
            'subtitles' => $this->subtitles,
            'captions' => $this->subtitles ? $this->captions : [],
            'hook' => $this->hook,
            'hook_duration_ms' => $this->hook !== null ? $this->hookDurationMs : 0,
        ];
    }
}
