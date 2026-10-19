<?php

namespace App\Domain\Video\Clipper\Analysis;

use App\Domain\Video\Clipper\ClipLimits;

/**
 * Analyse LOCALE des passages (ADR-047) : heuristique déterministe, sans IA ni réseau.
 *
 * Avec transcription : chaque fenêtre de segments consécutifs (≤ 60 s) est notée sur 100 —
 * phrases complètes au début et à la fin, continuité de la parole, débit, durée proche de
 * 20-45 s, accroche (question, exclamation, chiffre, mot d'appel).
 * Sans transcription : fenêtres découpées sur les pauses détectées (FFmpeg silencedetect), notées
 * sur la continuité du son et la durée.
 * Les meilleures fenêtres sans chevauchement sont retenues ; chaque justification décrit les critères
 * réellement mesurés (jamais présentée comme une IA).
 */
class LocalClipAnalyzer implements ClipAnalyzer
{
    /** Durée minimale d'un passage proposé (ms) : un extrait plus court n'est pas un clip utile. */
    private const MIN_SUGGESTION_MS = 10_000;

    /** Mots d'appel (français, anglais) signalant une accroche ou une idée clé. */
    private const HOOK_WORDS = ['important', 'secret', 'astuce', 'conseil', 'erreur', 'pourquoi', 'comment', 'jamais', 'toujours', 'attention', 'tip', 'mistake', 'why', 'how', 'never', 'always', 'key'];

    public function mode(): string
    {
        return 'local';
    }

    public function requiresTranscript(): bool
    {
        return false;
    }

    public function analyze(array $segments, array $silences, int $durationMs, string $title, string $projectUserId, string $projectId): array
    {
        $count = max(1, min(10, (int) config('video.clipper.suggestions', 5)));
        $candidates = $segments !== [] ? $this->fromTranscript($segments, $durationMs) : $this->fromPauses($silences, $durationMs);

        return $this->select($candidates, $count);
    }

    /**
     * @param  list<array{start_ms: int, end_ms: int, text: string}>  $segments
     * @return list<array{start_ms: int, end_ms: int, title: string, topic: string, reason: string, score: int}>
     */
    private function fromTranscript(array $segments, int $durationMs): array
    {
        $min = max(ClipLimits::minClipMs(), self::MIN_SUGGESTION_MS);
        $candidates = [];
        $n = count($segments);
        for ($i = 0; $i < $n; $i++) {
            $startsClean = $i === 0 || $this->endsSentence($segments[$i - 1]['text']);
            $spoken = 0;
            $words = 0;
            $texts = [];
            for ($j = $i; $j < $n; $j++) {
                $start = $segments[$i]['start_ms'];
                $end = $segments[$j]['end_ms'];
                if ($end - $start > ClipLimits::MAX_CLIP_MS) {
                    break;
                }
                $spoken += $segments[$j]['end_ms'] - $segments[$j]['start_ms'];
                $words += count(preg_split('/\s+/u', trim($segments[$j]['text']), -1, PREG_SPLIT_NO_EMPTY) ?: []);
                $texts[] = $segments[$j]['text'];
                $duration = $end - $start;
                if ($duration < $min) {
                    continue;
                }
                $range = ClipLimits::bound($start, $end, $durationMs);
                if ($range === null) {
                    continue;
                }
                $endsClean = $this->endsSentence($segments[$j]['text']);
                $coverage = min(1.0, $spoken / $duration);
                $rate = $words / ($duration / 1000);
                $hook = $this->hook($segments[$i]['text']);
                $score = 100 * (
                    0.30 * min(1.0, $rate / 2.5)
                    + 0.25 * $coverage
                    + 0.15 * ($startsClean ? 1 : 0)
                    + 0.15 * ($endsClean ? 1 : 0)
                    + 0.15 * $this->durationFit($duration)
                ) + ($hook !== null ? 5 : 0);

                $text = trim(preg_replace('/\s+/u', ' ', implode(' ', $texts)) ?? '');
                $reasons = array_filter([
                    $startsClean && $endsClean ? 'phrases complètes du début à la fin' : null,
                    'parole continue à '.(int) round($coverage * 100).' %',
                    'débit de '.number_format($rate, 1, ',', '').' mots/s',
                    $hook !== null ? "accroche : {$hook}" : null,
                ]);
                $candidates[] = [
                    'start_ms' => $range[0],
                    'end_ms' => $range[1],
                    'title' => $this->title($text),
                    'topic' => mb_substr($text, 0, 255),
                    'reason' => 'Analyse locale (sans IA) : '.implode(', ', $reasons).'.',
                    'score' => (int) max(0, min(100, round($score))),
                ];
            }
        }

        return $candidates;
    }

