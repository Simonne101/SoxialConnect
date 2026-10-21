<?php

namespace App\Domain\Quota;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Quota d'un utilisateur atteint (ADR-071) : rien n'est lancé ni stocké. 413 pour le stockage (le fichier ne
 * tient pas dans l'espace restant), 429 pour les projets de clipping (actifs ou sur 24 h, avec Retry-After).
 */
final class QuotaExceeded extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status,
        public readonly ?int $retryAfterSeconds = null,
    ) {
        parent::__construct($message);
    }

    public static function storage(int $remainingBytes, int $limitBytes): self
    {
        return new self('STORAGE_QUOTA_EXCEEDED', sprintf(
            'Espace de stockage insuffisant : il vous reste %s sur %s. Supprimez des médias (l\'espace est libéré après la purge des éléments supprimés) puis réessayez.',
            self::size($remainingBytes), self::size($limitBytes),
        ), 413);
    }

    public static function activeProjects(int $max): self
    {
        return new self('CLIPPING_ACTIVE_LIMIT', "Vous avez déjà {$max} projets de clipping en cours de traitement. Attendez la fin de l'un d'eux (ou annulez-en un) puis réessayez.", 429, 60);
    }

    public static function dailyProjects(int $max, int $retryAfterSeconds): self
    {
        return new self('CLIPPING_DAILY_LIMIT', "Limite de {$max} nouveaux projets de clipping sur 24 heures atteinte. Réessayez plus tard.", 429, max(60, $retryAfterSeconds));
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'errors' => (object) [], 'code' => $this->errorCode], $this->status,
            $this->retryAfterSeconds !== null ? ['Retry-After' => (string) $this->retryAfterSeconds] : []);
    }

    private static function size(int $bytes): string
    {
        return $bytes >= 1024 ** 3 ? round($bytes / 1024 ** 3, 1).' Go' : max(0, (int) round($bytes / 1024 ** 2)).' Mo';
    }
}
