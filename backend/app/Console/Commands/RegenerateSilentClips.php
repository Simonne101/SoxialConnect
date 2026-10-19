<?php

namespace App\Console\Commands;

use App\Domain\Video\Clipper\ClipperConflict;
use App\Domain\Video\Clipper\ClipperService;
use App\Domain\Video\Enums\ClipStatus;
use App\Domain\Video\Rendering\AudioCheck;
use App\Models\Media;
use App\Models\VideoClip;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Clips rendus AVANT le contrôle audio (ADR-061, son atténué de 40 dB) : mesure réelle de chaque MP4 face au
 * même passage de la source, puis (avec --execute) NOUVEAU rendu du même passage — l'original n'est jamais
 * modifié ni supprimé ; le nouveau clip passe le contrôle audio obligatoire avant publication. Par défaut :
 * simulation (aucune écriture). Relançable sans doublon (rendu déjà demandé = ignoré).
 */
class RegenerateSilentClips extends Command
{
    public const SUFFIX = ' (son rétabli)';

    protected $signature = 'clipper:regenerate-silent-clips {--execute : lance réellement les nouveaux rendus} {--limit=0 : nombre maximal de rendus lancés (0 = tous)}';

    protected $description = 'Mesure les clips antérieurs au contrôle audio et relance le rendu de ceux dont le son a été perdu (originaux conservés)';

    public function handle(AudioCheck $checks, ClipperService $clipper): int
    {
        $execute = (bool) $this->option('execute');
        $limit = max(0, (int) $this->option('limit'));
        $launched = 0;
        $rows = [];
        $clips = VideoClip::query()->with(['outputMedia', 'project'])->where('status', ClipStatus::Rendered->value)->whereNotNull('output_media_id')
            ->whereHas('project')->orderBy('created_at')->get()
            ->filter(fn (VideoClip $c) => ! isset($c->outputMedia?->metadata['audio']) && ! str_ends_with((string) $c->title, self::SUFFIX));

        foreach ($clips as $clip) {
            $output = $this->path($clip->outputMedia);
            $source = $this->path($clip->project->source_media_id ? Media::find($clip->project->source_media_id) : null);
            $out = $output ? $checks->loudness($output) : null;
            $ref = $source ? $checks->loudness($source, $clip->start_ms, $clip->end_ms - $clip->start_ms) : null;
            $verdict = match (true) {
                $output === null || $source === null => 'fichier absent',
                $ref === null || $ref['max'] <= -50 => 'source muette (rien à rétablir)',
                $out === null || $out['max'] <= -50 || $out['mean'] < $ref['mean'] - 6 => 'son perdu',
                default => 'son correct',
            };
            $action = '—';
            if ($verdict === 'son perdu') {
                $action = $this->alreadyRequested($clip) ? 'déjà relancé' : ($execute && ($limit === 0 || $launched < $limit) ? $this->relaunch($clipper, $clip, $launched) : 'à relancer');
            }
            $rows[] = [substr($clip->id, 0, 13), mb_strimwidth((string) $clip->title, 0, 30, '…'), sprintf('%.1f s', ($clip->end_ms - $clip->start_ms) / 1000),
                $out ? sprintf('%.1f', $out['mean']) : '—', $ref ? sprintf('%.1f', $ref['mean']) : '—', $verdict, $action];
        }
        $this->table(['Clip', 'Titre', 'Durée', 'Clip dB', 'Source dB', 'Diagnostic', 'Action'], $rows);
        $this->components->info($execute ? "{$launched} nouveau(x) rendu(s) lancé(s) ; originaux conservés." : 'Simulation : aucun rendu lancé (option --execute pour lancer).');

        return self::SUCCESS;
    }

    private function relaunch(ClipperService $clipper, VideoClip $clip, int &$launched): string
    {
        try {
            $new = $clipper->requestClip($clip->project, $clip->start_ms, $clip->end_ms, $clip->aspect_ratio,
                mb_substr((string) $clip->title, 0, 240).self::SUFFIX, null, (bool) $clip->subtitles_enabled, $clip->subtitle_style);
        } catch (ClipperConflict $e) {
            if ($e->errorCode !== 'SUBTITLES_UNAVAILABLE') {
                return 'impossible : '.$e->getMessage();
            }
            $new = $clipper->requestClip($clip->project, $clip->start_ms, $clip->end_ms, $clip->aspect_ratio, mb_substr((string) $clip->title, 0, 240).self::SUFFIX);
        }
        $launched++;

        return 'relancé → '.substr($new->id, 0, 13);
    }

    /** Un rendu de remplacement (même passage, même format) existe déjà : jamais de doublon. */
    private function alreadyRequested(VideoClip $clip): bool
    {
        return VideoClip::query()->where('video_project_id', $clip->video_project_id)->where('start_ms', $clip->start_ms)->where('end_ms', $clip->end_ms)
            ->where('aspect_ratio', $clip->aspect_ratio->value)->where('title', 'like', '%'.self::SUFFIX)
            ->whereIn('status', [ClipStatus::Rendering->value, ClipStatus::Rendered->value])->exists();
    }

    private function path(?Media $media): ?string
    {
        $disk = $media ? Storage::disk($media->disk ?? 'media') : null;

        return $media && $media->path && $disk->exists($media->path) ? $disk->path($media->path) : null;
    }
}
