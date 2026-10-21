<?php

use App\Support\Database\SchemaConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Clipper sans IA obligatoire (ADR-047) :
 * - source « auto » : passage proposé par l'analyse locale (heuristique), distinct de « ai » pour ne
 *   jamais présenter une heuristique comme de l'IA ;
 * - render_stage : étape du rendu d'un clip (preparing → rendering → validating → publishing).
 * Aucune donnée existante modifiée.
 */
return new class extends Migration
{
    private const SOURCES = ['ai', 'manual'];

    private const STAGES = ['preparing', 'rendering', 'validating', 'publishing'];

    public function up(): void
    {
        Schema::table('video_clips', function (Blueprint $table) {
            $table->string('render_stage', 16)->nullable()->after('status');
        });
        $this->replaceSourceCheck([...self::SOURCES, 'auto']);
        SchemaConstraints::check('video_clips', 'video_clips_render_stage_check', '`render_stage` IS NULL OR `render_stage` IN ('.implode(', ', array_map(fn ($s) => "'{$s}'", self::STAGES)).')');
    }

    /** Échoue (volontairement) si des passages « auto » existent encore : aucune donnée n'est effacée. */
    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE `video_clips` DROP CHECK `video_clips_render_stage_check`');
        }
        $this->replaceSourceCheck(self::SOURCES);
        Schema::table('video_clips', function (Blueprint $table) {
            $table->dropColumn('render_stage');
        });
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
