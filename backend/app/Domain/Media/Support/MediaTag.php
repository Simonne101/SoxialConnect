<?php

namespace App\Domain\Media\Support;

/** Normalisation des tags (stockés dans media.tags, colonne JSON indexée — docs/database.md §3.4). */
final class MediaTag
{
    /** Lettres, chiffres, espaces, tirets et soulignés ; commence par une lettre ou un chiffre. */
    public const PATTERN = '/^[\pL\pN][\pL\pN _-]*$/u';

    public static function normalize(string $tag): string
    {
        $tag = ltrim(trim($tag), '#');
        $tag = (string) preg_replace('/\s+/u', ' ', $tag);

        return mb_strtolower(trim($tag));
    }

    /**
     * Tri naturel en français, sans tenir compte des accents (« été » avant « marque ») —
     * même ordre que la collation MySQL utf8mb4_0900_ai_ci de la liste des tags.
     *
     * @param  list<string>  $tags
     * @return list<string>
     */
    public static function sort(array $tags): array
    {
        $key = fn (string $t) => strtolower((string) (iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $t) ?: $t));
        usort($tags, fn (string $a, string $b) => [$key($a), $a] <=> [$key($b), $b]);

        return array_values($tags);
    }

    public static function isValid(string $normalized): bool
    {
        return $normalized !== ''
            && mb_strlen($normalized) <= (int) config('media.tags.max_length', 40)
            && preg_match(self::PATTERN, $normalized) === 1;
    }
}
