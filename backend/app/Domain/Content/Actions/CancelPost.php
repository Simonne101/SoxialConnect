<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Enums\PostStatus;
use App\Domain\Content\Enums\VariantStatus;
use App\Domain\Content\Exceptions\PostConflict;
use App\Domain\Content\Support\PostLock;
use App\Domain\Publishing\PostStatusAggregator;
use App\Models\Post;
use App\Models\PostVariant;
use App\Models\User;
use App\Services\Audit\AuditAction;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Annule une publication programmée ou en file d'attente avant son envoi (docs/database.md §3.3,
 * docs/publishing.md §2). Refusé si une variante est en cours d'envoi (résultat attendu).
 * La date prévue est conservée pour l'historique et le calendrier ; la publication reste
 * modifiable et peut être reprogrammée. Aucun appel aux réseaux sociaux.
 */
final class CancelPost
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(User $user, Post $post, ?int $expectedVersion = null): Post
    {
        $post = DB::transaction(function () use ($post, $expectedVersion) {
            $locked = PostLock::acquire($post, $expectedVersion);
            if (! in_array($locked->status, [PostStatus::Scheduled, PostStatus::Processing], true)) {
                throw PostConflict::notCancellable();
            }

            // Variantes verrouillées après le post (même ordre que le moteur de publication).
            $variants = $locked->variants()->lockForUpdate()->get();
            if ($variants->contains(fn ($v) => $v->status === VariantStatus::Publishing)) {
                throw PostConflict::publishingInProgress();
            }
            $cancellable = $variants->filter(fn ($v) => in_array($v->status, [VariantStatus::Scheduled, VariantStatus::Queued, VariantStatus::Draft], true));
            if ($cancellable->isEmpty()) {
                throw PostConflict::notCancellable();
            }

            // Un job déjà en file pour une variante annulée ne fera rien (le moteur vérifie le statut).
            PostVariant::query()->whereKey($cancellable->modelKeys())
                ->update(['status' => VariantStatus::Cancelled->value, 'updated_at' => now()]);

            $statuses = PostVariant::query()->where('post_id', $locked->id)->get(['status'])->pluck('status')->all();
            $locked->status = PostStatusAggregator::aggregate($statuses, PostStatus::Cancelled);
            $locked->lock_version++;
            $locked->save();

            return $locked;
        });

        $this->audit->record(AuditAction::PostCancelled, $user, $post);

        return $post->fresh(['variants.socialAccount.platform', 'media']);
    }
}
