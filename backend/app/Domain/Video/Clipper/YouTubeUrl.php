<?php

namespace App\Domain\Video\Clipper;

/**
 * Lien vers une vidéo YouTube, validé côté serveur (ADR-046) : https uniquement, domaines officiels
 * (youtube.com, m.youtube.com, youtu.be, youtube-nocookie.com), identifiant de 11 caractères.
 * Formes acceptées : /watch?v=ID, /shorts/ID, /live/ID, /embed/ID, youtu.be/ID. L'URL conservée est
 * toujours la forme canonique reconstruite (jamais la saisie brute).
 */
final readonly class YouTubeUrl
{
    private const HOSTS = ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'music.youtube.com', 'youtube-nocookie.com', 'www.youtube-nocookie.com', 'youtu.be'];

    private const ID = '/^[A-Za-z0-9_-]{11}$/';

    private function __construct(public string $videoId) {}

    public static function parse(string $input): ?self
    {
        $input = trim($input);
        if ($input === '' || strlen($input) > 2048) {
            return null;
        }
        $parts = parse_url($input);
        if ($parts === false || ($parts['scheme'] ?? null) !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            return null;
        }
        $host = strtolower($parts['host'] ?? '');
        if (! in_array($host, self::HOSTS, true)) {
            return null;
        }
        $path = $parts['path'] ?? '/';

        if ($host === 'youtu.be') {
            $id = trim($path, '/');
        } elseif ($path === '/watch') {
            parse_str($parts['query'] ?? '', $query);
            $id = is_string($query['v'] ?? null) ? $query['v'] : '';
        } elseif (preg_match('#^/(shorts|live|embed)/([^/]+)/?$#', $path, $m)) {
            $id = $m[2];
        } else {
            return null;
        }

        return preg_match(self::ID, $id) ? new self($id) : null;
    }

    public function canonical(): string
    {
        return 'https://www.youtube.com/watch?v='.$this->videoId;
    }
}
