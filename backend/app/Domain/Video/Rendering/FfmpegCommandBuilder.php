<?php

namespace App\Domain\Video\Rendering;

/**
 * Traduit une spécification de rendu figée (soxial.render_spec.v1) en commande FFmpeg.
 * Classe pure : aucun processus, aucun accès disque.
 *
 * Sécurité :
 * - arguments en tableau (jamais de chaîne shell) ; chemins des sources = arguments séparés ;
 * - entrées limitées au protocole « file » ;
 * - textes des utilisateurs dans des fichiers lus par drawtext (textfile, expansion=none), jamais
 *   dans le graphe de filtres ; le graphe est lui-même lu depuis un fichier (-/filter_complex) ;
 * - fichiers annexes en chemins relatifs au dossier de travail (aucun « : » ni « \ » à échapper).
 *
 * Composition : fond noir W×H, puis les pistes de l'arrière-plan au premier plan ; dans une piste,
 * les éléments dans l'ordre du temps. Image et vidéo : mise à l'échelle (cover = recadrage,
 * contain = bandes transparentes), fondu d'entrée/sortie facultatif, superposition entre start et
 * fin. Texte : drawtext. Son : sources trimmées par -ss/-t, volume, décalage (adelay), mixage.
 */
final class FfmpegCommandBuilder
{
    public const FILTER_FILE = 'filter.txt';

    public const FONT_FILE = 'font.ttf';

    /**
     * @param  array<string, mixed>  $spec  spécification figée du rendu
     * @param  array<string, string>  $sources  media_id → chemin local lisible
     * @param  array<string, bool>  $sourceHasAudio  media_id → la vidéo contient du son
     * @param  string  $output  chemin du MP4 à produire
     */
    public function build(array $spec, array $sources, array $sourceHasAudio, string $output): BuiltCommand
    {
        $width = (int) $spec['project']['width'];
        $height = (int) $spec['project']['height'];
        $fps = (int) $spec['project']['fps'];
        $durationMs = (int) $spec['project']['duration_ms'];
        $duration = $this->seconds($durationMs);

        $inputs = [];
        $filters = ["color=c=black:s={$width}x{$height}:r={$fps}:d={$duration},format=yuv420p[base]"];
        $audio = [];
        $files = [];
        $video = 'base';
        $hasText = false;
        $step = 0;

        foreach ($spec['tracks'] as $track) {
            foreach ($track['items'] as $item) {
                $start = (int) $item['start_ms'];
                $end = $start + (int) $item['duration_ms'];
                $props = (array) ($item['properties'] ?? []);
                $enable = sprintf("enable='between(t,%s,%s)'", $this->seconds($start), $this->seconds($end));

                if ($item['type'] === 'text') {
                    if ($track['hidden']) {
                        continue;
                    }
                    $textFile = "text_{$step}.txt";
                    $files[$textFile] = (string) ($props['text'] ?? '');
                    $hasText = true;
                    $next = 'v'.(++$step);
                    $filters[] = "[{$video}]drawtext=fontfile=".self::FONT_FILE.":textfile={$textFile}:expansion=none"
                        .':fontsize='.(int) ($props['font_size'] ?? 48)
                        .':fontcolor=0x'.ltrim((string) ($props['color'] ?? '#FFFFFF'), '#')
                        .':x='.$this->textX($props['align'] ?? 'center')
                        .':y='.$this->textY($props['position'] ?? 'bottom')
                        .":shadowcolor=black@0.6:shadowx=2:shadowy=2:{$enable}[{$next}]";
                    $video = $next;

                    continue;
                }

                $mediaId = (string) $item['media_id'];
                $index = count($inputs);
                $sourceStart = $this->seconds((int) ($item['source_start_ms'] ?? 0));
                $sourceLength = $this->seconds((int) ($item['source_duration_ms'] ?? $item['duration_ms']));
                $inputs[] = $item['type'] === 'image'
                    ? ['-loop', '1', '-framerate', (string) $fps, '-t', $this->seconds((int) $item['duration_ms']), '-protocol_whitelist', 'file', '-i', $sources[$mediaId]]
                    : ['-ss', $sourceStart, '-t', $sourceLength, '-protocol_whitelist', 'file', '-i', $sources[$mediaId]];

                if (in_array($item['type'], ['image', 'video'], true) && ! $track['hidden']) {
                    $layer = 'l'.$step;
                    $filters[] = "[{$index}:v]".$this->fit($props['fit'] ?? 'cover', $width, $height).",fps={$fps}"
                        .$this->fades((array) ($item['transitions'] ?? []), (int) $item['duration_ms'])
                        .',setpts=PTS-STARTPTS+'.$this->seconds($start)."/TB[{$layer}]";
                    $next = 'v'.(++$step);
                    $filters[] = "[{$video}][{$layer}]overlay=x=0:y=0:eof_action=pass:{$enable}[{$next}]";
                    $video = $next;
                }

                $audible = $item['type'] === 'audio' || ($item['type'] === 'video' && ($sourceHasAudio[$mediaId] ?? false));
                if ($audible && ! $track['muted']) {
                    $label = 'a'.count($audio);
                    $volume = max(0, min(100, (int) ($props['volume'] ?? 100))) / 100;
                    $filters[] = "[{$index}:a]aresample=".(int) config('video.encoding.audio_sample_rate', 48_000)
                        .',aformat=sample_fmts=fltp:channel_layouts=stereo,asetpts=PTS-STARTPTS'
                        .',volume='.number_format($volume, 2, '.', '')
                        .",adelay=delays={$start}:all=1[{$label}]";
                    $audio[] = $label;
                }
            }
        }

        $filters[] = "[{$video}]format=yuv420p[vout]";
        if ($audio !== []) {
            $mix = count($audio) > 1
                ? implode('', array_map(fn ($a) => "[{$a}]", $audio)).'amix=inputs='.count($audio).':duration=longest:normalize=0,'
                : "[{$audio[0]}]";
            $filters[] = $mix."apad=whole_dur={$duration},atrim=0:{$duration}[aout]";
        }
        $files[self::FILTER_FILE] = implode(";\n", $filters);

        $encoding = (array) config('video.encoding');
        $args = ['-hide_banner', '-nostdin', '-y', '-loglevel', 'error'];
        foreach ($inputs as $input) {
            array_push($args, ...$input);
        }
        array_push($args, '-/filter_complex', self::FILTER_FILE, '-map', '[vout]');
        if ($audio !== []) {
            array_push($args, '-map', '[aout]', '-c:a', 'aac', '-b:a', (string) $encoding['audio_bitrate'], '-ar', (string) $encoding['audio_sample_rate']);
        } else {
            $args[] = '-an';
        }
        array_push($args,
            '-c:v', 'libx264', '-preset', (string) $encoding['video_preset'], '-crf', (string) $encoding['video_crf'],
            '-pix_fmt', 'yuv420p', '-r', (string) $fps, '-t', $duration,
            '-movflags', '+faststart', '-f', 'mp4',
            '-progress', 'pipe:1', '-nostats',
            $output,
        );

        return new BuiltCommand($args, $files, $audio !== [], $hasText, $durationMs);
    }

