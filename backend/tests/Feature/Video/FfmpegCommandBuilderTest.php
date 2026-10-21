<?php

namespace Tests\Feature\Video;

use App\Domain\Video\Rendering\FfmpegCommandBuilder;
use Tests\TestCase;

/** Construction de la commande FFmpeg (ADR-045) : sans exécution, sans shell, textes hors de la commande. */
class FfmpegCommandBuilderTest extends TestCase
{
    private function spec(array $tracks, int $durationMs = 6000): array
    {
        return ['schema' => 'soxial.render_spec.v1', 'project' => ['width' => 1080, 'height' => 1920, 'fps' => 30, 'duration_ms' => $durationMs], 'tracks' => $tracks];
    }

    private function track(string $type, array $items, bool $hidden = false, bool $muted = false): array
    {
        return ['type' => $type, 'position' => 0, 'hidden' => $hidden, 'muted' => $muted, 'items' => $items];
    }

    private function item(string $type, array $overrides = []): array
    {
        return $overrides + ['type' => $type, 'media_id' => $type === 'text' ? null : "m-{$type}", 'start_ms' => 0, 'duration_ms' => 3000, 'source_start_ms' => null, 'source_duration_ms' => null, 'properties' => [], 'transitions' => null];
    }

    public function test_a_full_timeline_becomes_a_safe_argument_list(): void
    {
        $evil = "Salut'; rm -rf / ; :x \\ %{pts} \"quote\"";
        $command = (new FfmpegCommandBuilder)->build($this->spec([
            $this->track('video', [
                $this->item('video', ['source_start_ms' => 1500, 'properties' => ['volume' => 50]]),
                $this->item('image', ['start_ms' => 3000, 'properties' => ['fit' => 'contain'], 'transitions' => ['in' => 'fade', 'duration_ms' => 400]]),
            ]),
            $this->track('text', [$this->item('text', ['start_ms' => 500, 'properties' => ['text' => $evil, 'font_size' => 80, 'color' => '#FFD700', 'position' => 'top', 'align' => 'left']])]),
            $this->track('audio', [$this->item('audio', ['start_ms' => 1000, 'duration_ms' => 5000, 'properties' => ['volume' => 30]])]),
        ]), ['m-video' => 'C:/src/clip one.mp4', 'm-image' => 'C:/src/img.png', 'm-audio' => 'C:/src/tone.mp3'], ['m-video' => true], 'C:/out/video.mp4');

        $args = $command->arguments;
        $this->assertIsList($args);
        $joined = implode("\n", $args);
        $this->assertStringNotContainsString($evil, $joined, 'Le texte de l\'utilisateur ne figure jamais dans la commande.');
        $this->assertStringNotContainsString('rm -rf', $joined.$command->files['filter.txt']);
        $this->assertSame($evil, $command->files['text_2.txt'] ?? null, 'Texte écrit tel quel dans un fichier lu par drawtext.');

        // Chemins = arguments distincts (espaces conservés, aucune concaténation), entrées limitées au protocole file.
        $this->assertContains('C:/src/clip one.mp4', $args);
        $this->assertSame(3, count(array_keys($args, '-i')));
        $this->assertSame(3, count(array_keys($args, 'file')));
        $this->assertSame(['-ss', '1.500', '-t', '3.000'], array_slice($args, array_search('C:/src/clip one.mp4', $args, true) - 7, 4));
        $this->assertContains('-loop', $args);

        $filter = $command->files['filter.txt'];
        $this->assertStringContainsString('color=c=black:s=1080x1920:r=30:d=6.000', $filter);
        $this->assertStringContainsString('crop=1080:1920', $filter, 'Vidéo en « cover ».');
        $this->assertStringContainsString('pad=1080:1920', $filter, 'Image en « contain ».');
        $this->assertStringContainsString('fade=t=in:st=0:d=0.400:alpha=1', $filter);
        $this->assertStringContainsString("overlay=x=0:y=0:eof_action=pass:enable='between(t,3.000,6.000)'", $filter);
        $this->assertStringContainsString('drawtext=fontfile=font.ttf:textfile=text_2.txt:expansion=none:fontsize=80:fontcolor=0xFFD700:x=w*0.05:y=h*0.08', $filter);
        $this->assertStringContainsString('volume=0.50,adelay=delays=0:all=1', $filter, 'Son de la vidéo.');
        $this->assertStringContainsString('volume=0.30,adelay=delays=1000:all=1', $filter, 'Piste audio décalée.');
        $this->assertStringContainsString('amix=inputs=2:duration=longest:normalize=0', $filter);
        $this->assertTrue($command->needsFont);
        $this->assertTrue($command->hasAudio);

        // Sortie : H.264 + AAC, MP4 lisible en streaming, durée fixée, progression sur stdout.
        foreach ([['-c:v', 'libx264'], ['-c:a', 'aac'], ['-pix_fmt', 'yuv420p'], ['-movflags', '+faststart'], ['-t', '6.000'], ['-progress', 'pipe:1'], ['-/filter_complex', 'filter.txt']] as [$flag, $value]) {
            $positions = array_keys($args, $flag, true); // options de sortie : dernière occurrence
            $this->assertSame($value, $args[end($positions) + 1], $flag);
        }
        $this->assertSame('C:/out/video.mp4', end($args));
    }

