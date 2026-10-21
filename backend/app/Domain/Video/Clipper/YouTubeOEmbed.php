<?php

namespace App\Domain\Video\Clipper;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Vérification d'une vidéo YouTube par l'endpoint public officiel oEmbed (ADR-046) : titre et
 * chaîne d'une vidéo publique. Aucune clé, aucun téléchargement : le fichier vidéo à découper est
 * fourni par l'utilisateur depuis sa médiathèque.
 */
class YouTubeOEmbed
{
    private const ENDPOINT = 'https://www.youtube.com/oembed';

    /**
     * @return array{title: string, author: string|null, thumbnail: string|null}
     *
     * @throws ClipperConflict vidéo introuvable, privée ou non intégrable ; YouTube injoignable
     */
    public function lookup(YouTubeUrl $url): array
    {
        try {
            $response = Http::acceptJson()
                ->withoutRedirecting() // jamais suivi : une redirection n'est pas une réponse oEmbed
                ->connectTimeout(5)
                ->timeout((int) config('video.clipper.oembed_timeout', 8))
                ->get(self::ENDPOINT, ['url' => $url->canonical(), 'format' => 'json']);
        } catch (ConnectionException) {
            throw ClipperConflict::youtubeUnreachable();
        }

        if ($response->serverError() || $response->status() === 429) {
            throw ClipperConflict::youtubeUnreachable();
        }
        $title = $response->json('title');
        if (! $response->successful() || ! is_string($title) || trim($title) === '') {
            throw ClipperConflict::videoUnavailable(); // 400, 401 (non intégrable), 403, 404 (privée, supprimée)
        }
        $author = $response->json('author_name');

        return ['title' => trim($title), 'author' => is_string($author) && $author !== '' ? $author : null, 'thumbnail' => self::thumbnail($response->json('thumbnail_url'))];
    }

    /** Miniature acceptée seulement si elle vient du CDN d'images de YouTube, en https. */
    public static function thumbnail(mixed $url): ?string
    {
        if (! is_string($url) || strlen($url) > 500) {
            return null;
        }
        $parts = parse_url($url);

        return ($parts['scheme'] ?? null) === 'https' && in_array($parts['host'] ?? null, ['i.ytimg.com', 'i9.ytimg.com'], true) && ! isset($parts['port']) ? $url : null;
    }
}
