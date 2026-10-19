<?php

namespace App\Domain\Analytics;

use App\Domain\Analytics\Data\MetricsResult;
use App\Domain\Analytics\Enums\Granularity;
use App\Domain\Analytics\Exceptions\MetricsFetchFailed;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Points d'un adaptateur → lignes de la table `analytics`. Règles (docs/database.md §3.7) :
 * - métrique absente ou null → NULL (« non fournie »), 0 → 0 : jamais l'un pour l'autre ;
 * - métriques connues : entiers ≥ 0 (followers_delta peut être négatif) ; toute autre valeur est une réponse
 *   incohérente → échec explicite (INVALID_METRICS), jamais corrigée en silence ;
 * - métriques inconnues : conservées dans `extra` (valeurs scalaires), sans être normalisées.
 */
final class MetricsNormalizer
{
    /** Colonnes normalisées de la table `analytics` (compteurs ≥ 0). */
    public const COUNTERS = ['impressions', 'reach', 'views', 'engagements', 'clicks', 'likes', 'comments', 'shares', 'saves', 'followers'];

    /**
     * @return list<array<string, mixed>> lignes prêtes à écrire (hors clés de compte/publication)
     *
     * @throws MetricsFetchFailed
     */
    public function normalize(MetricsResult $result): array
    {
        $rows = [];
        foreach ($result->points as $i => $point) {
            $granularity = Granularity::tryFrom((string) ($point['granularity'] ?? Granularity::Day->value))
                ?? $this->invalid("point {$i} : granularité inconnue");
            $rows[$this->key($point, $granularity, $i)] = [
                'metric_date' => $this->date($point['date'] ?? null, $i),
                'granularity' => $granularity->value,
                ...$this->metrics((array) ($point['metrics'] ?? []), $i),
            ];
        }

        return array_values($rows); // un même jour renvoyé deux fois : dernière valeur retenue, jamais deux lignes
    }

    /** @param array<string, mixed> $metrics */
    private function metrics(array $metrics, int $i): array
    {
        $row = array_fill_keys([...self::COUNTERS, 'followers_delta'], null);
        $extra = [];
        foreach ($metrics as $name => $value) {
            if (in_array($name, self::COUNTERS, true)) {
                $row[$name] = $this->integer($value, $name, $i, signed: false);
            } elseif ($name === 'followers_delta') {
                $row[$name] = $this->integer($value, $name, $i, signed: true);
            } elseif (is_scalar($value) || $value === null) {
                $extra[(string) $name] = $value;
            }
        }
        $row['extra'] = $extra !== [] ? $extra : null;

        return $row;
    }

    private function integer(mixed $value, string $name, int $i, bool $signed): ?int
    {
        if ($value === null) {
            return null; // non fournie par la plateforme
        }
        if (is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', $value)) || (is_float($value) && floor($value) === $value)) {
            $int = (int) $value;
            if ($signed || $int >= 0) {
                return $int;
            }
        }
        $this->invalid("point {$i} : valeur invalide pour {$name}");
    }

    private function date(mixed $date, int $i): string
    {
        try {
            if (is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $date, 'UTC');
                if ($parsed !== null && $parsed->toDateString() === $date) { // pas de report silencieux (31 février → mars)
                    return $date;
                }
            }
        } catch (Throwable) {
            // date impossible (ex. 2026-02-31)
        }
        $this->invalid("point {$i} : date invalide");
    }

    /** @param array<string, mixed> $point */
    private function key(array $point, Granularity $granularity, int $i): string
    {
        return $this->date($point['date'] ?? null, $i).'|'.$granularity->value;
    }

    private function invalid(string $detail): never
    {
        throw new MetricsFetchFailed('INVALID_METRICS', "Réponse de la plateforme incohérente ({$detail}) : rien n'a été enregistré.");
    }
}
