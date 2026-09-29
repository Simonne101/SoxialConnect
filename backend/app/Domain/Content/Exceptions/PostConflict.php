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

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'errors' => (object) []], 409);
    }
}
