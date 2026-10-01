<?php

namespace App\Domain\Social\Actions;

use App\Domain\Social\Enums\PermissionStatus;
use App\Domain\Social\Enums\SocialAccountStatus;
use App\Domain\Social\Enums\SocialAccountType;
use App\Domain\Social\Enums\TokenKind;
use App\Domain\Social\Enums\TokenStatus;
use App\Domain\Social\OAuth\OAuthTokens;
use App\Domain\Social\OAuth\TikTokOAuthProvider;
use App\Models\SocialAccount;
use App\Models\SocialPermission;
use App\Models\SocialPlatform;
use App\Models\SocialToken;
use App\Models\User;
use App\Services\Audit\AuditAction;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Enregistre (ou reconnecte) le compte TikTok autorisé : un seul compte par open_id et par
 * utilisateur, un seul token actif (chiffré), permissions réellement accordées.
 */
final class ConnectTikTokAccount
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @param array{open_id: string, display_name: string} $profile */
    public function handle(User $user, OAuthTokens $tokens, array $profile): SocialAccount
    {
        $platform = SocialPlatform::query()->where('key', TikTokOAuthProvider::PROVIDER)->firstOrFail();

        [$account, $reconnected] = DB::transaction(function () use ($user, $tokens, $profile, $platform) {
            $account = SocialAccount::withTrashed()
                ->where('user_id', $user->getKey())
                ->where('social_platform_id', $platform->getKey())
                ->where('external_account_id', $profile['open_id'])
                ->lockForUpdate()
                ->first();
            $reconnected = $account !== null;
            $previousToken = $account ? SocialToken::query()
                ->where('social_account_id', $account->getKey())
                ->where('token_kind', TokenKind::User->value)
                ->where('status', TokenStatus::Active->value)
                ->first() : null;

            $account ??= new SocialAccount(['social_platform_id' => $platform->getKey(), 'external_account_id' => $profile['open_id']]);
            $account->user_id = $user->getKey();
            $account->fill([
                'account_type' => SocialAccountType::Creator,
                'display_name' => $profile['display_name'],
                'metadata' => ['provider' => 'tiktok'],
            ]);
            $account->forceFill([
                'status' => SocialAccountStatus::Connected,
                'status_reason' => null,
                'connected_at' => now(),
                'last_synced_at' => now(),
                'deleted_at' => null,
            ])->save();

            $previousToken?->forceFill(['status' => TokenStatus::Revoked])->save();
            SocialToken::create([
                'social_account_id' => $account->getKey(),
                'token_kind' => TokenKind::User,
                'access_token_encrypted' => $tokens->accessToken,
                'refresh_token_encrypted' => $tokens->refreshToken ?? $previousToken?->refresh_token_encrypted,
                'expires_at' => $tokens->expiresAt,
                'refresh_expires_at' => $tokens->refreshExpiresAt,
                'scopes' => $tokens->scopes,
                'token_type' => $tokens->tokenType,
                'status' => TokenStatus::Active,
            ]);

            foreach (TikTokOAuthProvider::SCOPES as $scope) {
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

        $this->audit->record($reconnected ? AuditAction::OauthReconnected : AuditAction::OauthConnected, $user, $account, ['platform' => 'tiktok', 'scopes' => $tokens->scopes]);

        return $account->fresh('platform');
    }
}
