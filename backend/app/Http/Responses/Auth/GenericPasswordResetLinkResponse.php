<?php

namespace App\Http\Responses\Auth;

use App\Support\Http\ApiResponse;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * « Mot de passe oublié » : réponse identique que le compte existe ou non, et même en cas de
 * limitation — aucune énumération d'adresses e-mail (docs/security.md §3).
 */
final class GenericPasswordResetLinkResponse implements FailedPasswordResetLinkRequestResponse, SuccessfulPasswordResetLinkRequestResponse
{
    public function __construct(public readonly string $status = '') {}

    public function toResponse($request): Response
    {
        return ApiResponse::ok(
            null,
            'Si un compte correspond à cette adresse, vous allez recevoir un e-mail avec un lien de réinitialisation.',
        );
    }
}
