<?php

namespace App\Domain\Video\Clipper\Analysis;

/**
 * Choix des meilleurs passages (ADR-047), interchangeable : IA (fournisseur configuré) ou analyse
 * locale (heuristique, sans IA). Les propositions sont TOUJOURS revalidées (≤ 60 s, dans la vidéo).
 */
interface ClipAnalyzer
{
    /** « ai » ou « local ». */
    public function mode(): string;

    /** Vrai si l'analyse a besoin d'une transcription. */
    public function requiresTranscript(): bool;

    /**
     * @param  list<array{start_ms: int, end_ms: int, text: string}>  $segments  transcription (peut être vide)
     * @param  list<array{0: int, 1: int}>  $silences  pauses détectées (ms)
     * @return list<array{start_ms: int, end_ms: int, title: string, topic: string, reason: string, score: int}>
     */
    public function analyze(array $segments, array $silences, int $durationMs, string $title, string $projectUserId, string $projectId): array;
}
