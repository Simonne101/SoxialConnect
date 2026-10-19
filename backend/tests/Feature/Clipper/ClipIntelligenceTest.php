<?php

namespace Tests\Feature\Clipper;

use App\Domain\Video\Clipper\ClipLimits;
use App\Domain\Video\Clipper\Intelligence\ClipCandidate;
use App\Domain\Video\Clipper\Intelligence\ClipDeduplicator;
use App\Domain\Video\Clipper\Intelligence\ClipScorer;
use App\Domain\Video\Clipper\TranscriptAnalyzer;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Clip Intelligence Engine (ADR-051) : score pondéré calculé par le backend, validation stricte de la
 * réponse IA, déduplication. Logique pure : aucune requête, aucune base.
 */
class ClipIntelligenceTest extends TestCase
{
    private const CRITERIA = ['hook' => 95, 'value' => 90, 'autonomy' => 88, 'emotion' => 82, 'rhythm' => 87, 'quality' => 92];

    /** Élément de réponse IA valide, modifiable champ par champ. */
    private function raw(array $overrides = []): array
    {
        return [...[
            'start_ms' => 10_000, 'end_ms' => 40_000, 'title' => 'Titre', 'hook' => 'Tu fais probablement cette erreur',
            'topic' => 'Sujet', 'reason' => 'Accroche forte', 'confidence' => 91, 'criteria' => self::CRITERIA,
        ], ...$overrides];
    }

    public function test_the_global_score_is_the_configured_weighted_average(): void
    {
        $scorer = app(ClipScorer::class);

        // 95×0,30 + 90×0,25 + 88×0,15 + 82×0,15 + 87×0,10 + 92×0,05 = 89,8
        $this->assertSame(89.8, $scorer->score(self::CRITERIA));
        $this->assertSame(0.0, $scorer->score(array_fill_keys(ClipScorer::CRITERIA, 0)));
        $this->assertSame(100.0, $scorer->score(array_fill_keys(ClipScorer::CRITERIA, 100)));
        $this->assertEqualsWithDelta(1.0, array_sum($scorer->weights()), 0.0001);

        // Poids modifiables par la seule configuration (normalisés : leur somme peut différer de 1).
        config(['video.clipper.scoring.weights' => ['hook' => 2, 'value' => 0, 'autonomy' => 0, 'emotion' => 0, 'rhythm' => 0, 'quality' => 0]]);
        $this->assertSame(95.0, app(ClipScorer::class)->score(self::CRITERIA), 'Seule l\'accroche compte.');
    }

