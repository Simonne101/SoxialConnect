<?php

namespace App\Domain\Publishing\Support;

use IntlBreakIterator;
use Normalizer;

/**
 * Longueur d'un post X telle que X la compte (docs.x.com, « Counting characters », et la
 * configuration v3 de la bibliothèque officielle twitter-text) :
 * - texte normalisé en NFC ;
 * - chaque URL (http/https) compte 23 caractères (raccourcie en t.co) ;
 * - chaque emoji compte 2, même composé (séquences ZWJ, drapeaux, touches) ;
 * - les caractères des plages latines, grecques, cyrilliques… et de ponctuation courante comptent
 *   1, tous les autres (CJK notamment) comptent 2.
 * Limite : 280 pour un post standard. Les posts longs des abonnés X Premium ne sont pas gérés.
 * Même règle que le compteur du Composer (frontend/src/lib/xText.ts).
 */
final class XPostLength
{
    public const MAX = 280;

    private const URL_LENGTH = 23;

    /** Plages de code points de poids 1 (twitter-text v3) ; tout le reste pèse 2. */
    private const LIGHT_RANGES = [[0x0000, 0x10FF], [0x2000, 0x200D], [0x2010, 0x201F], [0x2032, 0x2037]];

    public static function of(string $text): int
    {
        if (class_exists(Normalizer::class)) {
            $text = Normalizer::normalize($text, Normalizer::FORM_C) ?: $text;
        }

        $urls = 0;
        $text = (string) preg_replace_callback('~https?://[^\s]+~iu', function () use (&$urls): string {
            $urls++;

            return ' ';
        }, $text);
        $length = $urls * self::URL_LENGTH - $urls; // chaque URL remplacée par une espace (poids 1)

        foreach (self::graphemes($text) as $grapheme) {
            if (self::isEmoji($grapheme)) {
                $length += 2;

                continue;
            }
            foreach (mb_str_split($grapheme) as $char) {
                $length += self::isLight(mb_ord($char)) ? 1 : 2;
            }
        }

        return $length;
    }

    /**
     * Graphèmes (caractères perçus) selon ICU (extension intl). Repli sur \X de PCRE, qui regroupe
     * à tort des emoji consécutifs dans certaines versions (PCRE2 10.42).
     *
     * @return list<string>
     */
    private static function graphemes(string $text): array
    {
        if (class_exists(IntlBreakIterator::class)) {
            $iterator = IntlBreakIterator::createCharacterInstance();
            $iterator->setText($text);

            return array_values(iterator_to_array($iterator->getPartsIterator(), false));
        }
        preg_match_all('/\X/u', $text, $matches);

        return $matches[0];
    }

    /**
     * Emoji : drapeau (indicateurs régionaux), touche (U+20E3), pictogramme suivi du sélecteur
     * U+FE0F, ou pictogramme hors des plages latines. « © » ou « ® » seuls restent du texte.
     */
    private static function isEmoji(string $grapheme): bool
    {
        if (preg_match('/[\x{1F1E6}-\x{1F1FF}\x{20E3}]/u', $grapheme)) {
            return true;
        }
        if (! preg_match('/\p{Extended_Pictographic}/u', $grapheme)) {
            return false;
        }

        return str_contains($grapheme, "\u{FE0F}") || (bool) preg_match('/[^\x{0000}-\x{10FF}]/u', $grapheme);
    }

    private static function isLight(int $codePoint): bool
    {
        foreach (self::LIGHT_RANGES as [$start, $end]) {
            if ($codePoint >= $start && $codePoint <= $end) {
                return true;
            }
        }

        return false;
    }
}
