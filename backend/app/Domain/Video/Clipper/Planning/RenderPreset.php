<?php

namespace App\Domain\Video\Clipper\Planning;

/**
 * Préréglage de rendu d'un clip (ADR-051) : modèle visuel, cadence, cadrage, durée d'affichage de
 * l'accroche. V1 : CleanShortPreset. Des préréglages par plateforme (youtube-shorts,
 * instagram-reels, tiktok) s'ajoutent en implémentant cette interface et en les déclarant dans
 * video.clipper.presets, sans toucher au moteur d'analyse ni aux moteurs de rendu.
 */
interface RenderPreset
{
    /** Identifiant stable, ex. « clean-short ». */
    public function name(): string;

    /** Composition / modèle visuel à utiliser par le moteur de rendu. */
    public function template(): string;

    public function fps(): int;

    /**
     * Stratégie de cadrage. V1 : « center » (recadrage centré). Aucune détection de visage n'est
     * simulée : une stratégie « face » ou « speaker » ne sera déclarée qu'une fois réellement implémentée.
     */
    public function crop(): string;

    /** Durée d'affichage de l'accroche en début de clip (ms). */
    public function hookDurationMs(): int;

    /** Longueur maximale d'une légende affichée (caractères) : les segments plus longs sont découpés. */
    public function maxCaptionChars(): int;
}
