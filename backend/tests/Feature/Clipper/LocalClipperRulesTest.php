<?php

namespace Tests\Feature\Clipper;

use App\Domain\Video\Clipper\Analysis\ClipAnalyzerResolver;
use App\Domain\Video\Clipper\Analysis\LocalClipAnalyzer;
use App\Domain\Video\Clipper\AudioSignals;
use App\Domain\Video\Clipper\ClipLimits;
use App\Domain\Video\Clipper\SubtitleTrack;
use App\Domain\Video\Clipper\Transcription\TranscriberResolver;
use App\Domain\Video\Clipper\Transcription\TranscriptionFailure;
use App\Domain\Video\Clipper\Transcription\WhisperCppTranscriber;
use Tests\TestCase;

/** Briques locales du Clipper sans IA (ADR-047) : heuristique, pauses, whisper.cpp, sous-titres, choix des moteurs. */
class LocalClipperRulesTest extends TestCase
{
    /** @return list<array{start_ms: int, end_ms: int, text: string}> */
    private function segments(): array
    {
        $texts = [
            'Bienvenue dans cette présentation.', 'Premier conseil : publiez régulièrement.', 'Trois publications par semaine suffisent.',
            'Pourquoi les vidéos courtes fonctionnent-elles ?', 'Elles retiennent l\'attention dès les premières secondes.', 'Une accroche forte change tout.',
            'Troisième conseil : analysez vos statistiques.', 'Regardez ce qui fonctionne', 'et faites-en davantage.', 'Merci et à bientôt.',
        ];
        $segments = [];
        foreach ($texts as $i => $text) {
            $segments[] = ['start_ms' => $i * 9_000, 'end_ms' => $i * 9_000 + 8_000, 'text' => $text];
        }

        return $segments;
    }

    public function test_local_analysis_ranks_transcript_windows_within_every_rule(): void
    {
        $clips = app(LocalClipAnalyzer::class)->analyze($this->segments(), [], 90_000, 'Titre', 'u', 'p');

        $this->assertNotEmpty($clips);
        $this->assertLessThanOrEqual(5, count($clips));
        $sorted = collect($clips)->sortBy('start_ms')->values();
        foreach ($sorted as $i => $clip) {
            $this->assertGreaterThanOrEqual(0, $clip['start_ms']);
            $this->assertLessThanOrEqual(90_000, $clip['end_ms']);
            $this->assertNull(ClipLimits::violation($clip['start_ms'], $clip['end_ms'], 90_000));
            $this->assertGreaterThanOrEqual(10_000, $clip['end_ms'] - $clip['start_ms']);
            $this->assertStringStartsWith('Analyse locale (sans IA)', $clip['reason']);
            $this->assertNotSame('', $clip['title']);
            if ($i > 0) {
                $this->assertGreaterThanOrEqual($sorted[$i - 1]['end_ms'], $clip['start_ms'], 'Aucun chevauchement.');
            }
        }
        $this->assertSame($clips, app(LocalClipAnalyzer::class)->analyze($this->segments(), [], 90_000, 'Titre', 'u', 'p'), 'Déterministe.');
    }

    public function test_local_analysis_on_pauses_and_never_invents_passages(): void
    {
        $analyzer = app(LocalClipAnalyzer::class);
        $silences = [[14_000, 20_000], [34_000, 40_000], [54_000, 60_000], [74_000, 80_000]];

        $clips = $analyzer->analyze([], $silences, 90_000, 'Titre', 'u', 'p');
        $this->assertNotEmpty($clips);
        foreach ($clips as $clip) {
            $this->assertNull(ClipLimits::violation($clip['start_ms'], $clip['end_ms'], 90_000));
            $this->assertStringStartsWith('Passage ', $clip['title']);
            $this->assertStringContainsString('sans IA ni transcription', $clip['reason']);
        }
        $this->assertSame([], $analyzer->analyze([], [], 90_000, 'Titre', 'u', 'p'), 'Sans transcription ni pauses : aucune proposition inventée.');
        $this->assertSame([], $analyzer->analyze([['start_ms' => 0, 'end_ms' => 4_000, 'text' => 'Court.']], [], 4_000, 'Titre', 'u', 'p'));
    }

    public function test_ffmpeg_silence_logs_are_parsed(): void
    {
        $log = "[silencedetect @ 0x1] silence_start: 14.0005\n[silencedetect @ 0x1] silence_end: 20.01 | silence_duration: 6\nautre ligne\n[silencedetect @ 0x1] silence_start: 85.5\n";

        $this->assertSame([[14_001, 20_010], [85_500, 90_000]], AudioSignals::parse($log, 90_000));
        $this->assertSame([], AudioSignals::parse('', 90_000));
    }

