<?php

namespace App\Domain\Video\Clipper\Intelligence;

use InvalidArgumentException;

/**
 * Score global d'un passage (ADR-051) : moyenne pondérée des critères notés par l'IA (0-100 chacun),
 * normalisée sur 0-100. Les poids viennent d'un seul endroit (video.clipper.scoring.weights) et sont
 * normalisés par leur somme : on peut les modifier sans toucher au code. L'IA fournit les critères ;
 * le score global est TOUJOURS calculé ici, jamais repris de la réponse du modèle.
 */
final class ClipScorer
{
    /** Critères notés, dans l'ordre d'affichage. */
    public const CRITERIA = ['hook', 'value', 'autonomy', 'emotion', 'rhythm', 'quality'];

    /** @var array<string, float> */
    private readonly array $weights;

    /** @param array<string, float|int>|null $weights poids (défaut : configuration) */
    public function __construct(?array $weights = null)
    {
        $weights ??= (array) config('video.clipper.scoring.weights', []);
        $clean = [];
        foreach (self::CRITERIA as $criterion) {
            $weight = $weights[$criterion] ?? 0;
            if (! is_numeric($weight) || $weight < 0) {
                throw new InvalidArgumentException("Poids invalide pour « {$criterion} ».");
            }
            $clean[$criterion] = (float) $weight;
        }
        if (array_sum($clean) <= 0) {
            throw new InvalidArgumentException('La somme des poids du score doit être positive.');
        }
        $this->weights = $clean;
    }

    /**
     * @param  array<string, int>  $breakdown  critères validés (0-100), cf. ClipCandidate
     * @return float score global 0-100, deux décimales
     */
    public function score(array $breakdown): float
    {
        $total = 0.0;
        foreach ($this->weights as $criterion => $weight) {
            $value = $breakdown[$criterion] ?? null;
            if (! is_int($value) || $value < 0 || $value > 100) {
                throw new InvalidArgumentException("Critère « {$criterion} » absent ou hors de 0-100.");
            }
            $total += $weight * $value;
        }

        return round($total / array_sum($this->weights), 2);
    }

    /** @return array<string, float> poids normalisés (somme = 1), pour l'affichage */
    public function weights(): array
    {
        $sum = array_sum($this->weights);

        return array_map(fn (float $w) => round($w / $sum, 4), $this->weights);
    }
}
