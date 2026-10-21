<?php

namespace App\Http\Responses\Auth;

use App\Http\Resources\UserResource;
use App\Support\Http\ApiResponse;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse;
use Symfony\Component\HttpFoundation\Response;

/** Connexion terminée par le code 2FA (ou un code de secours) : même enveloppe que la connexion simple. */
final class JsonTwoFactorLoginResponse implements TwoFactorLoginResponse
{
    public function toResponse($request): Response
    {
        return ApiResponse::ok(new UserResource($request->user()), 'Connexion réussie.');
    }
}