    /**
     * @param  list<array{0: int, 1: int}>  $silences
     * @return list<array{start_ms: int, end_ms: int, title: string, topic: string, reason: string, score: int}>
     */
    private function fromPauses(array $silences, int $durationMs): array
    {
        if ($silences === []) {
            return []; // aucune structure mesurable : pas de passage inventé
        }
        // Zones sonores = complément des pauses dans [0, durée].
        $regions = [];
        $cursor = 0;
        foreach ($silences as [$from, $to]) {
            if ($from - $cursor >= 500) {
                $regions[] = [$cursor, $from];
            }
            $cursor = max($cursor, $to);
        }
        if ($durationMs - $cursor >= 500) {
            $regions[] = [$cursor, $durationMs];
        }

        $min = max(ClipLimits::minClipMs(), self::MIN_SUGGESTION_MS);
        $candidates = [];
        $n = count($regions);
        for ($i = 0; $i < $n; $i++) {
            $sound = 0;
            for ($j = $i; $j < $n; $j++) {
                [$start, $end] = [$regions[$i][0], $regions[$j][1]];
                if ($end - $start > ClipLimits::MAX_CLIP_MS) {
                    break;
                }
                $sound += $regions[$j][1] - $regions[$j][0];
                $duration = $end - $start;
                $range = $duration >= $min ? ClipLimits::bound($start, $end, $durationMs) : null;
                if ($range === null) {
                    continue;
                }
                $coverage = min(1.0, $sound / $duration);
                $candidates[] = [
                    'start_ms' => $range[0],
                    'end_ms' => $range[1],
                    'title' => '',
                    'topic' => '',
                    'reason' => 'Analyse locale (sans IA ni transcription) : section sonore continue à '.(int) round($coverage * 100).' %, découpée sur des pauses naturelles.',
                    'score' => (int) round(100 * (0.6 * $coverage + 0.4 * $this->durationFit($duration))),
                ];
            }
        }

        return $candidates;
    }

    /**
     * Meilleures fenêtres sans chevauchement, puis titres des passages sans transcription.
     *
     * @param  list<array{start_ms: int, end_ms: int, title: string, topic: string, reason: string, score: int}>  $candidates
     * @return list<array{start_ms: int, end_ms: int, title: string, topic: string, reason: string, score: int}>
     */
    private function select(array $candidates, int $count): array
    {
        usort($candidates, fn ($a, $b) => [$b['score'], $a['start_ms']] <=> [$a['score'], $b['start_ms']]);
        $kept = [];
        foreach ($candidates as $candidate) {
            foreach ($kept as $other) {
                if ($candidate['start_ms'] < $other['end_ms'] && $other['start_ms'] < $candidate['end_ms']) {
                    continue 2;
                }
            }
            $kept[] = $candidate;
            if (count($kept) >= $count) {
                break;
            }
        }
        foreach ($kept as $i => $clip) {
            if ($clip['title'] === '') {
                $kept[$i]['title'] = 'Passage '.($i + 1).' · '.$this->timecode($clip['start_ms']).' → '.$this->timecode($clip['end_ms']);
            }
        }

        return $kept;
    }

    /** 1 pour 20-45 s, décroît vers les extrêmes (10 s, 60 s). */
    private function durationFit(int $durationMs): float
    {
        $s = $durationMs / 1000;

        return match (true) {
            $s >= 20 && $s <= 45 => 1.0,
            $s < 20 => max(0.4, ($s - 10) / 10 * 0.6 + 0.4),
            default => max(0.6, 1 - ($s - 45) / 15 * 0.4),
        };
    }

    private function endsSentence(string $text): bool
    {
        return (bool) preg_match('/[.!?…]["»”)]?\s*$/u', trim($text));
    }

    /** Nature de l'accroche du premier segment, null s'il n'y en a pas. */
    private function hook(string $text): ?string
    {
        $lower = mb_strtolower($text);

        return match (true) {
            str_contains($text, '?') => 'question',
            str_contains($text, '!') => 'exclamation',
            (bool) preg_match('/\d/u', $text) => 'chiffre',
            (bool) preg_match('/\b('.implode('|', self::HOOK_WORDS).')\b/u', $lower) => 'mot clé',
            default => null,
        };
    }

    /** Première phrase, 70 caractères au plus. */
    private function title(string $text): string
    {
        $first = preg_split('/(?<=[.!?…])\s+/u', $text)[0] ?? $text;
        $first = rtrim(trim($first), '.');

        return mb_strlen($first) > 70 ? rtrim(mb_substr($first, 0, 69)).'…' : ($first !== '' ? $first : 'Passage proposé');
    }

    private function timecode(int $ms): string
    {
        $s = intdiv($ms, 1000);

        return sprintf('%02d:%02d', intdiv($s, 60), $s % 60);
    }
}
