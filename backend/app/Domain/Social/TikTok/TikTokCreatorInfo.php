<?php

namespace App\Domain\Social\TikTok;

use App\Domain\Social\OAuth\OAuthException;
use App\Domain\Social\Tokens\TikTokTokenManager;
use App\Models\SocialAccount;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Informations du créateur TikTok (POST /v2/post/publish/creator_info/query/) : compte cible,
 * confidentialités autorisées, interactions désactivées, durée maximale. TikTok impose de les
 * afficher avant la publication et de les respecter (Content Sharing Guidelines) : utilisées
 * par le Composer ET revérifiées au moment de l'envoi (TikTokPublisher).
 */
final class TikTokCreatorInfo
{
    private const URL = 'https://open.tiktokapis.com/v2/post/publish/creator_info/query/';

    public function __construct(private readonly TikTokTokenManager $tokens) {}

    /**
     * @return array{nickname: string, username: ?string, privacy_level_options: list<string>, comment_disabled: bool, duet_disabled: bool, stitch_disabled: bool, max_video_post_duration_sec: ?int}
     *
     * @throws OAuthException|TikTokApiException
     */
    public function fetch(SocialAccount $account): array
    {
        $token = $this->tokens->accessTokenFor($account);
        $error = null;
        foreach ([false, true] as $retried) {
            try {
                $response = Http::withToken($token)->acceptJson()->asJson()->timeout(20)->post(self::URL, (object) []);
            } catch (ConnectionException) {
                throw new TikTokApiException('NETWORK_ERROR', 'TikTok est momentanément injoignable. Réessayez dans un instant.', true);
            }
            $error = TikTokApiException::fromResponse($response);
            if ($error === null) {
                break;
            }
            if (! $error->tokenInvalid || $retried) {
                throw $error;
            }
            // Jeton refusé malgré sa date : un seul renouvellement forcé, puis un seul nouvel essai.
            $token = $this->tokens->accessTokenFor($account, forceRefresh: true);
        }

        $data = (array) $response->json('data');
        $options = array_values(array_filter((array) ($data['privacy_level_options'] ?? []), 'is_string'));

        return [
            'nickname' => (string) ($data['creator_nickname'] ?? $account->display_name ?? 'Compte TikTok'),
            'username' => isset($data['creator_username']) ? (string) $data['creator_username'] : null,
            'privacy_level_options' => $options,
            'comment_disabled' => (bool) ($data['comment_disabled'] ?? false),
            'duet_disabled' => (bool) ($data['duet_disabled'] ?? false),
            'stitch_disabled' => (bool) ($data['stitch_disabled'] ?? false),
            'max_video_post_duration_sec' => isset($data['max_video_post_duration_sec']) ? (int) $data['max_video_post_duration_sec'] : null,
        ];
    }
}
