<?php

namespace App\Domain\Video\Rendering;

use Illuminate\Support\Facades\File;
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

    public function delete(): void
    {
        File::deleteDirectory($this->root);
    }
}
