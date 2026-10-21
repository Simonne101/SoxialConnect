<?php

use App\Support\Database\SchemaConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Video Studio — timeline (ADR-044) : description et résolution de sortie d'un projet.
 * width / height / fps sont fixés à la création d'une composition (config/video.php, formats) ;
 * NULL pour un clipping. duration_ms (existante) devient, pour une composition, la fin de la
 * timeline, recalculée à chaque modification. Ajouts seulement : aucune donnée modifiée.
 */
return new class extends Migration
{
    private const CHECKS = ['video_projects_resolution_check', 'video_projects_fps_check'];

    public function up(): void
    {
        Schema::table('video_projects', function (Blueprint $table) {
            $table->text('description')->nullable()->after('title');
            $table->unsignedSmallInteger('width')->nullable()->after('aspect_ratio');
            $table->unsignedSmallInteger('height')->nullable()->after('width');
            $table->unsignedTinyInteger('fps')->nullable()->after('height');
        });

        SchemaConstraints::check('video_projects', 'video_projects_resolution_check', '(`width` IS NULL OR `width` > 0) AND (`height` IS NULL OR `height` > 0)');
        SchemaConstraints::check('video_projects', 'video_projects_fps_check', '`fps` IS NULL OR `fps` BETWEEN 1 AND 60');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            foreach (self::CHECKS as $name) {
                DB::statement("ALTER TABLE `video_projects` DROP CHECK `{$name}`");
            }
        }
        Schema::table('video_projects', function (Blueprint $table) {
            $table->dropColumn(['description', 'width', 'height', 'fps']);
        });
    }
};
