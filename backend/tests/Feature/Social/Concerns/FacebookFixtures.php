<?php

namespace Tests\Feature\Social\Concerns;

use App\Domain\Social\Enums\TokenKind;
use App\Models\Media;
use App\Models\SocialAccount;
use App\Models\SocialPlatform;
use App\Models\SocialToken;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

/**
 * Données de test Facebook : identifiants FICTIFS, plateforme, Page connectée, jeton de Page chiffré.
 * Aucun appel réel (Http::preventStrayRequests).
 */
trait FacebookFixtures
{
    protected const FB_GRAPH = 'https://graph.facebook.com/v23.0/';

    protected const FB_USER_TOKEN = 'EAAG-test-user-token-ne-doit-jamais-sortir';

    protected const FB_PAGE_TOKEN = 'EAAG-test-page-token-ne-doit-jamais-sortir';

    protected const FB_PAGE_ID = '102030405060708';

    protected const FB_SECRET = 'test-meta-app-secret';

    protected function configureFacebook(): SocialPlatform
    {
        config([
            'services.facebook.client_id' => '1234567890123456',
            'services.facebook.client_secret' => self::FB_SECRET,
            'services.facebook.redirect' => 'https://tunnel.exemple.test/oauth/facebook/callback',
            'services.facebook.login_config_id' => null,
            'services.facebook.graph_version' => 'v23.0',
        ]);

        return SocialPlatform::query()->where('key', 'facebook')->first()
            ?? SocialPlatform::factory()->create(['key' => 'facebook', 'name' => 'Facebook']);
    }

    protected function facebookPage(User $user, array $token = [], array $attributes = []): SocialAccount
    {
        $platform = $this->configureFacebook();
        $account = SocialAccount::factory()->for($user)->create(array_merge([
            'social_platform_id' => $platform->id,
            'external_account_id' => self::FB_PAGE_ID,
            'account_type' => 'page',
            'display_name' => 'Boulangerie Ada',
            'metadata' => ['provider' => 'facebook', 'tasks' => ['CREATE_CONTENT', 'MANAGE']],
        ], $attributes));
        SocialToken::create(array_merge([
            'social_account_id' => $account->id,
            'token_kind' => TokenKind::Page,
            'access_token_encrypted' => self::FB_PAGE_TOKEN,
            'expires_at' => null,
            'scopes' => ['pages_show_list', 'pages_manage_posts', 'pages_read_engagement', 'read_insights'],
            'status' => 'active',
        ], $token));

        return $account;
    }

    protected function facebookMedia(User $user, string $mime, int $bytes = 2048): Media
    {
        [$type, $extension] = match ($mime) {
            'image/jpeg' => ['image', 'jpg'],
            'image/png' => ['image', 'png'],
            'image/gif' => ['image', 'gif'],
            'image/webp' => ['image', 'webp'],
            'video/mp4' => ['video', 'mp4'],
            'video/quicktime' => ['video', 'mov'],
            default => ['document', 'pdf'],
        };
        $media = Media::factory()->for($user)->create([
            'type' => $type,
            'original_name' => "fichier-prive.{$extension}",
            'mime_type' => $mime,
            'extension' => $extension,
            'size_bytes' => $bytes,
        ]);
        Storage::disk('media')->put($media->path, str_repeat('x', $bytes));

        return $media;
    }

    /** Preuve appsecret_proof attendue pour un jeton (HMAC-SHA256 par la clé secrète FICTIVE). */
    protected function fbProof(string $token): string
    {
        return hash_hmac('sha256', $token, self::FB_SECRET);
    }
}
