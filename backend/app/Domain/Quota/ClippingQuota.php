<?php

namespace App\Domain\Quota;

use App\Domain\Video\Enums\ClipStatus;
use App\Domain\Video\Enums\VideoProjectKind;
use App\Domain\Video\Enums\VideoProjectStatus;
use App\Models\User;
use App\Models\VideoProject;
use Carbon\Carbon;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

/**
 * Quotas de clipping par utilisateur (ADR-071), vérifiés AVANT toute création ou relance de traitement coûteux
 * (yt-dlp, FFmpeg, whisper, IA, Remotion) :
 * - projets actifs simultanés : déduits de l'état réel (aucun compteur à tenir à jour) — un échec, une
 *   annulation ou la fin d'un traitement libère la place aussitôt ; un projet resté bloqué (worker tué) la libère
 *   après clipping.stale_after_minutes sans activité ;
 * - créations sur 24 heures glissantes, projets supprimés compris.
 * Vérification et action sous verrou par utilisateur : deux requêtes simultanées ne dépassent pas la limite.
 */
class ClippingQuota
{
    /** Statuts d'un projet en cours de traitement. */
    public const ACTIVE_STATUSES = [
        VideoProjectStatus::Pending, VideoProjectStatus::Importing, VideoProjectStatus::Analyzing,
        VideoProjectStatus::Transcribing, VideoProjectStatus::Detecting, VideoProjectStatus::Rendering,
    ];

    public function maxActive(): int
    {
        return max(0, (int) config('quotas.clipping.max_active', 5));
    }

    public function maxPerDay(): int
    {
        return max(0, (int) config('quotas.clipping.max_per_day', 20));
    }

    public function activeCount(string $userId): int
    {
        return $this->active($userId)->count();
    }

    public function isActive(VideoProject $project): bool
    {
        return $this->active((string) $project->user_id)->whereKey($project->getKey())->exists();
    }

    public function createdLastDay(string $userId): int
    {
        return VideoProject::query()->withTrashed()->where('user_id', $userId)
            ->where('kind', VideoProjectKind::Clipping->value)
            ->where('created_at', '>', now()->subDay())->count();
    }

    /**
     * Création d'un projet : quotas vérifiés, puis $create exécuté sous le même verrou (le nouveau projet est
     * compté pour la requête suivante).
     *
     * @template T
     *
     * @param  callable(): T  $create
     * @return T
     *
     * @throws QuotaExceeded
     */
    public function forNewProject(User $user, callable $create): mixed
    {
        return $this->locked((string) $user->getKey(), function () use ($user, $create) {
            $userId = (string) $user->getKey();
            if ($this->maxPerDay() > 0 && $this->createdLastDay($userId) >= $this->maxPerDay()) {
                throw QuotaExceeded::dailyProjects($this->maxPerDay(), $this->secondsUntilDailySlot($userId));
            }
            $this->assertActiveSlot($userId);

            return $create();
        });
    }

    /**
     * Relance d'un traitement sur un projet existant (analyse, acquisition, rendu de clip) : un projet déjà actif
     * ne consomme pas de place supplémentaire ; sinon il faut une place libre.
     *
     * @template T
     *
     * @param  callable(): T  $start
     * @return T
     *
     * @throws QuotaExceeded
     */
    public function forProjectWork(VideoProject $project, callable $start): mixed
    {
        return $this->locked((string) $project->user_id, function () use ($project, $start) {
            if (! $this->isActive($project)) {
                $this->assertActiveSlot((string) $project->user_id);
            }

            return $start();
        });
    }

    private function assertActiveSlot(string $userId): void
    {
        if ($this->maxActive() > 0 && $this->activeCount($userId) >= $this->maxActive()) {
            throw QuotaExceeded::activeProjects($this->maxActive());
        }
    }

    /** @return Builder<VideoProject> */
    private function active(string $userId): Builder
    {
        $fresh = now()->subMinutes(max(1, (int) config('quotas.clipping.stale_after_minutes', 240)));

        return VideoProject::query()->where('user_id', $userId)->where('kind', VideoProjectKind::Clipping->value)
            ->where(fn (Builder $q) => $q
                ->where(fn (Builder $p) => $p->whereIn('status', array_map(fn ($s) => $s->value, self::ACTIVE_STATUSES))->where('updated_at', '>', $fresh))
                ->orWhereHas('clips', fn (Builder $c) => $c->where('status', ClipStatus::Rendering->value)->where('updated_at', '>', $fresh)));
    }

    private function secondsUntilDailySlot(string $userId): int
    {
        $oldest = VideoProject::query()->withTrashed()->where('user_id', $userId)->where('kind', VideoProjectKind::Clipping->value)
            ->where('created_at', '>', now()->subDay())->min('created_at');

        return $oldest !== null ? max(60, (int) now()->diffInSeconds(Carbon::parse($oldest)->addDay())) : 60;
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
            return Cache::lock('quota-lock:clipping:'.$userId, 60)->block(20, $callback);
        } catch (LockTimeoutException) {
            throw new QuotaExceeded('CLIPPING_QUOTA_BUSY', 'Une autre demande de clipping est en cours de vérification. Réessayez dans un instant.', 429, 5);
        }
    }
}
