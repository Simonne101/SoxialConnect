<?php

namespace App\Domain\Video\Clipper;

/**
 * Sous-titres incrustés d'un clip (ADR-047) à partir de la transcription horodatée : piste « text »
 * de la spécification de rendu, dessinée par le moteur FFmpeg existant (drawtext). Chaque segment
 * compris dans le clip est recalé sur le temps du clip, coupé en lignes selon la largeur de l'image
 * et, s'il est long, en blocs de deux lignes affichés successivement.
 */
final class SubtitleTrack
{
    public const POSITIONS = ['bottom', 'center', 'top'];

    public const SIZES = ['small', 'medium', 'large'];

    private const SIZE_FACTORS = ['small' => 0.045, 'medium' => 0.055, 'large' => 0.068];

    private const LINES_PER_BLOCK = 2;

    /** Durée minimale d'affichage d'un bloc (ms). */
    private const MIN_BLOCK_MS = 400;

    /**
     * @param  list<array{start_ms: int, end_ms: int, text: string}>  $segments  transcription de la source
     * @param  array{position?: string, size?: string}  $style
     * @return list<array<string, mixed>> éléments « text » de la spécification (temps relatifs au clip)
     */
    public static function items(array $segments, int $clipStartMs, int $clipEndMs, int $width, int $height, array $style = []): array
    {
        $position = in_array($style['position'] ?? null, self::POSITIONS, true) ? $style['position'] : 'bottom';
        $size = in_array($style['size'] ?? null, self::SIZES, true) ? $style['size'] : 'medium';
        $fontSize = max(24, (int) round(min($width, $height) * self::SIZE_FACTORS[$size]));
        // Largeur moyenne d'un caractère ≈ 0,55 × taille ; 86 % de la largeur utilisable.
        $maxChars = max(12, (int) floor($width * 0.86 / ($fontSize * 0.55)));

        $items = [];
        foreach ($segments as $segment) {
            $start = max($segment['start_ms'], $clipStartMs);
            $end = min($segment['end_ms'], $clipEndMs);
            $text = trim(preg_replace('/\s+/u', ' ', $segment['text']) ?? '');
            if ($end - $start < self::MIN_BLOCK_MS || $text === '') {
                continue;
            }
            $blocks = array_chunk(self::wrap($text, $maxChars), self::LINES_PER_BLOCK);
            $total = array_sum(array_map(fn ($b) => mb_strlen(implode(' ', $b)), $blocks));
            $cursor = $start;
            foreach ($blocks as $i => $lines) {
                $share = $i === count($blocks) - 1
                    ? $end - $cursor
                    : (int) round(($end - $start) * mb_strlen(implode(' ', $lines)) / max(1, $total));
                if ($share < self::MIN_BLOCK_MS) {
                    continue;
                }
                $items[] = [
                    'id' => 'subtitle-'.count($items),
                    'type' => 'text',
                    'media_id' => null,
                    'start_ms' => $cursor - $clipStartMs,
                    'duration_ms' => $share,
                    'source_start_ms' => null,
                    'source_duration_ms' => null,
                    'properties' => ['text' => implode("\n", $lines), 'font_size' => $fontSize, 'color' => '#FFFFFF', 'position' => $position, 'align' => 'center', 'box' => true],
                    'transitions' => null,
                ];
                $cursor += $share;
            }
        }

        return $items;
    }

    /**
     * Lignes d'au plus $maxChars caractères, coupées entre les mots (un mot trop long est coupé).
     *
     * @return list<string>
     */
    public static function wrap(string $text, int $maxChars): array
    {
        $lines = [];
        $line = '';
        foreach (preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            while (mb_strlen($word) > $maxChars) {
                if ($line !== '') {
                    $lines[] = $line;
                    $line = '';
                }
                $lines[] = mb_substr($word, 0, $maxChars);
                $word = mb_substr($word, $maxChars);
            }
            $candidate = $line === '' ? $word : "{$line} {$word}";
            if (mb_strlen($candidate) > $maxChars) {
                $lines[] = $line;
                $line = $word;
            } else {
                $line = $candidate;
            }
        }
        if ($line !== '') {
            $lines[] = $line;
        }

        return $lines;
    }
}
