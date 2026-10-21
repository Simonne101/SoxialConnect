<?php

namespace App\Domain\Video\Rendering;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Dossier de travail isolé d'un rendu : {video.temp_path}/{render-id}/{input,work,output}.
 * Jamais partagé entre deux rendus ; toujours supprimé à la fin (succès, échec, annulation,
 * exception). Les médias source ne sont jamais copiés ici s'ils sont lisibles sur le disque local,
 * et jamais supprimés.
 */
final class RenderWorkspace
{
    private function __construct(public readonly string $root) {}

    public static function create(string $renderId): self
    {
        if (! preg_match('/^[0-9a-f-]{36}$/i', $renderId)) {
            throw new InvalidArgumentException('Identifiant de rendu invalide.');
        }
        $root = rtrim((string) config('video.temp_path'), '/\\').DIRECTORY_SEPARATOR.$renderId;
        File::deleteDirectory($root); // reste d'un essai interrompu : on repart d'un dossier vide
        foreach (['input', 'work', 'output'] as $dir) {
            File::ensureDirectoryExists($root.DIRECTORY_SEPARATOR.$dir);
        }

        return new self($root);
    }

    public function path(string $dir, string $file = ''): string
    {
        return $this->root.DIRECTORY_SEPARATOR.$dir.($file !== '' ? DIRECTORY_SEPARATOR.$file : '');
    }

    /**
     * Sous Windows, un fichier peut rester verrouillé quelques instants après la fin de FFmpeg ou de
     * Remotion (handle pas encore libéré, antivirus ; le profil Chrome de Remotion peut rester verrouillé
     * plus d'une seconde après un échec, observé le 2026-10-11) : la suppression est retentée pendant ~4 s.
     */
    public function delete(): void
    {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            File::deleteDirectory($this->root);
            if (! File::isDirectory($this->root)) {
                return;
            }
            usleep(200_000);
        }
        // Diagnostic (aucun effet sur le rendu) : ce qui empêche la suppression, pour en trouver la cause.
        Log::warning('video.workspace_not_deleted', [
            'workspace' => basename($this->root),
            'remaining' => array_map(fn ($file) => str_replace($this->root, '', (string) $file), array_slice(File::allFiles($this->root, true), 0, 20)),
            'directories' => array_map(fn ($dir) => str_replace($this->root, '', $dir), File::directories($this->root)),
        ]);
    }
}
