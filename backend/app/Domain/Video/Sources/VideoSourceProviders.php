<?php

namespace App\Domain\Video\Sources;

use App\Models\VideoSource;

/** Fournisseurs d'acquisition dans l'ordre de video.sources.providers (ADR-049). */
class VideoSourceProviders
{
    /** @return list<VideoSourceProvider> */
    public function all(): array
    {
        return array_values(array_map(
            fn (string $class) => app($class),
            array_filter((array) config('video.sources.providers', []), fn ($class) => is_string($class) && is_subclass_of($class, VideoSourceProvider::class)),
        ));
    }

    /** @return list<VideoSourceProvider> fournisseurs activés et configurés */
    public function configured(): array
    {
        return array_values(array_filter($this->all(), fn (VideoSourceProvider $p) => $p->isConfigured()));
    }

    /** @return list<VideoSourceProvider> fournisseurs configurés qui prennent en charge cette source, dans l'ordre */
    public function candidates(VideoSource $source): array
    {
        return array_values(array_filter($this->configured(), fn (VideoSourceProvider $p) => $p->supports($source)));
    }

    /** Premier fournisseur configuré qui prend en charge cette source, sinon null. */
    public function for(VideoSource $source): ?VideoSourceProvider
    {
        return $this->candidates($source)[0] ?? null;
    }

    public function named(string $name): ?VideoSourceProvider
    {
        foreach ($this->all() as $provider) {
            if ($provider->name() === $name) {
                return $provider;
            }
        }

        return null;
    }
}
