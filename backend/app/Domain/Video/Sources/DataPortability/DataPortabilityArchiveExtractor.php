<?php

namespace App\Domain\Video\Sources\DataPortability;

use App\Domain\Quota\DiskGuard;
use App\Domain\Quota\DiskSpaceInsufficient;
use App\Domain\Video\Enums\VideoProjectStatus;
use App\Domain\Video\Sources\AcquisitionStep;
use App\Models\VideoProject;
use App\Models\VideoSource;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;
use ZipArchive;

/**
 * Exploitation de l'archive Data Portability (ADR-054) : téléchargement des fichiers de l'archive
 * (URL signées Cloud Storage uniquement, sans redirection, taille cumulée plafonnée), puis extraction
 * de LA vidéo demandée. Google documente que l'archive contient les vidéos téléversées (« Original
 * format / MP4 ») mais pas l'arborescence ni le lien identifiant → fichier : seuls des rapprochements
 * SÛRS sont tentés, dans cet ordre, et une correspondance UNIQUE est exigée :
 *   1. identifiant de la vidéo présent dans le nom du fichier ;
 *   2. titre associé à l'identifiant dans un CSV de métadonnées de l'archive ;
 *   3. titre de la vidéo (oEmbed) ;
 *   4. une seule vidéo dans tout l'export.
 * Sinon : échec explicite (ARCHIVE_VIDEO_NOT_FOUND / ARCHIVE_VIDEO_AMBIGUOUS), jamais une supposition.
 * Le dossier de travail est toujours supprimé ; le fichier extrait est importé puis supprimé par
 * VideoSourceService (médiathèque : inspection, antivirus).
 */
class DataPortabilityArchiveExtractor
{
    public function __construct(private readonly DiskGuard $disk) {}

    private const VIDEO_EXTENSIONS = ['mp4' => 'video/mp4', 'mov' => 'video/quicktime', 'm4v' => 'video/x-m4v', 'webm' => 'video/webm', 'mkv' => 'video/x-matroska', 'avi' => 'video/x-msvideo', 'wmv' => 'video/x-ms-wmv', 'flv' => 'video/x-flv', '3gp' => 'video/3gpp', 'mpg' => 'video/mpeg', 'mpeg' => 'video/mpeg'];

    private const CSV_MAX_BYTES = 20 * 1024 * 1024;

    private const CHUNK = 1024 * 1024;

    /** @param list<string> $urls URL signées de l'archive (déjà filtrées par DataPortabilityClient) */
    public function extract(VideoSource $source, array $urls): AcquisitionStep
    {
        $urls = array_values(array_filter($urls, fn ($url) => is_string($url) && DataPortabilityClient::isSignedStorageUrl($url)));
        if ($urls === []) {
            return AcquisitionStep::failed('ARCHIVE_EMPTY', 'L\'export Google ne contient aucun fichier téléchargeable.');
        }
        $dir = rtrim((string) config('video.temp_path'), '/\\').DIRECTORY_SEPARATOR.'portability-'.$source->getKey();
        File::deleteDirectory($dir);
        File::ensureDirectoryExists($dir);
        $archives = [];
        try {
            $budget = (int) config('video.sources.data_portability.archive_max_bytes');
            foreach ($urls as $i => $url) {
                if ($this->cancelled($source)) {
                    return AcquisitionStep::failed('CANCELLED', 'Recherche de la source vidéo annulée.');
                }
                $path = $dir.DIRECTORY_SEPARATOR."part-{$i}.zip";
                $written = $this->download($url, $path, $budget);
                if (is_string($written)) {
                    return $this->downloadFailure($written);
                }
                $budget -= $written;
                $zip = new ZipArchive;
                if ($zip->open($path, ZipArchive::RDONLY) !== true) {
                    return AcquisitionStep::failed('ARCHIVE_INVALID', 'Un fichier de l\'export Google est illisible.');
                }
                $archives[] = $zip;
            }

            [$videos, $titles] = $this->index($archives, (string) $source->external_reference);
            $match = $this->match($videos, $titles, (string) $source->external_reference, (string) ($source->project?->title ?? ''));
            if (is_string($match)) {
                Log::info('clipper.portability_archive_unmatched', ['source_id' => $source->getKey(), 'videos' => count($videos), 'reason' => $match]);

                return $match === 'ambiguous'
                    ? AcquisitionStep::failed('ARCHIVE_VIDEO_AMBIGUOUS', 'L\'export Google contient plusieurs vidéos qui pourraient correspondre : la bonne ne peut pas être identifiée avec certitude.')
                    : AcquisitionStep::failed('ARCHIVE_VIDEO_NOT_FOUND', 'Cette vidéo est absente de l\'export Google (vidéo privée, d\'une autre chaîne ou d\'un autre compte Google).');
            }
            Log::info('clipper.portability_archive_matched', ['source_id' => $source->getKey(), 'videos' => count($videos), 'strategy' => $match['strategy']]);

            $target = rtrim((string) config('video.temp_path'), '/\\').DIRECTORY_SEPARATOR.'acquired-'.$source->getKey().'.'.$match['extension'];
            $written = $this->copyEntry($archives[$match['archive']], $match['name'], $target, (int) config('video.sources.max_bytes'));
            if (is_string($written)) {
                @unlink($target);

                return $written === 'too_large'
                    ? AcquisitionStep::failed('SOURCE_TOO_LARGE', 'La vidéo dépasse la taille maximale acceptée pour le Clipper.')
                    : AcquisitionStep::failed('ARCHIVE_INVALID', 'La vidéo n\'a pas pu être extraite de l\'export Google.');
            }

            return AcquisitionStep::acquired($target, Str::limit(basename($match['name']), 200, ''), self::VIDEO_EXTENSIONS[$match['extension']]);
        } catch (Throwable $e) {
            Log::error('clipper.portability_archive_failed', ['source_id' => $source->getKey(), 'exception' => $e::class]);

            return AcquisitionStep::failed('ARCHIVE_INVALID', 'L\'export Google n\'a pas pu être exploité.');
        } finally {
            foreach ($archives as $zip) {
                $zip->close();
            }
            $this->deleteDirectory($dir);
        }
    }

