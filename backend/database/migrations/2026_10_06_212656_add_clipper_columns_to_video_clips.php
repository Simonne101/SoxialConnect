<?php

use App\Support\Database\SchemaConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * YouTube Clipper (ADR-046) : sujet et justification des passages proposés, erreur et dates de
 * rendu d'un clip. Règle métier garantie aussi par la base : un clip ne dépasse JAMAIS 60 s
 * (end_ms - start_ms <= 60000), quel que soit le chemin d'écriture.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('video_clips', function (Blueprint $table) {
            $table->string('topic', 255)->nullable()->after('title');
            $table->string('reason', 1000)->nullable()->after('topic');
            $table->string('error_code', 64)->nullable()->after('status');
            $table->string('error_message', 500)->nullable()->after('error_code');
            $table->timestamp('started_at')->nullable()->after('error_message');
            $table->timestamp('finished_at')->nullable()->after('started_at');
        });

        SchemaConstraints::check('video_clips', 'video_clips_max_duration_check', '`end_ms` - `start_ms` <= 60000');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE `video_clips` DROP CHECK `video_clips_max_duration_check`');
        }

        Schema::table('video_clips', function (Blueprint $table) {
            $table->dropColumn(['topic', 'reason', 'error_code', 'error_message', 'started_at', 'finished_at']);
        });
    }
};
