<?php

use App\Support\Database\SchemaConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Type de compte « group » (groupes et supergroupes Telegram) : seule la contrainte CHECK de
 * social_accounts.account_type change. Aucune colonne ni donnée modifiée : les comptes existants
 * gardent leurs valeurs, toutes encore autorisées.
 */
return new class extends Migration
{
    private const NAME = 'social_accounts_account_type_check';

    private const BEFORE = ['profile', 'page', 'business', 'creator', 'channel', 'organization', 'board_owner'];

    public function up(): void
    {
        $this->replaceCheck([...self::BEFORE, 'group']);
    }

    /** Échoue (volontairement) si des comptes « group » existent encore : aucune donnée n'est effacée. */
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
        DB::statement('ALTER TABLE `social_accounts` DROP CHECK `'.self::NAME.'`');
        SchemaConstraints::check('social_accounts', self::NAME, "`account_type` IS NULL OR `account_type` IN ({$list})");
    }
};
