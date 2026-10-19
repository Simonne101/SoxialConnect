<?php

namespace App\Domain\Analytics\Data;

use App\Domain\Analytics\Enums\Granularity;
use Carbon\CarbonImmutable;

/**
 * Réponse d'un adaptateur : réponse brute (expurgée, conservée 13 mois) et points de métriques non encore
 * normalisés. Chaque point : date, granularité, métriques (nom => valeur, null = non fournie).
 */
final readonly class MetricsResult
{
    /**
     * @param  array<string, mixed>  $payload  réponse brute de la plateforme, sans jeton ni secret
     * @param  list<array{date: string, granularity?: string, metrics: array<string, mixed>}>  $points
     */
    public function __construct(
        public array $payload,
        public array $points,
        public CarbonImmutable $periodStart,
        public CarbonImmutable $periodEnd,
        public ?string $apiVersion = null,
    ) {}

    /** @param array<string, mixed> $metrics */
    public static function point(string $date, array $metrics, Granularity $granularity = Granularity::Day): array
    {
        return ['date' => $date, 'granularity' => $granularity->value, 'metrics' => $metrics];
    }
}
