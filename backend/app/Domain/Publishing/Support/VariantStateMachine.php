<?php

namespace App\Domain\Publishing\Support;

use App\Domain\Content\Enums\VariantStatus;
use App\Models\PostVariant;
use LogicException;

/**
 * Cycle de vie d'une variante côté moteur de publication (docs/publishing.md §2) :
 *
 *   draft / scheduled / cancelled ──► queued ──► publishing ──► published
 *   scheduled / queued ──► cancelled          publishing ──► failed ──► queued (Réessayer)
 *   publishing ──► queued (erreur temporaire, tentative automatique)
 *   queued ──► failed (job abandonné)          published : état final, jamais republié
 *
 * Chaque transition est une mise à jour conditionnelle (WHERE status = <attendu>) : si deux
 * workers se disputent la même variante, un seul l'emporte, l'autre ne fait rien.
 */
final class VariantStateMachine
{
    /** @var array<string, list<VariantStatus>> */
    private const ALLOWED = [
        'draft' => [VariantStatus::Queued, VariantStatus::Cancelled],
        'scheduled' => [VariantStatus::Queued, VariantStatus::Cancelled],
        'cancelled' => [VariantStatus::Queued],
        'queued' => [VariantStatus::Publishing, VariantStatus::Cancelled, VariantStatus::Failed],
        'publishing' => [VariantStatus::Published, VariantStatus::Failed, VariantStatus::Queued],
        'failed' => [VariantStatus::Queued],
        'published' => [],
    ];

    public static function allows(VariantStatus $from, VariantStatus $to): bool
    {
        return in_array($to, self::ALLOWED[$from->value], true);
    }

    /**
     * Applique la transition si la variante est toujours dans l'état lu (et, si fourni, au même
     * compteur de tentatives). Renvoie false si un autre processus est passé avant.
     *
     * @param  array<string, mixed>  $attributes  colonnes mises à jour en même temps que le statut
     */
    public static function transition(PostVariant $variant, VariantStatus $to, array $attributes = [], ?int $expectedAttempts = null): bool
    {
        $from = $variant->status;
        if (! self::allows($from, $to)) {
            throw new LogicException("Transition de variante interdite : {$from->value} → {$to->value}.");
        }

        $updated = PostVariant::query()
            ->whereKey($variant->getKey())
            ->where('status', $from->value)
            ->when($expectedAttempts !== null, fn ($q) => $q->where('attempts_count', $expectedAttempts))
            ->update(['status' => $to->value, 'updated_at' => now()] + $attributes);

        if ($updated === 0) {
            return false;
        }

        $variant->forceFill(['status' => $to] + $attributes)->syncOriginal();

        return true;
    }
}
