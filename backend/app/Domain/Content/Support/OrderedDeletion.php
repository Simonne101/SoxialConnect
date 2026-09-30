<?php

namespace App\Domain\Content\Support;

use App\Models\PostMedia;
use App\Models\PostVariant;
use App\Models\PublicationAttempt;

/**
 * Suppressions ordonnées du module Content, sans s'appuyer sur les actions référentielles
 * (ON DELETE CASCADE) : MySQL 8.0.30 (Laragon) s'est arrêté sur une cascade touchant des tables
 * indexées sur des colonnes générées virtuelles (ADR-034). `post_media` en fait partie
 * (`post_variant_key`, index unique). Ordre : enfants d'abord, parents ensuite, donc aucune cascade.
 */
final class OrderedDeletion
{
    /**
     * Supprime des variantes et leurs dépendances : médias propres à la variante,
     * tentatives de publication, puis la variante elle-même.
     *
     * @param  list<string>  $variantIds
     */
    public static function variants(array $variantIds): void
    {
        if ($variantIds === []) {
            return;
        }
        PostMedia::query()->whereIn('post_variant_id', $variantIds)->delete();
        PublicationAttempt::query()->whereIn('post_variant_id', $variantIds)->delete();
        PostVariant::query()->whereIn('id', $variantIds)->delete();
    }
}
