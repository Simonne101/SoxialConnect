<?php

namespace App\Domain\Video\Clipper;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/** Refus métier du YouTube Clipper (code stable pour le frontend, statut HTTP adapté). */
final class ClipperConflict extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message, public readonly int $status = 409)
    {
        parent::__construct($message);
    }

    public static function invalidUrl(): self
    {
        return new self('INVALID_URL', 'Ce lien n\'est pas un lien de vidéo YouTube valide.', 422);
    }

    public static function videoUnavailable(): self
    {
        return new self('VIDEO_UNAVAILABLE', 'Cette vidéo YouTube est introuvable, privée ou non intégrable.', 422);
    }

    public static function youtubeUnreachable(): self
    {
        return new self('YOUTUBE_UNREACHABLE', 'YouTube ne répond pas pour le moment. Réessayez dans un instant.', 503);
    }

    public static function invalidSource(): self
    {
        return new self('SOURCE_INVALID', 'Choisissez une vidéo de votre médiathèque, prête à être utilisée.', 422);
    }

    public static function invalidRange(string $message): self
    {
        return new self('INVALID_DURATION', $message, 422);
    }

    public static function sourceRequired(): self
    {
        return new self('SOURCE_REQUIRED', 'Les passages sont analysés, mais la génération des MP4 nécessite une source vidéo autorisée, pas encore disponible pour ce projet.', 409);
    }

    public static function cancelled(): self
    {
        return new self('PROJECT_CANCELLED', 'Le traitement de cette vidéo a été annulé. Relancez l\'analyse pour reprendre.');
    }

    public static function nothingToCancel(): self
    {
        return new self('NOTHING_TO_CANCEL', 'Aucun traitement en cours pour cette vidéo.');
    }

    public static function sourceAlreadyReady(): self
    {
        return new self('SOURCE_ALREADY_READY', 'La source vidéo de ce projet est déjà disponible.');
    }

    public static function sourceTooLarge(): self
    {
        return new self('SOURCE_TOO_LARGE', 'Cette vidéo dépasse la taille maximale acceptée pour le Clipper.', 422);
    }

    public static function notReady(): self
    {
        return new self('NOT_READY', 'L\'analyse de la vidéo source n\'est pas terminée.');
    }

    public static function analysisRunning(): self
    {
        return new self('ANALYSIS_RUNNING', 'L\'analyse de cette vidéo est en cours.');
    }

    public static function notRenderable(): self
    {
        return new self('NOT_RENDERABLE', 'Ce clip est déjà en cours de génération ou terminé.');
    }

    public static function subtitlesUnavailable(): self
    {
        return new self('SUBTITLES_UNAVAILABLE', 'Les sous-titres nécessitent une transcription de la vidéo, indisponible pour ce projet.', 422);
    }

    public static function aiNotConfigured(): self
    {
        return new self('AI_NOT_CONFIGURED', 'L\'analyse par IA n\'est pas configurée sur ce serveur (fournisseur ou modèles manquants).', 503);
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'errors' => (object) [], 'code' => $this->errorCode], $this->status);
    }
}
