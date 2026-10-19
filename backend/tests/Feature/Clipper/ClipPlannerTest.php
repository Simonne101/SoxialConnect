<?php

namespace Tests\Feature\Clipper;

use App\Domain\Video\Clipper\ClipLimits;
use App\Domain\Video\Clipper\Planning\ClipPlanner;
use App\Domain\Video\Enums\AspectRatio;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Planificateur (ADR-051) : passage → RenderPlan, indépendant de tout moteur de rendu. Logique pure.
 */
class ClipPlannerTest extends TestCase
{
    /** Transcription : deux segments, le second avec mots horodatés. */
    private function segments(): array
    {
        return [
            ['start_ms' => 180_000, 'end_ms' => 186_000, 'text' => 'Avant le passage.'],
            ['start_ms' => 186_000, 'end_ms' => 196_000, 'text' => 'Tu fais probablement cette erreur sans le savoir chaque jour.', 'words' => [
                [186_000, 186_400, 'Tu'], [186_400, 186_900, 'fais'], [186_900, 187_800, 'probablement'], [187_800, 188_200, 'cette'],
                [188_200, 188_900, 'erreur'], [188_900, 189_300, 'sans'], [189_300, 189_500, 'le'], [189_500, 190_100, 'savoir'],
                [190_100, 190_800, 'chaque'], [190_800, 191_400, 'jour.'],
            ]],
            ['start_ms' => 250_000, 'end_ms' => 260_000, 'text' => 'Après le passage.'],
        ];
    }

    public function test_a_candidate_becomes_a_vertical_render_plan(): void
    {
        $plan = app(ClipPlanner::class)->plan(['start_ms' => 185_200, 'end_ms' => 243_500, 'hook' => 'Tu fais probablement cette erreur'], AspectRatio::Vertical, $this->segments());

        $this->assertSame([185_200, 243_500, 58_300], [$plan->sourceStartMs, $plan->sourceEndMs, $plan->durationMs()]);
        $this->assertSame([1080, 1920, 30, 'vertical'], [$plan->width, $plan->height, $plan->fps, $plan->format]);
        $this->assertSame(['clean-short', 'center'], [$plan->template, $plan->crop]);
        $this->assertSame('Tu fais probablement cette erreur', $plan->hook);
        $this->assertTrue($plan->subtitles);
        $this->assertSame(1749, $plan->durationInFrames());

        // Légendes relatives au clip, ≤ 42 caractères, jamais coupées au milieu d'un mot.
        $captions = $plan->captions;
        $this->assertSame(['start_ms' => 0, 'end_ms' => 800, 'text' => 'Avant le passage.'], $captions[0]);
        $this->assertSame(800, $captions[1]['start_ms']);
        foreach (array_slice($captions, 1) as $caption) {
            $this->assertLessThanOrEqual(42, mb_strlen($caption['text']));
            $this->assertLessThan($caption['end_ms'], $caption['start_ms']);
        }
        $this->assertSame('Tu fais probablement cette erreur sans le savoir chaque jour.', implode(' ', array_column(array_slice($captions, 1), 'text')));

        $array = $plan->toArray();
        $this->assertSame('soxial.clip_render_plan.v1', $array['schema']);
        $this->assertSame(3_000, $array['hook_duration_ms']);
    }

    public function test_formats_hook_and_subtitles_are_options_of_the_plan(): void
    {
        $planner = app(ClipPlanner::class);
        $candidate = ['start_ms' => 0, 'end_ms' => 20_000, 'hook' => 'Accroche'];

        $square = $planner->plan($candidate, AspectRatio::Square, $this->segments(), subtitles: false, showHook: false);
        $this->assertSame([1080, 1080, 'square'], [$square->width, $square->height, $square->format]);
        $this->assertNull($square->hook);
        $this->assertFalse($square->subtitles);
        $this->assertSame([], $square->toArray()['captions']);

        $landscape = $planner->plan($candidate, AspectRatio::Landscape, []);
        $this->assertSame([1920, 1080, 'landscape'], [$landscape->width, $landscape->height, $landscape->format]);
        $this->assertFalse($landscape->subtitles, 'Sans transcription : aucun sous-titre annoncé.');
        $this->assertSame(3_000, $planner->plan(['start_ms' => 0, 'end_ms' => 5_000], AspectRatio::Vertical)->hookDurationMs);
        config(['video.clipper.min_clip_ms' => 1_000]);
        $this->assertSame(2_000, $planner->plan(['start_ms' => 0, 'end_ms' => 2_000], AspectRatio::Vertical)->hookDurationMs, 'Accroche jamais plus longue que le clip.');
    }

    public function test_the_plan_never_exceeds_sixty_seconds_nor_accepts_an_unknown_preset(): void
    {
        $planner = app(ClipPlanner::class);
        $this->assertSame(ClipLimits::MAX_CLIP_MS, $planner->plan(['start_ms' => 0, 'end_ms' => ClipLimits::MAX_CLIP_MS], AspectRatio::Vertical)->durationMs());

        foreach ([['start_ms' => 0, 'end_ms' => ClipLimits::MAX_CLIP_MS + 1], ['start_ms' => 5_000, 'end_ms' => 5_000], ['start_ms' => -1, 'end_ms' => 9_000]] as $invalid) {
            try {
                $planner->plan($invalid, AspectRatio::Vertical);
                $this->fail('Passage invalide planifié.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(InvalidArgumentException::class);
        $planner->plan(['start_ms' => 0, 'end_ms' => 10_000], AspectRatio::Vertical, preset: 'tiktok');
    }
}
