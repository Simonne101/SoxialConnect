<?php

namespace App\Domain\Video\Clipper\Transcription;

/**
 * Choix du moteur de transcription (video.clipper.transcription) :
 * auto = whisper.cpp local s'il est installé, sinon fournisseur IA s'il est configuré, sinon aucun ;
 * local | ai = ce moteur seulement (null s'il n'est pas disponible) ; none = aucune transcription.
 */
class TranscriberResolver
{
    public function __construct(
        private readonly WhisperCppTranscriber $local,
        private readonly AiTranscriber $ai,
    ) {}

    public function resolve(): ?Transcriber
    {
        $local = $this->local->isInstalled() ? $this->local : null;
        $ai = $this->ai->isConfigured() ? $this->ai : null;

        return match ((string) config('video.clipper.transcription', 'auto')) {
            'local' => $local,
            'ai' => $ai,
            'none' => null,
            default => $local ?? $ai,
        };
    }
}