    public function test_scorer_rejects_invalid_weights_and_criteria(): void
    {
        foreach ([['hook' => -1], array_fill_keys(ClipScorer::CRITERIA, 0)] as $weights) {
            try {
                new ClipScorer($weights);
                $this->fail('Poids invalides acceptés.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(InvalidArgumentException::class);
        app(ClipScorer::class)->score([...self::CRITERIA, 'hook' => 101]);
    }

    public function test_a_valid_ai_candidate_is_scored_by_the_backend_not_by_the_model(): void
    {
        $candidate = ClipCandidate::fromAi($this->raw(['score' => 3]), 300_000, app(ClipScorer::class));

        $this->assertNotNull($candidate);
        $this->assertSame(89.8, $candidate->score, 'Le score éventuel du modèle est ignoré.');
        $this->assertSame(30_000, $candidate->durationMs());
        $this->assertSame(91, $candidate->confidence);
        $this->assertSame(self::CRITERIA, $candidate->criteria);
        $this->assertSame('Tu fais probablement cette erreur', $candidate->toArray()['hook']);
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function invalidCandidates(): array
    {
        $criteria = self::CRITERIA;

        return [
            'début manquant' => [['start_ms' => null]],
            'timestamp texte' => [['start_ms' => '10000']],
            'timestamp négatif' => [['start_ms' => -1]],
            'fin avant début' => [['start_ms' => 40_000, 'end_ms' => 10_000]],
            'fin égale au début' => [['end_ms' => 10_000]],
            'plus de 60 s' => [['end_ms' => 10_000 + ClipLimits::MAX_CLIP_MS + 1]],
            'trop court' => [['end_ms' => 10_500]],
            'hors de la vidéo' => [['start_ms' => 400_000, 'end_ms' => 420_000]],
            'accroche manquante' => [['hook' => '']],
            'raison manquante' => [['reason' => null]],
            'confiance négative' => [['confidence' => -1]],
            'confiance > 100' => [['confidence' => 101]],
            'confiance texte' => [['confidence' => '90']],
            'critères manquants' => [['criteria' => null]],
            'critère absent' => [['criteria' => array_diff_key($criteria, ['rhythm' => 1])]],
            'critère < 0' => [['criteria' => [...$criteria, 'emotion' => -5]]],
            'critère > 100' => [['criteria' => [...$criteria, 'value' => 150]]],
        ];
    }

    #[DataProvider('invalidCandidates')]
    public function test_invalid_ai_candidates_never_enter_the_domain(array $overrides): void
    {
        $this->assertNull(ClipCandidate::fromAi($this->raw($overrides), 300_000, app(ClipScorer::class)));
    }

    public function test_the_analyzer_keeps_only_valid_deduplicated_ranked_candidates(): void
    {
        $analyzer = app(TranscriptAnalyzer::class);
        $reply = json_encode(['clips' => [
            $this->raw(['title' => 'Moins bon', 'criteria' => array_fill_keys(ClipScorer::CRITERIA, 60)]),
            $this->raw(['title' => 'Meilleur', 'start_ms' => 12_000, 'end_ms' => 41_000]),     // doublon mieux noté
            $this->raw(['title' => 'Distinct', 'start_ms' => 100_000, 'end_ms' => 130_000, 'criteria' => array_fill_keys(ClipScorer::CRITERIA, 70)]),
            $this->raw(['title' => 'Invalide', 'confidence' => 500]),
            'pas un objet',
        ]]);

        $clips = $analyzer->parse($reply, 300_000, 5);

        $this->assertSame(['Meilleur', 'Distinct'], array_column($clips, 'title'));
        $this->assertSame([89.8, 70.0], array_column($clips, 'score'));
        $this->assertSame(['Meilleur'], array_column($analyzer->parse($reply, 300_000, 1), 'title'), 'Limite du nombre de passages.');

        foreach (['', 'pas du JSON', '{"clips": "pas une liste"}', '{"clips": {"a": 1}}', '{"autre": []}', '[1,2,3]'] as $invalid) {
            $this->assertSame([], $analyzer->parse($invalid, 300_000, 5), "Réponse invalide : {$invalid}");
        }
    }

    public function test_deduplication_keeps_the_best_score_with_a_configurable_threshold(): void
    {
        $a = ['start_ms' => 0, 'end_ms' => 30_000, 'score' => 80];
        $b = ['start_ms' => 20_000, 'end_ms' => 50_000, 'score' => 90];   // chevauche a sur 10 s (1/3 du plus court)
        $c = ['start_ms' => 60_000, 'end_ms' => 90_000, 'score' => 50];

        $this->assertEqualsWithDelta(1 / 3, app(ClipDeduplicator::class)->overlapRatio($a, $b), 0.0001);
        $this->assertSame([$b, $a, $c], app(ClipDeduplicator::class)->dedupe([$a, $b, $c]), 'Sous le seuil (0,5) : distincts, triés par score.');

        config(['video.clipper.dedupe_overlap' => 0.2]);
        $this->assertSame([$b, $c], app(ClipDeduplicator::class)->dedupe([$a, $b, $c]), 'Seuil abaissé : doublons, le meilleur reste.');
        $this->assertSame([$b], app(ClipDeduplicator::class)->dedupe([$a, $b, $c], 1));
    }
}
