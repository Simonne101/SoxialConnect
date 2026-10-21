<?php

namespace Tests\Feature\Clipper;

use App\Domain\Video\Clipper\ClipLimits;
use App\Domain\Video\Clipper\TranscriptAnalyzer;
use App\Domain\Video\Clipper\YouTubeUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Règles du YouTube Clipper (ADR-046) : limite de 60 s, liens YouTube, revalidation de l'IA. */
class ClipRulesTest extends TestCase
{
    public function test_clips_up_to_sixty_seconds_are_accepted_and_longer_ones_refused(): void
    {
        $this->assertNull(ClipLimits::violation(130_000, 175_000), '45 s');
        $this->assertNull(ClipLimits::violation(0, 30_000), '30 s');
        $this->assertNull(ClipLimits::violation(320_000, 380_000), '60 s exactement');
        $this->assertSame('Un clip ne peut pas dépasser 60 secondes.', ClipLimits::violation(0, 61_000), '61 s');
        $this->assertSame('Un clip ne peut pas dépasser 60 secondes.', ClipLimits::violation(600_000, 665_000), '65 s');
        $this->assertSame('Un clip ne peut pas dépasser 60 secondes.', ClipLimits::violation(0, 120_000), '120 s');
        $this->assertSame('Un clip ne peut pas dépasser 60 secondes.', ClipLimits::violation(0, 60_001), '60,001 s');
        $this->assertNotNull(ClipLimits::violation(10_000, 10_000), 'fin = début');
        $this->assertNotNull(ClipLimits::violation(10_000, 5_000), 'fin avant début');
        $this->assertNotNull(ClipLimits::violation(0, 1_000), 'plus court que le minimum');
        $this->assertSame('Le clip dépasse la fin de la vidéo source.', ClipLimits::violation(80_000, 100_000, 90_000));
        $this->assertSame(60_000, ClipLimits::MAX_CLIP_MS);
    }

    public function test_a_longer_passage_is_bounded_to_sixty_seconds(): void
    {
        $this->assertSame([600_000, 660_000], ClipLimits::bound(600_000, 665_000), '65 s → 60 s');
        $this->assertSame([0, 60_000], ClipLimits::bound(0, 120_000), '120 s → 60 s');
        $this->assertSame([80_000, 90_000], ClipLimits::bound(80_000, 130_000, 90_000), 'borné à la fin de la source');
        $this->assertNull(ClipLimits::bound(89_000, 95_000, 90_000), 'trop court une fois borné');
    }

    #[DataProvider('validUrls')]
    public function test_valid_youtube_links_are_normalized(string $input): void
    {
        $this->assertSame('https://www.youtube.com/watch?v=dQw4w9WgXcQ', YouTubeUrl::parse($input)?->canonical());
    }

    /** @return array<string, array{0: string}> */
    public static function validUrls(): array
    {
        return [
            'watch' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ'],
            'watch + paramètres' => ['https://youtube.com/watch?v=dQw4w9WgXcQ&t=42s&list=PL123'],
            'mobile' => ['https://m.youtube.com/watch?v=dQw4w9WgXcQ'],
            'court' => ['https://youtu.be/dQw4w9WgXcQ'],
            'shorts' => ['https://www.youtube.com/shorts/dQw4w9WgXcQ'],
            'live' => ['https://www.youtube.com/live/dQw4w9WgXcQ'],
            'embed' => ['https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ'],
            'espaces' => ['  https://www.youtube.com/watch?v=dQw4w9WgXcQ  '],
            // Liens de partage réels (application mobile, bouton « Partager »).
            'court + partage' => ['https://youtu.be/dQw4w9WgXcQ?si=AbCdEf123_-x'],
            'court + horodatage' => ['https://youtu.be/dQw4w9WgXcQ?t=42'],
            'shorts + partage' => ['https://youtube.com/shorts/dQw4w9WgXcQ?si=AbCdEf123_-x'],
            'shorts + feature' => ['https://www.youtube.com/shorts/dQw4w9WgXcQ?feature=share'],
            'shorts mobile' => ['https://m.youtube.com/shorts/dQw4w9WgXcQ'],
            'v après un autre paramètre' => ['https://www.youtube.com/watch?feature=shared&v=dQw4w9WgXcQ'],
            'music' => ['https://music.youtube.com/watch?v=dQw4w9WgXcQ&si=xyz'],
            'live + partage' => ['https://www.youtube.com/live/dQw4w9WgXcQ?si=xyz'],
        ];
    }

    #[DataProvider('invalidUrls')]
    public function test_invalid_links_are_refused(string $input): void
    {
        $this->assertNull(YouTubeUrl::parse($input));
    }

    /** @return array<string, array{0: string}> */
    public static function invalidUrls(): array
    {
        return [
            'vide' => [''],
            'texte' => ['pas une url'],
            'http' => ['http://www.youtube.com/watch?v=dQw4w9WgXcQ'],
            'autre domaine' => ['https://www.youtube.com.evil.test/watch?v=dQw4w9WgXcQ'],
            'sous-domaine piégé' => ['https://youtube.com@evil.test/watch?v=dQw4w9WgXcQ'],
            'port' => ['https://www.youtube.com:8443/watch?v=dQw4w9WgXcQ'],
            'chaîne' => ['https://www.youtube.com/@SoxialConnect'],
            'playlist seule' => ['https://www.youtube.com/playlist?list=PL123'],
            'identifiant trop court' => ['https://youtu.be/abc'],
            'identifiant invalide' => ['https://www.youtube.com/watch?v=dQw4w9WgX$Q'],
            'injection' => ['https://youtu.be/dQw4w9WgXcQ;rm -rf'],
            'fichier local' => ['file:///etc/passwd'],
        ];
    }

    public function test_ai_suggestions_are_revalidated_bounded_and_deduplicated(): void
    {
        $analyzer = app(TranscriptAnalyzer::class);
        $clip = fn (mixed $start, mixed $end, string $title, int $hook) => ['start_ms' => $start, 'end_ms' => $end, 'title' => $title, 'hook' => 'Accroche', 'topic' => 'Sujet', 'reason' => 'Raison', 'confidence' => 80,
            'criteria' => ['hook' => $hook, 'value' => 80, 'autonomy' => 80, 'emotion' => 80, 'rhythm' => 80, 'quality' => 80]];
        $reply = "```json\n".json_encode(['clips' => [
            $clip(10_000, 40_000, 'Bon passage', 95),
            $clip(100_000, 160_000, 'Soixante secondes', 85),
            $clip(200_000, 275_000, 'Trop long', 99),   // 75 s : rejeté (jamais raccourci au milieu d'une idée)
            $clip(15_000, 42_000, 'Doublon', 70),       // chevauche le premier
            $clip(310_000, 340_000, 'Hors vidéo', 99),  // commence après la fin de la source
            $clip(50_000, 50_500, 'Trop court', 60),
            $clip('x', 2, 'Invalide', 60),
        ]])."\n```";

        $clips = $analyzer->parse($reply, 300_000, 5);

        $this->assertCount(2, $clips);
        $this->assertSame(['Bon passage', 'Soixante secondes'], array_column($clips, 'title'));
        $this->assertSame([100_000, 160_000], [$clips[1]['start_ms'], $clips[1]['end_ms']]);
        foreach ($clips as $clip) {
            $this->assertLessThanOrEqual(ClipLimits::MAX_CLIP_MS, $clip['end_ms'] - $clip['start_ms']);
        }
        $this->assertSame([], $analyzer->parse('Désolé, je ne peux pas.', 300_000, 5), 'Réponse non JSON : aucune proposition.');
    }
}
