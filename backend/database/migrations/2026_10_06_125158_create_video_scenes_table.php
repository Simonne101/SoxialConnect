<?php

use App\Support\Database\SchemaConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Scènes d'un projet « composition » (ADR-042), dans l'ordre de `position`.
 * Le visuel d'une scène est un média de la médiathèque (aucune copie, aucune seconde médiathèque) ;
 * RESTRICT : un média utilisé par une scène ne peut pas être supprimé définitivement.
 *
 * Règles non exprimables en SQL (MySQL interdit un CHECK sur une colonne portant une action
 * référentielle) — appliquées par TimelineService (phase 5) :
 * - media_type renseigné si et seulement si media_id l'est, et égal au type réel du média ;
 * - le média appartient au propriétaire du projet, est « ready » et utilisable (ScanPolicy) ;
 * - media_start_ms + duration_ms <= durée de la vidéo source.
 * L'unicité (projet, position) impose des positions distinctes : la timeline est remplacée en bloc.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('video_scenes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('video_project_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('role', 8)->default('body');
            $table->unsignedInteger('duration_ms');
            $table->foreignUuid('media_id')->nullable()->constrained('media')->restrictOnDelete();
            $table->string('media_type', 8)->nullable();
            $table->unsignedInteger('media_start_ms')->nullable();
            $table->string('fit', 8)->default('cover');
            $table->string('on_screen_text', 500)->nullable();
            $table->text('voiceover_text')->nullable();
            $table->string('visual_prompt', 500)->nullable();
            $table->string('transition', 8)->default('none');
            $table->timestamps();

            // Sert aussi d'index (video_project_id, position).
            $table->unique(['video_project_id', 'position']);
        });

        SchemaConstraints::in('video_scenes', 'role', ['hook', 'intro', 'body', 'cta']);
        SchemaConstraints::check('video_scenes', 'video_scenes_duration_check', '`duration_ms` BETWEEN 500 AND 30000');
        SchemaConstraints::check('video_scenes', 'video_scenes_media_type_check', "`media_type` IS NULL OR `media_type` IN ('image', 'video')");
        SchemaConstraints::in('video_scenes', 'fit', ['cover', 'contain']);
        SchemaConstraints::in('video_scenes', 'transition', ['none', 'fade']);
    }

    public function down(): void
    {
        Schema::dropIfExists('video_scenes');
    }
};
