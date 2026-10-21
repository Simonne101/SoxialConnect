<?php

namespace App\Domain\Quota;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/** Espace disque du serveur insuffisant pour lancer l'opération (507, ADR-071) : rien n'est lancé. */
final class DiskSpaceInsufficient extends RuntimeException
{
    public const CODE = 'DISK_SPACE_LOW';

    public function __construct(public readonly string $operation)
    {
        parent::__construct('Espace disque insuffisant sur le serveur pour lancer cette opération. Réessayez plus tard.');
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'errors' => (object) [], 'code' => self::CODE], 507, ['Retry-After' => '900']);
    }
}