    public function test_hidden_tracks_are_not_drawn_and_muted_tracks_are_silent(): void
    {
        $command = (new FfmpegCommandBuilder)->build($this->spec([
            $this->track('video', [$this->item('video')], muted: true),
            $this->track('text', [$this->item('text', ['properties' => ['text' => 'caché']])], hidden: true),
        ]), ['m-video' => 'C:/src/clip.mp4'], ['m-video' => true], 'C:/out/video.mp4');

        $this->assertStringNotContainsString('drawtext', $command->files['filter.txt']);
        $this->assertArrayNotHasKey('text_1.txt', $command->files);
        $this->assertFalse($command->needsFont);
        $this->assertFalse($command->hasAudio);
        $this->assertContains('-an', $command->arguments, 'Aucune piste son si rien n\'est audible.');
        $this->assertNotContains('aac', $command->arguments);
    }

    public function test_the_same_specification_always_gives_the_same_command(): void
    {
        $spec = $this->spec([$this->track('video', [$this->item('video'), $this->item('image', ['start_ms' => 3000])])]);
        $build = fn () => (new FfmpegCommandBuilder)->build($spec, ['m-video' => 'a.mp4', 'm-image' => 'b.png'], ['m-video' => false], 'o.mp4');

        $this->assertEquals($build(), $build());
    }

    /** Sous-titre du Clipper (fond « box ») : seul cas qui demande text_align. */
    private function subtitled(?string $version): array
    {
        $command = (new FfmpegCommandBuilder)->forVersion($version)->build($this->spec([
            $this->track('text', [$this->item('text', ['properties' => ['text' => 'Bonjour', 'box' => true]])]),
        ]), [], [], 'o.mp4');

        return ['arguments' => $command->arguments, 'files' => $command->files];
    }

    public static function versions(): array
    {
        return [
            'Debian bookworm (image worker initiale)' => ['ffmpeg version 5.1.9-0+deb12u1 Copyright (c) 2000-2026 the FFmpeg developers', '-filter_complex_script', false],
            'FFmpeg 6.0' => ['ffmpeg version 6.0-static https://johnvansickle.com/ffmpeg/', '-filter_complex_script', false],
            'Ubuntu 24.04 (CI)' => ['ffmpeg version 6.1.1-3ubuntu5 Copyright (c) 2000-2023 the FFmpeg developers', '-filter_complex_script', true],
            'Debian trixie (image worker)' => ['ffmpeg version 7.1.5-0+deb13u1 Copyright (c) 2000-2026 the FFmpeg developers', '-/filter_complex', true],
            'compilation « n7.1 »' => ['ffmpeg version n7.1-12-gabcdef Copyright (c) 2000-2024', '-/filter_complex', true],
            'poste de développement' => ['ffmpeg version 9.0.2-essentials_build-www.gyan.dev Copyright (c) 2000-2026', '-/filter_complex', true],
            'compilation de développement' => ['ffmpeg version N-118000-g0123456 Copyright (c) 2000-2026', '-/filter_complex', true],
            'version inconnue' => [null, '-/filter_complex', true],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('versions')]
    public function test_the_command_syntax_follows_the_ffmpeg_version(?string $version, string $filterOption, bool $textAlign): void
    {
        $command = $this->subtitled($version);

        $this->assertContains($filterOption, $command['arguments'], 'Option de lecture du graphe de filtres.');
        $this->assertSame('filter.txt', $command['arguments'][array_search($filterOption, $command['arguments'], true) + 1]);
        $this->assertNotContains($filterOption === '-/filter_complex' ? '-filter_complex_script' : '-/filter_complex', $command['arguments']);
        $this->assertSame($textAlign, str_contains($command['files']['filter.txt'], 'text_align=C'), 'text_align seulement à partir de FFmpeg 6.1.');
        $this->assertStringContainsString('box=1:boxcolor=black@0.55', $command['files']['filter.txt'], 'Fond des sous-titres conservé dans tous les cas.');
    }

    public function test_the_version_does_not_change_anything_else(): void
    {
        $old = $this->subtitled('ffmpeg version 5.1.9-0+deb12u1');
        $new = $this->subtitled('ffmpeg version 9.0.2');

        $normalize = fn (array $c) => [array_values(array_diff($c['arguments'], ['-filter_complex_script', '-/filter_complex'])), str_replace(':text_align=C', '', $c['files']['filter.txt'])];
        $this->assertSame($normalize($new), $normalize($old));
    }
}
