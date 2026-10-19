<?php

namespace App\Domain\Video\Clipper\Planning;

use App\Domain\Video\Clipper\ClipLimits;
use App\Domain\Video\Enums\AspectRatio;
use InvalidArgumentException;

/**
 * Planificateur de clips (ADR-051) : passage validé (+ transcription) → RenderPlan. Il ne produit
 * aucun fichier et ne connaît ni FFmpeg ni Remotion : changer de moteur de rendu ne touche ni
 * l'intelligence de clipping ni ce plan. Règles : ≤ 60 s (ClipLimits), dimensions du format choisi
 * (video.formats), préréglage déclaré dans video.clipper.presets, légendes relatives au clip.
 */
final class ClipPlanner
{
    /**
     * @param  array{start_ms: int, end_ms: int, hook?: ?string}  $candidate
     * @param  list<array{start_ms: int, end_ms: int, text: string, words?: list<array{0: int, 1: int, 2: string}>}>  $segments  transcription de la source (vide : pas de sous-titres possibles)
     *
     * @throws InvalidArgumentException passage invalide (> 60 s, bornes incohérentes) ou préréglage inconnu
     */
    public function plan(array $candidate, AspectRatio $aspectRatio, array $segments = [], bool $subtitles = true, bool $showHook = true, string $preset = 'clean-short'): RenderPlan
    {
        $start = (int) $candidate['start_ms'];
        $end = (int) $candidate['end_ms'];
        $violation = ClipLimits::violation($start, $end);
        if ($violation !== null) {
            throw new InvalidArgumentException($violation);
        }
        $preset = $this->preset($preset);
        $size = (array) config('video.formats.'.$aspectRatio->value);
        $hook = $showHook && is_string($candidate['hook'] ?? null) && trim($candidate['hook']) !== '' ? trim($candidate['hook']) : null;
        $captions = $subtitles ? $this->captions($segments, $start, $end, $preset->maxCaptionChars()) : [];

        return new RenderPlan(
            sourceStartMs: $start,
            sourceEndMs: $end,
            aspectRatio: $aspectRatio->value,
            format: match ($aspectRatio) {
                AspectRatio::Vertical => 'vertical',
                AspectRatio::Square => 'square',
                AspectRatio::Landscape => 'landscape',
            },
            width: (int) $size['width'],
            height: (int) $size['height'],
            fps: $preset->fps(),
            template: $preset->template(),
            crop: $preset->crop(),
            subtitles: $subtitles && $captions !== [],
            captions: $captions,
            hook: $hook,
            hookDurationMs: min($preset->hookDurationMs(), $end - $start),
        );
    }

    public function preset(string $name): RenderPreset
    {
        $class = ((array) config('video.clipper.presets', []))[$name] ?? null;
        if (! is_string($class) || ! is_subclass_of($class, RenderPreset::class)) {
            throw new InvalidArgumentException("Préréglage de rendu inconnu : {$name}.");
        }

        return app($class);
    }

    /**
     * Légendes du passage, relatives à son début. Avec les mots horodatés : regroupées en légendes
     * courtes (≤ $maxChars), jamais coupées au milieu d'un mot ; sinon, un segment par légende.
     *
     * @param  list<array{start_ms: int, end_ms: int, text: string, words?: list<array{0: int, 1: int, 2: string}>}>  $segments
     * @return list<array{start_ms: int, end_ms: int, text: string}>
     */
    private function captions(array $segments, int $start, int $end, int $maxChars): array
    {
        $captions = [];
        foreach ($segments as $segment) {
            if (! is_array($segment) || ($segment['end_ms'] ?? 0) <= $start || ($segment['start_ms'] ?? PHP_INT_MAX) >= $end) {
                continue;
            }
            $words = array_values(array_filter((array) ($segment['words'] ?? []), fn ($w) => is_array($w) && is_int($w[0] ?? null) && is_int($w[1] ?? null) && is_string($w[2] ?? null) && $w[1] > $start && $w[0] < $end));
            if ($words === []) {
                $text = trim((string) ($segment['text'] ?? ''));
                if ($text !== '') {
                    $captions[] = $this->cue(max($segment['start_ms'], $start), min($segment['end_ms'], $end), $text, $start);
                }

                continue;
            }
            $line = [];
            foreach ($words as $word) {
                $candidate = trim(implode(' ', array_column([...$line, $word], 2)));
                if ($line !== [] && mb_strlen($candidate) > $maxChars) {
                    $captions[] = $this->cue(max($line[0][0], $start), min(end($line)[1], $end), implode(' ', array_column($line, 2)), $start);
                    $line = [];
                }
                $line[] = $word;
            }
            if ($line !== []) {
                $captions[] = $this->cue(max($line[0][0], $start), min(end($line)[1], $end), implode(' ', array_column($line, 2)), $start);
            }
        }

        return array_values(array_filter($captions, fn ($c) => $c['end_ms'] > $c['start_ms']));
    }

    /** @return array{start_ms: int, end_ms: int, text: string} */
    private function cue(int $from, int $to, string $text, int $clipStart): array
    {
        return ['start_ms' => $from - $clipStart, 'end_ms' => $to - $clipStart, 'text' => trim(preg_replace('/\s+/u', ' ', $text) ?? '')];
    }
}
