<?php

use App\Support\Database\SchemaConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Clip Intelligence Engine (ADR-051) : un passage proposé porte son accroche (hook), la confiance de
 * l'analyse (0-100) et le détail des critères notés par l'IA (score_breakdown). Le score global
 * existant (`score`) est désormais calculé par le backend à partir de ce détail (ClipScorer).
 * Colonnes facultatives : les passages existants et les passages manuels n'en ont pas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('video_clips', function (Blueprint $table) {
            $table->string('hook', 255)->nullable()->after('reason');
            $table->unsignedTinyInteger('confidence')->nullable()->after('score');
            $table->json('score_breakdown')->nullable()->after('confidence');
        });
        SchemaConstraints::check('video_clips', 'video_clips_confidence_check', '`confidence` IS NULL OR `confidence` <= 100');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE `video_clips` DROP CHECK `video_clips_confidence_check`');
        }
        Schema::table('video_clips', function (Blueprint $table) {
            $table->dropColumn(['hook', 'confidence', 'score_breakdown']);
        });
    }
};
