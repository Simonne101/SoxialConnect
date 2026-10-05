<?php

namespace App\Domain\Publishing\Adapters;

use App\Domain\Media\Storage\MediaStorage;
use App\Domain\Publishing\Contracts\SocialPublisher;
use App\Domain\Publishing\Data\PublishRequest;
use App\Domain\Publishing\Data\PublishResult;
use App\Domain\Social\Actions\ConnectTelegramChat;
use App\Domain\Social\Enums\SocialAccountStatus;
use App\Domain\Social\Telegram\TelegramApiException;
use App\Domain\Social\Telegram\TelegramBotClient;
use App\Models\Media;
use App\Models\SocialAccount;
use RuntimeException;

/**
 * Adaptateur Telegram (Bot API, bot partagé) — docs/publishing.md §13.
 *
 * - texte seul → sendMessage ; une image → sendPhoto ; un GIF → sendAnimation ; une vidéo MP4 →
 *   sendVideo ; 2 à 10 images/vidéos → sendMediaGroup (album, légende sur le premier élément) ;
 * - texte envoyé brut (aucun parse_mode : pas d'interprétation de balises) ;
 * - limites vérifiées AVANT tout envoi (refus définitif, rien n'est envoyé) : texte 4096,
 *   légende 1024, 10 médias, MP4, 50 Mo par fichier (10 Mo par photo) ;
 * - fichiers envoyés en multipart depuis le stockage privé (aucune URL publique) ;
 * - publication synchrone : succès UNIQUEMENT avec le message_id renvoyé par Telegram ;
 * - Telegram n'accepte pas de clé d'idempotence : toute interruption pendant l'envoi est un
 *   résultat inconnu (exception), jamais relancé automatiquement.
 */
final class TelegramPublisher implements SocialPublisher
{
    public const TEXT_LIMIT = 4096;

    public const CAPTION_LIMIT = 1024;

    public const ALBUM_MAX = 10;

    /** Inférieur au timeout du job (PublishPostVariantJob::$timeout = 300 s). */
    private const UPLOAD_TIMEOUT_SECONDS = 240;

    private const NOTHING = ' Rien n\'a été publié.';

    public function __construct(
        private readonly TelegramBotClient $client,
        private readonly MediaStorage $storage,
    ) {}

