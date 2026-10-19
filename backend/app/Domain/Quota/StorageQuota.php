<?php

namespace App\Domain\Quota;

use App\Models\Media;
use App\Models\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Quota de stockage par utilisateur (ADR-071).
 *
 * Occupation = originaux + dérivés de TOUS les médias de l'utilisateur (quarantaine, traitement, générés,
 * supprimés logiquement tant que la purge n'a pas libéré les fichiers) + réservations en cours.
 * Vérification et réservation sous verrou par utilisateur : deux imports simultanés ne peuvent pas dépasser le
 * quota à eux deux. Une réservation est libérée dès que le fichier est enregistré (il est alors compté en base)
 * ou en cas d'échec ; une réservation oubliée (worker tué) expire d'elle-même.
 */
class StorageQuota
{
    private const RESERVATIONS = 'quota:storage:reservations:';

    public function limitBytes(): int
    {
        return max(0, (int) config('quotas.storage.max_mb', 10240)) * 1024 * 1024;
    }

    public function enabled(): bool
    {
        return $this->limitBytes() > 0;
    }

    /** Octets occupés en base (originaux + dérivés), suppressions logiques comprises. */
    public function storedBytes(string $userId): int
    {
        $query = Media::query()->withTrashed()->where('user_id', $userId);
        $originals = (int) (clone $query)->sum('size_bytes');
        $derivatives = (clone $query)->whereNotNull('derivatives')->pluck('derivatives')
            ->sum(fn ($items) => collect((array) $items)->sum(fn ($item) => is_array($item) ? (int) ($item['size_bytes'] ?? 0) : 0));

        return $originals + (int) $derivatives;
    }

    public function reservedBytes(string $userId): int
    {
        return array_sum(array_column($this->reservations($userId), 'bytes'));
    }

    public function usedBytes(string $userId): int
    {
        return $this->storedBytes($userId) + $this->reservedBytes($userId);
    }

    public function remainingBytes(string $userId): int
    {
        return $this->enabled() ? max(0, $this->limitBytes() - $this->usedBytes($userId)) : PHP_INT_MAX;
    }

    /**
     * Réserve $bytes pour l'utilisateur ; QuotaExceeded si le total dépasserait le quota.
     *
     * @return string identifiant de réservation (à libérer)
     *
     * @throws QuotaExceeded
     */
    public function reserve(User|string $user, int $bytes, string $purpose): string
    {
        $userId = $user instanceof User ? (string) $user->getKey() : $user;
        $id = (string) Str::uuid();
        if (! $this->enabled()) {
            return $id;
        }

        return $this->locked($userId, function () use ($userId, $bytes, $purpose, $id) {
            $used = $this->usedBytes($userId);
            if ($used + max(0, $bytes) > $this->limitBytes()) {
                throw QuotaExceeded::storage(max(0, $this->limitBytes() - $used), $this->limitBytes());
            }
            $reservations = $this->reservations($userId);
            $reservations[$id] = ['bytes' => max(0, $bytes), 'purpose' => $purpose, 'expires' => now()->addMinutes($this->ttl())->getTimestamp()];
            Cache::put(self::RESERVATIONS.$userId, $reservations, now()->addMinutes($this->ttl()));

            return $id;
        });
    }

    /** Libère une réservation (sans effet si elle n'existe plus). */
    public function release(User|string $user, ?string $reservationId): void
    {
        $userId = $user instanceof User ? (string) $user->getKey() : $user;
        if ($reservationId === null || ! $this->enabled()) {
            return;
        }
        try {
            $this->locked($userId, function () use ($userId, $reservationId) {
                $reservations = $this->reservations($userId);
                unset($reservations[$reservationId]);
                $reservations === []
                    ? Cache::forget(self::RESERVATIONS.$userId)
                    : Cache::put(self::RESERVATIONS.$userId, $reservations, now()->addMinutes($this->ttl()));
            });
        } catch (QuotaExceeded) {
            // Appelée dans un « finally » : ne masque jamais l'erreur d'origine ; la réservation expirera.
        }
    }

    /** @return array<string, array{bytes: int, purpose: string, expires: int}> réservations non expirées */
    private function reservations(string $userId): array
    {
        $now = now()->getTimestamp();

        return array_filter((array) Cache::get(self::RESERVATIONS.$userId, []), fn ($r) => is_array($r) && ($r['expires'] ?? 0) > $now);
    }

    private function ttl(): int
    {
        return max(5, (int) config('quotas.storage.reservation_ttl_minutes', 240));
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function locked(string $userId, callable $callback): mixed
    {
        try {
            return Cache::lock('quota-lock:storage:'.$userId, 30)->block(15, $callback);
        } catch (LockTimeoutException) {
            // Verrou indisponible : refus prudent plutôt qu'un dépassement possible.
            throw new QuotaExceeded('STORAGE_QUOTA_BUSY', 'Vérification de votre espace de stockage en cours. Réessayez dans un instant.', 429, 5);
        }
    }
}
