<?php

namespace App\Domain\Social\Actions;

use App\Domain\Social\Enums\PermissionStatus;
use App\Domain\Social\Enums\SocialAccountStatus;
use App\Domain\Social\Enums\SocialAccountType;
use App\Domain\Social\Enums\TokenKind;
use App\Domain\Social\Enums\TokenStatus;
use App\Domain\Social\OAuth\InstagramOAuthProvider;
use App\Domain\Social\OAuth\OAuthException;
use App\Domain\Social\OAuth\OAuthTokens;
use App\Models\SocialAccount;
use App\Models\SocialPermission;
use App\Models\SocialPlatform;
use App\Models\SocialToken;
use App\Models\User;
use App\Services\Audit\AuditAction;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Enregistre (ou reconnecte) le compte Instagram professionnel autorisé : un compte par identifiant
 * Instagram et par utilisateur, type « business » ou « creator », un seul jeton actif (chiffré,
 * 60 jours, renouvelé par InstagramTokenManager) et les permissions réellement accordées.
 * Un compte personnel est refusé : l'API Instagram ne permet de publier que sur un compte professionnel.
 */
final class ConnectInstagramAccount
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{user_id: string, username: string, name: string, account_type: string, picture: ?string}  $profile
     *
     * @throws OAuthException compte personnel
     */
    public function handle(User $user, OAuthTokens $tokens, array $profile): SocialAccount
    {
        if (! in_array($profile['account_type'], InstagramOAuthProvider::PROFESSIONAL_TYPES, true)) {
            throw new OAuthException('NOT_PROFESSIONAL_ACCOUNT', 'Ce compte Instagram est un compte personnel. Passez-le en compte professionnel (Business ou Créateur) dans les paramètres d\'Instagram, puis reconnectez-le.');
        }
        $platform = SocialPlatform::query()->where('key', InstagramOAuthProvider::PROVIDER)->firstOrFail();

        [$account, $reconnected] = DB::transaction(function () use ($user, $tokens, $profile, $platform) {
            $account = SocialAccount::withTrashed()
                ->where('user_id', $user->getKey())
                ->where('social_platform_id', $platform->getKey())
                ->where('external_account_id', $profile['user_id'])
                ->lockForUpdate()
                ->first();
            $reconnected = $account !== null;

            $account ??= new SocialAccount(['social_platform_id' => $platform->getKey(), 'external_account_id' => $profile['user_id']]);
            $account->user_id = $user->getKey();
            $account->fill([
                'account_type' => $profile['account_type'] === 'MEDIA_CREATOR' ? SocialAccountType::Creator : SocialAccountType::Business,
                'display_name' => $profile['name'],
                'username' => $profile['username'] !== '' ? '@'.$profile['username'] : null,
                'metadata' => array_filter([
                    'provider' => InstagramOAuthProvider::PROVIDER,
                    'instagram_account_type' => $profile['account_type'],
                    'picture_url' => $profile['picture'],
                ]),
            ]);
            $account->forceFill([
                'status' => SocialAccountStatus::Connected,
                'status_reason' => null,
                'connected_at' => now(),
                'last_synced_at' => now(),
                'last_error_at' => null,
                'deleted_at' => null,
            ])->save();

            // Un seul jeton actif par compte : l'ancien est révoqué localement.
            SocialToken::query()
                ->where('social_account_id', $account->getKey())
                ->whereIn('status', [TokenStatus::Active->value, TokenStatus::Expired->value, TokenStatus::Invalid->value])
                ->update(['status' => TokenStatus::Revoked->value]);
            SocialToken::create([
                'social_account_id' => $account->getKey(),
                'token_kind' => TokenKind::User,
                'access_token_encrypted' => $tokens->accessToken,
                'refresh_token_encrypted' => null,
                'expires_at' => $tokens->expiresAt,
                'refresh_expires_at' => null,
                'scopes' => $tokens->scopes,
                'token_type' => 'Bearer',
                'status' => TokenStatus::Active,
            ]);

            foreach (InstagramOAuthProvider::SCOPES as $scope) {
                SocialPermission::query()->updateOrCreate(
                    ['social_account_id' => $account->getKey(), 'scope' => $scope],
                    [
                        'status' => in_array($scope, $tokens->scopes, true) ? PermissionStatus::Granted : PermissionStatus::Missing,
                        'is_required' => in_array($scope, InstagramOAuthProvider::REQUIRED_SCOPES, true),
                        'checked_at' => now(),
                    ],
                );
            }

            return [$account, $reconnected];
        });

        $this->audit->record($reconnected ? AuditAction::OauthReconnected : AuditAction::OauthConnected, $user, $account, ['platform' => 'instagram', 'scopes' => $tokens->scopes]);

        return $account->fresh('platform');
    }
}
