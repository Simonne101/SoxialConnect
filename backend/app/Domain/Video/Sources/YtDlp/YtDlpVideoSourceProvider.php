<?php

namespace App\Domain\Video\Sources\YtDlp;

use App\Domain\Video\Clipper\YouTubeUrl;
use App\Domain\Video\Enums\AcquisitionMethod;
use App\Domain\Video\Enums\VideoProjectStatus;
use App\Domain\Video\Sources\AcquisitionStep;
use App\Domain\Video\Sources\VideoSourceProvider;
use App\Domain\Video\Sources\VideoSourceService;
use App\Models\VideoProject;
use App\Models\VideoSource;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Acquisition par yt-dlp (ADR-059), décidée par l'éditrice du produit : téléchargement d'une vidéo YouTube
 * PUBLIQUE ou non répertoriée pour laquelle l'utilisateur a ATTESTÉ détenir les droits ou l'autorisation
 * (attestation enregistrée sur la source). Les conditions d'utilisation de YouTube restreignent le
 * téléchargement : voie désactivée par défaut (video.sources.yt_dlp.enabled).
 *
 * Garde-fous : extracteur YouTube uniquement, URL reconstruite depuis l'identifiant validé (aucune URL
 * libre, aucun risque SSRF), aucun cookie, aucun compte, aucune configuration externe (--ignore-config) ;
 * vidéos privées, réservées aux membres, à limite d'âge, en direct ou trop longues : refusées ; taille,
 * durée, délai et espace disque bornés ; processus isolé (arguments en tableau, dossier propre à la tâche,
 * arrêté en cas d'annulation) ; seul un fichier réel, non vide, est remis à l'import (médiathèque,
 * antivirus) puis à FFprobe. Les messages renvoyés ne contiennent ni chemin interne ni sortie brute.
 */
class YtDlpVideoSourceProvider implements VideoSourceProvider
{
    public function __construct(private readonly YtDlpBinary $binary) {}

    public function name(): string
    {
        return 'yt_dlp';
    }

    public function method(): AcquisitionMethod
    {
        return AcquisitionMethod::YtDlp;
    }

    /** Activé par configuration ET exécutable réellement utilisable. */
    public function isConfigured(): bool
    {
        return (bool) config('video.sources.yt_dlp.enabled', false) && $this->binary->isAvailable();
    }

    /**
     * Raison pour laquelle la voie yt-dlp n'est PAS utilisable (désactivée par configuration, exécutable
     * absent), distincte d'un échec de téléchargement ; null si elle est opérationnelle.
     */
    public function inactiveReason(): ?AcquisitionStep
    {
        if (! (bool) config('video.sources.yt_dlp.enabled', false)) {
            return AcquisitionStep::unavailable('VIDEO_DOWNLOAD_DISABLED', 'Le fichier de cette vidéo n\'est pas accessible automatiquement : la récupération automatique des vidéos YouTube publiques n\'est pas activée sur ce serveur. Importez un fichier dont vous détenez les droits pour générer les clips.');
        }
        if (! $this->binary->isAvailable()) {
            return AcquisitionStep::unavailable('YTDLP_UNAVAILABLE', 'Le fichier de cette vidéo n\'est pas accessible automatiquement : l\'outil de récupération des vidéos est indisponible sur le serveur. Importez un fichier dont vous détenez les droits pour générer les clips.');
        }

        return null;
    }

    /** Toute vidéo YouTube identifiée ; l'attestation de droits est vérifiée dans begin() (erreur explicite). */
    public function supports(VideoSource $source): bool
    {
        return $source->provider === VideoSource::PROVIDER_YOUTUBE && YouTubeUrl::parse('https://youtu.be/'.$source->external_reference) !== null;
    }

    public function begin(VideoSource $source): AcquisitionStep
    {
        if (($source->metadata['rights_attested'] ?? false) !== true) {
            return AcquisitionStep::unavailable('RIGHTS_CONFIRMATION_REQUIRED', 'Confirmez que vous détenez les droits ou l\'autorisation d\'utiliser cette vidéo pour que SoxialConnect la récupère automatiquement.');
        }
        $url = YouTubeUrl::parse('https://youtu.be/'.$source->external_reference)?->canonical();
        if ($url === null) {
            return AcquisitionStep::failed('UNSUPPORTED_URL', 'Ce lien ne correspond pas à une vidéo YouTube prise en charge.');
        }
        // Limite effective : celle du Clipper, celle des vidéos de la médiathèque (le fichier y sera importé) et
        // l'espace de stockage restant de l'utilisateur (ADR-071) — jamais un téléchargement inimportable.
        $maxBytes = VideoSourceService::maxAcquiredBytes($source);
        $temp = rtrim((string) config('video.temp_path'), '/\\');
        File::ensureDirectoryExists($temp);
        $free = @disk_free_space($temp);
        if ($free !== false && $free < $maxBytes * 1.2) {
            return AcquisitionStep::failed('INSUFFICIENT_DISK_SPACE', 'Espace disque insuffisant sur le serveur pour récupérer la vidéo.', transient: true, retryAfterSeconds: 900);
        }

        Log::info('clipper.ytdlp_started', ['source_id' => $source->getKey(), 'video_id' => $source->external_reference]);
        $dir = $temp.DIRECTORY_SEPARATOR.'ytdlp-'.$source->getKey();
        File::deleteDirectory($dir);
        File::ensureDirectoryExists($dir);
        $started = microtime(true);
        try {
            // 1. Métadonnées du format qui SERA téléchargé : disponibilité, âge, direct, durée, taille (aucun téléchargement).
            $info = $this->run($source, [...$this->baseArguments(), ...$this->formatArguments($maxBytes), '--skip-download', '-J', $url], $dir, 300); // démarrage jusqu'à ~80 s sous charge
            if ($info instanceof AcquisitionStep) {
                return $info;
            }
            $meta = json_decode($info, true);
            if (! is_array($meta)) {
                return AcquisitionStep::failed('METADATA_INVALID', 'Les informations de la vidéo n\'ont pas pu être lues.', transient: true, retryAfterSeconds: 300);
            }
            $refusal = $this->refusal($meta, $maxBytes);
            if ($refusal !== null) {
                return $refusal;
            }

            // 2. Téléchargement dans le dossier propre à la tâche. Refus ponctuel des données par YouTube (HTTP 403) :
            // UNE nouvelle tentative (nouvelle extraction, nouvelles adresses), sans cookie ni identité ; au-delà,
            // erreur BLOCKED_BY_YOUTUBE temporaire, relancée plus tard par le service.
            $download = [
                ...$this->baseArguments(),
                ...$this->formatArguments($maxBytes),
                '--merge-output-format', 'mp4',
                '--max-filesize', (string) $maxBytes,
                ...$this->ffmpegLocation(),
                '--no-mtime',
                '-o', $dir.DIRECTORY_SEPARATOR.'source.%(ext)s',
                '--print', 'after_move:filepath',
                $url,
            ];
            $timeout = max(5, (int) config('video.sources.yt_dlp.timeout', 1200));
            $output = $this->run($source, $download, $dir, $timeout);
            if ($output instanceof AcquisitionStep && $output->errorCode === 'BLOCKED_BY_YOUTUBE' && $output->transient) {
                usleep(5_000_000);
                $output = $this->run($source, $download, $dir, $timeout);
            }
            if ($output instanceof AcquisitionStep) {
                return $output;
            }

            // 3. Fichier réel, non vide, dans le dossier de la tâche (jamais un chemin fourni ailleurs).
            $lines = preg_split('/\R/', trim($output)) ?: [];
            $file = realpath((string) end($lines));
            $root = realpath($dir);
            if ($file === false || $root === false || ! str_starts_with($file, $root.DIRECTORY_SEPARATOR) || ! is_file($file) || filesize($file) === 0) {
                return AcquisitionStep::failed('DOWNLOAD_INCOMPLETE', 'Le téléchargement de la vidéo n\'a pas produit de fichier exploitable.', transient: true, retryAfterSeconds: 300);
            }
            if (filesize($file) > $maxBytes) {
                return $this->tooLarge($maxBytes);
            }
            $target = $temp.DIRECTORY_SEPARATOR.'acquired-'.$source->getKey().'.mp4';
            @unlink($target);
            if (! rename($file, $target)) {
                return AcquisitionStep::failed('DOWNLOAD_INCOMPLETE', 'Le fichier téléchargé n\'a pas pu être préparé.', transient: true, retryAfterSeconds: 300);
            }
            Log::info('clipper.ytdlp_acquired', ['source_id' => $source->getKey(), 'seconds' => round(microtime(true) - $started, 1), 'bytes' => filesize($target), 'max_bytes' => $maxBytes, 'format' => $meta['format_id'] ?? null, 'resolution' => $meta['resolution'] ?? null, 'license' => $meta['license'] ?? null, 'duration' => $meta['duration'] ?? null]);

            return AcquisitionStep::acquired($target, 'youtube-'.$source->external_reference.'.mp4', 'video/mp4');
        } finally {
            File::deleteDirectory($dir);
        }
    }

    /** Acquisition en une étape (synchrone dans le job) : un suivi relance simplement l'acquisition. */
    public function poll(VideoSource $source): AcquisitionStep
    {
        return $this->begin($source);
    }

    /**
     * Meilleure qualité (jusqu'à max_height) dont la taille connue tient dans la limite : vidéo + audio AAC
     * (audio quelconque à défaut), fusionnés en MP4 ; flux HTTP directs uniquement (taille annoncée), à
     * résolution égale H.264/AAC préférés. Une vidéo trop lourde même en qualité réduite n'a aucun format
     * admissible : refus explicite avant tout téléchargement.
     *
     * @return list<string>
     */
    private function formatArguments(int $maxBytes): array
    {
        $height = max(360, (int) config('video.sources.yt_dlp.max_height', 1080));
        $video = (int) floor($maxBytes * 0.85); // part réservée à l'audio
        $alternatives = [];
        foreach (['ba[acodec^=mp4a]', 'ba'] as $audio) {
            foreach (['filesize', 'filesize_approx'] as $size) {
                $alternatives[] = "bv*[height<={$height}][protocol^=http][{$size}<{$video}]+{$audio}";
            }
        }
        foreach (['filesize', 'filesize_approx'] as $size) {
            $alternatives[] = "b[height<={$height}][protocol^=http][{$size}<{$maxBytes}]";
        }

        return ['-f', implode('/', $alternatives), '-S', 'res,vcodec:h264,acodec:aac'];
    }

    private function tooLarge(int $maxBytes): AcquisitionStep
    {
        return AcquisitionStep::failed('SOURCE_TOO_LARGE', sprintf('La vidéo dépasse la taille maximale acceptée par la médiathèque (%d Mo), même en qualité réduite. Importez un extrait plus court.', intdiv($maxBytes, 1024 * 1024)));
    }

    /** @return list<string> */
    private function baseArguments(): array
    {
        return [
            '--ignore-config', '--no-cookies', '--no-cookies-from-browser',
            '--use-extractors', 'youtube', '--no-playlist',
            '--js-runtimes', (string) config('video.sources.yt_dlp.js_runtime', 'node'),
            '--socket-timeout', (string) max(5, (int) config('video.sources.yt_dlp.socket_timeout', 30)),
            '--retries', '3', '--no-progress', '--no-warnings',
            ...$this->proxyArguments(),
        ];
    }

    /**
     * Relais réseau configuré (video.sources.yt_dlp.proxy) : schéma http(s) ou socks5(h) et hôte obligatoires.
     * Une valeur invalide n'est jamais transmise (connexion directe) et n'est signalée que par son absence de
     * validité, jamais par son contenu (elle peut contenir des identifiants).
     *
     * @return list<string>
     */
    private function proxyArguments(): array
    {
        $proxy = trim((string) config('video.sources.yt_dlp.proxy'));
        if ($proxy === '') {
            return [];
        }
        $parts = parse_url($proxy);
        if (! is_array($parts) || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https', 'socks5', 'socks5h'], true) || ($parts['host'] ?? '') === '') {
            Log::warning('clipper.ytdlp_proxy_invalid');

            return [];
        }

        return ['--proxy', $proxy];
    }

    /**
     * FFmpeg du serveur (fusion audio + vidéo) lorsqu'il est configuré par chemin absolu ; sinon yt-dlp
     * le cherche dans le PATH.
     *
     * @return list<string>
     */
    private function ffmpegLocation(): array
    {
        $ffmpeg = (string) config('video.binaries.ffmpeg');

        return is_file($ffmpeg) ? ['--ffmpeg-location', $ffmpeg] : [];
    }

    /** @param array<string, mixed> $meta */
    private function refusal(array $meta, int $maxBytes): ?AcquisitionStep
    {
        $availability = $meta['availability'] ?? null;
        if (is_string($availability) && ! in_array($availability, ['public', 'unlisted'], true)) {
            return AcquisitionStep::unavailable('VIDEO_NOT_PUBLIC', 'Cette vidéo n\'est pas publique (privée, réservée aux membres ou à un abonnement) : elle ne peut pas être récupérée.');
        }
        if ((int) ($meta['age_limit'] ?? 0) > 0) {
            return AcquisitionStep::unavailable('AGE_RESTRICTED', 'Cette vidéo est soumise à une limite d\'âge : elle ne peut pas être récupérée automatiquement.');
        }
        if (($meta['is_live'] ?? false) === true || in_array($meta['live_status'] ?? null, ['is_live', 'is_upcoming', 'post_live'], true)) {
            return AcquisitionStep::unavailable('LIVE_NOT_SUPPORTED', 'Les directs (en cours ou à venir) ne sont pas pris en charge.');
        }
        $maxMs = (int) config('video.clipper.max_source_minutes', 120) * 60_000;
        if (is_numeric($meta['duration'] ?? null) && (float) $meta['duration'] * 1000 > $maxMs) {
            return AcquisitionStep::failed('SOURCE_TOO_LONG', 'La vidéo dépasse la durée maximale acceptée pour le Clipper.');
        }
        // Taille annoncée du format choisi (vidéo + audio) : refus avant de télécharger un fichier inutilisable.
        $parts = is_array($meta['requested_formats'] ?? null) ? $meta['requested_formats'] : [$meta];
        $announced = array_sum(array_map(fn ($f) => is_array($f) ? (int) ($f['filesize'] ?? $f['filesize_approx'] ?? 0) : 0, $parts));
        if ($announced > $maxBytes) {
            return $this->tooLarge($maxBytes);
        }

        return null;
    }

    /**
     * Processus yt-dlp surveillé : annulation du projet (processus arrêté), délai maximal, erreurs normalisées.
     *
     * @param  list<string>  $arguments
     */
    private function run(VideoSource $source, array $arguments, string $cwd, int $timeout): string|AcquisitionStep
    {
        $process = new Process([$this->binary->path(), ...$arguments], $cwd, $this->binary->environment());
        $process->setTimeout($timeout);
        try {
            $process->start();
            while ($process->isRunning()) {
                if ($this->cancelled($source)) {
                    $process->stop(3);

                    return AcquisitionStep::failed('CANCELLED', 'Récupération de la vidéo annulée.');
                }
                $process->checkTimeout();
                usleep(500_000);
            }
        } catch (ProcessTimedOutException) {
            return AcquisitionStep::failed('DOWNLOAD_TIMEOUT', 'La récupération de la vidéo a dépassé le délai autorisé.', transient: true, retryAfterSeconds: 600);
        } catch (Throwable $e) {
            Log::warning('clipper.ytdlp_failed', ['source_id' => $source->getKey(), 'exception' => $e::class]);

            return AcquisitionStep::failed('YTDLP_UNAVAILABLE', 'L\'outil de récupération de vidéos est indisponible sur le serveur.');
        }
        if ($process->isSuccessful()) {
            return $process->getOutput();
        }
        $step = $this->failure($process->getErrorOutput());
        Log::warning('clipper.ytdlp_failed', ['source_id' => $source->getKey(), 'exit_code' => $process->getExitCode(), 'error_code' => $step->errorCode, 'stderr' => $this->excerpt($process->getErrorOutput())]);

        return $step;
    }

    /**
     * Extrait de l'erreur pour le journal serveur UNIQUEMENT (diagnostic) : dernières lignes, adresses
     * (URL signées) et chemins absolus masqués, longueur bornée. Jamais transmis à l'utilisateur.
     */
    private function excerpt(string $stderr): string
    {
        $lines = array_slice(array_values(array_filter(array_map('trim', preg_split('/\R/', $stderr) ?: []))), -3);
        $text = preg_replace(['#\b[a-z][a-z0-9+.-]*://\S+#i', '#[A-Za-z]:[\\\\/][^\s"\']*#', '#(?<![\w.])/(?:[\w.-]+/)+[\w.-]*#'], ['[url]', '[path]', '[path]'], implode(' | ', $lines));

        return mb_substr((string) $text, 0, 500);
    }

    /** Erreur yt-dlp → code normalisé (la sortie brute n'est jamais transmise à l'utilisateur). */
    private function failure(string $stderr): AcquisitionStep
    {
        $e = mb_strtolower($stderr);
        $has = fn (string ...$needles) => array_filter($needles, fn ($n) => str_contains($e, $n)) !== [];

        return match (true) {
            $has('private video') => AcquisitionStep::unavailable('VIDEO_NOT_PUBLIC', 'Cette vidéo est privée : elle ne peut pas être récupérée.'),
            $has('members-only', 'join this channel', 'premium') => AcquisitionStep::unavailable('VIDEO_NOT_PUBLIC', 'Cette vidéo est réservée aux membres ou à un abonnement : elle ne peut pas être récupérée.'),
            $has('confirm your age', 'age-restricted', 'inappropriate for some users') => AcquisitionStep::unavailable('AGE_RESTRICTED', 'Cette vidéo est soumise à une limite d\'âge : elle ne peut pas être récupérée automatiquement.'),
            // L'exécutable lui-même n'a pas pu démarrer (décompression impossible) : outil indisponible, pas YouTube.
            $has('[pyi-', 'could not create temporary directory') => AcquisitionStep::failed('YTDLP_UNAVAILABLE', 'L\'outil de récupération des vidéos n\'a pas pu démarrer sur le serveur.', transient: true, retryAfterSeconds: 900),
            // Relais réseau configuré injoignable ou refusant la connexion : panne du relais, pas refus de YouTube.
            $has('proxy', 'socks') => AcquisitionStep::failed('PROXY_UNAVAILABLE', 'Le relais réseau utilisé pour récupérer les vidéos ne répond pas : nouvel essai automatique dans quelques minutes. Vous pouvez aussi importer le fichier de la vidéo.', transient: true, retryAfterSeconds: 600),
            $has('not a bot', 'sign in to confirm') => AcquisitionStep::failed('BLOCKED_BY_YOUTUBE', 'YouTube a refusé la récupération depuis ce serveur (vérification anti-robot). Importez le fichier de la vidéo.'),
            $has('http error 403') => AcquisitionStep::failed('BLOCKED_BY_YOUTUBE', 'YouTube a refusé le téléchargement de la vidéo depuis ce serveur (accès refusé). Nouvel essai automatique dans quelques minutes ; si le refus persiste, importez le fichier de la vidéo.', transient: true, retryAfterSeconds: 300),
            $has('available in your country', 'geo restrict', 'geo-restrict') => AcquisitionStep::unavailable('GEO_RESTRICTED', 'Cette vidéo n\'est pas disponible dans la région du serveur.'),
            $has('live event', 'premieres in', 'is live') => AcquisitionStep::unavailable('LIVE_NOT_SUPPORTED', 'Les directs (en cours ou à venir) ne sont pas pris en charge.'),
            $has('larger than max-filesize', 'file is larger than', 'requested format is not available') => $this->tooLarge(VideoSourceService::maxAcquiredBytes()),
            $has('video unavailable', 'not available', 'has been removed', 'does not exist') => AcquisitionStep::unavailable('VIDEO_UNAVAILABLE', 'Cette vidéo est indisponible (supprimée ou inaccessible).'),
            $has('http error 429', 'too many requests') => AcquisitionStep::failed('RATE_LIMITED', 'YouTube limite temporairement les récupérations : nouvel essai plus tard.', transient: true, retryAfterSeconds: 900),
            $has('timed out', 'connection', 'network', 'unable to download webpage', 'getaddrinfo') => AcquisitionStep::failed('NETWORK_ERROR', 'La vidéo n\'a pas pu être récupérée (erreur réseau) : nouvel essai plus tard.', transient: true, retryAfterSeconds: 300),
            default => AcquisitionStep::failed('DOWNLOAD_FAILED', 'La récupération de la vidéo a échoué : nouvel essai automatique plus tard.', transient: true, retryAfterSeconds: 600),
        };
    }

    private function cancelled(VideoSource $source): bool
    {
        return VideoProject::query()->whereKey($source->video_project_id)->where('status', VideoProjectStatus::Cancelled->value)->exists();
    }
}
