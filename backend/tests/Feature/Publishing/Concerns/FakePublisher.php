<?php

namespace Tests\Feature\Publishing\Concerns;

use App\Domain\Publishing\Contracts\SocialPublisher;
use App\Domain\Publishing\Data\PublishRequest;
use App\Domain\Publishing\Data\PublishResult;
use RuntimeException;

/**
 * Adaptateur de TEST uniquement (jamais déclaré dans config/publishing.php) : renvoie les
 * résultats programmés par le test, sans aucun appel réseau, et compte les appels.
 */
final class FakePublisher implements SocialPublisher
{
    /** @var list<PublishResult|RuntimeException> */
    public static array $queue = [];

    /** @var list<PublishRequest> */
    public static array $requests = [];

    public static function reset(PublishResult|RuntimeException ...$results): void
    {
        self::$queue = $results;
        self::$requests = [];
    }

    public function publish(PublishRequest $request): PublishResult
    {
        self::$requests[] = $request;
        $next = array_shift(self::$queue) ?? PublishResult::success("ext-{$request->attemptNumber}", 'https://exemple.test/p/'.$request->attemptNumber);
        if ($next instanceof RuntimeException) {
            throw $next;
        }

        return $next;
    }
}
