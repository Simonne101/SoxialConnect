<?php

namespace App\Domain\Social\Actions;

use App\Domain\Social\Enums\SocialAccountStatus;
use App\Domain\Social\Enums\TokenStatus;
use App\Domain\Social\OAuth\TikTokOAuthProvider;
use App\Domain\Social\OAuth\XOAuthProvider;
use App\Domain\Social\OAuth\YouTubeOAuthProvider;
use App\Models\SocialAccount;
use App\Models\SocialToken;
use App\Models\User;
use App\Services\Audit\AuditAction;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Déconnexion d'un compte social (docs/security.md §5) : révocation distante quand l'API le
 * permet (au mieux), puis effacement des tokens chiffrés. Le compte est conservé en statut
 * « revoked » : ses publications et leur historique restent consultables.
 */
final class DisconnectSocialAccount
{
    public function __construct(
        private readonly YouTubeOAuthProvider $youtube,
        private readonly TikTokOAuthProvider $tiktok,
        private readonly XOAuthProvider $x,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(User $user, SocialAccount $account): SocialAccount
    {
        $tokens = SocialToken::query()->where('social_account_id', $account->getKey())->get();

        if ($account->platform?->key === YouTubeOAuthProvider::PROVIDER && $this->youtube->isConfigured()) {
            // Révoquer le refresh token révoque aussi les jetons d'accès associés (Google).
            $token = $tokens->first(fn (SocialToken $t) => $t->status === TokenStatus::Active);
            $secret = $token?->refresh_token_encrypted ?? $token?->access_token_encrypted;
            if ($secret !== null) {
                $this->youtube->revoke($secret);
            }
        }
        if ($account->platform?->key === TikTokOAuthProvider::PROVIDER && $this->tiktok->isConfigured()) {
            // TikTok révoque l'autorisation à partir du jeton d'accès. Échec de la révocation
            // (réseau, jeton déjà expiré) : la déconnexion locale a lieu quand même et les tokens
            // sont effacés ; l'utilisateur peut aussi retirer l'accès depuis l'app TikTok.
            $token = $tokens->first(fn (SocialToken $t) => $t->status === TokenStatus::Active);
            if ($token !== null) {
                $this->tiktok->revoke($token->access_token_encrypted);
            }
        }

        if ($account->platform?->key === XOAuthProvider::PROVIDER && $this->x->isConfigured()) {
            // X révoque chaque jeton séparément (POST /2/oauth2/revoke) : refresh token puis access
            // token, au mieux. Un échec (réseau, jeton déjà expiré) n'empêche pas la déconnexion
            // locale ; l'utilisateur peut aussi retirer l'accès depuis ses paramètres X.
            $token = $tokens->first(fn (SocialToken $t) => $t->status === TokenStatus::Active);
            if ($token?->refresh_token_encrypted !== null) {
                $this->x->revoke($token->refresh_token_encrypted, 'refresh_token');
            }
            if ($token !== null) {
                $this->x->revoke($token->access_token_encrypted, 'access_token');
            }
        }

        DB::transaction(function () use ($account) {
            SocialToken::query()->where('social_account_id', $account->getKey())->delete();
            $account->forceFill([
                'status' => SocialAccountStatus::Revoked,
                'status_reason' => 'Compte déconnecté de SoxialConnect.',
            ])->save();
        });

        $this->audit->record(AuditAction::OauthRevoked, $user, $account, ['platform' => $account->platform?->key]);

        return $account->fresh('platform');
    }
}
