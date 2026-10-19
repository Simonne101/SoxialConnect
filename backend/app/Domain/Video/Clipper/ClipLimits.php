<?php

namespace App\Domain\Video\Clipper;

/**
 * Règle métier du YouTube Clipper (ADR-046) : AUCUN clip ne dépasse 60 secondes.
 * Vérifiée à chaque étape (validation HTTP, service, job avant FFmpeg, FFprobe après rendu) et
 * par la base (CHECK video_clips_max_duration_check). Volontairement non configurable.
 */
final class ClipLimits
{
    /** Durée maximale d'un clip, en millisecondes. */
    public const MAX_CLIP_MS = 60_000;

    /** Durée minimale d'un clip, en millisecondes (config video.clipper.min_clip_ms). */
    public static function minClipMs(): int
    {
        return max(1_000, (int) config('video.clipper.min_clip_ms', 3_000));
    }

    /**
     * Motif du refus d'un extrait, null s'il est valide.
     *
     * @param  int|null  $sourceDurationMs  durée de la vidéo source si connue
     */
    public static function violation(int $startMs, int $endMs, ?int $sourceDurationMs = null): ?string
    {
        if ($startMs < 0 || $endMs <= $startMs) {
            return 'La fin du clip doit être après son début.';
        }
        $duration = $endMs - $startMs;
        if ($duration > self::MAX_CLIP_MS) {
            return 'Un clip ne peut pas dépasser 60 secondes.';
        }
        if ($duration < self::minClipMs()) {
            return 'Un clip doit durer au moins '.intdiv(self::minClipMs(), 1000).' secondes.';
        }
        if ($sourceDurationMs !== null && $endMs > $sourceDurationMs) {
            return 'Le clip dépasse la fin de la vidéo source.';
        }

        return null;
    }

    /**
     * Borne un passage proposé par l'analyse : fin ramenée à début + 60 s, et à la fin de la source.
     *
     * @return array{0: int, 1: int}|null null si le passage reste invalide
     */
    public static function bound(int $startMs, int $endMs, ?int $sourceDurationMs = null): ?array
    {
        $startMs = max(0, $startMs);
        $endMs = min($endMs, $startMs + self::MAX_CLIP_MS);
        if ($sourceDurationMs !== null) {
            $endMs = min($endMs, $sourceDurationMs);
        }

        return self::violation($startMs, $endMs, $sourceDurationMs) === null ? [$startMs, $endMs] : null;
    }
}
