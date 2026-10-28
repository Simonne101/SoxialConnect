<?php

namespace Tests\Feature\Social\Concerns;

use App\Domain\Social\Enums\TokenKind;
use App\Models\SocialAccount;
use App\Models\SocialPlatform;
use App\Models\SocialToken;
use App\Models\User;

/**
 * Données de test Instagram : identifiants FICTIFS, plateforme, compte professionnel connecté, jeton
 * longue durée chiffré. Aucun appel réel (Http::preventStrayRequests).
 */
trait InstagramFixtures
{
    protected const IG_GRAPH = 'https://graph.instagram.com/v23.0/';

    protected const IG_SHORT_TOKEN = 'IGAA-test-short-token-ne-doit-jamais-sortir';

    protected const IG_TOKEN = 'IGAA-test-long-token-ne-doit-jamais-sortir';

    protected const IG_USER_ID = '17841400000000001';

    protected const IG_SECRET = 'test-instagram-app-secret';

    protected function configureInstagram(): SocialPlatform
    {
        config([
            'services.instagram.client_id' => '987654321098765',
            'services.instagram.client_secret' => self::IG_SECRET,
            'services.instagram.redirect' => 'https://tunnel.exemple.test/oauth/instagram/callback',
            'services.instagram.graph_version' => 'v23.0',
        ]);

        return SocialPlatform::query()->where('key', 'instagram')->first()
            ?? SocialPlatform::factory()->create(['key' => 'instagram', 'name' => 'Instagram']);
    }

    protected function instagramAccount(User $user, array $token = [], array $attributes = []): SocialAccount
    {
        $platform = $this->configureInstagram();
        $account = SocialAccount::factory()->for($user)->create(array_merge([
            'social_platform_id' => $platform->id,
            'external_account_id' => self::IG_USER_ID,
            'account_type' => 'business',
            'display_name' => 'Boulangerie Ada',
            'username' => '@boulangerie.ada',
            'metadata' => ['provider' => 'instagram', 'instagram_account_type' => 'BUSINESS'],
        ], $attributes));
        SocialToken::create(array_merge([
            'social_account_id' => $account->id,
            'token_kind' => TokenKind::User,
            'access_token_encrypted' => self::IG_TOKEN,
            'expires_at' => now()->addDays(50),
            'scopes' => ['instagram_business_basic', 'instagram_business_content_publish', 'instagram_business_manage_insights'],
            'status' => 'active',
        ], $token));

        return $account;
    }
}
