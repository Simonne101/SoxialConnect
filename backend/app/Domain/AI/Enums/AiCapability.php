<?php

namespace App\Domain\AI\Enums;

/** Capacités d'un fournisseur IA (ADR-048) : vérifiées avant tout appel, jamais supposées. */
enum AiCapability: string
{
    case Text = 'text';
    case Transcription = 'transcription';
    case Image = 'image';
    /** Analyse d'une vidéo publique à partir de son URL (sans téléchargement). */
    case VideoUrl = 'video_url';
}
