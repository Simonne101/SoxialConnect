<?php

namespace Tests\Feature\Social\Concerns;

use App\Domain\Social\Enums\TokenKind;
use App\Models\SocialAccount;
use App\Models\SocialPlatform;
use App\Models\SocialToken;
use App\Models\User;

/**
 * Données de test X : identifiants FICTIFS, plateforme, compte connecté, tokens chiffrés.
 * Aucun appel réel (Http::preventStrayRequests).
 */
trait XFixtures
{
    protected const X_ACCESS = 'x-test-access-token-ne-doit-jamais-sortir';

    protected const X_REFRESH = 'x-test-refresh-token-ne-doit-jamais-sortir';

    protected const X_USER_ID = '1449073420001234567';

    protected const X_CLIENT_ID = 'test-x-client-id';

    protected const X_CLIENT_SECRET = 'test-x-client-secret';

    protected const X_REDIRECT = 'https://tunnel.exemple.test/oauth/x/callback';

    protected function configureX(): SocialPlatform
    {
        config([
            'services.x.client_id' => self::X_CLIENT_ID,
            'services.x.client_secret' => self::X_CLIENT_SECRET,
            'services.x.redirect' => self::X_REDIRECT,
        ]);

        return SocialPlatform::query()->where('key', 'x')->first()
            ?? SocialPlatform::factory()->create(['key' => 'x', 'name' => 'X (Twitter)']);
    }

    protected function xAccount(User $user, array $token = [], array $attributes = []): SocialAccount
    {
        $platform = $this->configureX();
        $account = SocialAccount::factory()->for($user)->create(array_merge([
            'social_platform_id' => $platform->id,
            'external_account_id' => self::X_USER_ID,
            'account_type' => 'profile',
            'display_name' => 'Ada Lovelace',
            'username' => '@ada',
            'metadata' => ['provider' => 'x', 'username' => 'ada'],
        ], $attributes));
        SocialToken::create(array_merge([
            'social_account_id' => $account->id,
            'token_kind' => TokenKind::User,
            'access_token_encrypted' => self::X_ACCESS,
            'refresh_token_encrypted' => self::X_REFRESH,
            'expires_at' => now()->addHours(2),
            'scopes' => ['users.read', 'tweet.read', 'tweet.write', 'offline.access'],
            'status' => 'active',
        ], $token));

        return $account;
    }
}
