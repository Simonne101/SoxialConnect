<?php

namespace Tests\Feature\Clipper;

use App\Domain\Video\Clipper\ClipLimits;
use App\Domain\Video\Clipper\ClipRecalibrator;
use App\Domain\Video\Clipper\Transcription\WhisperCppTranscriber;
use Tests\TestCase;

/**
 * Recalage des passages proposés depuis le lien (ADR-049) et mots horodatés de whisper.cpp :
 * logique pure, aucune requête, aucune base.
 */
class ClipRecalibrationTest extends TestCase
{
    /** Transcription : phrases de 6 s, mots de 1 s. */
    private function segments(): array
    {
        $segments = [];
        foreach ([[0, 'Bonjour à tous et bienvenue.'], [6_000, 'Premier conseil important'], [12_000, 'publiez trois fois par semaine.'], [18_000, 'Deuxième conseil très utile.'], [24_000, 'Répondez aux commentaires.']] as [$start, $text]) {
            $words = [];
            foreach (array_slice(explode(' ', $text), 0, 6) as $i => $word) {
                $words[] = [$start + $i * 1_000, $start + ($i + 1) * 1_000 - 100, $word];
            }
            $segments[] = ['start_ms' => $start, 'end_ms' => $start + 5_800, 'text' => $text, 'words' => $words];
        }

        return $segments;
    }

    public function test_bounds_snap_to_sentences_and_never_cut_a_word(): void
    {
        $result = app(ClipRecalibrator::class)->recalibrate([
            // Gemini : début 1,5 s après le début de phrase, fin au milieu d'une phrase.
            ['start_ms' => 7_500, 'end_ms' => 16_200, 'title' => 'Conseil', 'score' => 90],
        ], $this->segments(), 30_000);

        $this->assertCount(1, $result);
        // « Premier conseil important » ne finit pas une phrase : on démarre au début de la phrase précédente
        // seulement si elle est plus proche ; ici le début de segment (6 s) est retenu.
        $this->assertSame(6_000, $result[0]['start_ms']);
        $this->assertSame(17_800, $result[0]['end_ms'], 'Fin calée sur la fin de phrase la plus proche.');
        $this->assertSame('Conseil', $result[0]['title']);
        $this->assertGreaterThan(0, $result[0]['shift_ms']);
    }

    public function test_never_more_than_sixty_seconds_nor_beyond_the_real_duration(): void
    {
        $segments = [];
        for ($t = 0; $t < 120_000; $t += 10_000) {
            $segments[] = ['start_ms' => $t, 'end_ms' => $t + 9_500, 'text' => 'Une phrase complète.'];
        }
        $result = app(ClipRecalibrator::class)->recalibrate([
            ['start_ms' => 10_000, 'end_ms' => 95_000, 'score' => 80], // 85 s proposées
            ['start_ms' => 100_000, 'end_ms' => 140_000, 'score' => 70], // au-delà de la vidéo (115 s)
            ['start_ms' => 130_000, 'end_ms' => 150_000, 'score' => 60], // entièrement hors vidéo
        ], $segments, 115_000);

        $this->assertCount(2, $result);
        foreach ($result as $clip) {
            $this->assertLessThanOrEqual(ClipLimits::MAX_CLIP_MS, $clip['end_ms'] - $clip['start_ms']);
            $this->assertLessThanOrEqual(115_000, $clip['end_ms']);
        }
        $this->assertSame([10_000, 69_500], [$result[0]['start_ms'], $result[0]['end_ms']], 'Dernière fin de phrase dans les 60 s.');
    }

    public function test_duplicates_keep_the_best_score_and_no_transcript_only_bounds(): void
    {
        $result = app(ClipRecalibrator::class)->recalibrate([
            ['start_ms' => 6_000, 'end_ms' => 18_000, 'title' => 'Moins bon', 'score' => 50],
            ['start_ms' => 6_500, 'end_ms' => 17_500, 'title' => 'Meilleur', 'score' => 95],
        ], $this->segments(), 30_000);
        $this->assertSame(['Meilleur'], array_column($result, 'title'));

        // Sans transcription : seulement bornés à la durée réelle et au plafond.
        $bare = app(ClipRecalibrator::class)->recalibrate([['start_ms' => 20_000, 'end_ms' => 45_000, 'score' => 70]], [], 30_000);
        $this->assertSame([20_000, 30_000], [$bare[0]['start_ms'], $bare[0]['end_ms']]);
    }

    public function test_whisper_words_are_rebuilt_even_when_an_accent_is_split_between_tokens(): void
    {
        // Sortie -ojf réelle : « présentation » découpé en « pr\xC3 » + « \xA9sentation » (octets UTF-8 incomplets).
        $tokens = [['[_BEG_]', 0, 0], [' Bien', 120, 240], ['venue', 240, 530], [' {C3}{A0}', 530, 700], [' la', 700, 900], [' pr{C3}', 1160, 1300], ['{A9}sentation', 1300, 1770], ['.', 1770, 1800]];
        $json = json_encode(['result' => ['language' => 'fr'], 'transcription' => [[
            'offsets' => ['from' => 0, 'to' => 3000],
            'text' => ' Bienvenue {C3}{A0} la pr{C3}{A9}sentation.',
            'tokens' => array_map(fn ($t) => ['text' => $t[0], 'offsets' => ['from' => $t[1], 'to' => $t[2]]], $tokens),
        ]]]);
        $json = str_replace(['{C3}', '{A0}', '{A9}'], [chr(0xC3), chr(0xA0), chr(0xA9)], $json); // octets bruts, comme whisper-cli

        $result = WhisperCppTranscriber::parse($json, 'ggml-base', 10);

        $this->assertSame('Bienvenue à la présentation.', $result->segments[0]['text']);
        $this->assertSame([[120, 530, 'Bienvenue'], [530, 700, 'à'], [700, 900, 'la'], [1160, 1800, 'présentation.']], $result->segments[0]['words']);
        $this->assertSame('fr', $result->language);
    }
}
