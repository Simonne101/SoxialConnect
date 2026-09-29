<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Enums\PostFormat;
use App\Domain\Content\Enums\PostStatus;
use App\Domain\Content\Enums\VariantStatus;
use App\Domain\Content\Exceptions\PostConflict;
use App\Domain\Publishing\PostStatusAggregator;
use App\Models\Post;
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
            $locked = Post::query()->whereKey($post->getKey())->lockForUpdate()->firstOrFail();
            if (! $locked->isEditable()) {
                throw PostConflict::notEditable();
            }
            if (isset($data['lock_version']) && (int) $data['lock_version'] !== $locked->lock_version) {
                throw PostConflict::staleVersion();
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

    /** @param array<string, mixed> $data */
    private function syncChildren(Post $post, array $data): void
    {
        $variantStatus = $post->status === PostStatus::Scheduled ? VariantStatus::Scheduled : VariantStatus::Draft;

        $post->variants()->delete();
        foreach ($data['targets'] ?? [] as $target) {
            $variant = $post->variants()->make([
                'social_account_id' => $target['social_account_id'],
                'format' => PostFormat::from($target['format']),
                'body' => $target['body'] ?? null,
                'options' => $target['options'] ?? [],
                'scheduled_at' => $post->scheduled_at,
            ]);
            $variant->status = $variantStatus;
            $variant->save();
        }

        $post->postMedia()->delete();
        foreach (array_values($data['media_ids'] ?? []) as $position => $mediaId) {
            $post->postMedia()->create(['media_id' => $mediaId, 'position' => $position]);
        }

        $post->status = PostStatusAggregator::aggregate(
            $post->variants()->get()->map->status->all(),
            $post->status,
        );
        $post->save();
    }
}
