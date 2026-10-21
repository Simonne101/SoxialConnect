<?php

namespace App\Domain\Video\Sources;

use App\Domain\Social\OAuth\OAuthException;
use App\Domain\Social\Tokens\YouTubeTokenManager;
use App\Domain\Video\Enums\SourceOwnership;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Propriété d'une vidéo YouTube (ADR-049) : YouTube Data API videos.list (part=snippet), appelée avec
 * le jeton OAuth d'une chaîne connectée par l'utilisateur (scope youtube.readonly, déjà demandé),
 * puis comparaison de snippet.channelId avec l'identifiant de la chaîne. Lecture seule, aucun
 * contenu audiovisuel n'est demandé. Toute erreur laisse la propriété « non vérifiée ».
 */
class YouTubeOwnershipVerifier
{
    public function __construct(private readonly YouTubeTokenManager $tokens) {}

    /** @return array{status: SourceOwnership, account_id: ?string, reason: ?string} */
    public function verify(User $user, string $videoId): array
    {
        $accounts = SocialAccount::query()
            ->where('user_id', $user->getKey())
            ->whereHas('platform', fn ($q) => $q->where('key', 'youtube'))
            ->get()
            ->filter(fn (SocialAccount $account) => $account->isConnected() && ! $account->isDemo());
        if ($accounts->isEmpty()) {
            return ['status' => SourceOwnership::Unverified, 'account_id' => null, 'reason' => 'NO_YOUTUBE_ACCOUNT'];
        }

        $channelId = null;
        foreach ($accounts as $account) {
            try {
                $channelId ??= $this->channelOf($this->tokens->accessTokenFor($account), $videoId);
            } catch (OAuthException) {
                continue; // compte à reconnecter : un autre compte connecté peut répondre
            }
            if ($channelId === false) {
                return ['status' => SourceOwnership::Unverified, 'account_id' => null, 'reason' => 'YOUTUBE_API_UNAVAILABLE'];
            }
            if ($channelId === '') {
                return ['status' => SourceOwnership::NotOwned, 'account_id' => null, 'reason' => 'VIDEO_NOT_FOUND'];
            }
            if ($channelId === $account->external_account_id) {
                return ['status' => SourceOwnership::Owned, 'account_id' => $account->getKey(), 'reason' => null];
            }
        }

        return $channelId === null
            ? ['status' => SourceOwnership::Unverified, 'account_id' => null, 'reason' => 'YOUTUBE_RECONNECT_REQUIRED']
            : ['status' => SourceOwnership::NotOwned, 'account_id' => null, 'reason' => null];
    }

    /** Chaîne propriétaire de la vidéo ; '' si introuvable (privée, supprimée) ; false si l'API échoue. */
    private function channelOf(string $accessToken, string $videoId): string|false
    {
        try {
            $response = Http::withToken($accessToken)->acceptJson()->withoutRedirecting()->connectTimeout(5)->timeout(10)
                ->get('https://www.googleapis.com/youtube/v3/videos', ['part' => 'snippet', 'id' => $videoId, 'fields' => 'items(snippet(channelId))']);
        } catch (ConnectionException) {
            return false;
        }
        if (! $response->successful()) {
            Log::info('clipper.ownership_check_failed', ['status' => $response->status()]);

            return false;
        }
        $channel = $response->json('items.0.snippet.channelId');

        return is_string($channel) ? $channel : '';
    }
}
