<?php

namespace App\Domain\Media\Actions;

use App\Domain\Media\Exceptions\MediaConflict;
use App\Models\Media;
use App\Models\MediaFolder;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Dossiers de la médiathèque (docs/database.md §3.4) : arborescence de 5 niveaux au plus,
 * nom unique par dossier parent. Supprimer un dossier supprime ses sous-dossiers (CASCADE) ;
 * les médias qu'ils contenaient reviennent à la racine (SET NULL), ils ne sont jamais supprimés.
 */
final class ManageFolders
{
    public const MAX_DEPTH = 5;

    public function create(User $user, string $name, ?string $parentId): MediaFolder
    {
        $this->assertDepth($parentId, 1);
        $folder = new MediaFolder(['name' => self::cleanName($name), 'parent_id' => $parentId]);
        $folder->user_id = $user->getKey();

        return $this->saveUnique($folder);
    }

    /** @param array{name?: string, parent_id?: string|null} $data */
    public function update(MediaFolder $folder, array $data): MediaFolder
    {
        if (array_key_exists('name', $data)) {
            $folder->name = self::cleanName((string) $data['name']);
        }
        if (array_key_exists('parent_id', $data) && $data['parent_id'] !== $folder->parent_id) {
            $parentId = $data['parent_id'];
            if ($parentId !== null && in_array($parentId, [$folder->id, ...$this->descendantIds($folder)], true)) {
                throw MediaConflict::folderCycle();
            }
            $this->assertDepth($parentId, 1 + $this->subtreeHeight($folder));
            $folder->parent_id = $parentId;
        }

        return $this->saveUnique($folder);
    }

    /**
     * Supprime le dossier et ses sous-dossiers ; leurs médias reviennent à la racine.
     * Fait côté application, sans s'appuyer sur les actions référentielles (CASCADE / SET NULL) :
     * MySQL 8.0.30 (Laragon) s'est arrêté sur une suppression en cascade touchant des tables
     * indexées sur des colonnes générées virtuelles (media_folders.parent_key, index multi-valué
     * de media.tags). Ordre : médias → dossiers du plus profond au plus haut, donc aucune cascade.
     */
    public function delete(MediaFolder $folder): void
    {
        DB::transaction(function () use ($folder) {
            $levels = [[$folder->id]];
            for ($i = 0; $i < self::MAX_DEPTH; $i++) {
                $next = MediaFolder::query()->whereIn('parent_id', end($levels))->pluck('id')->all();
                if ($next === []) {
                    break;
                }
                $levels[] = $next;
            }

            Media::withTrashed()->whereIn('folder_id', array_merge(...$levels))->update(['folder_id' => null]);
            foreach (array_reverse($levels) as $ids) {
                MediaFolder::query()->whereIn('id', $ids)->delete();
            }
        });
    }

    public static function cleanName(string $name): string
    {
        $name = (string) preg_replace('/[\x00-\x1F\x7F\/\\\\]/u', '', $name);

        return trim((string) preg_replace('/\s+/u', ' ', $name));
    }

    private function saveUnique(MediaFolder $folder): MediaFolder
    {
        try {
            $folder->save();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => 'Un dossier porte déjà ce nom à cet endroit.']);
        }

        return $folder;
    }

    /** Profondeur du parent + hauteur de la branche déplacée ≤ MAX_DEPTH. */
    private function assertDepth(?string $parentId, int $branchHeight): void
    {
        $depth = 0;
        $current = $parentId;
        while ($current !== null && $depth <= self::MAX_DEPTH) {
            $depth++;
            $current = MediaFolder::query()->whereKey($current)->value('parent_id');
        }
        if ($depth + $branchHeight > self::MAX_DEPTH) {
            throw MediaConflict::folderTooDeep(self::MAX_DEPTH);
        }
    }

    /** @return list<string> */
    private function descendantIds(MediaFolder $folder): array
    {
        $ids = [];
        $level = [$folder->id];
        for ($i = 0; $i < self::MAX_DEPTH && $level !== []; $i++) {
            $level = MediaFolder::query()->whereIn('parent_id', $level)->pluck('id')->all();
            array_push($ids, ...$level);
        }

        return $ids;
    }

    private function subtreeHeight(MediaFolder $folder): int
    {
        $height = 0;
        $level = [$folder->id];
        while ($level !== [] && $height < self::MAX_DEPTH) {
            $level = MediaFolder::query()->whereIn('parent_id', $level)->pluck('id')->all();
            if ($level !== []) {
                $height++;
            }
        }

        return $height;
    }
}
