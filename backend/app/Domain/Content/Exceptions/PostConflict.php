<?php

namespace App\Domain\Content\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/** Conflit métier sur une publication : non modifiable, ou modifiée entre-temps (verrou optimiste). */
final class PostConflict extends RuntimeException
{
    public static function notEditable(): self
    {
        return new self('Cette publication ne peut plus être modifiée.');
    }

    public static function staleVersion(): self
    {
        return new self('Cette publication a été modifiée entre-temps. Rechargez-la avant d\'enregistrer.');
    }

    public static function notCancellable(): self
    {
        return new self('Seule une publication programmée ou en file d\'attente peut être annulée.');
    }

    public static function publishingInProgress(): self
    {
        return new self('Une publication est en cours d\'envoi : elle ne peut plus être annulée. Attendez son résultat.');
    }

    public static function notPublishable(): self
    {
        return new self('Cette publication ne peut pas être publiée maintenant.');
    }

    public static function notRetryable(): self
    {
        return new self('Seule une publication en échec peut être relancée.');
    }

    public static function unknownOutcome(): self
    {
        return new self('Le résultat de la dernière tentative est inconnu : vérifiez sur le réseau que rien n\'a été publié, puis confirmez la relance.');
    }

    public static function notReschedulable(): self
    {
        return new self('Cette publication ne peut pas être reprogrammée.');
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'errors' => (object) []], 409);
    }
}
