<?php

namespace App\Domain\Media\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/** Analyse antivirus obligatoire mais aucun moteur disponible : import refusé (échec fermé), 503. */
final class MediaScanUnavailable extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('L\'import de fichiers est temporairement indisponible (analyse de sécurité hors service).');
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'errors' => (object) []], 503);
    }
}
