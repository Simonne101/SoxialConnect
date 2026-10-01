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
 * Données de test TikTok : identifiants FICTIFS, plateforme, compte connecté, token chiffré,
 * vidéo dans un stockage factice. Aucun appel réel (Http::preventStrayRequests).
 */
trait TikTokFixtures
{
    protected const TT_ACCESS = 'act.test-tiktok-access-ne-doit-jamais-sortir';

    protected const TT_REFRESH = 'rft.test-tiktok-refresh-ne-doit-jamais-sortir';

    protected const TT_OPEN_ID = 'open-id-test-123';

    protected const TT_UPLOAD_URL = 'https://open-upload.tiktokapis.com/video/?upload_id=UP123&upload_token=TOK';

    protected function configureTikTok(): SocialPlatform
    {
        config([
            'services.tiktok.client_key' => 'test-client-key',
            'services.tiktok.client_secret' => 'test-tiktok-client-secret',
            'services.tiktok.redirect' => 'https://tunnel.exemple.test/oauth/tiktok/callback',
        ]);

        return SocialPlatform::query()->where('key', 'tiktok')->first()
            ?? SocialPlatform::factory()->create(['key' => 'tiktok', 'name' => 'TikTok']);
    }

    protected function tiktokAccount(User $user, array $token = []): SocialAccount
    {
        $platform = $this->configureTikTok();
        $account = SocialAccount::factory()->for($user)->create([
            'social_platform_id' => $platform->id,
            'external_account_id' => self::TT_OPEN_ID,
            'account_type' => 'creator',
            'display_name' => 'Créateur test',
        ]);
        SocialToken::create(array_merge([
            'social_account_id' => $account->id,
            'token_kind' => TokenKind::User,
            'access_token_encrypted' => self::TT_ACCESS,
            'refresh_token_encrypted' => self::TT_REFRESH,
            'expires_at' => now()->addHours(20),
            'refresh_expires_at' => now()->addDays(300),
            'scopes' => ['user.info.basic', 'video.publish'],
            'status' => 'active',
        ], $token));

        return $account;
    }

    /** Vidéo MP4 fictive de $bytes octets dans le stockage privé factice. */
    protected function tiktokVideo(User $user, int $bytes = 2048, ?int $durationMs = 15_000): Media
    {
        Storage::fake('media');
        $media = Media::factory()->for($user)->create([
            'type' => 'video',
            'original_name' => 'clip.mp4',
            'mime_type' => 'video/mp4',
            'extension' => 'mp4',
            'size_bytes' => $bytes,
            'duration_ms' => $durationMs,
        ]);
        Storage::disk('media')->put($media->path, str_repeat('t', $bytes));

        return $media;
    }

    /** @return array<string, mixed> Réponse creator_info (valeurs modifiables). */
    protected function creatorInfo(array $overrides = []): array
    {
        return ['data' => array_merge([
            'creator_nickname' => 'Créateur test',
            'creator_username' => 'createur.test',
            'privacy_level_options' => ['PUBLIC_TO_EVERYONE', 'MUTUAL_FOLLOW_FRIENDS', 'SELF_ONLY'],
            'comment_disabled' => false,
            'duet_disabled' => false,
            'stitch_disabled' => false,
            'max_video_post_duration_sec' => 300,
        ], $overrides), 'error' => ['code' => 'ok', 'message' => '', 'log_id' => 'log']];
    }
}
