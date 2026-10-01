<?php

namespace Tests\Feature\Publishing\Concerns;

use App\Domain\Publishing\Contracts\SocialPublisher;
use App\Domain\Publishing\Contracts\TracksPublicationStatus;
use App\Domain\Publishing\Data\PublishRequest;
use App\Domain\Publishing\Data\PublishResult;
use App\Models\SocialAccount;
use RuntimeException;

/**
 * Adaptateur asynchrone de TEST (jamais déclaré en configuration réelle) : l'envoi renvoie
 * « en traitement », puis chaque vérification de statut renvoie le résultat programmé.
 */
final class FakeTrackingPublisher implements SocialPublisher, TracksPublicationStatus
{
    /** @var list<PublishResult|RuntimeException> */
    public static array $statuses = [];

    public static int $publishCalls = 0;

    public static int $statusCalls = 0;

    public static function reset(PublishResult|RuntimeException ...$statuses): void
    {
        self::$statuses = $statuses;
        self::$publishCalls = 0;
        self::$statusCalls = 0;
    }

    public function publish(PublishRequest $request): PublishResult
    {
        self::$publishCalls++;

        return PublishResult::processing('track-'.$request->attemptNumber, ['publish_id' => 'track-'.$request->attemptNumber]);
    }

    public function checkStatus(SocialAccount $account, string $trackingId): PublishResult
    {
        self::$statusCalls++;
        $next = array_shift(self::$statuses) ?? PublishResult::processing($trackingId);
        if ($next instanceof RuntimeException) {
            throw $next;
        }

        return $next;
    }
}
