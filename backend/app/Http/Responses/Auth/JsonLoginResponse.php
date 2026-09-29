<?php

namespace App\Http\Responses\Auth;

use App\Http\Resources\UserResource;
use App\Support\Http\ApiResponse;
use Laravel\Fortify\Contracts\LoginResponse;
use Symfony\Component\HttpFoundation\Response;

/** Réponse JSON de Fortify après connexion (enveloppe data/message/meta). */
final class JsonLoginResponse implements LoginResponse
{
    public function toResponse($request): Response
    {
        return ApiResponse::ok(new UserResource($request->user()), 'Connexion réussie.');
    }
}
