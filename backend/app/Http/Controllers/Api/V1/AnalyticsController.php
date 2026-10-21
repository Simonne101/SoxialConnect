<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Analytics\AnalyticsQuery;
use App\Domain\Analytics\AnalyticsSyncService;
use App\Domain\Social\Enums\SocialAccountStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Analytics\AnalyticsIndexRequest;
use App\Models\SocialAccount;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Statistiques réelles des réseaux (étape 7, P1 — ADR-063). Lecture : uniquement ce qui a été collecté
 * (aucun chiffre simulé) avec l'état de collecte de chaque compte. Synchronisation manuelle : compte de
 * l'utilisateur, réseau doté d'un adaptateur, compte connecté.
 */
class AnalyticsController extends Controller
{
    public function index(AnalyticsIndexRequest $request, AnalyticsQuery $query): JsonResponse
    {
        $result = $query->forUser($request->user(), $request->validated());

        return ApiResponse::ok(['points' => $result['points'], 'posts' => $result['posts'], 'sync' => $result['sync']], meta: $result['meta']);
    }

    public function sync(Request $request, AnalyticsSyncService $sync): JsonResponse
    {
        $data = $request->validate(['social_account_id' => ['required', 'uuid']]);
        $account = SocialAccount::query()->with('platform')->findOrFail($data['social_account_id']);
        Gate::authorize('update', $account); // compte d'un autre utilisateur : 404

        if ($account->status !== SocialAccountStatus::Connected) {
            return ApiResponse::error('Ce compte n\'est plus connecté : reconnectez-le pour collecter ses statistiques.', 409, ['code' => ['ACCOUNT_NOT_CONNECTED']]);
        }
        if (! $sync->supports($account)) {
            return ApiResponse::error('La collecte des statistiques n\'est pas encore disponible pour ce réseau.', 409, ['code' => ['ANALYTICS_UNSUPPORTED']]);
        }

        return ApiResponse::ok(['dispatched' => $sync->dispatchManual($account)], 'Synchronisation des statistiques lancée.', status: 202);
    }
}
