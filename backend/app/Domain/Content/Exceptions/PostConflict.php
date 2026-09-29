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
        return new self('Seule une publication programmée peut être annulée.');
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
