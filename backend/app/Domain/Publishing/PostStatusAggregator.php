<?php

namespace App\Domain\Publishing;

use App\Domain\Content\Enums\PostStatus;
use App\Domain\Content\Enums\VariantStatus;

/**
 * Statut agrégé d'une publication à partir des statuts de ses variantes
 * (docs/database.md §3.3). Le statut d'un post n'est jamais saisi par le client.
 */
final class PostStatusAggregator
{
    /** @param list<VariantStatus> $variants */
    public static function aggregate(array $variants, PostStatus $fallback = PostStatus::Draft): PostStatus
    {
        if ($variants === []) {
            return $fallback;
        }

        // array_all / array_any n'existent qu'à partir de PHP 8.4 (projet : PHP 8.3).
        $all = fn (VariantStatus $s) => count(array_filter($variants, fn (VariantStatus $v) => $v !== $s)) === 0;
        $any = fn (VariantStatus ...$s) => count(array_filter($variants, fn (VariantStatus $v) => in_array($v, $s, true))) > 0;

        return match (true) {
            $all(VariantStatus::Draft) => PostStatus::Draft,
            $all(VariantStatus::Cancelled) => PostStatus::Cancelled,
            $any(VariantStatus::Publishing, VariantStatus::Queued) => PostStatus::Processing,
            $all(VariantStatus::Published) => PostStatus::Published,
            $all(VariantStatus::Failed) => PostStatus::Failed,
            $any(VariantStatus::Published) && $any(VariantStatus::Failed) => PostStatus::PartiallyPublished,
            $any(VariantStatus::Scheduled) => PostStatus::Scheduled,
            // Variantes annulées mêlées à des variantes terminées (annulation d'une partie de l'envoi).
            $any(VariantStatus::Published) => PostStatus::PartiallyPublished,
            $any(VariantStatus::Failed) => PostStatus::Failed,
            default => $fallback,
        };
    }
}
