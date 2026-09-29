<?php

namespace App\Support\Database;

use Illuminate\Support\Facades\DB;

/**
 * Contraintes MySQL que le Schema Builder ne sait pas exprimer (docs/database.md §1 et §1.1) :
 * CHECK (appliqués depuis MySQL 8.0.16), index multi-valués JSON, index FULLTEXT.
 * Les listes de valeurs sont écrites en dur dans les migrations (historique immuable),
 * les enums PHP du domaine restent la source de vérité du code.
 */
final class SchemaConstraints
{
    /** CHECK (column IN (...)). */
    public static function in(string $table, string $column, array $values): void
    {
        $list = implode(', ', array_map(fn (string $v) => DB::getPdo()->quote($v), $values));
        self::check($table, "{$table}_{$column}_check", "`{$column}` IN ({$list})");
    }

    public static function check(string $table, string $name, string $expression): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }
        DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT `{$name}` CHECK ({$expression})");
    }

    /** Index multi-valué sur un tableau JSON de chaînes (ex. tags). */
    public static function jsonArrayIndex(string $table, string $column, int $length = 64): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }
        DB::statement("CREATE INDEX `{$table}_{$column}_mvi` ON `{$table}` ((CAST(`{$column}` AS CHAR({$length}) ARRAY)))");
    }
}
