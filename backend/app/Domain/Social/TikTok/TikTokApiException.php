<?php

namespace App\Domain\Social\TikTok;

use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * Erreur normalisée de la Content Posting API TikTok (format { data, error: { code, message,
 * log_id } }). Seul le code d'erreur TikTok est conservé : jamais le corps brut ni de token.
 */
final class TikTokApiException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        /** Erreur passagère : une nouvelle tentative a un sens (limite de débit, indisponibilité). */
        public readonly bool $transient = false,
        public readonly ?int $httpStatus = null,
        /** Jeton refusé : un renouvellement forcé puis un seul nouvel essai sont permis. */
        public readonly bool $tokenInvalid = false,
    ) {
        parent::__construct($message);
    }

    /** Erreur contenue dans une réponse TikTok, ou null si la réponse est un succès (« ok »). */
    public static function fromResponse(Response $response): ?self
    {
        $code = (string) ($response->json('error.code') ?? '');
        if ($response->successful() && ($code === '' || $code === 'ok')) {
            return null;
        }
        $status = $response->status();
        $nothing = ' Rien n\'a été publié.';

        return match (true) {
            $code === 'access_token_invalid' || $status === 401 => new self('TOKEN_INVALID', 'TikTok a refusé l\'autorisation. Reconnectez le compte puis réessayez.'.$nothing, httpStatus: $status, tokenInvalid: true),
            $code === 'scope_not_authorized' => new self('SCOPE_NOT_AUTHORIZED', 'La publication n\'est pas autorisée pour ce compte TikTok. Reconnectez-le en acceptant la publication de vidéos.'.$nothing, httpStatus: $status),
            $code === 'rate_limit_exceeded' || $status === 429 => new self('RATE_LIMITED', 'Trop de requêtes vers TikTok. Nouvel essai automatique.'.$nothing, true, $status),
            $code === 'spam_risk_too_many_posts' => new self('DAILY_POST_LIMIT', 'Limite quotidienne de publications TikTok atteinte pour ce compte. Réessayez demain.'.$nothing, httpStatus: $status),
            $code === 'spam_risk_user_banned_from_posting' => new self('POSTING_BANNED', 'TikTok n\'autorise pas ce compte à publier pour le moment.'.$nothing, httpStatus: $status),
            $code === 'reached_active_user_cap' => new self('ACTIVE_USER_CAP', 'Limite quotidienne d\'utilisateurs de l\'application TikTok atteinte (application non auditée : 5 utilisateurs par 24 h). Réessayez plus tard.'.$nothing, httpStatus: $status),
            $code === 'unaudited_client_can_only_post_to_private_accounts' => new self('PRIVATE_ONLY', 'Tant que l\'application n\'est pas auditée par TikTok, seules les publications privées (« Moi uniquement ») sont possibles.'.$nothing, httpStatus: $status),
            $code === 'privacy_level_option_mismatch' => new self('PRIVACY_NOT_ALLOWED', 'Cette confidentialité n\'est pas disponible pour ce compte TikTok.'.$nothing, httpStatus: $status),
            str_starts_with($code, 'invalid_param') || $status === 400 => new self('INVALID_REQUEST', 'TikTok a refusé les informations de la publication.'.$nothing, httpStatus: $status),
            $code === 'internal_error' || $status >= 500 => new self('PLATFORM_UNAVAILABLE', 'TikTok rencontre un problème temporaire. Nouvel essai automatique.'.$nothing, true, $status),
            default => new self('HTTP_ERROR', "TikTok a répondu par une erreur ({$status}).".$nothing, httpStatus: $status),
        };
    }
}
