<?php

namespace App\Domain\Video\Clipper\Intelligence;

use App\Domain\Video\Clipper\ClipLimits;

/**
 * Passage proposé par l'IA, VALIDÉ (ADR-051). Aucune donnée du modèle n'entre dans le domaine sans
 * passer par fromAi() : champs obligatoires, types, bornes (0-100), début < fin, durée ≤ 60 s et
 * dans la vidéo. Un candidat invalide est rejeté (null), jamais « réparé » en silence — sauf la fin,
 * ramenée à 60 s / à la durée connue comme le faisait déjà l'analyse (ClipLimits::bound).
 */
final readonly class ClipCandidate
{
    /** @param array<string, int> $criteria */
    private function __construct(
        public int $startMs,
        public int $endMs,
        public string $title,
        public string $hook,
        public string $reason,
        public ?string $topic,
        public int $confidence,
        public array $criteria,
        public float $score,
    ) {}

    /**
     * Construit un candidat à partir d'un élément de la réponse IA, ou null s'il est invalide.
     *
     * @param  int|null  $sourceDurationMs  durée connue de la vidéo (null : inconnue)
     */
    public static function fromAi(mixed $raw, ?int $sourceDurationMs, ClipScorer $scorer): ?self
    {
        return self::rejectionReason($raw, $sourceDurationMs) === null ? self::build($raw, $sourceDurationMs, $scorer) : null;
    }

    /**
     * Raison du rejet d'un élément de la réponse IA (diagnostic), ou null s'il est valide :
     * « invalid » (champ manquant ou mal typé), « order » (fin ≤ début), « too_long » (> 60 s),
     * « out_of_range » (hors de la vidéo ou trop court).
     */
    public static function rejectionReason(mixed $raw, ?int $sourceDurationMs): ?string
    {
        if (! is_array($raw)) {
            return 'invalid';
        }
        $start = self::milliseconds($raw['start_ms'] ?? null);
        $end = self::milliseconds($raw['end_ms'] ?? null);
        if ($start === null || $end === null || self::text($raw['hook'] ?? null, 255) === '' || self::text($raw['reason'] ?? null, 1000) === ''
            || self::percent($raw['confidence'] ?? null) === null || self::criteria($raw['criteria'] ?? null) === null) {
            return 'invalid';
        }

        return match (true) {
            $end <= $start => 'order',
            $end - $start > ClipLimits::MAX_CLIP_MS => 'too_long',
            ClipLimits::bound($start, $end, $sourceDurationMs) === null => 'out_of_range',
            default => null,
        };
    }

    /** @param array<string, mixed> $raw élément déjà validé par rejectionReason() */
    private static function build(array $raw, ?int $sourceDurationMs, ClipScorer $scorer): ?self
    {
        $start = self::milliseconds($raw['start_ms'] ?? null);
        $end = self::milliseconds($raw['end_ms'] ?? null);
        $hook = self::text($raw['hook'] ?? null, 255);
        $reason = self::text($raw['reason'] ?? null, 1000);
        $confidence = self::percent($raw['confidence'] ?? null);
        $criteria = self::criteria($raw['criteria'] ?? null);
        if ($start === null || $end === null || $end <= $start || $hook === '' || $reason === '' || $confidence === null || $criteria === null) {
            return null;
        }
        if ($end - $start > ClipLimits::MAX_CLIP_MS) {
            return null; // l'IA a ignoré la contrainte de 60 s : le passage n'est pas réécrit
        }
        $range = ClipLimits::bound($start, $end, $sourceDurationMs);
        if ($range === null) {
            return null; // hors de la vidéo ou trop court
        }
        $title = self::text($raw['title'] ?? null, 120);

        return new self(
            startMs: $range[0],
            endMs: $range[1],
            title: $title !== '' ? $title : mb_substr($hook, 0, 120),
            hook: $hook,
            reason: $reason,
            topic: self::text($raw['topic'] ?? null, 255) ?: null,
            confidence: $confidence,
            criteria: $criteria,
            score: $scorer->score($criteria),
        );
    }

    public function durationMs(): int
    {
        return $this->endMs - $this->startMs;
    }

    /**
     * Forme tableau commune au pipeline (recalage, déduplication, enregistrement).
     *
     * @return array{start_ms: int, end_ms: int, title: string, hook: string, topic: string, reason: string, confidence: int, score_breakdown: array<string, int>, score: float}
     */
    public function toArray(): array
    {
        return [
            'start_ms' => $this->startMs,
            'end_ms' => $this->endMs,
            'title' => $this->title,
            'hook' => $this->hook,
            'topic' => (string) $this->topic,
            'reason' => $this->reason,
            'confidence' => $this->confidence,
            'score_breakdown' => $this->criteria,
            'score' => $this->score,
        ];
    }

    /** Entier positif ou nul (un nombre décimal est arrondi ; une chaîne n'est jamais acceptée). */
    private static function milliseconds(mixed $value): ?int
    {
        return (is_int($value) || is_float($value)) && $value >= 0 && is_finite((float) $value) ? (int) round($value) : null;
    }

    private static function percent(mixed $value): ?int
    {
        return (is_int($value) || is_float($value)) && $value >= 0 && $value <= 100 ? (int) round($value) : null;
    }

    /** @return array<string, int>|null tous les critères présents et dans 0-100, sinon null */
    private static function criteria(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }
        $criteria = [];
        foreach (ClipScorer::CRITERIA as $criterion) {
            $score = self::percent($value[$criterion] ?? null);
            if ($score === null) {
                return null;
            }
            $criteria[$criterion] = $score;
        }

        return $criteria;
    }

    private static function text(mixed $value, int $max): string
    {
        return is_string($value) ? mb_substr(trim(preg_replace('/\s+/u', ' ', $value) ?? ''), 0, $max) : '';
    }
}
