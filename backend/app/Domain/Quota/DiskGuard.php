<?php

namespace App\Domain\Quota;

use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Garde d'espace disque global (ADR-071) : avant un import, une acquisition, une extraction d'archive, une
 * analyse ou un rendu, réserve la taille prévue. L'opération n'est lancée que s'il reste, après elle ET les
 * autres opérations en cours, au moins disk.min_free_mb, avec une marge (margin_percent) sur sa taille.
 * Vérification et réservation sous un verrou global : deux opérations simultanées ne peuvent pas consommer à
 * elles deux l'espace qu'une seule a vérifié. Une réservation oubliée (worker tué) expire d'elle-même.
 */
class DiskGuard
{
    private const RESERVATIONS = 'quota:disk:reservations';

    private const TTL_MINUTES = 240;

    /** @param (Closure(string): (float|false))|null $freeSpace mesure de l'espace libre (remplaçable en test) */
    public function __construct(private readonly ?Closure $freeSpace = null) {}

    public function enabled(): bool
    {
        return (bool) config('quotas.disk.enabled', true);
    }

    /**
     * @return string identifiant de réservation (à libérer)
     *
     * @throws DiskSpaceInsufficient
     */
    public function reserve(int $bytes, string $operation, ?string $path = null): string
    {
        $id = (string) Str::uuid();
        if (! $this->enabled()) {
            return $id;
        }
        $path ??= storage_path('app');

        try {
            return Cache::lock('quota-lock:disk', 30)->block(15, function () use ($bytes, $operation, $path, $id) {
                $free = $this->free($path);
                if ($free === null) {
                    // Espace non mesurable (système de fichiers particulier) : opération autorisée, signalée.
                    Log::warning('disk_guard.unmeasurable', ['operation' => $operation]);

                    return $id;
                }
                $reservations = $this->reservations();
                $needed = (int) ceil(max(0, $bytes) * (1 + max(0, (int) config('quotas.disk.margin_percent', 20)) / 100));
                $minFree = max(0, (int) config('quotas.disk.min_free_mb', 2048)) * 1024 * 1024;
                if ($free - array_sum(array_column($reservations, 'bytes')) - $needed < $minFree) {
                    Log::warning('disk_guard.refused', ['operation' => $operation, 'needed_mb' => intdiv($needed, 1048576), 'free_mb' => intdiv((int) $free, 1048576)]);
                    throw new DiskSpaceInsufficient($operation);
                }
                $reservations[$id] = ['bytes' => $needed, 'operation' => $operation, 'expires' => now()->addMinutes(self::TTL_MINUTES)->getTimestamp()];
                Cache::put(self::RESERVATIONS, $reservations, now()->addMinutes(self::TTL_MINUTES));

                return $id;
            });
        } catch (LockTimeoutException) {
            throw new DiskSpaceInsufficient($operation); // refus prudent
        }
    }

    public function release(?string $reservationId): void
    {
        if ($reservationId === null || ! $this->enabled()) {
            return;
        }
        try {
            Cache::lock('quota-lock:disk', 30)->block(15, function () use ($reservationId) {
                $reservations = $this->reservations();
                unset($reservations[$reservationId]);
                $reservations === [] ? Cache::forget(self::RESERVATIONS) : Cache::put(self::RESERVATIONS, $reservations, now()->addMinutes(self::TTL_MINUTES));
            });
        } catch (LockTimeoutException) {
            // Appelée dans un « finally » : ne masque jamais l'erreur d'origine ; la réservation expirera.
        }
    }

    public function reservedBytes(): int
    {
        return array_sum(array_column($this->reservations(), 'bytes'));
    }

    /** @return array<string, array{bytes: int, operation: string, expires: int}> */
    private function reservations(): array
    {
        $now = now()->getTimestamp();

        return array_filter((array) Cache::get(self::RESERVATIONS, []), fn ($r) => is_array($r) && ($r['expires'] ?? 0) > $now);
    }

    private function free(string $path): ?float
    {
        // Dossier pas encore créé : premier parent existant (même volume).
        while (! is_dir($path) && dirname($path) !== $path) {
            $path = dirname($path);
        }
        $free = $this->freeSpace !== null ? ($this->freeSpace)($path) : @disk_free_space($path);

        return $free === false ? null : (float) $free;
    }
}
