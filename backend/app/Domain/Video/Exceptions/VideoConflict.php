<?php

namespace App\Domain\Video\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Action impossible dans l'état actuel du projet vidéo (409), même format que PostConflict.
 * Les erreurs de saisie (valeurs, références) restent des erreurs de validation (422).
 */
final class VideoConflict extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }

    public static function staleVersion(): self
    {
        return new self('STALE_VERSION', 'Ce projet a été modifié entre-temps (autre onglet ?). Rechargez-le puis réessayez.');
    }

    public static function notComposition(): self
    {
        return new self('NOT_COMPOSITION', 'Ce projet n\'est pas un projet Video Studio.');
    }

    public static function trackLocked(): self
    {
        return new self('TRACK_LOCKED', 'Cette piste est verrouillée : déverrouillez-la pour la modifier.');
    }

    public static function trackNotEmpty(): self
    {
        return new self('TRACK_NOT_EMPTY', 'Le type d\'une piste ne peut pas changer tant qu\'elle contient des éléments.');
    }

    public static function limitReached(string $what, int $max): self
    {
        return new self('LIMIT_REACHED', "Limite atteinte : {$max} {$what} au maximum par projet.");
    }

    public static function assetInUse(): self
    {
        return new self('ASSET_IN_USE', 'Ce média est utilisé sur la timeline : retirez-en d\'abord les éléments.');
    }

    public static function renderActive(): self
    {
        return new self('RENDER_ACTIVE', 'Un rendu de ce projet est déjà en cours.');
    }

    public static function emptyTimeline(): self
    {
        return new self('EMPTY_TIMELINE', 'La timeline est vide : ajoutez au moins un élément avant de lancer un rendu.');
    }

    public static function unavailableMedia(): self
    {
        return new self('MEDIA_UNAVAILABLE', 'Un média de la timeline n\'est plus disponible : remplacez-le avant de lancer un rendu.');
    }

    public static function notCancellable(): self
    {
        return new self('NOT_CANCELLABLE', 'Ce rendu est déjà terminé.');
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'errors' => (object) [], 'code' => $this->errorCode], 409);
    }
}
