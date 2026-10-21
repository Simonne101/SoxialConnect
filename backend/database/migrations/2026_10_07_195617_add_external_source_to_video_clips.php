<?php

use App\Support\Database\SchemaConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Clipper (ADR-050) : source « external » — clip sélectionné ET rendu par un fournisseur externe de
 * clipping (OpusClip), importé dans la médiathèque. La limite de 60 s (CHECK existant) s'applique
 * toujours. Aucune donnée existante modifiée.
 */
return new class extends Migration
{
    private const SOURCES = ['ai', 'manual', 'auto'];

    public function up(): void
    {
        $this->replaceSourceCheck([...self::SOURCES, 'external']);
    }

    /** Échoue (volontairement) si des clips « external » existent encore : aucune donnée n'est effacée. */
    public function down(): void
    {
        $this->replaceSourceCheck(self::SOURCES);
    }

    /** @param list<string> $values */
    private function replaceSourceCheck(array $values): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }
        DB::statement('ALTER TABLE `video_clips` DROP CHECK `video_clips_source_check`');
        SchemaConstraints::in('video_clips', 'source', $values);
    }
};
