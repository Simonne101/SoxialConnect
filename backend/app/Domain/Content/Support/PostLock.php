<?php

namespace App\Domain\Content\Support;

use App\Domain\Content\Exceptions\PostConflict;
use App\Models\Post;

/**
 * Verrou d'écriture d'une publication : verrou pessimiste (SELECT … FOR UPDATE) + contrôle
 * de version optimiste (lock_version). À appeler dans une transaction.
 * Pas d'écrasement silencieux entre deux onglets ou deux actions concurrentes.
 */
final class PostLock
{
    public static function acquire(Post $post, ?int $expectedVersion): Post
    {
        $locked = Post::query()->whereKey($post->getKey())->lockForUpdate()->firstOrFail();

        if ($expectedVersion !== null && $expectedVersion !== $locked->lock_version) {
            throw PostConflict::staleVersion();
        }

        return $locked;
    }
}
