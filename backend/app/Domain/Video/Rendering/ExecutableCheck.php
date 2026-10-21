<?php

namespace App\Domain\Video\Rendering;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Vérification réelle d'un exécutable externe (FFmpeg, FFprobe, yt-dlp) par une commande courte
 * (`-version`, `--version`), partagée par l'API et les workers.
 *
 * Trois états, pour qu'une machine chargée ne se traduise jamais par « outil indisponible » ni par une
 * erreur fatale de l'API (incidents du 2026-10-12/13 : démarrage de yt-dlp mesuré à 70-82 s sous la
 * charge d'un rendu Remotion ; requêtes de suivi de l'API tuées par max_execution_time pendant les
 * contrôles FFmpeg/FFprobe, d'où « Projet introuvable » à l'écran) :
 * - OK : commande réussie et sortie conforme ; mémorisé 7 jours pour CE fichier (chemin, taille, date :
 *   un exécutable remplacé est revérifié), partagé entre processus ;
 * - UNVERIFIED : délai dépassé (lenteur, pas absence) ; l'outil reste utilisable, l'opération réelle tranche ;
 * - UNAVAILABLE : échec AVÉRÉ (introuvable, code de sortie, sortie non conforme).
 * Un état non OK n'est mémorisé que dans le processus qui l'a constaté (30 s, 5 min si non vérifié), jamais
 * partagé.
 *
 * Une requête web n'exécute JAMAIS de binaire : sous charge, même plafonné, le lancement puis l'arrêt d'un
 * processus y ont duré 12 à 20 s (mesuré le 2026-10-13), et une requête cumule jusqu'à trois contrôles.
 * Elle lit le succès partagé vérifié par les processus CLI (workers, commande planifiée
 * video:check-executables) ; à défaut, « non vérifié » (jamais « absent »).
 */
final class ExecutableCheck
{
    public const OK = 'ok';

    public const UNVERIFIED = 'unverified';

    public const UNAVAILABLE = 'unavailable';

    /** @var array<string, array{state: string, at: float}> */
    private static array $local = [];

    /**
     * @param  list<string>  $arguments  arguments de la commande de vérification
     * @param  Closure(string): ?string  $accept  sortie standard → valeur retenue (ex. version), ou null si non conforme
     * @param  array<string, string>  $env
     * @param  bool  $force  vérifier même hors CLI (diagnostic, tests)
     * @return array{state: string, value: ?string}
     */
    public function check(string $name, string $binary, array $arguments, Closure $accept, int $timeout, array $env = [], bool $force = false): array
    {
        $key = $this->key($name, $binary);
        $cached = Cache::get($key);
        if (is_string($cached) && $cached !== '') {
            return ['state' => self::OK, 'value' => $cached];
        }
        $local = self::$local[$key] ?? null;
        if ($local !== null && microtime(true) - $local['at'] < ($local['state'] === self::UNVERIFIED ? 300 : 30)) {
            return ['state' => $local['state'], 'value' => null];
        }
        if (! $force && ! in_array(PHP_SAPI, ['cli', 'phpdbg'], true)) {
            return ['state' => self::UNVERIFIED, 'value' => null]; // requête web : aucune exécution
        }
        if ($this->isPath($binary) && ! is_file($binary)) {
            return $this->remember($name, $key, self::UNAVAILABLE, 'BINARY_NOT_FOUND');
        }
        $started = microtime(true);
        try {
            $process = new Process([$binary, ...$arguments], null, $env ?: null);
            $process->setTimeout($this->timeout($timeout));
            $process->run();
        } catch (ProcessTimedOutException) {
            return $this->remember($name, $key, self::UNVERIFIED, 'CHECK_TIMEOUT', $started);
        } catch (Throwable $e) {
            return $this->remember($name, $key, self::UNAVAILABLE, class_basename($e), $started);
        }
        $value = $process->isSuccessful() ? $accept($process->getOutput()) : null;
        if ($value === null) {
            $stderr = (string) preg_replace('#[A-Za-z]:[\\\\/][^\s"\']*#', '[path]', trim($process->getErrorOutput()));

            return $this->remember($name, $key, self::UNAVAILABLE, 'EXIT_'.$process->getExitCode().': '.mb_substr($stderr, 0, 200), $started);
        }
        Cache::put($key, $value, now()->addDays(7));
        unset(self::$local[$key]);

        return ['state' => self::OK, 'value' => $value];
    }

    public function forget(string $name, string $binary): void
    {
        $key = $this->key($name, $binary);
        Cache::forget($key);
        unset(self::$local[$key]);
    }

    /** Délai effectif : plafonné dans une requête web (max_execution_time). */
    private function timeout(int $timeout): int
    {
        $limit = (int) ini_get('max_execution_time');
        if (! in_array(PHP_SAPI, ['cli', 'phpdbg'], true) && $limit > 0) {
            return min($timeout, max(3, intdiv($limit, 4)));
        }

        return $timeout;
    }

    /** Clé liée au fichier précis lorsqu'il s'agit d'un chemin (un exécutable remplacé est revérifié). */
    private function key(string $name, string $binary): string
    {
        $stamp = $this->isPath($binary) && is_file($binary) ? filesize($binary).'|'.filemtime($binary) : 'path';

        return "video:executable:{$name}:".hash('sha256', $binary.'|'.$stamp);
    }

    private function isPath(string $binary): bool
    {
        return str_contains($binary, '/') || str_contains($binary, '\\');
    }

    /** @return array{state: string, value: null} */
    private function remember(string $name, string $key, string $state, string $reason, ?float $started = null): array
    {
        self::$local[$key] = ['state' => $state, 'at' => microtime(true)];
        Log::warning('video.executable_check', ['executable' => $name, 'state' => $state, 'reason' => $reason, 'seconds' => $started !== null ? round(microtime(true) - $started, 1) : null, 'sapi' => PHP_SAPI]);

        return ['state' => $state, 'value' => null];
    }
}
