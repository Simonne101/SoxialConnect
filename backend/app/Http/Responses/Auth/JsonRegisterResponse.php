<?php

namespace App\Http\Responses\Auth;

use App\Http\Resources\UserResource;
use App\Support\Http\ApiResponse;
use Laravel\Fortify\Contracts\RegisterResponse;
use Symfony\Component\HttpFoundation\Response;

/** Réponse JSON de Fortify après inscription (201). */
final class JsonRegisterResponse implements RegisterResponse
{
    public function toResponse($request): Response
    {
        return ApiResponse::created(
            new UserResource($request->user()),
            'Compte créé. Un e-mail de vérification vous a été envoyé.',
        );
    }
}
