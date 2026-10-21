<?php

namespace App\Domain\Video\Clipper\Transcription;

use App\Domain\AI\Data\AiTranscriptionResult;
use App\Domain\Video\Rendering\RenderWorkspace;

/**
 * Transcription horodatée d'une piste audio (ADR-047), interchangeable : whisper.cpp local (aucune
 * API) ou fournisseur IA configuré. Aucune n'est obligatoire pour le Clipper.
 */
interface Transcriber
{
    /** « local » ou « ai » : enregistré dans l'analyse du projet et affiché à l'utilisateur. */
    public function mode(): string;

    /** Nom du moteur (ex. « whisper.cpp », « openai ») et modèle utilisé. */
    public function engine(): string;

    public function model(): string;

    /** Format audio attendu par le moteur. */
    public function audioFormat(): string;

    /** Durée maximale transcrite par ce moteur (ms) ; au-delà, l'analyse se fait sans transcription. */
    public function maxDurationMs(): int;

    /** @throws TranscriptionFailure */
    public function transcribe(string $audioPath, RenderWorkspace $workspace): AiTranscriptionResult;
}
