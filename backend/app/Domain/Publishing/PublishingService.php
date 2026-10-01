<?php

namespace App\Domain\Publishing;

use App\Domain\Content\Enums\PostStatus;
use App\Domain\Content\Enums\VariantStatus;
use App\Domain\Content\Exceptions\PostConflict;
use App\Domain\Content\Support\PostLock;
use App\Domain\Publishing\Contracts\TracksPublicationStatus;
use App\Domain\Publishing\Data\PublishRequest;
use App\Domain\Publishing\Data\PublishResult;
use App\Domain\Publishing\Enums\AttemptStatus;
use App\Domain\Publishing\Enums\AttemptTrigger;
use App\Domain\Publishing\Enums\PublishOutcome;
use App\Domain\Publishing\Support\VariantStateMachine;
use App\Jobs\PublishPostVariantJob;
use App\Jobs\ReconcilePublicationJob;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\PostVariant;
use App\Models\PublicationAttempt;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Audit\AuditAction;
use App\Services\Audit\AuditLogger;
use App\Support\Logging\SecretRedactor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Moteur de publication interne (docs/publishing.md). Point d'entrée unique, réutilisé par
 * l'API (publier, relancer), le job de publication, le planificateur et une future commande.
 *
 * Garanties :
 * - l'unité de publication est la variante (un compte social) ; le statut du post est agrégé ;
 * - verrou d'écriture toujours pris dans le même ordre (post puis variante) : pas d'interblocage ;
 * - transitions conditionnelles (VariantStateMachine) + compteur de tentatives servant de jeton :
 *   un job dupliqué, périmé ou concurrent ne s'exécute jamais ;
 * - une tentative = une ligne publication_attempts, jamais écrasée ;
 * - l'adaptateur est appelé HORS transaction (aucun verrou tenu pendant un appel réseau) ;
 * - résultat inconnu (exception, worker tué) : échec explicite, jamais de relance aveugle ;
 * - « published » uniquement sur succès d'un adaptateur réel avec identifiant externe.
 */
final class PublishingService
{
    public const UNKNOWN_CODE = 'PUBLISH_OUTCOME_UNKNOWN';

    public const UNKNOWN_MESSAGE = 'Le résultat de l\'envoi est inconnu (interruption pendant la publication). Vérifiez sur le réseau avant de relancer.';

    private const POST_RELATIONS = ['variants.socialAccount.platform', 'media'];

    public function __construct(
        private readonly PublisherRegistry $registry,
        private readonly AuditLogger $audit,
        private readonly SecretRedactor $redactor,
    ) {}

    // --- Demandes de l'utilisateur ------------------------------------------------------------

    /** « Publier maintenant » : brouillon, programmée ou annulée → toutes les variantes en file. */
    public function publishNow(User $user, Post $post, ?int $expectedVersion = null): Post
    {
        $post = DB::transaction(function () use ($user, $post, $expectedVersion) {
            $locked = PostLock::acquire($post, $expectedVersion);
            if (! in_array($locked->status, [PostStatus::Draft, PostStatus::Scheduled, PostStatus::Cancelled], true)) {
                throw PostConflict::notPublishable();
            }

            $variants = $locked->variants()->with('socialAccount')->lockForUpdate()->get();
            $this->assertReady($locked, $variants);
            if ($variants->contains(fn (PostVariant $v) => ! VariantStateMachine::allows($v->status, VariantStatus::Queued))) {
                throw PostConflict::notPublishable();
            }

            $now = now();
            foreach ($variants as $variant) {
                $this->enqueue($variant, AttemptTrigger::Manual, $user->getKey(), ['scheduled_at' => $now]);
            }
            $locked->scheduled_at = $now;
            $this->refreshPostStatus($locked);

            return $locked;
        });

        $this->audit->record(AuditAction::PublishRequested, $user, $post, ['variants' => $post->variants()->count()]);

        return $post->fresh(self::POST_RELATIONS);
    }

