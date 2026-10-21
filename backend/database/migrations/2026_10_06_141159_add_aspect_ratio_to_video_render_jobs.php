<?php

use App\Support\Database\SchemaConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Format de sortie d'un rendu (ADR-044), figé à la demande comme width / height / fps.
 * Table vide à la date de la migration ; colonne facultative pour rester compatible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('video_render_jobs', function (Blueprint $table) {
            $table->string('aspect_ratio', 8)->nullable()->after('renderer');
        });

        SchemaConstraints::check('video_render_jobs', 'video_render_jobs_aspect_ratio_check', "`aspect_ratio` IS NULL OR `aspect_ratio` IN ('9:16', '1:1', '16:9')");
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE `video_render_jobs` DROP CHECK `video_render_jobs_aspect_ratio_check`');
        }
        Schema::table('video_render_jobs', function (Blueprint $table) {
            $table->dropColumn('aspect_ratio');
        });
    }
};