    /**
     * Sous Windows, un fichier de l'archive peut rester verrouillé quelques instants après sa fermeture
     * (antivirus, handle pas encore libéré : constaté en test le 2026-10-12) : suppression retentée pendant
     * ~4 s ; un reste éventuel est purgé ensuite par video:purge-temp.
     */
    private function deleteDirectory(string $dir): void
    {
        $started = microtime(true);
        for ($attempt = 0; $attempt < 20; $attempt++) {
            File::deleteDirectory($dir);
            if (! File::isDirectory($dir)) {
                if ($attempt > 0) {
                    Log::info('clipper.portability_workspace_deleted_late', ['workspace' => basename($dir), 'seconds' => round(microtime(true) - $started, 2)]);
                }

                return;
            }
            usleep(200_000);
        }
        Log::warning('clipper.portability_workspace_not_deleted', ['workspace' => basename($dir)]);
    }

    /** Téléchargement en flux, sans redirection, plafonné. Renvoie la taille écrite ou un code d'erreur. */
    private function download(string $url, string $path, int $budget): int|string
    {
        try {
            $response = Http::withoutRedirecting()->withOptions(['stream' => true])
                ->connectTimeout(15)->timeout(max(60, (int) config('video.sources.data_portability.download_timeout', 1500)))
                ->get($url);
        } catch (ConnectionException) {
            return 'network';
        }
        if ($response->status() === 403 || $response->status() === 410) {
            return 'expired'; // URL signée expirée : un nouvel export fournira des liens frais
        }
        if (! $response->successful()) {
            return $response->serverError() ? 'network' : 'invalid';
        }
        // Disque (ADR-071) : taille annoncée de l'archive (sinon valeur prudente), réservée le temps de l'écriture.
        $announced = $response->header('Content-Length');
        $expected = ctype_digit($announced) ? min((int) $announced, $budget) : min($budget, max(1, (int) config('quotas.disk.unknown_download_reserve_mb', 4096)) * 1024 * 1024);
        try {
            $reservation = $this->disk->reserve($expected, 'archive_download', dirname($path));
        } catch (DiskSpaceInsufficient) {
            return 'disk';
        }
        try {
            return $this->write($response, $path, $budget);
        } finally {
            $this->disk->release($reservation);
        }
    }

    /** Écriture en flux de l'archive, plafonnée. */
    private function write(Response $response, string $path, int $budget): int|string
    {
        $body = $response->toPsrResponse()->getBody();
        $out = fopen($path, 'wb');
        $written = 0;
        try {
            while (! $body->eof()) {
                $chunk = $body->read(self::CHUNK);
                $written += strlen($chunk);
                if ($written > $budget) {
                    return 'too_large';
                }
                fwrite($out, $chunk);
            }
        } catch (Throwable) {
            return 'network';
        } finally {
            fclose($out);
        }

        return $written > 0 ? $written : 'invalid';
    }

    private function downloadFailure(string $reason): AcquisitionStep
    {
        return match ($reason) {
            'network' => AcquisitionStep::failed('ARCHIVE_DOWNLOAD_FAILED', 'Le téléchargement de l\'export Google a été interrompu : nouvel essai.', transient: true, retryAfterSeconds: 300),
            'expired' => AcquisitionStep::failed('ARCHIVE_EXPIRED', 'Les liens de l\'export Google ont expiré : relancez la recherche automatique.'),
            'too_large' => AcquisitionStep::failed('ARCHIVE_TOO_LARGE', 'L\'export Google de la chaîne dépasse la taille maximale acceptée.'),
            'disk' => AcquisitionStep::failed(DiskSpaceInsufficient::CODE, 'Espace disque insuffisant sur le serveur pour télécharger l\'export Google : nouvel essai plus tard.', transient: true, retryAfterSeconds: 900),
            default => AcquisitionStep::failed('ARCHIVE_INVALID', 'Un fichier de l\'export Google est invalide.'),
        };
    }

