<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Enums\PostFormat;
use App\Domain\Content\Enums\PostStatus;
use App\Domain\Content\Enums\VariantStatus;
use App\Domain\Content\Exceptions\PostConflict;
use App\Domain\Content\Support\OrderedDeletion;
use App\Domain\Content\Support\PostLock;
use App\Domain\Publishing\PostStatusAggregator;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\PostVariant;
use App\Models\User;
use App\Services\Audit\AuditAction;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Création et modification d'une publication avec ses variantes et ses médias.
 * Les données reçues sont déjà validées (FormRequest) : propriété des comptes et des médias,
 * formats, dates. Modes acceptés en phase 2 : « draft » et « schedule » (enregistrement seul,
 * aucune publication réelle — phases Scheduler et Publishing).
 */
final class SavePost
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @param array<string, mixed> $data */
    public function create(User $user, array $data): Post
    {
        $post = DB::transaction(function () use ($user, $data) {
            $post = new Post;
            $post->user_id = $user->getKey();
            $this->fill($post, $data);
            $post->save();
            $this->syncChildren($post, $data);

            return $post;
        });

        $this->audit->record(AuditAction::PostCreated, $user, $post);
        if ($post->status === PostStatus::Scheduled) {
            $this->audit->record(AuditAction::PostScheduled, $user, $post, ['scheduled_at' => $post->scheduled_at?->toIso8601String()]);
        }

        return $post->fresh(['variants.socialAccount.platform', 'media']);
    }

    /** @param array<string, mixed> $data */
    public function update(User $user, Post $post, array $data): Post
    {
        $post = DB::transaction(function () use ($post, $data) {
            // Verrou pessimiste + contrôle de version : pas d'écrasement silencieux entre deux onglets.
            $locked = PostLock::acquire($post, isset($data['lock_version']) ? (int) $data['lock_version'] : null);
            if (! $locked->isEditable()) {
                throw PostConflict::notEditable();
            }

            $this->fill($locked, $data);
            $locked->lock_version++;
            $locked->save();
            $this->syncChildren($locked, $data);

            return $locked;
        });

        $this->audit->record(AuditAction::PostUpdated, $user, $post);

        return $post->fresh(['variants.socialAccount.platform', 'media']);
    }

    public function delete(User $user, Post $post): void
    {
        $post->delete();
        $this->audit->record(AuditAction::PostDeleted, $user, $post);
    }

    /** @param array<string, mixed> $data */
    private function fill(Post $post, array $data): void
    {
        $schedule = ($data['mode'] ?? 'draft') === 'schedule';
        $post->title = $data['title'] ?? null;
        $post->body = $data['body'] ?? '';
        $post->timezone = $data['timezone'] ?? $post->timezone ?? 'Europe/Paris';
        $post->scheduled_at = $schedule ? Carbon::parse($data['scheduled_at'])->utc() : null;
        $post->status = $schedule ? PostStatus::Scheduled : PostStatus::Draft;
    }

    /**
     * Synchronise les variantes et les médias SANS tout supprimer puis recréer :
     * les variantes existantes gardent leur identifiant (et donc leur historique de tentatives),
     * seules les cibles retirées sont supprimées, dans un ordre sans cascade (OrderedDeletion).
     *
     * @param  array<string, mixed>  $data
     */
    private function syncChildren(Post $post, array $data): void
    {
        $variantStatus = $post->status === PostStatus::Scheduled ? VariantStatus::Scheduled : VariantStatus::Draft;

        $existing = $post->variants()->get();
        $remaining = $existing->keyBy('id');
        $unmatched = [];

        // 1) Même compte ET même format : mise à jour sur place.
        foreach ($data['targets'] ?? [] as $target) {
            $match = $remaining->first(fn (PostVariant $v) => $v->social_account_id === $target['social_account_id']
                && $v->format->value === $target['format']);
            if ($match) {
                $remaining->forget($match->id);
                $this->fillVariant($match, $target, $post, $variantStatus);
            } else {
                $unmatched[] = $target;
            }
        }

        // 2) Même compte, format changé : l'identifiant de la variante est conservé.
        $toCreate = [];
        foreach ($unmatched as $target) {
            $match = $remaining->first(fn (PostVariant $v) => $v->social_account_id === $target['social_account_id']);
            if ($match) {
                $remaining->forget($match->id);
                $this->fillVariant($match, $target, $post, $variantStatus);
            } else {
                $toCreate[] = $target;
            }
        }

        // 3) Cibles retirées : suppression ordonnée (avant création, pour l'unicité compte + format).
        OrderedDeletion::variants($remaining->keys()->all());

        // 4) Nouvelles cibles.
        foreach ($toCreate as $target) {
            $this->fillVariant($post->variants()->make(), $target, $post, $variantStatus);
        }

        $this->syncMedia($post, array_values($data['media_ids'] ?? []));

        $post->status = PostStatusAggregator::aggregate(
            $post->variants()->get()->map->status->all(),
            $post->status,
        );
        $post->save();
    }

    /** @param array<string, mixed> $target */
    private function fillVariant(PostVariant $variant, array $target, Post $post, VariantStatus $status): void
    {
        $variant->fill([
            'social_account_id' => $target['social_account_id'],
            'format' => PostFormat::from($target['format']),
            'body' => $target['body'] ?? null,
            'options' => $target['options'] ?? [],
            'scheduled_at' => $post->scheduled_at,
        ]);
        if ($variant->exists && $variant->status !== $status) {
            // Nouveau cycle (brouillon ou programmation) : la dernière erreur reste dans l'historique
            // des tentatives, elle n'est plus affichée comme erreur courante.
            $variant->last_error_code = null;
            $variant->last_error_message = null;
        }
        $variant->status = $status;
        $variant->save();
    }

    /**
     * Médias communs à toutes les variantes : ajout, retrait et réordonnancement sans tout
     * supprimer (les médias propres à une variante, post_variant_id non nul, ne sont pas touchés).
     *
     * @param  list<string>  $mediaIds
     */
    private function syncMedia(Post $post, array $mediaIds): void
    {
        // Requêtes ciblées par identifiant (pas de save() de modèle chargé) : post_media contient une
        // colonne générée (post_variant_key) qu'une écriture Eloquent complète tenterait de réécrire.
        // toBase() : except() d'une collection Eloquent filtre par clé primaire, pas par clé de collection.
        $links = $post->postMedia()->whereNull('post_variant_id')->get(['id', 'media_id', 'position'])->keyBy('media_id')->toBase();
        $removed = $links->except($mediaIds)->pluck('id')->all();
        if ($removed !== []) {
            PostMedia::query()->whereIn('id', $removed)->delete();
        }
        foreach ($mediaIds as $position => $mediaId) {
            $link = $links->get($mediaId);
            if ($link) {
                if ((int) $link->position !== $position) {
                    PostMedia::query()->whereKey($link->id)->update(['position' => $position]);
                }
            } else {
                $post->postMedia()->create(['media_id' => $mediaId, 'position' => $position]);
            }
        }
    }
}
