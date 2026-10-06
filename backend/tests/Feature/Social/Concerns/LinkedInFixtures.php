<?php

namespace Tests\Feature\Social\Concerns;

use App\Domain\Social\Enums\TokenKind;
use App\Models\SocialAccount;
use App\Models\SocialPlatform;
use App\Models\SocialToken;
use App\Models\User;

/**
 * Données de test LinkedIn : identifiants FICTIFS, plateforme, profil connecté, token chiffré.
 * Aucun appel réel (Http::preventStrayRequests).
 */
trait LinkedInFixtures
{
    protected const LI_ACCESS = 'AQX-test-linkedin-access-token-ne-doit-jamais-sortir';

    protected const LI_SUB = 'li-member-782bbtaQ';

    protected function configureLinkedIn(): SocialPlatform
    {
        config([
            'services.linkedin.client_id' => 'test-linkedin-client-id',
            'services.linkedin.client_secret' => 'test-linkedin-client-secret',
            'services.linkedin.redirect' => 'https://tunnel.exemple.test/oauth/linkedin/callback',
        ]);

        return SocialPlatform::query()->where('key', 'linkedin')->first()
            ?? SocialPlatform::factory()->create(['key' => 'linkedin', 'name' => 'LinkedIn']);
    }

    protected function linkedinAccount(User $user, array $token = [], array $attributes = []): SocialAccount
    {
        $platform = $this->configureLinkedIn();
        $account = SocialAccount::factory()->for($user)->create(array_merge([
            'social_platform_id' => $platform->id,
            'external_account_id' => self::LI_SUB,
            'account_type' => 'profile',
            'display_name' => 'Ada Lovelace',
            'metadata' => ['provider' => 'linkedin', 'person_urn' => 'urn:li:person:'.self::LI_SUB],
        ], $attributes));
        SocialToken::create(array_merge([
            'social_account_id' => $account->id,
            'token_kind' => TokenKind::User,
            'access_token_encrypted' => self::LI_ACCESS,
            'expires_at' => now()->addDays(59),
            'scopes' => ['openid', 'profile', 'email', 'w_member_social'],
            'status' => 'active',
        ], $token));

        return $account;
    }
}