    /**
     * « Réessayer » : variantes en échec → en file, avec une NOUVELLE tentative (l'historique est
     * conservé). Si la dernière tentative a un résultat inconnu, la relance exige une confirmation
     * explicite (risque de doublon sur le réseau).
     */
    public function retry(User $user, Post $post, ?int $expectedVersion = null, bool $confirmUnknown = false): Post
    {
        $post = DB::transaction(function () use ($user, $post, $expectedVersion, $confirmUnknown) {
            $locked = PostLock::acquire($post, $expectedVersion);
            if (! in_array($locked->status, [PostStatus::Failed, PostStatus::PartiallyPublished], true)) {
                throw PostConflict::notRetryable();
            }

            $failed = $locked->variants()->where('status', VariantStatus::Failed->value)->with('latestAttempt')->lockForUpdate()->get();
            if ($failed->isEmpty()) {
                throw PostConflict::notRetryable();
            }
            if (! $confirmUnknown && $failed->contains(fn (PostVariant $v) => $v->latestAttempt?->status === AttemptStatus::Unknown)) {
                throw PostConflict::unknownOutcome();
            }

            foreach ($failed as $variant) {
                $this->enqueue($variant, AttemptTrigger::Retry, $user->getKey());
            }
            $this->refreshPostStatus($locked);

            return $locked;
        });

        $this->audit->record(AuditAction::PublishRetried, $user, $post);

        return $post->fresh(self::POST_RELATIONS);
    }

    // --- Planificateur ------------------------------------------------------------------------

    /**
     * Passe en file les variantes programmées arrivées à échéance. Les posts sont verrouillés
     * avec SKIP LOCKED : plusieurs planificateurs (ou un post en cours d'édition) ne se bloquent
     * pas et ne traitent jamais deux fois la même publication.
     *
     * @return int nombre de variantes mises en file
     */
    public function dispatchDue(?int $limit = null): int
    {
        $limit ??= (int) config('publishing.dispatch_batch_size', 50);

        return DB::transaction(function () use ($limit) {
            $now = now();
            $count = 0;
            $posts = Post::query()
                ->where('status', PostStatus::Scheduled->value)
                ->where('scheduled_at', '<=', $now)
                ->orderBy('scheduled_at')
                ->limit($limit)
                ->lock('for update skip locked')
                ->get();

            foreach ($posts as $post) {
                $due = $post->variants()
                    ->where('status', VariantStatus::Scheduled->value)
                    ->where(fn ($q) => $q->whereNull('scheduled_at')->orWhere('scheduled_at', '<=', $now))
                    ->lockForUpdate()
                    ->get();
                foreach ($due as $variant) {
                    $this->enqueue($variant, AttemptTrigger::Schedule, null);
                    $count++;
                }
                // Recalcul systématique : un post « programmé » sans variante due ne reste pas éligible indéfiniment.
                $this->refreshPostStatus($post);
            }

            return $count;
        });
    }

    /**
     * Reprise après incident, appelée par le planificateur :
     * - variante « en cours de publication » depuis trop longtemps (worker tué) → échec au
     *   résultat inconnu, sans relance automatique ;
     * - variante « en file » sans job depuis trop longtemps (file vidée) → job renvoyé. Un job
     *   d'origine encore présent ne s'exécutera pas deux fois (jeton attempts_count).
     * Les tentatives « en traitement suivi » (external_post_id renseigné) sont exclues : elles
     * relèvent de reconcile() et de sweepProcessing().
     *
     * @return array{abandoned: int, redispatched: int}
     */
    public function recoverStale(): array
    {
        $abandoned = 0;
        $publishingCutoff = now()->subMinutes((int) config('publishing.stale_publishing_after_minutes', 15));
        PostVariant::query()
            ->where('status', VariantStatus::Publishing->value)
            ->where('updated_at', '<', $publishingCutoff)
            // Contenu transmis et suivi (TracksPublicationStatus) : conclu par reconcile(), pas abandonné.
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('publication_attempts as pa')
                ->whereColumn('pa.post_variant_id', 'post_variants.id')
                ->whereColumn('pa.attempt_number', 'post_variants.attempts_count')
                ->where('pa.status', AttemptStatus::InProgress->value)
                ->whereNotNull('pa.external_post_id'))
            ->limit(100)
            ->get(['id', 'attempts_count'])
            ->each(function (PostVariant $v) use (&$abandoned) {
                $abandoned += (int) $this->abandon($v->id, $v->attempts_count - 1);
            });

