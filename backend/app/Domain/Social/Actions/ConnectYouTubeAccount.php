<?php

namespace App\Domain\Social\Actions;

use App\Domain\Social\Enums\PermissionStatus;
use App\Domain\Social\Enums\SocialAccountStatus;
use App\Domain\Social\Enums\SocialAccountType;
use App\Domain\Social\Enums\TokenKind;
use App\Domain\Social\Enums\TokenStatus;
use App\Domain\Social\OAuth\OAuthTokens;
use App\Domain\Social\OAuth\YouTubeOAuthProvider;
use App\Models\SocialAccount;
use App\Models\SocialPermission;
use App\Models\SocialPlatform;
use App\Models\SocialToken;
use App\Models\User;
use App\Services\Audit\AuditAction;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Enregistre (ou reconnecte) la chaîne YouTube autorisée par l'utilisateur : compte social,
 * token chiffré (un seul actif par compte), permissions réellement accordées.
 */
final class ConnectYouTubeAccount
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @param array{id: string, title: string, custom_url: ?string} $channel */
    public function handle(User $user, OAuthTokens $tokens, array $channel): SocialAccount
    {
        $platform = SocialPlatform::query()->where('key', YouTubeOAuthProvider::PROVIDER)->firstOrFail();

        [$account, $reconnected] = DB::transaction(function () use ($user, $tokens, $channel, $platform) {
            $account = SocialAccount::withTrashed()
                ->where('user_id', $user->getKey())
                ->where('social_platform_id', $platform->getKey())
                ->where('external_account_id', $channel['id'])
                ->lockForUpdate()
                ->first();
            $reconnected = $account !== null;
            $previousToken = $account ? SocialToken::query()
                ->where('social_account_id', $account->getKey())
                ->where('token_kind', TokenKind::User->value)
                ->where('status', TokenStatus::Active->value)
                ->first() : null;

            $account ??= new SocialAccount(['social_platform_id' => $platform->getKey(), 'external_account_id' => $channel['id']]);
            $account->user_id = $user->getKey();
            $account->fill([
                'account_type' => SocialAccountType::Channel,
                'display_name' => $channel['title'],
                'username' => $channel['custom_url'],
                'metadata' => ['provider' => 'google'],
            ]);
            $account->forceFill([
                'status' => SocialAccountStatus::Connected,
                'status_reason' => null,
                'connected_at' => now(),
                'last_synced_at' => now(),
                'deleted_at' => null,
            ])->save();

            // Google ne renvoie pas toujours de refresh token lors d'une reconnexion : l'ancien est conservé.
            $refresh = $tokens->refreshToken ?? $previousToken?->refresh_token_encrypted;
            $previousToken?->forceFill(['status' => TokenStatus::Revoked])->save();
            SocialToken::create([
                'social_account_id' => $account->getKey(),
                'token_kind' => TokenKind::User,
                'access_token_encrypted' => $tokens->accessToken,
                'refresh_token_encrypted' => $refresh,
                'expires_at' => $tokens->expiresAt,
                'scopes' => $tokens->scopes,
                'token_type' => $tokens->tokenType,
                'status' => TokenStatus::Active,
            ]);

            foreach (YouTubeOAuthProvider::SCOPES as $scope) {
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

        $this->audit->record($reconnected ? AuditAction::OauthReconnected : AuditAction::OauthConnected, $user, $account, ['platform' => 'youtube', 'scopes' => $tokens->scopes]);

        return $account->fresh('platform');
    }
}
