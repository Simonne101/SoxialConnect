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
 * Données de test YouTube : identifiants Google FICTIFS, plateforme, chaîne connectée, token
 * chiffré, vidéo dans un stockage factice. Aucun appel réel (Http::preventStrayRequests).
 */
trait YouTubeFixtures
{
    protected const ACCESS_TOKEN = 'ya29.test-access-token-ne-doit-jamais-sortir';

    protected const REFRESH_TOKEN = '1//test-refresh-token-ne-doit-jamais-sortir';

    protected function configureYouTube(): SocialPlatform
    {
        config([
            'services.youtube.client_id' => 'test-client-id.apps.googleusercontent.com',
            'services.youtube.client_secret' => 'test-client-secret',
            'services.youtube.redirect' => 'http://localhost:5173/oauth/youtube/callback',
        ]);

        return SocialPlatform::query()->where('key', 'youtube')->first()
            ?? SocialPlatform::factory()->create(['key' => 'youtube', 'name' => 'YouTube']);
    }

    protected function youtubeAccount(User $user, array $token = []): SocialAccount
    {
        $platform = $this->configureYouTube();
        $account = SocialAccount::factory()->for($user)->create([
            'social_platform_id' => $platform->id,
            'external_account_id' => 'UC_test_channel',
            'account_type' => 'channel',
            'display_name' => 'Chaîne de test',
        ]);
        SocialToken::create(array_merge([
            'social_account_id' => $account->id,
            'token_kind' => TokenKind::User,
            'access_token_encrypted' => self::ACCESS_TOKEN,
            'refresh_token_encrypted' => self::REFRESH_TOKEN,
            'expires_at' => now()->addHour(),
            'scopes' => ['https://www.googleapis.com/auth/youtube.upload', 'https://www.googleapis.com/auth/youtube.readonly'],
            'status' => 'active',
        ], $token));

        return $account;
    }

    protected function videoMedia(User $user, bool $withFile = true): Media
    {
        Storage::fake('media');
        $media = Media::factory()->for($user)->create([
            'type' => 'video',
            'original_name' => 'clip.mp4',
            'mime_type' => 'video/mp4',
            'extension' => 'mp4',
            'size_bytes' => 2048,
        ]);
        if ($withFile) {
            Storage::disk('media')->put($media->path, str_repeat('v', 2048));
        }

        return $media;
    }
}
