<?php

namespace App\Domain\Video\Rendering;

/** Commande FFmpeg prête à exécuter dans le dossier de travail du rendu. */
final readonly class BuiltCommand
{
    /**
     * @param  list<string>  $arguments  arguments FFmpeg (sans le binaire), jamais une chaîne shell
     * @param  array<string, string>  $files  fichiers annexes à écrire dans le dossier de travail (nom relatif → contenu)
     */
    public function __construct(
        public array $arguments,
        public array $files,
        public bool $hasAudio,
        public bool $needsFont,
        public int $durationMs,
    ) {}
}
