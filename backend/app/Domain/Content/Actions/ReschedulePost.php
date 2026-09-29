<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Enums\PostStatus;
use App\Domain\Content\Enums\VariantStatus;
use App\Domain\Content\Exceptions\PostConflict;
use App\Domain\Content\Support\PostLock;
use App\Models\Post;
use App\Models\PostVariant;
use App\Models\User;
use App\Services\Audit\AuditAction;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Programme (brouillon, annulée) ou reprogramme (programmée) une publication à une nouvelle
 * date. Mêmes exigences qu'une programmation depuis le Composer : au moins un réseau, des
 * comptes connectés, un texte ou un média. Enregistrement seul : l'exécution à l'heure
 * prévue relève des phases Scheduler et Publishing.
 */
final class ReschedulePost
{
    private const RESCHEDULABLE = [PostStatus::Draft, PostStatus::Scheduled, PostStatus::Cancelled];

    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(User $user, Post $post, string $scheduledAt, ?string $timezone = null, ?int $expectedVersion = null): Post
    {
        $post = DB::transaction(function () use ($post, $scheduledAt, $timezone, $expectedVersion) {
            $locked = PostLock::acquire($post, $expectedVersion);
            if (! in_array($locked->status, self::RESCHEDULABLE, true)) {
                throw PostConflict::notReschedulable();
            }

            $variants = $locked->variants()->with('socialAccount')->get();
            $this->assertReady($locked, $variants);

            $at = Carbon::parse($scheduledAt)->utc();
            foreach ($variants as $variant) {
                $variant->status = VariantStatus::Scheduled;
                $variant->scheduled_at = $at;
                $variant->save();
            }

            $locked->scheduled_at = $at;
            $locked->timezone = $timezone ?? $locked->timezone;
            $locked->status = PostStatus::Scheduled;
            $locked->lock_version++;
            $locked->save();

            return $locked;
        });

        $this->audit->record(AuditAction::PostScheduled, $user, $post, ['scheduled_at' => $post->scheduled_at?->toIso8601String()]);

        return $post->fresh(['variants.socialAccount.platform', 'media']);
    }

    /** @param Collection<int, PostVariant> $variants */
    private function assertReady(Post $post, $variants): void
    {
        $errors = [];
        if ($variants->isEmpty()) {
            $errors['targets'] = 'Choisissez au moins un réseau social avant de programmer.';
        } elseif ($variants->contains(fn ($v) => $v->socialAccount === null || ! $v->socialAccount->isConnected())) {
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