    /**
     * Vidéos de l'archive et titres associés à l'identifiant demandé (CSV de métadonnées).
     *
     * @param  list<ZipArchive>  $archives
     * @return array{0: list<array{archive: int, name: string, extension: string}>, 1: list<string>}
     */
    private function index(array $archives, string $videoId): array
    {
        $videos = [];
        $titles = [];
        foreach ($archives as $a => $zip) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $name = is_array($stat) ? (string) $stat['name'] : '';
                if ($name === '' || str_ends_with($name, '/') || (int) $stat['size'] <= 0) {
                    continue;
                }
                $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                if (isset(self::VIDEO_EXTENSIONS[$extension])) {
                    $videos[] = ['archive' => $a, 'name' => $name, 'extension' => $extension];
                } elseif ($extension === 'csv' && (int) $stat['size'] <= self::CSV_MAX_BYTES && $videoId !== '') {
                    array_push($titles, ...$this->titlesFromCsv((string) $zip->getFromIndex($i), $videoId));
                }
            }
        }

        return [$videos, array_values(array_unique($titles))];
    }

    /** @return list<string> titres des lignes dont une colonne « … ID » vaut l'identifiant demandé */
    private function titlesFromCsv(string $csv, string $videoId): array
    {
        $lines = preg_split('/\R/', ltrim($csv, "\xEF\xBB\xBF")) ?: [];
        $header = array_map(fn ($h) => strtolower(trim((string) $h)), str_getcsv((string) array_shift($lines)));
        $idColumns = array_keys(array_filter($header, fn ($h) => preg_match('/\bvideo\s*id\b/', $h) === 1));
        $titleColumns = array_keys(array_filter($header, fn ($h) => str_contains($h, 'title') && ! str_contains($h, 'channel') && ! str_contains($h, 'playlist')));
        if ($idColumns === [] || $titleColumns === []) {
            return [];
        }
        $titles = [];
        foreach ($lines as $line) {
            $row = str_getcsv($line);
            foreach ($idColumns as $c) {
                if (trim((string) ($row[$c] ?? '')) === $videoId) {
                    foreach ($titleColumns as $t) {
                        $title = trim((string) ($row[$t] ?? ''));
                        if ($title !== '') {
                            $titles[] = $title;
                        }
                    }
                }
            }
        }

        return $titles;
    }

    /**
     * @param  list<array{archive: int, name: string, extension: string}>  $videos
     * @param  list<string>  $titles
     * @return array{archive: int, name: string, extension: string, strategy: string}|string
     */
    private function match(array $videos, array $titles, string $videoId, string $projectTitle): array|string
    {
        if ($videos === []) {
            return 'not_found';
        }
        $ambiguous = false;
        $strategies = [
            'video_id' => fn (array $v) => $videoId !== '' && str_contains(basename($v['name']), $videoId),
            'metadata_title' => fn (array $v) => $this->sameTitle(basename($v['name']), $titles),
            'title' => fn (array $v) => $this->sameTitle(basename($v['name']), [$projectTitle]),
        ];
        foreach ($strategies as $strategy => $test) {
            $found = array_values(array_filter($videos, $test));
            if (count($found) === 1) {
                return [...$found[0], 'strategy' => $strategy];
            }
            $ambiguous = $ambiguous || count($found) > 1;
        }
        if (! $ambiguous && count($videos) === 1) {
            return [...$videos[0], 'strategy' => 'single_video'];
        }

        return $ambiguous || count($videos) > 1 ? 'ambiguous' : 'not_found';
    }

    /** @param list<string> $titles */
    private function sameTitle(string $fileName, array $titles): bool
    {
        $name = $this->normalize(pathinfo($fileName, PATHINFO_FILENAME));
        foreach ($titles as $title) {
            $title = $this->normalize($title);
            // Noms de fichiers d'export : caractères interdits remplacés, titres longs tronqués.
            if ($title !== '' && mb_strlen($name) >= 8 && ($name === $title || str_starts_with($title, $name) || str_starts_with($name, $title))) {
                return true;
            }
        }

        return false;
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower(Str::ascii($value));

        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $value));
    }

    /** Copie en flux d'une entrée de l'archive vers $target, plafonnée (nom d'entrée jamais utilisé comme chemin). */
    private function copyEntry(ZipArchive $zip, string $name, string $target, int $maxBytes): int|string
    {
        $in = $zip->getStream($name);
        if ($in === false) {
            return 'invalid';
        }
        $out = fopen($target, 'wb');
        $written = 0;
        try {
            while (! feof($in)) {
                $chunk = (string) fread($in, self::CHUNK);
                $written += strlen($chunk);
                if ($written > $maxBytes) {
                    return 'too_large';
                }
                fwrite($out, $chunk);
            }
        } finally {
            fclose($in);
            fclose($out);
        }

        return $written > 0 ? $written : 'invalid';
    }

    private function cancelled(VideoSource $source): bool
    {
        return VideoProject::query()->whereKey($source->video_project_id)->where('status', VideoProjectStatus::Cancelled->value)->exists();
    }
}
