<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Enums\PostStatus;
use App\Domain\Content\Enums\VariantStatus;
use App\Models\Post;
use App\Models\User;
use App\Services\Audit\AuditAction;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Duplique une publication (quel que soit son statut) en un nouveau brouillon :
 * texte, variantes (format, texte, options) et médias sont copiés ; dates, statuts de
 * publication, liens externes et erreurs ne le sont pas. Les variantes visant un compte
 * social supprimé sont ignorées.
 */
final class DuplicatePost
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(User $user, Post $source): Post
    {
        $copy = DB::transaction(function () use ($user, $source) {
            $source->loadMissing(['variants.socialAccount', 'postMedia']);

            $copy = new Post;
            $copy->user_id = $user->getKey();
            $copy->title = $source->title
                ? Str::limit($source->title, 255 - Str::length(' (copie)'), '').' (copie)'
                : 'Copie';
            $copy->body = $source->body;
            $copy->timezone = $source->timezone;
            $copy->source = $source->source;
            $copy->status = PostStatus::Draft;
            $copy->save();

            $variantIds = [];
            foreach ($source->variants as $variant) {
                if ($variant->socialAccount === null) {
                    continue; // compte supprimé (soft delete) : la cible n'est plus disponible
                }
                $new = $copy->variants()->make([
                    'social_account_id' => $variant->social_account_id,
                    'format' => $variant->format,
                    'body' => $variant->body,
                    'options' => $variant->options ?? [],
                ]);
                $new->status = VariantStatus::Draft;
                $new->save();
                $variantIds[$variant->getKey()] = $new->getKey();
            }

            foreach ($source->postMedia as $link) {
                if ($link->post_variant_id !== null && ! isset($variantIds[$link->post_variant_id])) {
                    continue;
                }
                $copy->postMedia()->create([
                    'media_id' => $link->media_id,
                    'post_variant_id' => $link->post_variant_id !== null ? $variantIds[$link->post_variant_id] : null,
                    'position' => $link->position,
                    'alt_text' => $link->alt_text,
                ]);
            }

            return $copy;
        });

        $this->audit->record(AuditAction::PostCreated, $user, $copy, ['duplicated_from' => $source->getKey()]);

        return $copy->fresh(['variants.socialAccount.platform', 'media']);
    }
}
