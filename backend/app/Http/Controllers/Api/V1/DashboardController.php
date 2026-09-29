<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Content\Enums\PostStatus;
use App\Domain\Social\Enums\SocialAccountStatus;
use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Indicateurs du tableau de bord calculés depuis la base. Les métriques d'audience
 * (impressions, portée…) restent null tant que la synchronisation Analytics n'existe pas :
 * « non disponible », jamais 0 (ADR-017).
 */
class DashboardController extends Controller
{
    public function summary(Request $request): JsonResponse
    {
        $range = $request->validate(['range' => ['sometimes', Rule::in(['today', '7d', '30d'])]])['range'] ?? '7d';
        $days = ['today' => 1, '7d' => 7, '30d' => 30][$range];
        $user = $request->user();

        $posts = Post::query()->ownedBy($user);

        return ApiResponse::ok([
            'range' => $range,
            'published_count' => (clone $posts)->whereIn('status', [PostStatus::Published, PostStatus::PartiallyPublished])
                ->where('published_at', '>=', now()->subDays($days))->count(),
            'scheduled_count' => (clone $posts)->where('status', PostStatus::Scheduled)->count(),
            'draft_count' => (clone $posts)->where('status', PostStatus::Draft)->count(),
            'failed_count' => (clone $posts)->whereIn('status', [PostStatus::Failed, PostStatus::PartiallyPublished])->count(),
            'connected_accounts' => SocialAccount::query()->ownedBy($user)->where('status', SocialAccountStatus::Connected)->count(),
            'impressions' => null,
            'reach' => null,
            'views' => null,
            'engagement_rate' => null,
        ]);
    }
}
