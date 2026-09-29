<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Enums\PostStatus;
use App\Domain\Content\Enums\VariantStatus;
use App\Domain\Content\Exceptions\PostConflict;
use App\Domain\Content\Support\PostLock;
use App\Models\Post;
use App\Models\User;
use App\Services\Audit\AuditAction;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Annule la programmation d'une publication avant toute publication (docs/database.md §3.3 :
 * statut « cancelled »). La date prévue est conservée pour l'historique et le calendrier ;
 * la publication reste modifiable et peut être reprogrammée. Aucun appel aux réseaux sociaux.
 */
final class CancelPost
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(User $user, Post $post, ?int $expectedVersion = null): Post
    {
        $post = DB::transaction(function () use ($post, $expectedVersion) {
            $locked = PostLock::acquire($post, $expectedVersion);
            if ($locked->status !== PostStatus::Scheduled) {
                throw PostConflict::notCancellable();
            }

            $locked->variants()
                ->whereIn('status', [VariantStatus::Scheduled->value, VariantStatus::Draft->value])
                ->update(['status' => VariantStatus::Cancelled->value, 'updated_at' => now()]);

            $locked->status = PostStatus::Cancelled;
            $locked->lock_version++;
            $locked->save();

            return $locked;
        });

        $this->audit->record(AuditAction::PostCancelled, $user, $post);

        return $post->fresh(['variants.socialAccount.platform', 'media']);
    }
}
