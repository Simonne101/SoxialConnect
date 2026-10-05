<?php

namespace App\Domain\Social\Actions;

use App\Domain\Social\Enums\SocialAccountStatus;
use App\Domain\Social\Enums\SocialAccountType;
use App\Models\SocialAccount;
use App\Models\SocialPlatform;
use App\Models\User;
use App\Services\Audit\AuditAction;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Enregistre (ou reconnecte) une chaîne ou un groupe Telegram VÉRIFIÉ (TelegramChatInspector) :
 * une ligne social_accounts par conversation et par utilisateur. Aucun token : le bot est partagé,
 * son token reste dans la configuration du serveur. Une liaison déconnectée est réactivée.
 */
final class ConnectTelegramChat
{
    public const PROVIDER = 'telegram';

    public function __construct(private readonly AuditLogger $audit) {}

    /** @param array{chat_id: string, type: string, title: string, username: ?string} $chat */
    public function handle(User $user, array $chat): SocialAccount
    {
        $platform = SocialPlatform::query()->where('key', self::PROVIDER)->firstOrFail();

        [$account, $reconnected] = DB::transaction(function () use ($user, $chat, $platform) {
            $account = SocialAccount::withTrashed()
                ->where('user_id', $user->getKey())
                ->where('social_platform_id', $platform->getKey())
                ->where('external_account_id', $chat['chat_id'])
                ->lockForUpdate()
                ->first();
            $reconnected = $account !== null;

            $account ??= new SocialAccount(['social_platform_id' => $platform->getKey(), 'external_account_id' => $chat['chat_id']]);
            $account->user_id = $user->getKey();
            $account->fill([
                'account_type' => $chat['type'] === 'channel' ? SocialAccountType::Channel : SocialAccountType::Group,
                'display_name' => $chat['title'],
                'username' => $chat['username'],
                'metadata' => ['provider' => self::PROVIDER, 'chat_type' => $chat['type']],
            ]);
            $account->forceFill([
                'status' => SocialAccountStatus::Connected,
                'status_reason' => null,
                'connected_at' => now(),
                'last_synced_at' => now(),
                'last_error_at' => null,
                'deleted_at' => null,
            ])->save();

            return [$account, $reconnected];
        });

        $this->audit->record($reconnected ? AuditAction::OauthReconnected : AuditAction::OauthConnected, $user, $account, [
            'platform' => self::PROVIDER,
            'method' => 'bot',
            'chat_type' => $chat['type'],
        ]);

        return $account->fresh('platform');
    }
}
