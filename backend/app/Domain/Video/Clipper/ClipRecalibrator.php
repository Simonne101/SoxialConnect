<?php

namespace App\Domain\Video\Clipper;

use App\Domain\Video\Clipper\Intelligence\ClipDeduplicator;

/**
 * Recalage des passages proposés depuis le lien YouTube (ADR-049) une fois la vraie source disponible.
 * Les horodatages de Gemini sont des estimations : ils sont recalés sur la durée mesurée par FFprobe
 * et sur la transcription horodatée (whisper.cpp), sans jamais dépasser 60 s :
 *
 * 1. bornes ramenées dans la vidéo ;
 * 2. début calé sur un début de phrase proche (sinon de segment, sinon de mot) ;
 * 3. fin calée sur une fin de phrase proche (sinon de segment, sinon de mot) — jamais au milieu d'un mot ;
 * 4. plafond de 60 s : fin ramenée à la dernière frontière qui tient ;
 * 5. doublons supprimés (ClipDeduplicator, seuil video.clipper.dedupe_overlap), meilleur score conservé.
 * Le score de l'analyse est conservé : il note le CONTENU du passage (critères), pas ses bornes à la
 * milliseconde ; seul le découpage change (décalage noté dans `shift_ms`, durée dans `duration_ms`).
 */
final class ClipRecalibrator
{
    /** Distance maximale (ms) entre une borne estimée et la frontière retenue. */
    private const TOLERANCE_MS = 4_000;

    /** Pénalité (ms) d'une frontière de segment qui n'est pas une fin de phrase. */
    private const WEAK_PENALTY_MS = 1_500;

    public function __construct(private readonly ClipDeduplicator $deduplicator) {}

    /**
     * @param  list<array{start_ms: int, end_ms: int, score?: int}&array<string, mixed>>  $proposals
     * @param  list<array{start_ms: int, end_ms: int, text: string, words?: list<array{0: int, 1: int, 2: string}>}>  $segments
     * @return list<array<string, mixed>> propositions recalées (start_ms, end_ms, shift_ms), triées par score
     */
    public function recalibrate(array $proposals, array $segments, int $durationMs): array
    {
        [$starts, $ends] = $this->boundaries($segments);
        $words = $this->words($segments);

        $result = [];
        foreach ($proposals as $proposal) {
            $start = max(0, min((int) $proposal['start_ms'], $durationMs));
            $end = max(0, min((int) $proposal['end_ms'], $durationMs));
            if ($end <= $start) {
                continue;
            }
            if ($starts !== []) {
                $start = $this->snap($start, $starts, fn (int $at) => $at < $durationMs) ?? $this->wordStart($start, $words);
                $limit = min($durationMs, $start + ClipLimits::MAX_CLIP_MS);
                $end = $this->snap(min($end, $limit), $ends, fn (int $at) => $at > $start + ClipLimits::minClipMs() && $at <= $limit)
                    ?? $this->lastEndBefore($limit, $start, $ends)
                    ?? $this->wordEnd(min($end, $limit), $start, $limit, $words);
            }
            $range = ClipLimits::bound($start, $end, $durationMs);
            if ($range === null) {
                continue;
            }
            $result[] = [...$proposal, 'start_ms' => $range[0], 'end_ms' => $range[1], 'duration_ms' => $range[1] - $range[0],
                'shift_ms' => abs($range[0] - (int) $proposal['start_ms']) + abs($range[1] - (int) $proposal['end_ms'])];
        }

        // Deux passages peuvent converger vers les mêmes phrases une fois recalés : nouvelle déduplication.
        return $this->deduplicator->dedupe($result);
    }

    /**
     * Frontières candidates : début / fin de chaque segment, avec un coût (0 pour une phrase, pénalité sinon).
     *
     * @param  list<array{start_ms: int, end_ms: int, text: string}>  $segments
     * @return array{0: list<array{0: int, 1: int}>, 1: list<array{0: int, 1: int}>}
     */
    private function boundaries(array $segments): array
    {
        $starts = [];
        $ends = [];
        $previousEndsSentence = true;
        foreach ($segments as $segment) {
            $endsSentence = (bool) preg_match('/[.!?…]["»”)\]]*\s*$/u', trim($segment['text']));
            $starts[] = [(int) $segment['start_ms'], $previousEndsSentence ? 0 : self::WEAK_PENALTY_MS];
            $ends[] = [(int) $segment['end_ms'], $endsSentence ? 0 : self::WEAK_PENALTY_MS];
            $previousEndsSentence = $endsSentence;
        }

        return [$starts, $ends];
    }

    /**
     * Frontière la plus proche (distance + pénalité) dans la tolérance, null sinon.
     *
     * @param  list<array{0: int, 1: int}>  $candidates
     * @param  callable(int): bool  $allowed
     */
    private function snap(int $at, array $candidates, callable $allowed): ?int
    {
        $best = null;
        $bestCost = PHP_INT_MAX;
        foreach ($candidates as [$position, $penalty]) {
            $distance = abs($position - $at);
            if ($distance > self::TOLERANCE_MS || ! $allowed($position)) {
                continue;
            }
            if ($distance + $penalty < $bestCost) {
                $best = $position;
                $bestCost = $distance + $penalty;
            }
        }

        return $best;
    }

    /** @param list<array{0: int, 1: int}> $ends dernière fin de segment qui tient dans le plafond */
    private function lastEndBefore(int $limit, int $start, array $ends): ?int
    {
        $best = null;
        foreach ($ends as [$position]) {
            if ($position <= $limit && $position >= $start + ClipLimits::minClipMs() && $position > ($best ?? -1)) {
                $best = $position;
            }
        }

        return $best;
    }

    /**
     * @param  list<array{start_ms: int, end_ms: int, words?: list<array{0: int, 1: int, 2: string}>}>  $segments
     * @return list<array{0: int, 1: int}>
     */
    private function words(array $segments): array
    {
        $words = [];
        foreach ($segments as $segment) {
            foreach ((array) ($segment['words'] ?? []) as $word) {
                if (is_array($word) && is_int($word[0] ?? null) && is_int($word[1] ?? null) && $word[1] >= $word[0]) {
                    $words[] = [$word[0], $word[1]];
                }
            }
        }

        return $words;
    }

    /** @param list<array{0: int, 1: int}> $words début du mot contenant $at (jamais au milieu d'un mot) */
    private function wordStart(int $at, array $words): int
    {
        foreach ($words as [$from, $to]) {
            if ($at >= $from && $at < $to) {
                return $from;
            }
        }

        return $at;
    }

    /** @param list<array{0: int, 1: int}> $words fin d'un mot, dans le plafond */
    private function wordEnd(int $at, int $start, int $limit, array $words): int
    {
        $best = $at;
        foreach ($words as [$from, $to]) {
            if ($at > $from && $at < $to) {
                return $to <= $limit ? $to : max($start, $from);
            }
        }

        return $best;
    }
}
