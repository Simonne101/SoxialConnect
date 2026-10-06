<?php

use App\Support\Database\SchemaConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Video Studio — timeline (ADR-044, remplace la partie « pistes » de l'ADR-042).
 * video_tracks passe de « piste audio (musique | narration), une par type » à « piste de
 * timeline » générique : video | image | text | audio | overlay, ordonnée (position = calque),
 * verrouillable, muette, masquée. Le média, l'extrait et le volume passent dans les éléments de
 * timeline (video_timeline_items).
 *
 * Table vide à la date de la migration (structure non encore utilisée par l'application).
 * Retour arrière refusé tant que des pistes existent : aucune donnée n'est effacée.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->dropCheck('video_tracks_type_check');
        $this->dropCheck('video_tracks_volume_check');

        Schema::table('video_tracks', function (Blueprint $table) {
            $table->string('name', 100)->default('')->after('type');
            $table->unsignedSmallInteger('position')->default(0)->after('name');
            $table->boolean('locked')->default(false)->after('position');
            $table->boolean('muted')->default(false)->after('locked');
            $table->boolean('hidden')->default(false)->after('muted');
            $table->json('metadata')->nullable()->after('hidden');
            // Créé avant de supprimer l'ancien unique : il sert d'index à la clé étrangère du projet.
            $table->unique(['video_project_id', 'position']);
        });

        Schema::table('video_tracks', function (Blueprint $table) {
            $table->dropUnique(['video_project_id', 'type']);
            $table->dropConstrainedForeignId('media_id');
            $table->dropColumn(['start_ms', 'trim_start_ms', 'volume', 'loop']);
        });

        SchemaConstraints::in('video_tracks', 'type', ['video', 'image', 'text', 'audio', 'overlay']);
    }

    public function down(): void
    {
        if (DB::table('video_tracks')->exists()) {
            throw new RuntimeException('Des pistes de timeline existent : supprimez-les avant d\'annuler cette migration.');
        }

        $this->dropCheck('video_tracks_type_check');

        Schema::table('video_tracks', function (Blueprint $table) {
            $table->foreignUuid('media_id')->after('type')->constrained('media')->restrictOnDelete();
            $table->unsignedInteger('start_ms')->default(0)->after('media_id');
            $table->unsignedInteger('trim_start_ms')->default(0)->after('start_ms');
            $table->unsignedTinyInteger('volume')->default(100)->after('trim_start_ms');
            $table->boolean('loop')->default(false)->after('volume');
            $table->unique(['video_project_id', 'type']);
        });

        Schema::table('video_tracks', function (Blueprint $table) {
            $table->dropUnique(['video_project_id', 'position']);
            $table->dropColumn(['name', 'position', 'locked', 'muted', 'hidden', 'metadata']);
        });

        SchemaConstraints::in('video_tracks', 'type', ['music', 'voiceover']);
        SchemaConstraints::check('video_tracks', 'video_tracks_volume_check', '`volume` BETWEEN 0 AND 100');
    }

    private function dropCheck(string $name): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `video_tracks` DROP CHECK `{$name}`");
        }
    }
};
