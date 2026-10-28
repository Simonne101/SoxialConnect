<?php

namespace App\Domain\Social\Actions;

use App\Domain\Social\Enums\PermissionStatus;
use App\Domain\Social\Enums\SocialAccountStatus;
use App\Domain\Social\Enums\SocialAccountType;
use App\Domain\Social\Enums\TokenKind;
use App\Domain\Social\Enums\TokenStatus;
use App\Domain\Social\OAuth\FacebookOAuthProvider;
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
 * Enregistre (ou reconnecte) les Pages Facebook autorisées : un compte « page » par Page ET par
 * utilisateur, chacun avec son jeton de Page (chiffré, sans expiration car issu d'un jeton utilisateur
 * longue durée) et les permissions réellement accordées. Seules les Pages où la personne peut publier
 * (tâche CREATE_CONTENT) sont enregistrées ; le jeton utilisateur n'est jamais conservé.
 */
final class ConnectFacebookPages
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{id: string, name: string}  $person
     * @param  list<array{id: string, name: string, access_token: string, tasks: list<string>, category: ?string, picture: ?string}>  $pages
     * @return list<SocialAccount>
     *
     * @throws OAuthException aucune Page sur laquelle publier
     */
    public function handle(User $user, OAuthTokens $tokens, array $person, array $pages): array
    {
        $publishable = array_values(array_filter($pages, fn (array $page) => in_array(FacebookOAuthProvider::PUBLISH_TASK, $page['tasks'], true)));
        if ($publishable === []) {
            throw new OAuthException($pages === [] ? 'NO_PAGES' : 'NO_PUBLISHABLE_PAGE', $pages === []
                ? 'Aucune Page Facebook n\'a été partagée avec SoxialConnect. Reconnectez-vous et sélectionnez au moins une Page (les profils personnels ne sont pas pris en charge par Facebook).'
                : 'Votre rôle sur les Pages sélectionnées ne permet pas d\'y publier. Il faut être administrateur, éditeur ou créateur de contenu de la Page.');
        }
        $platform = SocialPlatform::query()->where('key', FacebookOAuthProvider::PROVIDER)->firstOrFail();

        $accounts = [];
        foreach ($publishable as $page) {
            [$account, $reconnected] = DB::transaction(fn () => $this->savePage($user, $platform, $tokens, $person, $page));
            $this->audit->record($reconnected ? AuditAction::OauthReconnected : AuditAction::OauthConnected, $user, $account, ['platform' => 'facebook', 'scopes' => $tokens->scopes]);
            $accounts[] = $account->fresh('platform');
        }

        return $accounts;
    }

    /**
     * @param  array{id: string, name: string}  $person
     * @param  array{id: string, name: string, access_token: string, tasks: list<string>, category: ?string, picture: ?string}  $page
     * @return array{0: SocialAccount, 1: bool}
     */
    private function savePage(User $user, SocialPlatform $platform, OAuthTokens $tokens, array $person, array $page): array
    {
        $account = SocialAccount::withTrashed()
            ->where('user_id', $user->getKey())
            ->where('social_platform_id', $platform->getKey())
            ->where('external_account_id', $page['id'])
            ->lockForUpdate()
            ->first();
        $reconnected = $account !== null;

        $account ??= new SocialAccount(['social_platform_id' => $platform->getKey(), 'external_account_id' => $page['id']]);
        $account->user_id = $user->getKey();
        $account->fill([
            'account_type' => SocialAccountType::Page,
            'display_name' => $page['name'],
            'username' => null,
            'metadata' => array_filter([
                'provider' => FacebookOAuthProvider::PROVIDER,
                'category' => $page['category'],
                'picture_url' => $page['picture'],
                'tasks' => $page['tasks'],
                // Personne qui a autorisé l'accès : permet de distinguer deux administrateurs d'une même Page.
                'facebook_user_id' => $person['id'],
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

        // Un seul jeton actif par Page : l'ancien est révoqué localement.
        SocialToken::query()
            ->where('social_account_id', $account->getKey())
            ->whereIn('status', [TokenStatus::Active->value, TokenStatus::Expired->value, TokenStatus::Invalid->value])
            ->update(['status' => TokenStatus::Revoked->value]);
        SocialToken::create([
            'social_account_id' => $account->getKey(),
            'token_kind' => TokenKind::Page,
            'access_token_encrypted' => $page['access_token'],
            'refresh_token_encrypted' => null,
            'expires_at' => null,
            'refresh_expires_at' => null,
            'scopes' => $tokens->scopes,
            'token_type' => 'Bearer',
            'status' => TokenStatus::Active,
        ]);

        foreach (FacebookOAuthProvider::SCOPES as $scope) {
            SocialPermission::query()->updateOrCreate(
                ['social_account_id' => $account->getKey(), 'scope' => $scope],
                [
                    'status' => in_array($scope, $tokens->scopes, true) ? PermissionStatus::Granted : PermissionStatus::Missing,
                    'is_required' => in_array($scope, FacebookOAuthProvider::REQUIRED_SCOPES, true),
                    'checked_at' => now(),
                ],
            );
        }

        return [$account, $reconnected];
    }
}
