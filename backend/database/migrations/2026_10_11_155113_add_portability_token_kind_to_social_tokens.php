<?php

use App\Support\Database\SchemaConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Jeton « portability » (ADR-054) : autorisation Google Data Portability (export officiel des vidéos
 * de la chaîne), rattachée au compte YouTube de l'utilisateur et distincte de son jeton de publication.
 * Seule la contrainte CHECK de social_tokens.token_kind change. Aucune colonne ni donnée modifiée.
 */
return new class extends Migration
{
    private const NAME = 'social_tokens_token_kind_check';

    private const BEFORE = ['user', 'page', 'app'];

    public function up(): void
    {
        $this->replaceCheck([...self::BEFORE, 'portability']);
    }

    /** Échoue (volontairement) si des jetons « portability » existent encore : aucune donnée n'est effacée. */
    public function down(): void
    {
        $this->replaceCheck(self::BEFORE);
    }

    /** @param list<string> $values */
    private function replaceCheck(array $values): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }
        $list = implode(',', array_map(fn (string $v) => DB::getPdo()->quote($v), $values));
        DB::statement('ALTER TABLE `social_tokens` DROP CHECK `'.self::NAME.'`');
        SchemaConstraints::check('social_tokens', self::NAME, "`token_kind` IN ({$list})");
    }
};