        $redispatched = 0;
        $queuedCutoff = now()->subMinutes((int) config('publishing.stale_queued_after_minutes', 30));
        PostVariant::query()
            ->where('status', VariantStatus::Queued->value)
            ->where('queued_at', '<', $queuedCutoff)
            ->whereHas('post')
            ->limit(100)
            ->get(['id', 'attempts_count'])
            ->each(function (PostVariant $v) use (&$redispatched) {
                $touched = PostVariant::query()->whereKey($v->id)
                    ->where('status', VariantStatus::Queued->value)
                    ->where('attempts_count', $v->attempts_count)
                    ->update(['queued_at' => now()]);
                if ($touched) {
                    PublishPostVariantJob::dispatch($v->id, AttemptTrigger::Recovery->value, null, $v->attempts_count);
                    $redispatched++;
                }
            });

        return ['abandoned' => $abandoned, 'redispatched' => $redispatched];
    }

    /**
     * Filet de sécurité du suivi : toute tentative « en traitement » (in_progress + identifiant de
     * suivi) sans vérification récente reçoit un nouveau ReconcilePublicationJob (job perdu, file
     * vidée). Idempotent : la date de vérification est avancée avant l'envoi du job.
     */
    public function sweepProcessing(): int
    {
        $cutoff = now()->subMinutes((int) config('publishing.processing_sweep_after_minutes', 5));
        $delays = (array) config('publishing.reconcile_delays', [15, 30, 30, 60, 120]);
        $dispatched = 0;

        PublicationAttempt::query()
            ->where('status', AttemptStatus::InProgress->value)
            ->whereNotNull('external_post_id')
            ->where('updated_at', '<', $cutoff)
            ->limit(100)
            ->get(['id', 'post_variant_id', 'external_post_id'])
            ->each(function (PublicationAttempt $a) use ($cutoff, $delays, &$dispatched) {
                $touched = PublicationAttempt::query()->whereKey($a->id)
                    ->where('status', AttemptStatus::InProgress->value)
                    ->where('external_post_id', $a->external_post_id)
                    ->where('updated_at', '<', $cutoff)
                    ->update(['updated_at' => now()]);
                if ($touched) {
                    ReconcilePublicationJob::dispatch($a->id, $a->post_variant_id, $a->external_post_id, count($delays));
                    $dispatched++;
                }
            });

        return $dispatched;
    }

    /**
     * Vérifie UNE fois le statut d'une tentative en traitement (docs/publishing.md §4) :
     * - toujours en cours, ou vérification impossible pour l'instant → vérification suivante
     *   programmée, jusqu'au délai maximal (puis résultat inconnu) ;
     * - publié → published (identifiant définitif s'il existe, sinon l'identifiant de suivi) ;
     * - échec signalé par le réseau → failed ; statut inexploitable → inconnu.
     * Jamais de nouvel envoi : le contenu a déjà été entièrement transmis.
     */
    public function reconcile(string $attemptId, string $variantId, string $trackingId, int $check = 1): void
    {
        // Une seule vérification à la fois par tentative (jobs dupliqués, balayage concurrent).
        $lock = Cache::lock("publication-reconcile:{$attemptId}", 120);
        if (! $lock->get()) {
            return;
        }

        try {
            $attempt = PublicationAttempt::query()->find($attemptId);
            if (! $this->isTracked($attempt, $variantId, $trackingId)) {
                return; // déjà conclue, remplacée, ou jeton différent
            }

            $account = $attempt->social_account_id
                ? SocialAccount::withTrashed()->with('platform')->find($attempt->social_account_id)
                : null;
            $adapter = $account ? $this->registry->for($account->platform?->key) : null;

            if ($account === null || ! $adapter instanceof TracksPublicationStatus) {
                $result = PublishResult::unknown('STATUS_TRACKING_UNAVAILABLE', 'Le suivi de cette publication n\'est pas disponible. Vérifiez sur le réseau avant de relancer.');
            } else {
                try {
                    $result = $adapter->checkStatus($account, $trackingId);
                } catch (Throwable $e) {
                    Log::warning('publishing.status_check_exception', ['attempt_id' => $attemptId, 'exception' => $e::class]);
                    $result = null; // vérification impossible pour l'instant
                }
            }

            $pending = $result === null || in_array($result->outcome, [PublishOutcome::Processing, PublishOutcome::TemporaryFailure, PublishOutcome::NotConfigured], true);
            if ($pending) {
                $deadline = now()->subMinutes((int) config('publishing.processing_max_minutes', 120));
                if ($attempt->started_at !== null && $attempt->started_at->lte($deadline)) {
                    $this->complete($attempt, null, 1); // délai maximal dépassé → résultat inconnu
                } else {
                    $this->scheduleReconcile($attempt, $trackingId, $check + 1);
                }

                return;
            }

            if ($result->outcome === PublishOutcome::Success && $result->externalPostId === null) {
                $result = PublishResult::success($trackingId, $result->externalUrl, $result->metadata);
            }
            // Conclusion définitive (succès, échec, inconnu) : même chemin que l'envoi initial.
            $this->complete($attempt, $result, PHP_INT_MAX);
        } finally {
            $lock->release();
        }
    }

    // --- Exécution (job) ----------------------------------------------------------------------

    /**
     * Exécute UNE tentative pour une variante en file. Ne fait rien (null) si la variante n'est
     * plus en file (publiée, annulée, déjà prise par un autre worker) ou si le job est périmé.
     */
    public function execute(string $variantId, AttemptTrigger $trigger, ?string $triggeredBy, int $expectedAttempts, int $cycleAttempt = 1, ?string $jobUuid = null): ?PublicationAttempt
    {
        // 1) Réservation : tentative créée et variante « en cours de publication », de façon atomique.
        $prepared = DB::transaction(function () use ($variantId, $trigger, $triggeredBy, $expectedAttempts, $jobUuid) {
            [$post, $variant] = $this->lockVariant($variantId);
            if (! $variant || $variant->status !== VariantStatus::Queued || $variant->attempts_count !== $expectedAttempts) {
                return null;
            }
            if ($post === null || $post->trashed()) {
                // Publication supprimée entre-temps : jamais envoyée.
                VariantStateMachine::transition($variant, VariantStatus::Cancelled);

                return null;
            }

            $number = $expectedAttempts + 1;
            $account = $variant->socialAccount()->with('platform')->first();
            $attempt = PublicationAttempt::create([
                'post_variant_id' => $variant->id,
                'social_account_id' => $variant->social_account_id,
                'attempt_number' => $number,
                'trigger' => $trigger,
                'triggered_by' => $triggeredBy,
                'idempotency_key' => hash('sha256', "{$variant->id}|{$number}"),
                'status' => AttemptStatus::InProgress,
                'retryable' => false,
                'job_uuid' => $jobUuid,
                'started_at' => now(),
            ]);
            VariantStateMachine::transition($variant, VariantStatus::Publishing, ['attempts_count' => $number], $expectedAttempts);
            $this->refreshPostStatus($post);

            $blocked = $this->precheck($post, $variant, $account);

            return [$attempt, $blocked, $blocked ? null : $this->buildRequest($post, $variant, $account, $attempt)];
        });

        if ($prepared === null) {
            return null;
        }
        [$attempt, $result, $request] = $prepared;

        // 2) Appel de l'adaptateur, hors transaction. Exception = résultat inconnu (null).
        if ($result === null) {
            try {
                $result = $this->registry->for($request->platform)->publish($request);
            } catch (Throwable $e) {
                Log::warning('publishing.adapter_exception', ['attempt_id' => $attempt->id, 'exception' => $e::class]);
                $result = null;
            }
        }

        // 3) Enregistrement du résultat.
        $this->complete($attempt, $result, $cycleAttempt);

        return $attempt->fresh();
    }

    /**
     * Job abandonné (exception, délai dépassé) : la variante ne reste jamais bloquée. Si l'envoi
     * avait commencé, son résultat est inconnu → échec explicite, aucune relance automatique.
     */
    public function abandon(string $variantId, int $expectedAttempts): bool
    {
        $changed = DB::transaction(function () use ($variantId, $expectedAttempts) {
            [$post, $variant] = $this->lockVariant($variantId);
            if (! $variant) {
                return false;
            }

            if ($variant->status === VariantStatus::Publishing && $variant->attempts_count === $expectedAttempts + 1) {
                PublicationAttempt::query()
                    ->where('post_variant_id', $variant->id)
                    ->where('attempt_number', $expectedAttempts + 1)
                    ->where('status', AttemptStatus::InProgress->value)
                    ->update([
                        'status' => AttemptStatus::Unknown->value,
                        'error_code' => self::UNKNOWN_CODE,
                        'error_message' => self::UNKNOWN_MESSAGE,
                        'finished_at' => now(),
                        'updated_at' => now(),
                    ]);
                VariantStateMachine::transition($variant, VariantStatus::Failed, [
                    'last_error_code' => self::UNKNOWN_CODE,
                    'last_error_message' => self::UNKNOWN_MESSAGE,
                ]);
            } elseif ($variant->status === VariantStatus::Queued && $variant->attempts_count === $expectedAttempts) {
                VariantStateMachine::transition($variant, VariantStatus::Failed, [
                    'last_error_code' => 'PUBLISH_JOB_FAILED',
                    'last_error_message' => 'L\'envoi n\'a pas pu être traité. Rien n\'a été publié : réessayez.',
                ]);
            } else {
                return false;
            }

            if ($post) {
                $this->refreshPostStatus($post);
            }

            return $post;
        });

        if ($changed instanceof Post) {
            $this->audit->record(AuditAction::PublishFailed, null, $changed, ['variant_id' => $variantId, 'error_code' => 'ABANDONED'], 'system');
        }

        return $changed !== false;
    }

    // --- Interne -------------------------------------------------------------------------------

    private function complete(PublicationAttempt $attempt, ?PublishResult $result, int $cycleAttempt): void
    {
        $outcome = DB::transaction(function () use ($attempt, $result, $cycleAttempt) {
            [$post, $variant] = $this->lockVariant($attempt->post_variant_id);
            $attempt = PublicationAttempt::query()->whereKey($attempt->getKey())->lockForUpdate()->first();
            if (! $attempt || $attempt->status !== AttemptStatus::InProgress) {
                return null; // déjà conclue (reprise après incident)
            }

            $ownsVariant = $variant
                && $variant->status === VariantStatus::Publishing
                && $variant->attempts_count === $attempt->attempt_number;
            $now = now();

            if ($result !== null && $result->outcome === PublishOutcome::Unknown) {
                $result = null; // statut inexploitable : même traitement qu'un résultat inconnu
            }

            if ($result !== null && $result->outcome === PublishOutcome::Processing) {
                // Contenu transmis : la tentative reste « en cours », suivie par son identifiant.
                // Aucune transition de variante, aucun nouvel envoi.
                $attempt->fill([
                    'external_post_id' => $result->externalPostId,
                    'retryable' => false,
                    'response_excerpt' => $result->metadata ? $this->redactor->redact($result->metadata) : null,
                ])->save();
                if ($ownsVariant && $result->externalPostId !== null) {
                    $this->scheduleReconcile($attempt, $result->externalPostId, 1);
                }

                return null;
            }

            if ($result === null) {
                $attempt->fill(['status' => AttemptStatus::Unknown, 'error_code' => self::UNKNOWN_CODE, 'error_message' => self::UNKNOWN_MESSAGE]);
                $variantTo = VariantStatus::Failed;
                $variantAttrs = ['last_error_code' => self::UNKNOWN_CODE, 'last_error_message' => self::UNKNOWN_MESSAGE];
            } else {
                $attempt->fill([
                    'status' => $result->outcome === PublishOutcome::Success ? AttemptStatus::Succeeded : AttemptStatus::Failed,
                    'retryable' => $result->retryable,
                    'http_status' => $result->httpStatus,
                    'error_code' => $result->code,
                    'error_message' => $result->message ? Str::limit($result->message, 990) : null,
                    'external_post_id' => $result->externalPostId,
                    'response_excerpt' => $result->metadata ? $this->redactor->redact($result->metadata) : null,
                ]);
                [$variantTo, $variantAttrs] = match ($result->outcome) {
                    PublishOutcome::Success => [VariantStatus::Published, [
                        'external_post_id' => $result->externalPostId,
                        'external_url' => $result->externalUrl,
                        'published_at' => $now,
                        'last_error_code' => null,
                        'last_error_message' => null,
                    ]],
                    PublishOutcome::TemporaryFailure => [
                        $cycleAttempt < (int) config('publishing.max_auto_attempts', 3) ? VariantStatus::Queued : VariantStatus::Failed,
                        ['last_error_code' => $result->code, 'last_error_message' => $attempt->error_message],
                    ],
                    default => [VariantStatus::Failed, ['last_error_code' => $result->code, 'last_error_message' => $attempt->error_message]],
                };
            }
            $attempt->finished_at = $now;
            $attempt->save();

            if (! $ownsVariant) {
                return null;
            }
            if ($variantTo === VariantStatus::Queued) {
                $variantAttrs['queued_at'] = $now;
            }
            VariantStateMachine::transition($variant, $variantTo, $variantAttrs, $attempt->attempt_number);

            if ($variantTo === VariantStatus::Queued) {
                // Erreur temporaire : nouvelle tentative automatique différée (nouvelle ligne d'historique).
                $backoff = (array) config('publishing.backoff', [60]);
                $delay = (int) ($backoff[$cycleAttempt - 1] ?? end($backoff));
                PublishPostVariantJob::dispatch($variant->id, AttemptTrigger::AutoRetry->value, null, $attempt->attempt_number, $cycleAttempt + 1)
                    ->delay(now()->addSeconds($delay));
            }
            if ($variantTo === VariantStatus::Published && $post->published_at === null) {
                $post->published_at = $now;
            }
            $this->refreshPostStatus($post);

            return [$post, $variantTo, $attempt];
        });

        if ($outcome === null) {
            return;
        }
        [$post, $status, $attempt] = $outcome;
        $meta = ['variant_id' => $attempt->post_variant_id, 'attempt' => $attempt->attempt_number, 'error_code' => $attempt->error_code];
        match ($status) {
            VariantStatus::Published => $this->audit->record(AuditAction::PostPublished, null, $post, $meta, 'system'),
            VariantStatus::Failed => $this->audit->record(AuditAction::PublishFailed, null, $post, $meta, 'system'),
            default => null,
        };
    }

    /** Tentative toujours « en traitement suivi » avec ce jeton, pour cette variante. */
    private function isTracked(?PublicationAttempt $attempt, string $variantId, string $trackingId): bool
    {
        return $attempt !== null
            && $attempt->post_variant_id === $variantId
            && $attempt->status === AttemptStatus::InProgress
            && $attempt->external_post_id === $trackingId;
    }

    /** Vérification n° $check : délais config publishing.reconcile_delays, le dernier se répète. */
    private function scheduleReconcile(PublicationAttempt $attempt, string $trackingId, int $check): void
    {
        $delays = array_values((array) config('publishing.reconcile_delays', [15, 30, 30, 60, 120]));
        $delay = (int) ($delays[min(max($check, 1), count($delays)) - 1] ?? 120);

        // Date de dernière vérification (sans colonne dédiée) : updated_at, lu par sweepProcessing().
        PublicationAttempt::query()->whereKey($attempt->getKey())
            ->where('status', AttemptStatus::InProgress->value)
            ->where('external_post_id', $trackingId)
            ->update(['updated_at' => now()]);

        ReconcilePublicationJob::dispatch($attempt->getKey(), $attempt->post_variant_id, $trackingId, $check)
            ->delay(now()->addSeconds($delay))
            ->afterCommit();
    }

    /**
     * Verrouille le post (supprimé compris) PUIS la variante : même ordre que les actions du
     * Composer (PostLock), donc aucun interblocage.
     *
     * @return array{0: ?Post, 1: ?PostVariant}
     */
    private function lockVariant(string $variantId): array
    {
        $postId = PostVariant::query()->whereKey($variantId)->value('post_id');
        if ($postId === null) {
            return [null, null];
        }
        $post = Post::withTrashed()->whereKey($postId)->lockForUpdate()->first();
        $variant = PostVariant::query()->whereKey($variantId)->lockForUpdate()->first();

        return [$post, $variant];
    }

    /** Met la variante en file et envoie son job (poussé seulement après validation de la transaction). */
    private function enqueue(PostVariant $variant, AttemptTrigger $trigger, ?string $triggeredBy, array $attributes = []): void
    {
        $moved = VariantStateMachine::transition($variant, VariantStatus::Queued, [
            'queued_at' => now(),
            'last_error_code' => null,
            'last_error_message' => null,
        ] + $attributes);
        if (! $moved) {
            throw PostConflict::staleVersion();
        }

        PublishPostVariantJob::dispatch($variant->id, $trigger->value, $triggeredBy, $variant->attempts_count)->afterCommit();
    }

    /** Recalcule le statut agrégé du post (verrouillé) ; la version n'avance que s'il change. */
    private function refreshPostStatus(Post $post): void
    {
        $statuses = PostVariant::query()->where('post_id', $post->getKey())->get(['status'])->pluck('status')->all();
        $post->status = PostStatusAggregator::aggregate($statuses, $post->status);
        if ($post->isDirty()) {
            $post->lock_version++;
            $post->save();
        }
    }

    /** Refus définitifs détectés avant tout appel : aucun envoi n'est tenté. */
    private function precheck(Post $post, PostVariant $variant, ?SocialAccount $account): ?PublishResult
    {
        if ($account === null) {
            return PublishResult::permanentFailure('ACCOUNT_MISSING', 'Le compte social de cette publication n\'existe plus. Rien n\'a été publié.');
        }
        if (! $account->isConnected()) {
            return PublishResult::permanentFailure('ACCOUNT_DISCONNECTED', "Le compte « {$account->display_name} » n'est plus connecté. Reconnectez-le puis réessayez. Rien n'a été publié.");
        }

        $links = $this->mediaLinks($post, $variant);
        if ($links->contains(fn (PostMedia $l) => $l->media === null || ! $l->media->isUsable())) {
            return PublishResult::permanentFailure('MEDIA_UNAVAILABLE', 'Un média de cette publication n\'est plus disponible. Remplacez-le puis réessayez. Rien n\'a été publié.');
        }
        if (blank($variant->body ?? $post->body) && $links->isEmpty()) {
            return PublishResult::permanentFailure('EMPTY_CONTENT', 'La publication ne contient ni texte ni média. Rien n\'a été publié.');
        }

        return null;
    }

    private function buildRequest(Post $post, PostVariant $variant, SocialAccount $account, PublicationAttempt $attempt): PublishRequest
    {
        return new PublishRequest(
            postId: $post->id,
            variantId: $variant->id,
            socialAccountId: $account->id,
            platform: $account->platform?->key,
            externalAccountId: $account->external_account_id,
            format: $variant->format->value,
            body: (string) ($variant->body ?? $post->body),
            options: $variant->options ?? [],
            media: $this->mediaLinks($post, $variant)->map(fn (PostMedia $l) => [
                'id' => $l->media->id,
                'type' => (string) ($l->media->type?->value ?? $l->media->type),
                'mime_type' => $l->media->mime_type,
            ])->values()->all(),
            idempotencyKey: $attempt->idempotency_key,
            attemptNumber: $attempt->attempt_number,
            title: $post->title,
        );
    }

    /** @return Collection<int, PostMedia> médias communs puis propres à la variante, dans l'ordre */
    private function mediaLinks(Post $post, PostVariant $variant): Collection
    {
        return PostMedia::query()
            ->where('post_id', $post->getKey())
            ->where(fn ($q) => $q->whereNull('post_variant_id')->orWhere('post_variant_id', $variant->getKey()))
            ->with('media')
            ->orderByRaw('post_variant_id IS NOT NULL')
            ->orderBy('position')
            ->get();
    }

    /** @param Collection<int, PostVariant> $variants */
    private function assertReady(Post $post, Collection $variants): void
    {
        $errors = [];
        if ($variants->isEmpty()) {
            $errors['targets'] = 'Choisissez au moins un réseau social avant de publier.';
        } elseif ($variants->contains(fn (PostVariant $v) => $v->socialAccount === null || ! $v->socialAccount->isConnected())) {
            $errors['targets'] = 'Un des comptes sociaux n\'est plus connecté. Reconnectez-le ou retirez-le de la publication.';
        }
        if (blank($post->body) && ! $post->postMedia()->exists()) {
            $errors['body'] = 'Ajoutez un texte ou un média.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
