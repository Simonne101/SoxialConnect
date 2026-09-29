<?php

namespace App\Domain\Media\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/** Conflit métier sur la médiathèque (409) : média utilisé, dossier invalide… */
final class MediaConflict extends RuntimeException
{
    public static function inUse(): self
    {
        return new self('Ce média est utilisé par une publication programmée ou en cours : il ne peut pas être supprimé.');
    }

    public static function folderCycle(): self
    {
        return new self('Un dossier ne peut pas être déplacé dans l\'un de ses sous-dossiers.');
    }

    public static function folderTooDeep(int $max): self
    {
        return new self("Profondeur maximale atteinte ({$max} niveaux de dossiers).");
    }

    public static function tooManyTags(int $max): self
    {
        return new self("Un média peut avoir au maximum {$max} tags.");
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'errors' => (object) []], 409);
    }
}
