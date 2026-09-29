<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Users\UpdateMeRequest;
use App\Http\Resources\UserResource;
use App\Services\Audit\AuditAction;
use App\Services\Audit\AuditLogger;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Utilisateur courant : GET/PATCH /api/v1/users/me. */
class MeController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return ApiResponse::ok(new UserResource($request->user()));
    }

    public function update(UpdateMeRequest $request, AuditLogger $audit): JsonResponse
    {
        $user = $request->user();
        $user->fill($request->validated())->save();
        $audit->record(AuditAction::ProfileUpdated, $user, $user, ['fields' => array_keys($request->validated())]);

        return ApiResponse::ok(new UserResource($user), 'Profil enregistré.');
    }
}
