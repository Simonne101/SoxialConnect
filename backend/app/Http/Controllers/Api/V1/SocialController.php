<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\SocialAccountResource;
use App\Http\Resources\SocialPlatformResource;
use App\Models\SocialAccount;
use App\Models\SocialPlatform;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Lecture seule en phase 2 : plateformes de référence et comptes de l'utilisateur.
 * Aucune connexion OAuth réelle ici (phase Social Connectors).
 */
class SocialController extends Controller
{
    public function platforms(): JsonResponse
    {
        return ApiResponse::ok(SocialPlatformResource::collection(SocialPlatform::query()->orderBy('name')->get())->resolve());
    }

    public function accounts(Request $request): JsonResponse
    {
        $accounts = SocialAccount::query()->ownedBy($request->user())->with('platform')->orderBy('created_at')->get();

        return ApiResponse::ok(SocialAccountResource::collection($accounts)->resolve());
    }
}
