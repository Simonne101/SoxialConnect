<?php

namespace App\Domain\Social\Actions;

use App\Domain\Social\Enums\PermissionStatus;
use App\Domain\Social\Enums\SocialAccountStatus;
use App\Domain\Social\Enums\SocialAccountType;
use App\Domain\Social\Enums\TokenKind;
use App\Domain\Social\Enums\TokenStatus;
use App\Domain\Social\OAuth\LinkedInOAuthProvider;
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
 * Enregistre (ou reconnecte) le profil LinkedIn autorisé : un compte par membre (sub) et par
 * utilisateur, type « profile », un seul token actif (chiffré), permissions réellement accordées.
 * Les pages d'organisation ne sont pas gérées (permissions LinkedIn supplémentaires requises).
 */
final class ConnectLinkedInAccount
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @param array{sub: string, name: string, email: ?string, picture: ?string} $member */
    public function handle(User $user, OAuthTokens $tokens, array $member): SocialAccount
    {
        $platform = SocialPlatform::query()->where('key', LinkedInOAuthProvider::PROVIDER)->firstOrFail();

        [$account, $reconnected] = DB::transaction(function () use ($user, $tokens, $member, $platform) {
            $account = SocialAccount::withTrashed()
                ->where('user_id', $user->getKey())
                ->where('social_platform_id', $platform->getKey())
                ->where('external_account_id', $member['sub'])
                ->lockForUpdate()
                ->first();
            $reconnected = $account !== null;
            $previous = $account ? SocialToken::query()
                ->where('social_account_id', $account->getKey())
                ->where('token_kind', TokenKind::User->value)
                ->where('status', TokenStatus::Active->value)
                ->first() : null;

            $account ??= new SocialAccount(['social_platform_id' => $platform->getKey(), 'external_account_id' => $member['sub']]);
            $account->user_id = $user->getKey();
            $account->fill([
                'account_type' => SocialAccountType::Profile,
                'display_name' => $member['name'],
                'username' => $member['email'],
                'metadata' => array_filter([
                    'provider' => LinkedInOAuthProvider::PROVIDER,
                    'person_urn' => 'urn:li:person:'.$member['sub'],
                    'picture_url' => $member['picture'],
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

            foreach (LinkedInOAuthProvider::SCOPES as $scope) {
                SocialPermission::query()->updateOrCreate(
                    ['social_account_id' => $account->getKey(), 'scope' => $scope],
                    [
                        'status' => in_array($scope, $tokens->scopes, true) ? PermissionStatus::Granted : PermissionStatus::Missing,
                        'is_required' => $scope !== 'email',
                        'checked_at' => now(),
                    ],
                );
            }

            return [$account, $reconnected];
        });

        $this->audit->record($reconnected ? AuditAction::OauthReconnected : AuditAction::OauthConnected, $user, $account, ['platform' => 'linkedin', 'scopes' => $tokens->scopes]);

        return $account->fresh('platform');
    }
}
