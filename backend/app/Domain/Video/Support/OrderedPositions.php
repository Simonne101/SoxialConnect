<?php

namespace App\Domain\Video\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Positions contiguës (0, 1, 2…) des scènes ou des pistes d'un projet, sous le verrou du projet.
 * L'unicité (projet, position) impose deux passes : décalage hors plage, puis positions finales.
 */
final class OrderedPositions
{
    /**
     * Position temporaire d'un élément tout juste créé, avant placement. PENDING + OFFSET doit
     * rester sous la capacité de la colonne (smallint non signé : 65 535).
     */
    public const PENDING = 30_000;

    private const OFFSET = 10_000;

    /**
     * Place $id à $position (null = à la fin) parmi les éléments du parent, puis renumérote.
     *
     * @param  class-string<Model>  $model
     */
    public static function place(string $model, string $parentColumn, string $parentId, string $id, ?int $position): void
    {
        $ids = $model::query()->where($parentColumn, $parentId)->orderBy('position')->pluck('id')
            ->reject(fn (string $other) => $other === $id)->values()->all();
        $position = $position === null ? count($ids) : max(0, min($position, count($ids)));
        array_splice($ids, $position, 0, [$id]);

        self::apply($model, $parentColumn, $parentId, $ids);
    }

    /**
     * Renumérote sans trou (après une suppression).
     *
     * @param  class-string<Model>  $model
     */
    public static function compact(string $model, string $parentColumn, string $parentId): void
    {
        self::apply($model, $parentColumn, $parentId, $model::query()->where($parentColumn, $parentId)->orderBy('position')->pluck('id')->all());
    }

    /**
     * @param  class-string<Model>  $model
     * @param  list<string>  $ids
     */
    private static function apply(string $model, string $parentColumn, string $parentId, array $ids): void
    {
        $model::query()->where($parentColumn, $parentId)->update(['position' => DB::raw('`position` + '.self::OFFSET)]);
        foreach ($ids as $index => $id) {
            $model::query()->whereKey($id)->update(['position' => $index]);
        }
    }
}