    public function test_whisper_cpp_output_is_normalized(): void
    {
        $json = json_encode(['result' => ['language' => 'fr'], 'transcription' => [
            ['offsets' => ['from' => 0, 'to' => 4_000], 'text' => ' Bonjour à tous. '],
            ['offsets' => ['from' => 4_000, 'to' => 6_000], 'text' => ' [Musique] '],
            ['offsets' => ['from' => 6_000, 'to' => 9_000], 'text' => '(applaudissements)'],
            ['offsets' => ['from' => 9_000, 'to' => 12_000], 'text' => 'Merci.'],
            ['offsets' => ['from' => 13_000, 'to' => 12_000], 'text' => 'Incohérent'],
        ]]);

        $result = WhisperCppTranscriber::parse((string) $json, 'ggml-base', 1_200);

        $this->assertSame('whisper.cpp', $result->provider);
        $this->assertSame('fr', $result->language);
        $this->assertSame([['start_ms' => 0, 'end_ms' => 4_000, 'text' => 'Bonjour à tous.'], ['start_ms' => 9_000, 'end_ms' => 12_000, 'text' => 'Merci.']], $result->segments);
        $this->expectException(TranscriptionFailure::class);
        WhisperCppTranscriber::parse('pas du json', 'ggml-base', 0);
    }

    public function test_subtitles_are_shifted_wrapped_and_kept_inside_the_clip(): void
    {
        $segments = [
            ['start_ms' => 1_000, 'end_ms' => 4_000, 'text' => 'Avant le clip.'],
            ['start_ms' => 9_000, 'end_ms' => 15_000, 'text' => str_repeat('Une phrase assez longue pour plusieurs lignes ', 4)],
            ['start_ms' => 29_500, 'end_ms' => 40_000, 'text' => 'Déborde après la fin.'],
        ];
        $items = SubtitleTrack::items($segments, 10_000, 30_000, 1080, 1920, ['position' => 'top', 'size' => 'small']);

        $this->assertNotEmpty($items);
        $this->assertSame(0, $items[0]['start_ms'], 'Recalé sur le début du clip.');
        foreach ($items as $item) {
            $this->assertSame('text', $item['type']);
            $this->assertGreaterThanOrEqual(0, $item['start_ms']);
            $this->assertLessThanOrEqual(20_000, $item['start_ms'] + $item['duration_ms'], 'Jamais au-delà du clip.');
            $this->assertLessThanOrEqual(2, count(explode("\n", $item['properties']['text'])), 'Deux lignes au plus.');
            $this->assertSame('top', $item['properties']['position']);
            $this->assertTrue($item['properties']['box']);
        }
        $this->assertStringNotContainsString('Avant le clip', (string) json_encode($items));
        $this->assertSame(['ab', 'cd', 'e'], SubtitleTrack::wrap('abcde', 2), 'Mot trop long coupé.');
        $this->assertSame(['un deux', 'trois'], SubtitleTrack::wrap('un deux trois', 8));
    }

    public function test_engines_are_chosen_from_the_configuration(): void
    {
        config(['ai.default_provider' => null, 'video.clipper.whisper.binary' => storage_path('framework/testing/absent.exe')]);
        $analyzers = app(ClipAnalyzerResolver::class);
        $transcribers = app(TranscriberResolver::class);

        config(['video.clipper.analysis' => 'auto', 'video.clipper.transcription' => 'auto']);
        $this->assertSame('local', $analyzers->primaryMode(), 'Sans IA : analyse locale.');
        $this->assertNull($transcribers->resolve(), 'Ni whisper.cpp ni IA : pas de transcription.');
        config(['video.clipper.analysis' => 'ai']);
        $this->assertSame('none', $analyzers->primaryMode());
        config(['video.clipper.analysis' => 'none']);
        $this->assertSame([], $analyzers->chain());

        config(['ai.default_provider' => 'openai', 'ai.providers.openai.api_key' => 'sk-test-cle-fictive', 'ai.tasks.video_analysis.model' => 'texte', 'ai.tasks.transcription.model' => 'audio', 'video.clipper.analysis' => 'auto']);
        $this->assertSame(['ai', 'local'], array_map(fn ($a) => $a->mode(), $analyzers->chain()), 'IA puis repli local.');
        $this->assertSame('ai', $transcribers->resolve()?->mode());
        config(['video.clipper.transcription' => 'local']);
        $this->assertNull($transcribers->resolve(), 'Local demandé mais non installé.');
    }
}
