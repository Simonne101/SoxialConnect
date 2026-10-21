<?php

namespace App\Domain\Video\Sources\YtDlp;

use App\Domain\Video\Rendering\ExecutableCheck;
use Illuminate\Support\Facades\File;

/**
 * Exécutable yt-dlp (ADR-059) : chemin centralisé (video.sources.yt_dlp.binary, YT_DLP_BINARY ; par défaut
 * l'exécutable autonome officiel installé dans storage/app/yt-dlp, lisible par l'API comme par les workers)
 * et vérification réelle par `yt-dlp --version` (format AAAA.MM.JJ).
 *
 * L'exécutable autonome se décompresse à CHAQUE lancement : 4 à 8 s à vide, 70 à 82 s mesurées sous forte
 * charge (4 encodages FFmpeg sur 4 cœurs). L'ancien contrôle (20 s, échec mis en cache PARTAGÉ 30 s) déclarait
 * alors l'outil indisponible, y compris pour le worker qui télécharge → YTDLP_UNAVAILABLE à tort
 * (incident du 2026-10-12). Désormais trois états :
 * - « ok » : version lue ; mémorisée 7 jours pour CE fichier (chemin, taille, date : une mise à jour est
 *   revérifiée), partagée entre processus ;
 * - « unverified » : démarrage plus long que video.sources.yt_dlp.check_timeout (lenteur, pas absence) ; la
 *   voie reste utilisable et le téléchargement, qui exécute réellement l'outil, tranche ;
 * - « unavailable » : fichier absent, code de sortie d'erreur ou sortie invalide (échec AVÉRÉ).
 * Un état non « ok » n'est mémorisé que dans le processus qui l'a constaté (jamais imposé à un autre) et
 * sa raison est journalisée (video.executable_check). Règles communes à FFmpeg/FFprobe : ExecutableCheck.
 *
 * Environnement : dossiers temporaire et de cache propres à l'application (storage/app/private/tmp/yt-dlp),
 * identiques pour l'API et les workers.
 */
class YtDlpBinary
{
    public const OK = ExecutableCheck::OK;

    public const UNVERIFIED = ExecutableCheck::UNVERIFIED;

    public const UNAVAILABLE = ExecutableCheck::UNAVAILABLE;

    /** Variables système dont un exécutable (Python embarqué, Node pour --js-runtimes) a besoin pour démarrer. */
    private const SYSTEM_VARIABLES = [
        'SystemRoot', 'windir', 'SystemDrive', 'ComSpec', 'PATH', 'PATHEXT', 'OS',
        'USERPROFILE', 'HOMEDRIVE', 'HOMEPATH', 'HOME', 'LOCALAPPDATA', 'APPDATA', 'ProgramData',
        'ProgramFiles', 'ProgramFiles(x86)', 'CommonProgramFiles', 'NUMBER_OF_PROCESSORS', 'PROCESSOR_ARCHITECTURE', 'LANG',
    ];

    public function path(): string
    {
        return (string) config('video.sources.yt_dlp.binary');
    }

    /** Outil utilisable : vérifié, ou présent mais lent à démarrer (jamais un échec avéré). */
    public function isAvailable(): bool
    {
        return $this->state() !== self::UNAVAILABLE;
    }

    public function state(): string
    {
        return $this->check()['state'];
    }

    /** Version lue (ex. 2026.08.19), ou null si elle n'a pas pu l'être. */
    public function version(): ?string
    {
        return $this->check()['value'];
    }

    /**
     * Variables d'environnement transmises à chaque lancement de yt-dlp, quel que soit le processus PHP.
     *
     * Sous le SAPI `cli-server` (serveur web de `php artisan serve`), Symfony Process ne transmet que les
     * variables présentes à la fois dans getenv() et $_SERVER : l'enfant démarrait SANS SystemRoot et
     * l'interpréteur Python embarqué de yt-dlp échouait (« _Py_HashRandomization_Init: failed to get random
     * numbers ») → l'API concluait à tort « outil indisponible » (2026-10-13). Les variables système
     * nécessaires sont donc lues dans l'environnement réel du processus et transmises explicitement, avec
     * des dossiers temporaire et de cache propres à l'application (aucun secret de l'application ajouté).
     *
     * @return array<string, string>
     */
    public function environment(): array
    {
        $root = storage_path('app'.DIRECTORY_SEPARATOR.'private'.DIRECTORY_SEPARATOR.'tmp'.DIRECTORY_SEPARATOR.'yt-dlp');
        File::ensureDirectoryExists($root.DIRECTORY_SEPARATOR.'tmp');
        File::ensureDirectoryExists($root.DIRECTORY_SEPARATOR.'cache');
        $tmp = $root.DIRECTORY_SEPARATOR.'tmp';

        $env = [];
        foreach (self::SYSTEM_VARIABLES as $name) {
            $value = getenv($name);
            if (is_string($value) && $value !== '') {
                $env[$name] = $value;
            }
        }
        if (PHP_OS_FAMILY === 'Windows' && ! isset($env['SystemRoot'])) {
            $env['SystemRoot'] = (string) (getenv('windir') ?: 'C:\\Windows');
        }

        return [...$env, 'TEMP' => $tmp, 'TMP' => $tmp, 'TMPDIR' => $tmp, 'XDG_CACHE_HOME' => $root.DIRECTORY_SEPARATOR.'cache'];
    }

    /** Oublie l'état mémorisé (commande de contrôle, tests). */
    public function forget(): void
    {
        app(ExecutableCheck::class)->forget('yt-dlp', $this->path());
    }

    /** Vérification réelle immédiate, quel que soit le processus (commande de contrôle, diagnostic). */
    public function verify(): string
    {
        return $this->check(force: true)['state'];
    }

    /** @return array{state: string, value: ?string} */
    private function check(bool $force = false): array
    {
        return app(ExecutableCheck::class)->check(
            'yt-dlp',
            $this->path(),
            ['--ignore-config', '--version'],
            fn (string $output) => preg_match('/^\d{4}\.\d{2}\.\d{2}(\.\d+)?$/', trim($output)) ? trim($output) : null,
            max(10, (int) config('video.sources.yt_dlp.check_timeout', 60)),
            $this->environment(),
            $force,
        );
    }
}