    /** Secondes avec 3 décimales, séparateur point (indépendant de la locale). */
    private function seconds(int $ms): string
    {
        return number_format($ms / 1000, 3, '.', '');
    }

    private function fit(string $fit, int $width, int $height): string
    {
        return $fit === 'contain'
            ? "scale={$width}:{$height}:force_original_aspect_ratio=decrease,setsar=1,format=yuva420p,pad={$width}:{$height}:(ow-iw)/2:(oh-ih)/2:color=black@0"
            : "scale={$width}:{$height}:force_original_aspect_ratio=increase,crop={$width}:{$height},setsar=1,format=yuva420p";
    }

    /** @param array<string, mixed> $transitions fondus d'entrée/sortie (transparence) */
    private function fades(array $transitions, int $durationMs): string
    {
        $fadeMs = min((int) ($transitions['duration_ms'] ?? 500), intdiv($durationMs, 2));
        if ($fadeMs <= 0) {
            return '';
        }
        $out = '';
        if (($transitions['in'] ?? 'none') === 'fade') {
            $out .= ',fade=t=in:st=0:d='.$this->seconds($fadeMs).':alpha=1';
        }
        if (($transitions['out'] ?? 'none') === 'fade') {
            $out .= ',fade=t=out:st='.$this->seconds($durationMs - $fadeMs).':d='.$this->seconds($fadeMs).':alpha=1';
        }

        return $out;
    }

    private function textX(string $align): string
    {
        return match ($align) {
            'left' => 'w*0.05',
            'right' => 'w-text_w-w*0.05',
            default => '(w-text_w)/2',
        };
    }

    private function textY(string $position): string
    {
        return match ($position) {
            'top' => 'h*0.08',
            'center' => '(h-text_h)/2',
            default => 'h-text_h-h*0.1',
        };
    }
}
