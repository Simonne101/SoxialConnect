<?php

namespace App\Domain\Social\Actions;

use App\Domain\Social\Enums\PermissionStatus;
use App\Domain\Social\Enums\SocialAccountStatus;
use App\Domain\Social\Enums\SocialAccountType;
use App\Domain\Social\Enums\TokenKind;
use App\Domain\Social\Enums\TokenStatus;
use App\Domain\Social\OAuth\OAuthTokens;
use App\Domain\Social\OAuth\XOAuthProvider;
use App\Models\SocialAccount;
use App\Models\SocialPermission;
use App\Models\SocialPlatform;
use App\Models\SocialToken;
use App\Models\User;
use App\Services\Audit\AuditAction;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Enregistre (ou reconnecte) le compte X autorisé : un compte par identifiant X et par
 * utilisateur, type « profile », un seul token actif (chiffré), permissions réellement accordées.
 * Même modèle que ConnectLinkedInAccount.
 */
final class ConnectXAccount
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @param array{id: string, username: string, name: string, picture: ?string} $profile */
    public function handle(User $user, OAuthTokens $tokens, array $profile): SocialAccount
    {
        $platform = SocialPlatform::query()->where('key', XOAuthProvider::PROVIDER)->firstOrFail();

        [$account, $reconnected] = DB::transaction(function () use ($user, $tokens, $profile, $platform) {
            $account = SocialAccount::withTrashed()
                ->where('user_id', $user->getKey())
                ->where('social_platform_id', $platform->getKey())
                ->where('external_account_id', $profile['id'])
                ->lockForUpdate()
                ->first();
            $reconnected = $account !== null;
            $previous = $account ? SocialToken::query()
                ->where('social_account_id', $account->getKey())
                ->where('token_kind', TokenKind::User->value)
                ->where('status', TokenStatus::Active->value)
                ->first() : null;

            $account ??= new SocialAccount(['social_platform_id' => $platform->getKey(), 'external_account_id' => $profile['id']]);
            $account->user_id = $user->getKey();
            $account->fill([
                'account_type' => SocialAccountType::Profile,
                'display_name' => $profile['name'],
                'username' => '@'.$profile['username'],
                'metadata' => array_filter([
                    'provider' => XOAuthProvider::PROVIDER,
                    'username' => $profile['username'],
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

            // Ancien token remplacé (révoqué localement) : un seul token actif par compte.
            $previous?->forceFill(['status' => TokenStatus::Revoked])->save();
            SocialToken::query()
                ->where('social_account_id', $account->getKey())
                ->whereIn('status', [TokenStatus::Expired->value, TokenStatus::Invalid->value])
                ->update(['status' => TokenStatus::Revoked->value]);
            SocialToken::create([
                'social_account_id' => $account->getKey(),
                'token_kind' => TokenKind::User,
                'access_token_encrypted' => $tokens->accessToken,
                'refresh_token_encrypted' => $tokens->refreshToken,
                'expires_at' => $tokens->expiresAt,
                'refresh_expires_at' => $tokens->refreshExpiresAt,
                'scopes' => $tokens->scopes,
                'token_type' => $tokens->tokenType,
                'status' => TokenStatus::Active,
            ]);

            foreach (XOAuthProvider::SCOPES as $scope) {
                SocialPermission::query()->updateOrCreate(
                    ['social_account_id' => $account->getKey(), 'scope' => $scope],
                    [
                        'status' => in_array($scope, $tokens->scopes, true) ? PermissionStatus::Granted : PermissionStatus::Missing,
                        'is_required' => true,
                        'checked_at' => now(),
                    ],
                );
            }

            return [$account, $reconnected];
        });

        $this->audit->record($reconnected ? AuditAction::OauthReconnected : AuditAction::OauthConnected, $user, $account, ['platform' => 'x', 'scopes' => $tokens->scopes]);

        return $account->fresh('platform');
    }
}
