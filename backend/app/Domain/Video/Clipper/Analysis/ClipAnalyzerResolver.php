<?php

namespace App\Domain\Video\Clipper\Analysis;

/**
 * Ordre d'essai des analyses (video.clipper.analysis) :
 * auto = IA si configurée, puis analyse locale en repli (IA en échec, quota, sans transcription) ;
 * ai = IA seulement ; local = analyse locale seulement ; none = aucune (sélection manuelle).
 */
class ClipAnalyzerResolver
{
    public function __construct(
        private readonly AiClipAnalyzer $ai,
        private readonly LocalClipAnalyzer $local,
    ) {}

    /** @return list<ClipAnalyzer> */
    public function chain(): array
    {
        $ai = $this->ai->isConfigured() ? [$this->ai] : [];

        return match ((string) config('video.clipper.analysis', 'auto')) {
            'ai' => $ai,
            'local' => [$this->local],
            'none' => [],
            default => [...$ai, $this->local],
        };
    }

    /**
     * Analyse depuis l'URL YouTube, sans fichier (ADR-048) : seulement en mode auto ou ai, et si le
     * fournisseur de l'analyse a la capacité VideoUrl (Gemini). Null sinon.
     */
    public function urlAnalyzer(): ?AiClipAnalyzer
    {
        return in_array((string) config('video.clipper.analysis', 'auto'), ['auto', 'ai'], true) && $this->ai->supportsUrl() ? $this->ai : null;
    }

    /** Mode d'analyse qui sera tenté en premier : « ai », « local » ou « none ». */
    public function primaryMode(): string
    {
        return ($this->chain()[0] ?? null)?->mode() ?? 'none';
    }
}