    public function publish(PublishRequest $request): PublishResult
    {
        if (! $this->client->isConfigured()) {
            return PublishResult::notConfigured('La publication Telegram n\'est pas configurée sur ce serveur (bot absent). Rien n\'a été publié.');
        }

        $account = SocialAccount::query()->with('platform')->find($request->socialAccountId);
        if ($account === null || $account->platform?->key !== ConnectTelegramChat::PROVIDER) {
            return PublishResult::permanentFailure('ACCOUNT_MISSING', 'La destination Telegram de cette publication n\'existe plus.'.self::NOTHING);
        }

        // --- Contrôles préalables (aucun envoi) -----------------------------------------------
        $text = $this->text($request);
        $prepared = $this->prepareMedia($request);
        if ($prepared instanceof PublishResult) {
            return $prepared;
        }
        $limit = $prepared === [] ? self::TEXT_LIMIT : self::CAPTION_LIMIT;
        $length = self::length($text);
        if ($length > $limit) {
            return PublishResult::permanentFailure('TEXT_TOO_LONG', $prepared === []
                ? "Le texte dépasse la limite Telegram de 4096 caractères ({$length}).".self::NOTHING
                : "Avec un média, le texte (légende) est limité à 1024 caractères par Telegram ({$length}). Raccourcissez-le.".self::NOTHING);
        }
        if ($prepared === [] && $length === 0) {
            return PublishResult::permanentFailure('EMPTY_CONTENT', 'La publication ne contient ni texte ni média.'.self::NOTHING);
        }

        // --- Envoi -------------------------------------------------------------------------------
        $chatId = $account->external_account_id;
        $streams = [];
        try {
            $result = match (true) {
                $prepared === [] => $this->client->call('sendMessage', ['chat_id' => $chatId, 'text' => $text]),
                count($prepared) === 1 => $this->sendSingle($chatId, $prepared[0], $text, $streams),
                default => $this->sendAlbum($chatId, $prepared, $text, $streams),
            };
        } catch (TelegramApiException $e) {
            return $this->fromApiError($e, $account);
        } finally {
            foreach ($streams as $stream) {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        }

        // --- Confirmation : identifiant(s) du ou des messages créés -----------------------------
        $messages = isset($result['message_id']) ? [$result] : (is_array($result) ? array_values($result) : []);
        $ids = array_values(array_filter(array_map(fn ($m) => is_array($m) && isset($m['message_id']) ? (int) $m['message_id'] : null, $messages)));
        if ($ids === []) {
            throw new RuntimeException('Réponse Telegram sans identifiant de message : résultat inconnu.');
        }

        return PublishResult::success((string) $ids[0], $this->messageUrl($account, $ids[0]), [
            'chat_id' => $chatId,
            'message_ids' => $ids,
        ]);
    }

    /**
     * Texte envoyé : texte de la variante (ou de la publication), puis les hashtags choisis qui n'y
     * figurent pas déjà (même rendu que l'aperçu du Composer). Jamais tronqué en silence.
     */
    public function text(PublishRequest $request): string
    {
        $text = trim($request->body);
        $missing = [];
        foreach ((array) ($request->options['hashtags'] ?? []) as $tag) {
            $tag = is_string($tag) ? trim(ltrim($tag, '#')) : '';
            if ($tag !== '' && ! preg_match('/(^|\s)#'.preg_quote($tag, '/').'(\s|$)/iu', $text)) {
                $missing[] = '#'.$tag;
            }
        }

        return $missing === [] ? $text : trim($text."\n\n".implode(' ', $missing));
    }

    /** Longueur comptée comme Telegram (unités UTF-16, comme les décalages d'entités). */
    public static function length(string $text): int
    {
        return intdiv(strlen((string) mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')), 2);
    }

    /**
     * Médias vérifiés (type, format, taille, présence dans le stockage privé), dans l'ordre.
     *
     * @return list<array{method: string, field: string, media: Media, path: string}>|PublishResult
     */
    private function prepareMedia(PublishRequest $request): array|PublishResult
    {
        if (count($request->media) > self::ALBUM_MAX) {
            return PublishResult::permanentFailure('TOO_MANY_MEDIA', 'Telegram accepte au plus 10 médias par publication (album).'.self::NOTHING);
        }

        $maxBytes = (int) config('services.telegram.max_upload_mb', 50) * 1024 * 1024;
        $maxPhotoBytes = (int) config('services.telegram.max_photo_mb', 10) * 1024 * 1024;
        $prepared = [];
        foreach ($request->media as $item) {
            $media = Media::query()->find($item['id']);
            $path = $media ? $this->storage->variantPath($media, MediaStorage::ORIGINAL) : null;
            if ($media === null || $path === null || ! $this->storage->exists($path)) {
                return PublishResult::permanentFailure('MEDIA_NOT_FOUND', 'Un fichier de cette publication est introuvable dans la médiathèque. Remplacez-le puis réessayez.'.self::NOTHING);
            }
            $mime = strtolower((string) $media->mime_type);
            $size = (int) $this->storage->disk()->size($path);

            [$method, $field] = match (true) {
                $mime === 'image/gif' => ['sendAnimation', 'animation'],
                in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true) => ['sendPhoto', 'photo'],
                $mime === 'video/mp4' => ['sendVideo', 'video'],
                str_starts_with($mime, 'video/') => [null, 'video'],
                default => [null, null],
            };
            if ($method === null) {
                return PublishResult::permanentFailure('MEDIA_INCOMPATIBLE', $field === 'video'
                    ? 'Telegram n\'accepte que les vidéos MP4. Convertissez la vidéo en MP4 puis réessayez.'.self::NOTHING
                    : 'Telegram accepte les images (JPEG, PNG, WebP, GIF) et les vidéos MP4 uniquement.'.self::NOTHING);
            }
            if ($size <= 0 || $size > $maxBytes) {
                return PublishResult::permanentFailure('FILE_TOO_LARGE', 'Un fichier dépasse la limite Telegram de 50 Mo par fichier.'.self::NOTHING);
            }
            if ($field === 'photo' && $size > $maxPhotoBytes) {
                return PublishResult::permanentFailure('FILE_TOO_LARGE', 'Une image dépasse la limite Telegram de 10 Mo par photo.'.self::NOTHING);
            }
            $prepared[] = ['method' => $method, 'field' => $field, 'media' => $media, 'path' => $path];
        }

        if (count($prepared) > 1 && collect($prepared)->contains(fn ($p) => $p['field'] === 'animation')) {
            return PublishResult::permanentFailure('MEDIA_INCOMPATIBLE', 'Un album Telegram ne peut pas contenir de GIF. Publiez le GIF seul.'.self::NOTHING);
        }

        return $prepared;
    }

    /**
     * @param  array{method: string, field: string, media: Media, path: string}  $item
     * @param  list<resource>  $streams
     */
    private function sendSingle(string $chatId, array $item, string $caption, array &$streams): mixed
    {
        $stream = $this->open($item['path'], $streams);
        $fields = ['chat_id' => $chatId];
        if ($caption !== '') {
            $fields['caption'] = $caption;
        }
        if ($item['field'] === 'video') {
            $fields['supports_streaming'] = true;
        }

        return $this->client->upload($item['method'], $fields, [[
            'name' => $item['field'],
            'stream' => $stream,
            'filename' => $this->filename($item['media']),
            'mime' => (string) $item['media']->mime_type,
        ]], self::UPLOAD_TIMEOUT_SECONDS);
    }

    /**
     * Album (sendMediaGroup) : fichiers joints « attach://fileN », légende sur le premier élément.
     *
     * @param  list<array{method: string, field: string, media: Media, path: string}>  $items
     * @param  list<resource>  $streams
     */
    private function sendAlbum(string $chatId, array $items, string $caption, array &$streams): mixed
    {
        $descriptors = [];
        $files = [];
        foreach ($items as $i => $item) {
            $descriptor = ['type' => $item['field'], 'media' => "attach://file{$i}"];
            if ($i === 0 && $caption !== '') {
                $descriptor['caption'] = $caption;
            }
            if ($item['field'] === 'video') {
                $descriptor['supports_streaming'] = true;
            }
            $descriptors[] = $descriptor;
            $files[] = [
                'name' => "file{$i}",
                'stream' => $this->open($item['path'], $streams),
                'filename' => $this->filename($item['media']),
                'mime' => (string) $item['media']->mime_type,
            ];
        }

        return $this->client->upload('sendMediaGroup', [
            'chat_id' => $chatId,
            'media' => json_encode($descriptors, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ], $files, self::UPLOAD_TIMEOUT_SECONDS);
    }

    /**
     * @param  list<resource>  $streams
     * @return resource
     */
    private function open(string $path, array &$streams)
    {
        $stream = $this->storage->readStream($path);
        if (! is_resource($stream)) {
            throw new TelegramApiException('Le fichier n\'a pas pu être lu.', -1, 'media_unreadable');
        }

        return $streams[] = $stream;
    }

    /** Nom transmis à Telegram : générique (le nom d'origine n'est pas diffusé). */
    private function filename(Media $media): string
    {
        $extension = preg_replace('/[^a-z0-9]/', '', strtolower((string) $media->extension)) ?: 'bin';

        return 'media.'.$extension;
    }

    /** Lien du message : chaîne/groupe public (t.me/<nom>/<id>) ou supergroupe/chaîne privé (t.me/c/…). */
    private function messageUrl(SocialAccount $account, int $messageId): ?string
    {
        $username = ltrim((string) $account->username, '@');
        if ($username !== '') {
            return 'https://t.me/'.rawurlencode($username).'/'.$messageId;
        }
        $chatId = (string) $account->external_account_id;

        return str_starts_with($chatId, '-100') ? 'https://t.me/c/'.substr($chatId, 4).'/'.$messageId : null;
    }

    /**
     * Erreurs de la Bot API (error_code + description, https://core.telegram.org/api/errors).
     * Envoi peut-être effectué (délai dépassé, erreur serveur) → exception = résultat inconnu.
     */
    private function fromApiError(TelegramApiException $e, SocialAccount $account): PublishResult
    {
        if ($e->errorCode === -1) {
            return PublishResult::permanentFailure('MEDIA_NOT_FOUND', 'Un fichier de cette publication n\'a pas pu être lu.'.self::NOTHING);
        }
        if ($e->maybeSent) {
            throw new RuntimeException('Envoi Telegram interrompu : résultat inconnu.');
        }

        $meta = array_filter(['error_code' => $e->errorCode ?: null, 'description' => $e->description ?: null, 'retry_after' => $e->retryAfter]);
        $status = $e->errorCode ?: null;

        if ($e->migrateToChatId !== null) {
            // Groupe devenu supergroupe : nouvel identifiant enregistré, la prochaine tentative l'utilisera.
            $account->forceFill(['external_account_id' => (string) $e->migrateToChatId])->save();

            return PublishResult::temporaryFailure('CHAT_MIGRATED', 'Le groupe Telegram est devenu un supergroupe ; son nouvel identifiant a été enregistré. Nouvel essai automatique.'.self::NOTHING, $status, $meta);
        }

        return match (true) {
            $e->errorCode === 0 => PublishResult::temporaryFailure('NETWORK_ERROR', 'Telegram est momentanément injoignable. Nouvel essai automatique.'.self::NOTHING, null, $meta),
            $e->errorCode === 429 => PublishResult::temporaryFailure('RATE_LIMITED', 'Trop de messages envoyés à Telegram en peu de temps. Nouvel essai automatique.'.self::NOTHING, $status, $meta),
            $e->errorCode === 401 || $e->errorCode === 404 => PublishResult::permanentFailure('BOT_TOKEN_INVALID', 'Le bot Telegram du serveur est mal configuré (token refusé). Contactez l\'administrateur.'.self::NOTHING, $status, $meta),
            $e->errorCode === 413 || $e->descriptionContains('too big', 'too large') => PublishResult::permanentFailure('FILE_TOO_LARGE', 'Telegram a refusé un fichier trop volumineux (50 Mo au maximum).'.self::NOTHING, $status, $meta),
            $e->errorCode === 403 || $e->descriptionContains('chat not found', 'not enough rights', 'need administrator rights', 'have no rights', 'chat_write_forbidden', 'chat_admin_required') => $this->lostAccess($account, $e, $meta),
            $e->descriptionContains('message is too long', 'caption is too long', 'message_too_long', 'media_caption_too_long') => PublishResult::permanentFailure('TEXT_TOO_LONG', 'Telegram a refusé le texte : il est trop long.'.self::NOTHING, $status, $meta),
            $e->descriptionContains('photo', 'video', 'animation', 'media', 'file', 'image', 'dimensions') => PublishResult::permanentFailure('MEDIA_REJECTED', 'Telegram a refusé un des médias (format ou dimensions).'.self::NOTHING, $status, $meta),
            default => PublishResult::permanentFailure('INVALID_REQUEST', "Telegram a refusé la publication ({$e->errorCode}).".self::NOTHING, $status, $meta),
        };
    }

    /**
     * Bot retiré, sans droit de publication, ou conversation introuvable : la destination est
     * signalée « à reconnecter » (rien n'est supprimé).
     *
     * @param  array<string, mixed>  $meta
     */
    private function lostAccess(SocialAccount $account, TelegramApiException $e, array $meta): PublishResult
    {
        $message = $e->descriptionContains('chat not found')
            ? 'La conversation Telegram est introuvable (supprimée, ou le bot n\'en fait plus partie).'
            : 'Le bot SoxialConnect n\'a plus le droit de publier dans cette conversation Telegram (retiré ou droits insuffisants).';
        $account->forceFill([
            'status' => SocialAccountStatus::Error,
            'status_reason' => $message.' Rétablissez le bot comme administrateur, puis reconnectez la destination.',
            'last_error_at' => now(),
        ])->save();

        return PublishResult::permanentFailure('PERMISSION_DENIED', $message.' Reconnectez la destination.'.self::NOTHING, $e->errorCode ?: null, $meta);
    }
}
