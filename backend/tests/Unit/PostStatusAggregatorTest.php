<?php

namespace Tests\Unit;

use App\Domain\Content\Enums\PostStatus;
use App\Domain\Content\Enums\VariantStatus as V;
use App\Domain\Publishing\PostStatusAggregator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Règles d'agrégation du statut d'une publication (docs/database.md §3.3). */
class PostStatusAggregatorTest extends TestCase
{
    public static function cases(): array
    {
        return [
            'aucune variante → repli' => [[], PostStatus::Draft],
            'tout brouillon' => [[V::Draft, V::Draft], PostStatus::Draft],
            'programmé' => [[V::Scheduled, V::Scheduled], PostStatus::Scheduled],
            'en cours (file)' => [[V::Queued, V::Scheduled], PostStatus::Processing],
            'en cours (publication)' => [[V::Publishing, V::Published], PostStatus::Processing],
            'tout publié' => [[V::Published, V::Published], PostStatus::Published],
            'publié + échec' => [[V::Published, V::Failed], PostStatus::PartiallyPublished],
            'tout en échec' => [[V::Failed, V::Failed], PostStatus::Failed],
            'tout annulé' => [[V::Cancelled], PostStatus::Cancelled],
            'publié + annulé' => [[V::Published, V::Cancelled], PostStatus::PartiallyPublished],
            'échec + annulé' => [[V::Failed, V::Cancelled], PostStatus::Failed],
        ];
    }

    #[DataProvider('cases')]
    public function test_aggregate(array $variants, PostStatus $expected): void
    {
        $this->assertSame($expected, PostStatusAggregator::aggregate($variants));
    }
}
