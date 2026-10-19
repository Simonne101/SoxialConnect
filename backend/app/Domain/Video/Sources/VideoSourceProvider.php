<?php

namespace App\Domain\Video\Sources;

use App\Domain\Video\Enums\AcquisitionMethod;
use App\Models\VideoSource;

/**
 * Fournisseur d'un fichier vidéo rendable pour une référence YouTube (ADR-049). Le Clipper ne
 * dépend d'aucun fournisseur particulier : VideoSourceProviders essaie, dans l'ordre configuré
 * (video.sources.providers), le premier configuré qui prend la source en charge.
 *
 * Implémentation : DataPortabilityVideoSourceProvider (export officiel Google, vidéos du
 * propriétaire). Une source autorisée supplémentaire s'ajoute en implémentant cette interface,
 * sans toucher à l'analyse, à la transcription, au rendu, à la médiathèque ni à la publication.
 * Aucun téléchargeur non officiel (ADR-051).
 */
interface VideoSourceProvider
{
    /** Identifiant stable (journal, métadonnées), ex. « data_portability ». */
    public function name(): string;

    public function method(): AcquisitionMethod;

    /** Activé et doté de ses identifiants : aucune requête réseau. */
    public function isConfigured(): bool;

    /** Peut traiter cette source (propriété exigée, région…) : aucune requête réseau. */
    public function supports(VideoSource $source): bool;

    /** Première sollicitation du fournisseur. */
    public function begin(VideoSource $source): AcquisitionStep;

    /** Suivi d'une acquisition en attente (metadata de la source = état du fournisseur). */
    public function poll(VideoSource $source): AcquisitionStep;
}
