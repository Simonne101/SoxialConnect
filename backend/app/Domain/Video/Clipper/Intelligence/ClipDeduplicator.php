<?php

namespace App\Domain\Video\Clipper\Intelligence;

/**
 * Déduplication des passages (ADR-051) : deux passages dont le chevauchement dépasse une part du plus
 * court (video.clipper.dedupe_overlap, 0.5 par défaut) sont pratiquement identiques ; seul le mieux
 * noté est conservé. Résultat trié par score décroissant, éventuellement limité.
 */
final class ClipDeduplicator
{
    private readonly float $threshold;

    public function __construct(?float $threshold = null)
    {
        $threshold ??= (float) config('video.clipper.dedupe_overlap', 0.5);
        $this->threshold = max(0.0, min(1.0, $threshold));
    }

    /**
     * @template T of array{start_ms: int, end_ms: int, score?: int|float|null}
     *
     * @param  list<T>  $candidates
     * @return list<T>
     */
    public function dedupe(array $candidates, ?int $limit = null): array
    {
        usort($candidates, fn ($a, $b) => ((float) ($b['score'] ?? 0)) <=> ((float) ($a['score'] ?? 0)));
        $kept = [];
        foreach ($candidates as $candidate) {
            foreach ($kept as $other) {
                if ($this->overlapRatio($candidate, $other) > $this->threshold) {
                    continue 2;
                }
            }
            $kept[] = $candidate;
            if ($limit !== null && count($kept) >= $limit) {
                break;
            }
        }

        return $kept;
    }

    /**
     * Chevauchement rapporté à la durée du plus court des deux passages (0 : disjoints, 1 : l'un contient l'autre).
     *
     * @param  array{start_ms: int, end_ms: int}  $a
     * @param  array{start_ms: int, end_ms: int}  $b
     */
    public function overlapRatio(array $a, array $b): float
    {
        $overlap = min($a['end_ms'], $b['end_ms']) - max($a['start_ms'], $b['start_ms']);
        $shorter = min($a['end_ms'] - $a['start_ms'], $b['end_ms'] - $b['start_ms']);

        return $overlap > 0 && $shorter > 0 ? $overlap / $shorter : 0.0;
    }
}
